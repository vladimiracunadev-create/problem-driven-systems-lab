<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const CASE_NAME = '15 - Backpressure en colas de mensajes';

$started = microtime(true);
$status = 200;

/**
 * El consumidor: drena `1` mensaje cada `consumeMs`, intercalado con la
 * produccion. En los otros seis stacks esto es un hilo aparte; aca es un paso
 * del mismo bucle, porque PHP no tiene concurrencia dentro del proceso.
 */
function consumeOne(array &$queue, int $consumeMs, float &$maxOldestMs, int &$consumed): void
{
    if ($queue === []) {
        return;
    }
    $msg = array_shift($queue);
    // Se mide ANTES de procesar: es la latencia real del consumidor final, y en
    // una cola sin limite crece sin techo.
    $age = (microtime(true) * 1000) - $msg['enqueued_at_ms'];
    $maxOldestMs = max($maxOldestMs, $age);
    if ($consumeMs > 0) {
        usleep($consumeMs * 1000);
    }
    $consumed++;
}

/**
 * Variante unbounded: el array crece sin techo.
 *
 * PHP no tiene una cola con capacidad, asi que "sin limite" es el estado por
 * defecto de cualquier array. No hay que pedirlo: viene puesto.
 */
function runUnbounded(int $messages, int $consumeMs): array
{
    $queue = [];
    $consumed = 0;
    $maxOldestMs = 0.0;
    $peak = 0;

    $t0 = microtime(true);
    for ($seq = 0; $seq < $messages; $seq++) {
        $queue[] = ['seq' => $seq, 'enqueued_at_ms' => microtime(true) * 1000];
        $peak = max($peak, count($queue));
        // El productor va 3x mas rapido que el consumidor: por cada 3 mensajes
        // producidos se drena 1. La cola crece de forma monotonica.
        if ($seq % 3 === 2) {
            consumeOne($queue, $consumeMs, $maxOldestMs, $consumed);
        }
    }
    $depthAtEnd = count($queue);
    while ($queue !== []) {
        consumeOne($queue, $consumeMs, $maxOldestMs, $consumed);
    }
    $wallMs = (microtime(true) - $t0) * 1000;

    return [
        'variant' => 'unbounded',
        'policy' => null,
        'capacity' => null,
        'produced' => $messages,
        'consumed' => $consumed,
        'dropped' => 0,
        'dead_lettered' => 0,
        'queue_depth_peak' => $peak,
        'queue_depth_at_end_of_production' => $depthAtEnd,
        'queue_bytes_peak' => $peak * MSG_BYTES,
        'oldest_msg_age_ms_peak' => round($maxOldestMs, 2),
        'producer_blocked_ms' => 0.0,
        'backpressure_signals' => 0,
        'wall_ms' => round($wallMs, 2),
        'throughput_msg_s' => $wallMs > 0 ? round($messages / ($wallMs / 1000), 2) : 0.0,
        'note' => 'Un array de PHP no tiene capacidad: "sin limite" es el estado por defecto, no algo que haya que '
            . 'pedir. La cola crece hasta donde de memory_limit y ahi el proceso muere.',
    ];
}

