<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const CASE_NAME = '14 - Agotamiento del pool de conexiones';

$started = microtime(true);
$status = 200;

/**
 * Variante leaky: sin `finally`, la conexion se pierde en el camino de excepcion.
 *
 * El bug cabe en lo que NO esta: no hay bloque `finally`, asi que cuando
 * runQuery lanza, la linea de release nunca se ejecuta. Nada en los logs dice
 * "se fugo una conexion" — el pool simplemente se achica.
 */
function loadLeaky(Pool $pool, int $requests, int $queryMs, int $failRate): array
{
    $counts = ['completed' => 0, 'failed_query' => 0, 'failed_timeout' => 0, 'hung' => 0];
    $waits = [];

    for ($i = 0; $i < $requests; $i++) {
        $t0 = microtime(true);
        $conn = $pool->acquire();
        $waits[] = round((microtime(true) - $t0) * 1000, 2);

        if ($conn === null) {
            // Con el pool vacio y un solo proceso, esperar no puede ayudar:
            // nadie va a devolver nada. En PHP-FPM esta espera la haria otro
            // proceso y si tendria sentido.
            $counts['hung']++;
            continue;
        }

        try {
            runQuery($conn, $queryMs, fails($i, $failRate));
        } catch (RuntimeException) {
            $counts['failed_query']++;
            continue;   // ← la conexion se fue con la excepcion
        }
        $pool->release($conn);
        $counts['completed']++;
    }

    return ['counts' => $counts, 'waits' => $waits];
}

/**
 * Variante managed: `finally` garantiza la devolucion en todos los caminos.
 *
 * `finally` en PHP corre tambien cuando el bloque `try` hace `continue`,
 * `break` o `return` — no solo cuando lanza. Es la misma garantia que Java
 * obtiene con try-with-resources y .NET con `using`, escrita a mano.
 */
function loadManaged(Pool $pool, int $requests, int $queryMs, int $failRate): array
{
    $counts = ['completed' => 0, 'failed_query' => 0, 'failed_timeout' => 0, 'hung' => 0];
    $waits = [];

    for ($i = 0; $i < $requests; $i++) {
        $t0 = microtime(true);
        $conn = $pool->acquire();
        $waits[] = round((microtime(true) - $t0) * 1000, 2);

        if ($conn === null) {
            // Falla rapido y de forma contable, con un codigo que el llamador
            // puede interpretar (503 + Retry-After), en vez de colgarse.
            $counts['failed_timeout']++;
            continue;
        }

        try {
            runQuery($conn, $queryMs, fails($i, $failRate));
            $counts['completed']++;
        } catch (RuntimeException) {
            $counts['failed_query']++;
        } finally {
            $pool->release($conn);
        }
    }

    return ['counts' => $counts, 'waits' => $waits];
}

function runLoad(string $variant, int $requests, int $poolSize, int $queryMs, int $failRate): array
{
    $pool = new Pool($poolSize);
    $t0 = microtime(true);
    $result = $variant === 'leaky'
        ? loadLeaky($pool, $requests, $queryMs, $failRate)
        : loadManaged($pool, $requests, $queryMs, $failRate);
    $wallMs = round((microtime(true) - $t0) * 1000, 2);

    $counts = $result['counts'];
    $waits = $result['waits'];

    $metrics = readMetrics();
    $slot = &$metrics[$variant];
    $slot['runs']++;
    foreach ($counts as $key => $value) {
        $slot[$key] += $value;
    }
    $slot['max_leaked'] = max($slot['max_leaked'], $pool->leaked());
    $slot['wait_samples_ms'] = array_slice(array_merge($slot['wait_samples_ms'], $waits), -500);
    unset($slot);
    writeMetrics($metrics);

    return [
        'variant' => $variant,
        'requests' => $requests,
        'pool_size' => $poolSize,
        'query_ms' => $queryMs,
        'fail_rate_pct' => $failRate,
        'acquire_timeout_ms' => $variant === 'managed' ? ACQUIRE_TIMEOUT_MS : null,
        'completed' => $counts['completed'],
        'failed_query' => $counts['failed_query'],
        'failed_timeout' => $counts['failed_timeout'],
        'hung' => $counts['hung'],
        'acquired' => $pool->acquired,
        'released' => $pool->released,
        'leaked' => $pool->leaked(),
        'pool_available_after' => $pool->available(),
        'pool_waiting_peak' => $pool->waitingPeak,
        'pool_wait_ms_p99' => percentile($waits, 99),
        'pool_wait_ms_max' => count($waits) > 0 ? round((float) max($waits), 2) : 0.0,
        'wall_ms' => $wallMs,
        'littles_law' => littlesLaw($requests, $queryMs, $wallMs),
        'note' => $variant === 'leaky'
            ? 'Sin finally: cada excepcion se lleva una conexion y el pool se achica hasta que no queda ninguna.'
            : 'finally garantiza la devolucion en todos los caminos de salida, incluido el continue del catch.',
    ];
}

