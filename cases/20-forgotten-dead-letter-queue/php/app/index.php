<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const CASE_NAME = '20 - La dead letter queue olvidada';

$status = 200;

// ---------------------------------------------------------------------------
// Variante silenciosa: cualquier fallo va a la DLQ, y nadie vuelve
// ---------------------------------------------------------------------------

function consumeSilent(array &$s, int $messages, int $transientPct, int $poisonPct): array
{
    $s['dlq'] = [];
    $s['alerts_fired'] = 0;
    $consumed = $succeeded = $dead = 0;
    $t0 = nowMs();

    for ($i = 0; $i < $messages; $i++) {
        $consumed++;
        try {
            procesar($i, $transientPct, $poisonPct, 0);
            $succeeded++;
        } catch (Throwable $e) {
            // El bug entero. `catch (Throwable)` no mira QUE error es, no
            // reintenta, y no guarda por que fallo. Y en PHP `Throwable` incluye
            // a `Error`, asi que se traga los bugs del propio consumidor junto
            // con los datos malos: un TypeError termina en la DLQ como si fuera
            // un mensaje corrupto.
            $s['dlq'][] = ['id' => "msg-$i", 'error_class' => 'unclassified', 'attempts' => 1,
                           'first_seen_ms' => nowMs(), 'sample' => null];
            $dead++;
        }
    }

    return ['consumed' => $consumed, 'succeeded' => $succeeded, 'retried' => 0,
            'dead_lettered' => $dead, 'alerts_fired' => 0, 'sampled' => 0,
            'wall_ms' => round(nowMs() - $t0, 2)];
}

// ---------------------------------------------------------------------------
// Variante observada: clasificar, reintentar, medir, alertar
// ---------------------------------------------------------------------------

function consumeObserved(array &$s, int $messages, int $transientPct, int $poisonPct,
                         int $maxRetries, int $alertThreshold, int $sampleSize): array
{
    $s['dlq'] = [];
    $s['alerts_fired'] = 0;
    $consumed = $succeeded = $retried = $dead = $sampled = 0;
    $t0 = nowMs();

    for ($i = 0; $i < $messages; $i++) {
        $consumed++;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                procesar($i, $transientPct, $poisonPct, $attempt);
                $succeeded++;
                break;
            } catch (ErrorTransitorio $e) {
                // Transitorio: el proximo intento tiene otra suerte. Mandarlo a
                // la DLQ seria tirar trabajo que se podia salvar.
                $retried++;
                if ($attempt === $maxRetries) {
                    $s['dlq'][] = ['id' => "msg-$i", 'error_class' => 'transient_exhausted',
                                   'attempts' => $attempt + 1, 'first_seen_ms' => nowMs(), 'sample' => null];
                    $dead++;
                }
                continue;
            } catch (ErrorVenenoso $e) {
                // Venenoso: reintentarlo es quemar CPU. Va a la DLQ ya mismo,
                // con su clase y —para los primeros— una muestra del payload.
                $muestra = null;
                if ($sampled < $sampleSize) {
                    $muestra = ['idx' => $i, 'payload' => "{\"id\": $i, \"campo\": \"...\"}"];
                    $sampled++;
                }
                $s['dlq'][] = ['id' => "msg-$i", 'error_class' => $e->clase,
                               'attempts' => $attempt + 1, 'first_seen_ms' => nowMs(), 'sample' => $muestra];
                $dead++;
                break;
            }
            // No hay `catch (Throwable)`: un error que no supimos clasificar NO
            // va a la DLQ, sube. Lo que PHP no da es exhaustividad: una clase
            // nueva no rompe nada, simplemente deja de manejarse.
        }
    }

    $alerts = 0;
    if (count($s['dlq']) > $alertThreshold) {
        $s['alerts_fired']++;
        $alerts = 1;
    }

    return ['consumed' => $consumed, 'succeeded' => $succeeded, 'retried' => $retried,
            'dead_lettered' => $dead, 'alerts_fired' => $alerts, 'sampled' => $sampled,
            'wall_ms' => round(nowMs() - $t0, 2)];
}

// ---------------------------------------------------------------------------
// La DLQ como cola observable, no como agujero
// ---------------------------------------------------------------------------

