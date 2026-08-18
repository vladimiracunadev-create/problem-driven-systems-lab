<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const CASE_NAME = '16 - Idempotencia y efectos duplicados';

$started = microtime(true);
$status = 200;

/**
 * `INSERT ... ON CONFLICT DO NOTHING RETURNING id`, modelado con flock().
 *
 * Devuelve [$leader, $response]. Si `$leader` es true, esta request reservo la
 * clave y le toca hacer el trabajo. Si es false, la clave ya estaba y esto es
 * un reintento: se devuelve la respuesta guardada, tal cual.
 *
 * El lock es lo que hace la operacion atomica ENTRE PROCESOS — que es lo que
 * PHP necesita y los otros seis stacks resuelven dentro del suyo.
 */
function reserveKey(string $key, callable $work): array
{
    $lock = fopen(lockPath(), 'c');
    if ($lock === false) {
        throw new RuntimeException('No se pudo abrir el lock de idempotencia.');
    }
    flock($lock, LOCK_EX);
    try {
        $state = readState();

        if (isset($state['idempotency'][$key])) {
            $entry = $state['idempotency'][$key];
            if (nowMs() - (float) $entry['stored_at_ms'] > DEDUPE_WINDOW_MS) {
                // Fuera de la ventana: la clave caduco y esto es una operacion nueva.
                unset($state['idempotency'][$key]);
            } else {
                return [false, $entry['response'], $state];
            }
        }

        // Reserva + trabajo + respuesta, todo bajo el mismo lock. Es la
        // transaccion: si el proceso muere aca, no queda ni la clave ni el cargo.
        $result = $work($state);
        $state = $result['state'];
        $state['idempotency'][$key] = [
            'response' => $result['response'],
            'stored_at_ms' => nowMs(),
        ];
        writeState($state);

        return [true, $result['response'], $state];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function applyCharge(array $state, string $account, int $amount): array
{
    $state['ledger'][$account] = (int) ($state['ledger'][$account] ?? 0) + $amount;

    return $state;
}

/** El efecto DIRECTO, fuera de la transaccion del cargo. */
function emitDirect(array $state, string $key, int $amount): array
{
    $state['delivered'][] = [
        'key' => $key, 'kind' => 'payment_receipt_email', 'amount_cents' => $amount,
        'at' => gmdate('c'), 'status' => 'delivered', 'via' => 'direct',
    ];
    $state['delivered'] = array_slice($state['delivered'], -MAX_ROWS);

    return $state;
}

/** Escribe el efecto en el outbox, junto al cargo. No lo entrega. */
function enqueueOutbox(array $state, string $key, int $amount): array
{
    $state['outbox'][] = [
        'key' => $key, 'kind' => 'payment_receipt_email', 'amount_cents' => $amount,
        'at' => gmdate('c'), 'status' => 'pending', 'via' => 'outbox',
    ];
    $state['outbox'] = array_slice($state['outbox'], -MAX_ROWS);

    return $state;
}

/** El worker que mueve el outbox al destino real. Idempotente por diseño. */
function drainOutbox(): int
{
    $state = readState();
    $moved = 0;
    foreach ($state['outbox'] as $i => $row) {
        if (($row['status'] ?? '') === 'pending') {
            $state['outbox'][$i]['status'] = 'delivered';
            $state['delivered'][] = $state['outbox'][$i];
            $moved++;
        }
    }
    $state['delivered'] = array_slice($state['delivered'], -MAX_ROWS);
    writeState($state);

    return $moved;
}

function runAttempts(string $variant, string $key, string $account, int $amount, int $attempts): array
{
    $t0 = microtime(true);
    $applied = $hits = 0;
    $lookups = [];

    for ($i = 0; $i < $attempts; $i++) {
        if ($variant === 'unsafe') {
            // Sin clave: cada intento cobra y publica su propio efecto.
            $state = readState();
            $state = applyCharge($state, $account, $amount);
            $state = emitDirect($state, $key, $amount);
            writeState($state);
            $applied++;
            continue;
        }

        $l0 = microtime(true);
        [$leader] = reserveKey($key, function (array $state) use ($account, $amount, $key) {
            // El cargo y el efecto pendiente se escriben JUNTOS, bajo el lock.
            $state = applyCharge($state, $account, $amount);
            $state = enqueueOutbox($state, $key, $amount);

            return [
                'state' => $state,
                'response' => [
                    'status' => 'charged',
                    'key' => $key,
                    'account' => $account,
                    'amount_cents' => $amount,
                    'balance_cents' => (int) $state['ledger'][$account],
                ],
            ];
        });
        $lookups[] = (microtime(true) - $l0) * 1000;
        if ($leader) {
            $applied++;
        } else {
            $hits++;
        }
    }

    $deliveredNow = $variant === 'idempotent' ? drainOutbox() : 0;

    $state = readState();
    $balance = (int) ($state['ledger'][$account] ?? 0);
    $pending = count(array_filter($state['outbox'], static fn ($r) => ($r['status'] ?? '') === 'pending'));
    $deliveredTotal = count($state['delivered']);
    $overcharged = max(0, $applied - 1) * $amount;
    $effects = $variant === 'unsafe' ? $attempts : $deliveredNow;

    $slot = &$state['metrics'][$variant];
    $slot['runs']++;
    $slot['attempts'] += $attempts;
    $slot['charges_applied'] += $applied;
    $slot['duplicates_prevented'] += $hits;
    $slot['duplicates_applied'] += max(0, $applied - 1);
    $slot['idempotency_hits'] += $hits;
    $slot['side_effects_emitted'] += $effects;
    $slot['overcharged_cents'] += $overcharged;
    unset($slot);
    writeState($state);

    $wallMs = (microtime(true) - $t0) * 1000;

    return [
        'variant' => $variant,
        'key' => $key,
        'account' => $account,
        'attempts' => $attempts,
        'amount_cents' => $amount,
        'charges_applied' => $applied,
        'duplicates_prevented' => $hits,
        'duplicates_applied' => max(0, $applied - 1),
        'idempotency_hits' => $hits,
        'balance_cents' => $balance,
        'overcharged_cents' => $overcharged,
        'side_effects_emitted' => $effects,
        'side_effect_transport' => $variant === 'unsafe'
            ? 'directo, fuera de la transaccion'
            : 'outbox, en la misma escritura que el cargo',
        'outbox_pending' => $pending,
        'outbox_delivered' => $deliveredTotal,
        'lookup_overhead_ms' => count($lookups) > 0 ? round(array_sum($lookups) / count($lookups), 3) : 0.0,
        'dedupe_window_ms' => DEDUPE_WINDOW_MS,
        'wall_ms' => round($wallMs, 2),
        'note' => $variant === 'unsafe'
            ? 'Sin clave de idempotencia: cada reintento aplica su propio cargo y publica su propio efecto. El cliente reintento por un timeout, no porque quisiera pagar de nuevo.'
            : 'Reserva atomica en almacenamiento compartido (el equivalente de INSERT ... ON CONFLICT DO NOTHING) + outbox en la misma escritura que el cargo. Es la unica version de las siete que sigue siendo correcta con varias replicas.',
    ];
}

function idempotencyStateSummary(): array
{
    $state = readState();
    $keys = [];
    foreach ($state['idempotency'] as $k => $v) {
        $age = nowMs() - (float) $v['stored_at_ms'];
        $keys[$k] = [
            'age_ms' => round($age, 2),
            'expired' => $age > DEDUPE_WINDOW_MS,
            'has_response' => $v['response'] !== null,
        ];
    }

    return [
        'keys' => $keys,
        'key_count' => count($keys),
        'ledger_cents' => $state['ledger'],
        'dedupe_window_ms' => DEDUPE_WINDOW_MS,
        'note' => 'La tabla vive en almacenamiento compartido, no en el proceso. Es la unica forma de que la clave siga '
            . 'siendo unica con varias replicas — y necesita ventana y limpieza, o crece para siempre.',
    ];
}

function outboxView(int $limit): array
{
    $state = readState();
    $pending = count(array_filter($state['outbox'], static fn ($r) => ($r['status'] ?? '') === 'pending'));

    return [
        'outbox_pending' => $pending,
        'outbox_total' => count($state['outbox']),
        'delivered_total' => count($state['delivered']),
        'limit' => $limit,
        'outbox' => array_slice(array_reverse($state['outbox']), 0, $limit),
        'delivered' => array_slice(array_reverse($state['delivered']), 0, $limit),
        'note' => 'El outbox se escribe en la misma transaccion que el cargo. El worker que lo drena puede reintentar '
            . 'sin miedo: entregar dos veces el mismo row es visible y corregible, perder el efecto no.',
    ];
}

function diagnosticsSummary(): array
{
    $state = readState();
    $pending = count(array_filter($state['outbox'], static fn ($r) => ($r['status'] ?? '') === 'pending'));

    return [
        'stack' => envOr('APP_STACK', 'PHP 8.3'),
        'case' => CASE_NAME,
        'variants' => $state['metrics'],
        'outbox_pending' => $pending,
        'outbox_delivered' => count($state['delivered']),
        'interpretation' => [
            'unsafe' => 'charges_applied = attempts: cada reintento cobro de nuevo. overcharged_cents es plata real que '
                . 'el negocio tiene que devolver.',
            'idempotent' => 'charges_applied = 1 y duplicates_prevented = attempts - 1, sin importar cuantas veces '
                . 'reintente el cliente.',
            'php_note' => 'Sin heap compartido, la clave de idempotencia tiene que vivir en el almacenamiento y la '
                . 'atomicidad la aporta el motor (INSERT ... ON CONFLICT DO NOTHING, o flock aca). Es la unica de las '
                . 'siete versiones que sigue siendo correcta con veinte replicas: las otras seis resuelven la carrera '
                . 'dentro de su proceso, y con dos pods el mismo pago se cobra dos veces — una por pod.',
        ],
        'fidelity_note' => 'Los N reintentos se recorren en secuencia porque el servidor embebido de PHP es de un solo '
            . 'proceso. La primitiva que se demuestra — reserva atomica sobre almacenamiento compartido — es exactamente '
            . 'la que hace falta bajo PHP-FPM con N procesos reales, y la que los otros stacks necesitarian al escalar.',
    ];
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '', $query);

$key = substr((string) ($query['key'] ?? 'order-4711'), 0, 60) ?: 'order-4711';
$account = substr((string) ($query['account'] ?? 'acct-1'), 0, 40) ?: 'acct-1';
$attempts = clampInt((int) ($query['attempts'] ?? 5), 1, 64);
$amount = clampInt((int) ($query['amount'] ?? 2500), 1, 10000000);
$limit = clampInt((int) ($query['limit'] ?? 20), 1, 200);

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
            'goal' => 'Mostrar que un reintento por timeout se convierte en un segundo cobro salvo que el servidor sepa '
                . "distinguir 'es la primera vez que veo esto' de 'ya procese esto'.",
            'php_specific' => 'Sin heap compartido, la clave vive en el almacenamiento y la atomicidad la da el motor: '
                . 'INSERT ... ON CONFLICT DO NOTHING. Es la unica version que sobrevive a varias replicas.',
            'routes' => [
                '/health' => 'Estado basico del servicio.',
                '/charge-unsafe?key=order-4711&attempts=5&amount=2500' => 'N reintentos, N cargos.',
                '/charge-idempotent?key=order-4711&attempts=5&amount=2500' => 'N reintentos, un cargo y un efecto.',
                '/idempotency/state' => 'Claves guardadas, edad, ventana de dedupe y saldo por cuenta.',
                '/outbox?limit=20' => 'Efectos pendientes y entregados.',
                '/diagnostics/summary' => 'Comparativa entre variantes.',
                '/reset-lab' => 'Vacia ledger, claves y outbox.',
            ],
        ];
    } elseif ($uri === '/health') {
        $payload = ['status' => 'ok', 'stack' => envOr('APP_STACK', 'PHP 8.3'), 'case' => CASE_NAME];
    } elseif ($uri === '/charge-unsafe') {
        $payload = runAttempts('unsafe', $key, $account, $amount, $attempts);
    } elseif ($uri === '/charge-idempotent') {
        $payload = runAttempts('idempotent', $key, $account, $amount, $attempts);
    } elseif ($uri === '/idempotency/state') {
        $payload = idempotencyStateSummary();
    } elseif ($uri === '/outbox') {
        $payload = outboxView($limit);
    } elseif ($uri === '/diagnostics/summary') {
        $payload = diagnosticsSummary();
    } elseif ($uri === '/reset-lab') {
        resetLabState();
        $payload = ['status' => 'reset', 'message' => 'Ledger, claves de idempotencia y outbox reiniciados.'];
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