function poolStateSummary(): array
{
    return [
        'initialized' => true,
        'model' => 'pool por request',
        'acquire_timeout_ms' => ACQUIRE_TIMEOUT_MS,
        'note' => 'PHP no comparte heap entre requests: el pool se construye y se destruye en cada llamada. El estado persistente que si existe en produccion son las conexiones PDO::ATTR_PERSISTENT pegadas al worker de FPM.',
    ];
}

function diagnosticsSummary(): array
{
    $metrics = readMetrics();
    $variants = [];
    foreach (['leaky', 'managed'] as $name) {
        $slot = $metrics[$name];
        $samples = $slot['wait_samples_ms'];
        $variants[$name] = [
            'runs' => (int) $slot['runs'],
            'completed' => (int) $slot['completed'],
            'failed_query' => (int) $slot['failed_query'],
            'failed_timeout' => (int) $slot['failed_timeout'],
            'hung' => (int) $slot['hung'],
            'max_leaked' => (int) $slot['max_leaked'],
            'avg_wait_ms' => count($samples) > 0 ? round(array_sum($samples) / count($samples), 2) : 0.0,
            'p99_wait_ms' => percentile($samples, 99),
        ];
    }

    return [
        'stack' => envOr('APP_STACK', 'PHP 8.3'),
        'case' => CASE_NAME,
        'variants' => $variants,
        'pool' => poolStateSummary(),
        'interpretation' => [
            'leaky' => 'leaked > 0 y hung > 0: las conexiones perdidas en el camino de excepcion no vuelven, y lo que llega despues no encuentra ninguna.',
            'managed' => 'leaked = 0 siempre. Los fallos de query se siguen contando, pero la conexion vuelve al pool y el que no alcanza recibe un fallo rapido.',
            'php_note' => 'El proceso por request tapa este bug: la conexion fugada se recupera sola al morir el proceso. La version PHP real del agotamiento es max_children de FPM por conexiones persistentes contra max_connections del motor.',
        ],
        'fidelity_note' => 'El servidor embebido de PHP es de un solo proceso, asi que las N requests se recorren en secuencia y el pool vive dentro de una sola llamada HTTP. La primitiva que se demuestra — finally garantizado — es la misma que hace falta bajo PHP-FPM.',
    ];
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '', $query);

$requests = clampInt((int) ($query['requests'] ?? 24), 1, 200);
$poolSize = clampInt((int) ($query['pool'] ?? 4), 1, 64);
$queryMs = clampInt((int) ($query['query_ms'] ?? 25), 1, 500);
$failRate = clampInt((int) ($query['fail_rate'] ?? 25), 0, 100);

try {
    if (($uri === '/' || $uri === '') && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html')) {
        require __DIR__ . '/ui.php';
        exit;
    }

    if ($uri === '/' || $uri === '') {
        $payload = [
            'lab' => 'Problem-Driven Systems Lab',
            'case' => CASE_NAME,
            'stack' => envOr('APP_STACK', 'PHP 8.3'),
            'goal' => 'Mostrar como un pool sin devolucion garantizada se achica hasta quedarse sin conexiones.',
            'php_specific' => 'finally garantiza la devolucion en todos los caminos. El proceso por request tapa el bug; las conexiones persistentes de FPM lo destapan.',
            'routes' => [
                '/health' => 'Estado basico del servicio.',
                '/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25' => 'Sin finally: fuga en el camino de excepcion.',
                '/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25' => 'Con finally y fallo rapido cuando no hay conexion.',
                '/pool/state' => 'Modelo del pool y nota sobre conexiones persistentes.',
                '/diagnostics/summary' => 'Comparativa entre variantes + ley de Little.',
                '/reset-lab' => 'Limpia contadores.',
            ],
        ];
    } elseif ($uri === '/health') {
        $payload = ['status' => 'ok', 'stack' => envOr('APP_STACK', 'PHP 8.3'), 'case' => CASE_NAME];
    } elseif ($uri === '/pool-leaky') {
        $payload = runLoad('leaky', $requests, $poolSize, $queryMs, $failRate);
    } elseif ($uri === '/pool-managed') {
        $payload = runLoad('managed', $requests, $poolSize, $queryMs, $failRate);
    } elseif ($uri === '/pool/state') {
        $payload = poolStateSummary();
    } elseif ($uri === '/diagnostics/summary') {
        $payload = diagnosticsSummary();
    } elseif ($uri === '/reset-lab') {
        resetLabState();
        $payload = ['status' => 'reset', 'message' => 'Metricas reiniciadas.'];
    } else {
        $status = 404;
        $payload = ['error' => 'Ruta no encontrada', 'path' => $uri];
    }
} catch (Throwable $e) {
    $status = 500;
    $payload = ['error' => 'Fallo al procesar la solicitud', 'message' => $e->getMessage(), 'path' => $uri];
}

$payload['elapsed_ms'] = round((microtime(true) - $started) * 1000, 2);
$payload['timestamp_utc'] = gmdate('c');
$payload['pid'] = getmypid();
jsonResponse($status, $payload);