/** Variante bounded: capacidad fija y politica explicita. */
function runBounded(int $messages, int $capacity, string $policy, int $consumeMs, array &$dlq): array
{
    $queue = [];
    $consumed = 0;
    $maxOldestMs = 0.0;
    $peak = 0;
    $produced = $dropped = $dead = $signals = 0;
    $blockedMs = 0.0;

    $t0 = microtime(true);
    for ($seq = 0; $seq < $messages; $seq++) {
        $msg = ['seq' => $seq, 'enqueued_at_ms' => microtime(true) * 1000];

        if (count($queue) >= $capacity) {
            $signals++;
            if ($policy === 'block') {
                // El freno: el productor no puede seguir hasta que el consumidor
                // haga lugar. En PHP-FPM esto es literalmente el kernel dejando
                // de aceptar conexiones cuando se llena el accept queue.
                $b0 = microtime(true);
                consumeOne($queue, $consumeMs, $maxOldestMs, $consumed);
                $blockedMs += (microtime(true) - $b0) * 1000;
            } elseif ($policy === 'drop_oldest') {
                array_shift($queue);
                $dropped++;
            } else {
                $dlq[] = ['seq' => $seq, 'reason' => 'queue_full', 'at' => gmdate('c')];
                $dlq = array_slice($dlq, -200);
                $dead++;
                $peak = max($peak, count($queue));
                continue;
            }
        }

        $queue[] = $msg;
        $produced++;
        $peak = max($peak, count($queue));
        if ($seq % 3 === 2) {
            consumeOne($queue, $consumeMs, $maxOldestMs, $consumed);
        }
    }
    $depthAtEnd = count($queue);
    while ($queue !== []) {
        consumeOne($queue, $consumeMs, $maxOldestMs, $consumed);
    }
    $wallMs = (microtime(true) - $t0) * 1000;

    $notes = [
        'block' => 'El productor no puede seguir hasta que el consumidor haga lugar. En PHP-FPM esto es el kernel '
            . 'dejando de aceptar conexiones cuando se llena el accept queue de listen.backlog.',
        'drop_oldest' => 'Se descarta el mas viejo: el productor nunca se frena, pero se pierden datos en silencio. '
            . 'Aceptable para telemetria, inaceptable para pagos.',
        'dead_letter' => 'Lo que no entra va a la DLQ: no se frena ni se pierde, pero el problema se muda a otra cola '
            . 'que alguien tiene que mirar. Si nadie la mira, es el caso 20.',
    ];

    return [
        'variant' => 'bounded',
        'policy' => $policy,
        'capacity' => $capacity,
        'produced' => $produced,
        'consumed' => $consumed,
        'dropped' => $dropped,
        'dead_lettered' => $dead,
        'queue_depth_peak' => $peak,
        'queue_depth_at_end_of_production' => $depthAtEnd,
        'queue_bytes_peak' => $peak * MSG_BYTES,
        'oldest_msg_age_ms_peak' => round($maxOldestMs, 2),
        'producer_blocked_ms' => round($blockedMs, 2),
        'backpressure_signals' => $signals,
        'wall_ms' => round($wallMs, 2),
        'throughput_msg_s' => $wallMs > 0 ? round($produced / ($wallMs / 1000), 2) : 0.0,
        'note' => $notes[$policy],
    ];
}

function record(string $variant, array $r, array $dlq): void
{
    $state = readState();
    $slot = &$state['metrics'][$variant];
    $slot['runs']++;
    $slot['produced'] += $r['produced'];
    $slot['consumed'] += $r['consumed'];
    $slot['dropped'] += $r['dropped'];
    $slot['dead_lettered'] += $r['dead_lettered'];
    $slot['max_queue_depth'] = max($slot['max_queue_depth'], $r['queue_depth_peak']);
    $slot['max_oldest_age_ms'] = max($slot['max_oldest_age_ms'], $r['oldest_msg_age_ms_peak']);
    $slot['producer_blocked_ms'] += $r['producer_blocked_ms'];
    unset($slot);
    $state['dlq'] = $dlq;
    $state['last_state'] = [
        'last_variant' => $variant,
        'last_policy' => $r['policy'],
        'capacity' => $r['capacity'],
        'queue_depth_peak' => $r['queue_depth_peak'],
        'queue_bytes_peak' => $r['queue_bytes_peak'],
        'oldest_msg_age_ms_peak' => $r['oldest_msg_age_ms_peak'],
    ];
    writeState($state);
}

function queueStateSummary(): array
{
    $state = readState();

    return array_merge($state['last_state'], [
        'dlq_depth' => count($state['dlq']),
        'msg_bytes' => MSG_BYTES,
        'policies' => POLICIES,
        'note' => 'queue_depth_peak x msg_bytes es lo que la cola llego a ocupar. Un array de PHP no tiene techo '
            . 'propio: el techo es memory_limit.',
    ]);
}

function dlqView(int $limit): array
{
    $state = readState();

    return [
        'dlq_depth' => count($state['dlq']),
        'limit' => $limit,
        'messages' => array_slice(array_reverse($state['dlq']), 0, $limit),
        'note' => 'La DLQ no resuelve el backpressure: lo muda. El caso 20 trata que pasa cuando nadie la mira.',
    ];
}