function dlqStats(array $s, int $alertThreshold): array
{
    $porClase = [];
    foreach ($s['dlq'] as $m) {
        $porClase[$m['error_class']] = ($porClase[$m['error_class']] ?? 0) + 1;
    }
    ksort($porClase);

    $now = nowMs();
    $oldest = 0.0;
    foreach ($s['dlq'] as $m) {
        $oldest = max($oldest, $now - $m['first_seen_ms']);
    }

    $muestras = [];
    foreach ($s['dlq'] as $m) {
        if ($m['sample'] !== null && count($muestras) < 5) {
            $muestras[] = $m['sample'];
        }
    }

    return [
        'dlq_depth' => count($s['dlq']),
        'dlq_oldest_msg_age_ms' => round($oldest, 2),
        'by_error_class' => $porClase === [] ? new stdClass() : $porClase,
        'alert_threshold' => $alertThreshold,
        'over_threshold' => count($s['dlq']) > $alertThreshold,
        'alerts_fired' => $s['alerts_fired'],
        'samples' => $muestras,
        'note' => 'Una DLQ sin profundidad publicada, sin antigüedad del mensaje más viejo y sin desglose por '
            . 'clase de error no es una cola: es un agujero. `by_error_class` convierte «hay 4.000 mensajes» en '
            . '«hay un bug de schema y tres timeouts».',
    ];
}

/**
 * Replay desde la DLQ. Lo que se recupera vuelve; lo venenoso sigue ahi.
 *
 * En PHP esto es un comando de cron —`bin/dlq:drain`— y eso es una ventaja
 * operativa real: se ejecuta a mano en un incidente sin redesplegar nada.
 */
function dlqDrain(array &$s, int $limit, int $transientPct, int $poisonPct, int $maxRetries): array
{
    $t0 = nowMs();
    $lote = array_slice($s['dlq'], 0, $limit);
    $resto = array_slice($s['dlq'], $limit);
    $ok = $fallo = 0;
    $quedan = [];

    foreach ($lote as $m) {
        $idx = (int) substr($m['id'], 4);
        $recuperado = false;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                procesar($idx, $transientPct, $poisonPct, $attempt);
                $recuperado = true;
                break;
            } catch (ErrorTransitorio $e) {
                continue;
            } catch (ErrorVenenoso $e) {
                break;
            }
        }
        if ($recuperado) {
            $ok++;
        } else {
            $fallo++;
            $m['attempts'] += $maxRetries;
            $quedan[] = $m;
        }
    }

    $s['dlq'] = array_merge($quedan, $resto);

    return [
        'drain_limit' => $limit,
        'drained_ok' => $ok,
        'drain_failed' => $fallo,
        'recovered_pct' => round($ok * 100 / max(1, $ok + $fallo), 2),
        'drain_duration_ms' => round(nowMs() - $t0, 2),
        'dlq_depth_after' => count($s['dlq']),
        'note' => 'Lo que se recupera en el replay es exactamente lo que nunca debería haber estado acá: errores '
            . 'transitorios que un reintento habría resuelto. Lo que sigue fallando es veneno de verdad, y '
            . 'necesita un cambio de código o de datos — no otro reintento.',
    ];
}

function runScenario(string $variant, int $messages, int $transientPct, int $poisonPct,
                     int $maxRetries, int $alertThreshold, int $sampleSize): array
{
    $s = loadState();
    $r = $variant === 'silent'
        ? consumeSilent($s, $messages, $transientPct, $poisonPct)
        : consumeObserved($s, $messages, $transientPct, $poisonPct, $maxRetries, $alertThreshold, $sampleSize);

    $stats = dlqStats($s, $alertThreshold);

    $s['metrics'][$variant]['runs']++;
    foreach (['consumed', 'succeeded', 'retried', 'dead_lettered', 'alerts_fired'] as $k) {
        $s['metrics'][$variant][$k] += $r[$k];
    }
    saveState($s);

    $payload = array_merge([
        'variant' => $variant,
        'messages' => $messages,
        'transient_pct' => $transientPct,
        'poison_pct' => $poisonPct,
        'max_retries' => $variant === 'observed' ? $maxRetries : 0,
    ], $r);
    foreach (['dlq_depth', 'dlq_oldest_msg_age_ms', 'by_error_class', 'alert_threshold', 'over_threshold'] as $k) {
        $payload[$k] = $stats[$k];
    }
    $payload['dead_letter_rate_pct'] = round($r['dead_lettered'] * 100 / max(1, $r['consumed']), 2);
    $payload['note'] = $variant === 'silent'
        ? 'El consumidor no clasificó nada: transitorio y venenoso fueron al mismo lugar, sin reintentar y sin '
          . 'registrar por qué. El pipeline se ve sano —throughput normal, cero errores— porque los errores se '
          . 'fueron a otro lado. Y nadie va a volver.'
        : 'Lo transitorio se reintentó y casi todo se recuperó; solo el veneno llegó a la DLQ, con su clase de '
          . 'error y una muestra del payload. La profundidad está publicada y el umbral disparó alerta.';
    $payload['php_note'] = 'Los tipos union en `catch` (PHP 8) dicen «estos dos se tratan igual» sin duplicar el '
        . 'bloque. Y `Throwable` como raíz común de `Exception` y `Error` hace explícito que capturar todo incluye '
        . 'capturar los bugs propios. Lo que PHP no da es exhaustividad: una clase de error nueva no rompe nada — '
        . 'simplemente deja de manejarse.';
    return $payload;
}

function diagnostics(string $stack, int $alertThreshold): array
{
    $s = loadState();
    return [
        'stack' => $stack,
        'case' => CASE_NAME,
        'variants' => $s['metrics'],
        'dlq' => dlqStats($s, $alertThreshold),
        'arco_con_el_caso_15' => 'En el caso 15 la DLQ NACE: es la política de rechazo que salva al productor de '
            . 'bloquearse cuando la cola se llena. Acá se ve qué pasa cuando nadie vuelve a mirarla. Los dos casos '
            . 'son el mismo mecanismo en dos momentos distintos.',
        'fidelity' => [
            'real' => 'La clasificación de errores, el reintento con presupuesto acotado, el desglose por clase, '
                . 'el muestreo de payloads y el replay desde la DLQ son código de verdad.',
            'modelado' => 'La DLQ vive en un archivo JSON bajo flock, no en SQS ni RabbitMQ. La clase de error de '
                . 'cada mensaje es determinista para que el escenario sea reproducible.',
            'honesto' => 'Lo que define el caso no es el broker: es que un mensaje que falla tiene que ir a algún '
                . 'lado, y que ese lado necesita profundidad, antigüedad, clasificación y una salida.',
        ],
        'interpretation' => [
            'silent' => 'dead_letter_rate_pct alto, by_error_class con una sola entrada («unclassified») y '
                . 'alerts_fired en cero. El pipeline se ve sano.',
            'observed' => 'dead_letter_rate_pct bajo —solo el veneno—, by_error_class desglosado y la alerta '
                . 'disparada. Lo transitorio se recuperó sin llegar a la DLQ.',
            'php_note' => 'El drenaje como comando de cron es una ventaja operativa real: se ejecuta a mano en un '
                . 'incidente sin redesplegar nada.',
        ],
    ];
}

// ---------------------------------------------------------------------------
// Enrutado
// ---------------------------------------------------------------------------

$stack = envOr('APP_STACK', 'PHP 8.3');
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$q = $_GET;

$messages = clampInt(queryInt($q, 'messages', 3000), 10, 200000);
$transientPct = clampInt(queryInt($q, 'transient_pct', 12), 0, 100);
$poisonPct = clampInt(queryInt($q, 'poison_pct', 4), 0, 100);
$maxRetries = clampInt(queryInt($q, 'max_retries', 3), 0, 20);
$alertThreshold = clampInt(queryInt($q, 'alert_threshold', 50), 0, 100000);
$sampleSize = clampInt(queryInt($q, 'sample_size', 20), 0, 1000);
$limit = clampInt(queryInt($q, 'limit', 500), 1, 200000);

if ($uri === '/' || $uri === '/index' || $uri === '/index.php') {
    require __DIR__ . '/ui.php';
    exit;
}

switch ($uri) {
    case '/health':
        $payload = ['status' => 'ok', 'stack' => $stack, 'case' => CASE_NAME];
        break;
    case '/consume-silent':
        $payload = runScenario('silent', $messages, $transientPct, $poisonPct, $maxRetries,
                               $alertThreshold, $sampleSize);
        break;
    case '/consume-observed':
        $payload = runScenario('observed', $messages, $transientPct, $poisonPct, $maxRetries,
                               $alertThreshold, $sampleSize);
        break;
    case '/dlq/stats':
        $payload = dlqStats(loadState(), $alertThreshold);
        break;
    case '/dlq/drain':
        $s = loadState();
        $payload = dlqDrain($s, $limit, $transientPct, $poisonPct, $maxRetries);
        saveState($s);
        break;
    case '/diagnostics/summary':
        $payload = diagnostics($stack, $alertThreshold);
        break;
    case '/reset-lab':
        saveState(emptyState());
        $payload = ['status' => 'reset', 'message' => 'DLQ y métricas reiniciadas.'];
        break;
    default:
        $status = 404;
        $payload = ['error' => 'Ruta no encontrada', 'path' => $uri];
}

$payload['timestamp_utc'] = gmdate('Y-m-d\TH:i:s\Z');
$payload['pid'] = getmypid();

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