function diagnosticsSummary(): array
{
    $state = readState();
    $variants = [];
    foreach (['unbounded', 'bounded'] as $name) {
        $s = $state['metrics'][$name];
        $variants[$name] = [
            'runs' => (int) $s['runs'],
            'produced' => (int) $s['produced'],
            'consumed' => (int) $s['consumed'],
            'dropped' => (int) $s['dropped'],
            'dead_lettered' => (int) $s['dead_lettered'],
            'max_queue_depth' => (int) $s['max_queue_depth'],
            'max_oldest_age_ms' => round((float) $s['max_oldest_age_ms'], 2),
            'producer_blocked_ms' => round((float) $s['producer_blocked_ms'], 2),
        ];
    }

    return [
        'stack' => envOr('APP_STACK', 'PHP 8.3'),
        'case' => CASE_NAME,
        'variants' => $variants,
        'dlq_depth' => count($state['dlq']),
        'interpretation' => [
            'unbounded' => 'producer_blocked_ms = 0 y dropped = 0 se ven bien hasta que se mira queue_depth_peak y '
                . 'oldest_msg_age_ms_peak.',
            'bounded' => 'Las tres politicas pagan algo distinto: block paga latencia del productor, drop_oldest paga '
                . 'datos, dead_letter paga deuda operativa. No hay una cuarta opcion gratis.',
            'php_note' => 'PHP no tiene cola en proceso, asi que el backpressure vive en el transporte: listen.backlog '
                . 'de FPM para bloquear, pm.max_children agotado para descartar (502), y la DLQ del broker real. Es el '
                . 'stack que mejor enseña que el backpressure es una propiedad del sistema, no de la cola.',
        ],
        'fidelity_note' => 'El productor y el consumidor son pasos del mismo bucle porque PHP no tiene concurrencia '
            . 'dentro del proceso. Las metricas de profundidad, edad y perdida son las mismas; lo que no es comparable '
            . 'con los otros stacks es producer_blocked_ms, que aca mide el drenaje intercalado.',
    ];
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '', $query);

$messages = clampInt((int) ($query['messages'] ?? 120), 1, 2000);
$capacity = clampInt((int) ($query['capacity'] ?? 32), 1, 1000);
$consumeMs = clampInt((int) ($query['consume_ms'] ?? 2), 0, 100);
$limit = clampInt((int) ($query['limit'] ?? 20), 1, 200);
$policy = (string) ($query['policy'] ?? 'block');
if (!in_array($policy, POLICIES, true)) {
    $policy = 'block';
}

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
            'goal' => 'Mostrar que una cola sin limite no es la opcion sin costo: es la opcion con el freno roto.',
            'php_specific' => 'PHP no tiene cola en proceso: el backpressure vive en el transporte (listen.backlog de '
                . 'FPM, pm.max_children, la DLQ del broker).',
            'routes' => [
                '/health' => 'Estado basico del servicio.',
                '/produce-unbounded?messages=120&consume_ms=2' => 'Array sin capacidad.',
                '/produce-bounded?messages=120&capacity=32&policy=block&consume_ms=2' => 'Capacidad fija, el productor se frena.',
                '/produce-bounded?messages=120&capacity=32&policy=drop_oldest' => 'Se descarta el mas viejo.',
                '/produce-bounded?messages=120&capacity=32&policy=dead_letter' => 'Lo que no entra va a la DLQ.',
                '/queue/state' => 'Profundidad pico, bytes y edad del mensaje mas viejo.',
                '/dlq?limit=20' => 'Contenido de la dead letter queue.',
                '/diagnostics/summary' => 'Comparativa entre variantes y politicas.',
                '/reset-lab' => 'Limpia DLQ y contadores.',
            ],
            'allowed_policies' => POLICIES,
        ];
    } elseif ($uri === '/health') {
        $payload = ['status' => 'ok', 'stack' => envOr('APP_STACK', 'PHP 8.3'), 'case' => CASE_NAME];
    } elseif ($uri === '/produce-unbounded') {
        $payload = runUnbounded($messages, $consumeMs);
        record('unbounded', $payload, readState()['dlq']);
    } elseif ($uri === '/produce-bounded') {
        $dlq = readState()['dlq'];
        $payload = runBounded($messages, $capacity, $policy, $consumeMs, $dlq);
        record('bounded', $payload, $dlq);
    } elseif ($uri === '/queue/state') {
        $payload = queueStateSummary();
    } elseif ($uri === '/dlq') {
        $payload = dlqView($limit);
    } elseif ($uri === '/diagnostics/summary') {
        $payload = diagnosticsSummary();
    } elseif ($uri === '/reset-lab') {
        resetLabState();
        $payload = ['status' => 'reset', 'message' => 'DLQ y metricas reiniciadas.'];
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
