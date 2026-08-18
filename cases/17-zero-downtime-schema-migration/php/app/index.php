<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const CASE_NAME = '17 - Migracion de esquema sin downtime';

$started = microtime(true);
$status = 200;

/**
 * Lector con deadline: `LOCK_SH | LOCK_NB` en un bucle acotado.
 *
 * `LOCK_SH` es el lock compartido — varios lectores a la vez, ninguno mientras
 * haya un `LOCK_EX` tomado. `LOCK_NB` lo vuelve no bloqueante, que es lo que
 * permite darle un plazo al lector en vez de dejarlo esperando para siempre.
 */
function readWithDeadline($fh, int $timeoutMs): bool
{
    $deadline = nowMs() + $timeoutMs;
    do {
        if (flock($fh, LOCK_SH | LOCK_NB)) {
            flock($fh, LOCK_UN);
            return true;
        }
        usleep(500);
    } while (nowMs() < $deadline);

    return false;
}

/** El escritor: `LOCK_EX` bloqueante. Nadie lee mientras esto esta tomado. */
function withWriteLock(callable $fn): float
{
    $fh = fopen(tableLockPath(), 'c');
    if ($fh === false) {
        throw new RuntimeException('No se pudo abrir el lock de la tabla.');
    }
    $t0 = nowMs();
    flock($fh, LOCK_EX);
    try {
        $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    return nowMs() - $t0;
}

/**
 * Trafico de lectura que se intercala con la migracion.
 *
 * El servidor embebido de PHP es de un solo proceso, asi que estos lectores se
 * recorren en secuencia y no en paralelo. Lo que si es real es el lock: bajo
 * PHP-FPM cada lector es otro proceso y `LOCK_SH` los coordina de verdad.
 */
function drainReaders($fh, int $count, array &$stats): void
{
    for ($i = 0; $i < $count; $i++) {
        $t0 = nowMs();
        $ok = readWithDeadline($fh, READ_TIMEOUT_MS);
        $stats['waits'][] = round(nowMs() - $t0, 2);
        if ($ok) {
            $stats['served']++;
        } else {
            $stats['failed']++;
        }
    }
}

/** Variante blocking: un solo LOCK_EX por toda la migracion. */
function migrateBlocking(int $rows, int $msPer1k, int $readers, array &$stats): array
{
    resetTable($rows);
    setPhase('expand');
    $durationMs = $rows / 1000 * $msPer1k;

    $probe = fopen(tableLockPath(), 'c');

    // El escritor toma LOCK_EX y lo mantiene. Los lectores que llegan mientras
    // tanto no pueden entrar: LOCK_SH es incompatible con LOCK_EX.
    $held = withWriteLock(function () use ($rows, $durationMs, $probe, $readers, &$stats) {
        // Los lectores golpean DURANTE el lock: todos fallan por deadline.
        drainReaders($probe, $readers, $stats);
        usleep((int) ($durationMs * 1000));
        $state = readState();
        $state['table']['has_new_column'] = true;
        $state['table']['backfilled'] = $rows;
        $state['table']['old_column_dropped'] = true;
        $state['read_from_new_column'] = true;
        writeState($state);
    });

    // Los que llegan despues ya no encuentran resistencia.
    drainReaders($probe, $readers, $stats);
    fclose($probe);
    setPhase('done');

    return ['held' => $held, 'batches' => 1];
}

/** Variante expand-contract: cuatro fases, LOCK_EX corto por lote. */
function migrateExpandContract(int $rows, int $msPer1k, int $batchSize, int $pauseMs, int $readers, array &$stats): array
{
    resetTable($rows);
    $totalMs = $rows / 1000 * $msPer1k;
    $held = 0.0;
    $batches = 0;
    $probe = fopen(tableLockPath(), 'c');

    // 1. EXPAND — columna nullable: metadata, instantaneo.
    setPhase('expand');
    $held += withWriteLock(function () {
        $state = readState();
        $state['table']['has_new_column'] = true;
        writeState($state);
    });

    // 2. BACKFILL — por lotes, soltando el lock entre cada uno.
    setPhase('backfill');
    $done = 0;
    $perBatchMs = $totalMs * ($batchSize / max(1, $rows));
    $readersPerBatch = max(1, (int) ceil($readers / max(1, (int) ceil($rows / $batchSize))));
    while ($done < $rows) {
        $chunk = min($batchSize, $rows - $done);
        $held += withWriteLock(function () use ($perBatchMs, $chunk) {
            usleep((int) ($perBatchMs * 1000));
            $state = readState();
            $state['table']['backfilled'] += $chunk;
            writeState($state);
        });
        $done += $chunk;
        $batches++;
        // La pausa entre lotes es lo que le devuelve el motor a la aplicacion:
        // aca entran los lectores que estaban esperando.
        usleep($pauseMs * 1000);
        drainReaders($probe, $readersPerBatch, $stats);
    }

    // 3. SWITCH — feature flag. No toca datos: reversible en un segundo.
    setPhase('switch');
    $state = readState();
    $state['read_from_new_column'] = true;
    writeState($state);

    // 4. CONTRACT — recien ahora se borra la vieja.
    setPhase('contract');
    $held += withWriteLock(function () {
        $state = readState();
        $state['table']['old_column_dropped'] = true;
        writeState($state);
    });
    fclose($probe);
    setPhase('done');

    return ['held' => $held, 'batches' => $batches];
}

function runMigration(string $variant, int $rows, int $readers, int $msPer1k, int $batchSize, int $pauseMs): array
{
    $stats = ['served' => 0, 'failed' => 0, 'waits' => []];
    $t0 = nowMs();
    $result = $variant === 'blocking'
        ? migrateBlocking($rows, $msPer1k, $readers, $stats)
        : migrateExpandContract($rows, $msPer1k, $batchSize, $pauseMs, $readers, $stats);
    $wallMs = nowMs() - $t0;

    $state = readState();
    $slot = &$state['metrics'][$variant];
    $slot['runs']++;
    $slot['lock_held_ms'] += $result['held'];
    $slot['readers_served'] += $stats['served'];
    $slot['readers_failed'] += $stats['failed'];
    $slot['max_read_wait_ms'] = max($slot['max_read_wait_ms'], $stats['waits'] === [] ? 0 : max($stats['waits']));
    $slot['backfill_batches'] += $result['batches'];
    unset($slot);
    writeState($state);

    $total = $stats['served'] + $stats['failed'];

    return [
        'variant' => $variant,
        'rows_total' => $state['table']['rows'],
        'readers' => $readers,
        'phase' => $state['phase'],
        'lock_held_ms' => round($result['held'], 2),
        'longest_single_lock_ms' => round(
            $variant === 'blocking' ? $result['held'] : $result['held'] / max(1, $result['batches']),
            2
        ),
        'readers_served' => $stats['served'],
        'readers_failed' => $stats['failed'],
        'availability_pct' => round($stats['served'] * 100 / max(1, $total), 2),
        'p99_read_wait_ms' => percentile($stats['waits'], 99),
        'max_read_wait_ms' => $stats['waits'] === [] ? 0.0 : round((float) max($stats['waits']), 2),
        'read_timeout_ms' => READ_TIMEOUT_MS,
        'backfill_batches' => $result['batches'],
        'backfill_progress_pct' => round($state['table']['backfilled'] * 100 / max(1, $state['table']['rows']), 2),
        'migration_ms' => round($wallMs, 2),
        'wall_ms' => round($wallMs, 2),
        'note' => $variant === 'blocking'
            ? 'Un solo LOCK_EX tomado durante toda la migracion: LOCK_SH es incompatible con el, asi que ningun '
                . 'lector entra hasta que termine. Es el ALTER TABLE que devuelve 503 durante veinte minutos.'
            : 'Expand, backfill por lotes con pausa, switch por feature flag y contract. El LOCK_EX se toma y se '
                . 'suelta en cada lote, asi que los lectores entran en las pausas.',
    ];
}

function migrationStateSummary(): array
{
    $state = readState();

    return [
        'phase' => $state['phase'],
        'phases' => PHASES,
        'rows_total' => $state['table']['rows'],
        'has_new_column' => $state['table']['has_new_column'],
        'backfilled' => $state['table']['backfilled'],
        'backfill_progress_pct' => round($state['table']['backfilled'] * 100 / max(1, $state['table']['rows']), 2),
        'old_column_dropped' => $state['table']['old_column_dropped'],
        'read_from_new_column' => $state['read_from_new_column'],
        'read_timeout_ms' => READ_TIMEOUT_MS,
        'lock_mechanism' => 'flock(LOCK_SH) / flock(LOCK_EX) — read-write lock del sistema operativo, entre procesos',
        'note' => 'El feature flag read_from_new_column es lo unico reversible en un segundo. Por eso el switch va '
            . 'antes del contract, y no al reves.',
    ];
}

function backfillStep(int $batchSize, int $msPer1k): array
{
    $state = readState();
    $rows = $state['table']['rows'];
    $done = $state['table']['backfilled'];
    if (!$state['table']['has_new_column']) {
        return ['status' => 'skipped', 'reason' => 'la columna nueva todavia no existe: falta la fase expand'];
    }
    if ($done >= $rows) {
        return ['status' => 'complete', 'backfilled' => $done, 'rows_total' => $rows];
    }

    $chunk = min($batchSize, $rows - $done);
    $held = withWriteLock(function () use ($rows, $msPer1k, $chunk) {
        usleep((int) ($rows / 1000 * $msPer1k * ($chunk / max(1, $rows)) * 1000));
        $state = readState();
        $state['table']['backfilled'] += $chunk;
        writeState($state);
    });

    $state = readState();

    return [
        'status' => 'batch_done',
        'batch_size' => $chunk,
        'lock_held_ms' => round($held, 2),
        'backfilled' => $state['table']['backfilled'],
        'rows_total' => $rows,
        'backfill_progress_pct' => round($state['table']['backfilled'] * 100 / max(1, $rows), 2),
    ];
}

function diagnosticsSummary(): array
{
    $state = readState();

    return [
        'stack' => envOr('APP_STACK', 'PHP 8.3'),
        'case' => CASE_NAME,
        'variants' => $state['metrics'],
        'migration' => migrationStateSummary(),
        'interpretation' => [
            'blocking' => 'readers_failed > 0 y max_read_wait_ms = el deadline completo: los lectores que llegaron '
                . 'durante el LOCK_EX no pudieron entrar nunca.',
            'expand_contract' => 'readers_failed = 0: los lectores entran en las pausas entre lotes. El trabajo total '
                . 'es el mismo; lo que cambia es como se reparte.',
            'php_note' => 'flock es un read-write lock DEL SISTEMA OPERATIVO, no una estructura en memoria: LOCK_SH '
                . 'para lectores, LOCK_EX para el escritor, LOCK_NB para el intento con deadline. Los otros seis '
                . 'stacks coordinan hilos de un proceso; este coordina procesos distintos, que es lo que hace de '
                . 'verdad un motor de base de datos.',
        ],
        'fidelity_note' => 'El servidor embebido de PHP es de un solo proceso, asi que los lectores se recorren en '
            . 'secuencia. El lock es real y entre procesos; lo que no es concurrente es el laboratorio.',
    ];
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '', $query);

$rows = clampInt((int) ($query['rows'] ?? 20000), 1000, 500000);
$readers = clampInt((int) ($query['readers'] ?? 8), 1, 64);
$msPer1k = clampInt((int) ($query['ms_per_1k'] ?? 20), 1, 200);
$batch = clampInt((int) ($query['batch'] ?? 2000), 100, 100000);
$pauseMs = clampInt((int) ($query['pause_ms'] ?? 5), 0, 200);

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
            'goal' => 'Mostrar que el trabajo total de una migracion es el mismo; lo que cambia es si se cobra todo '
                . 'junto con la app caida o repartido en lotes que nadie nota.',
            'php_specific' => 'flock con LOCK_SH / LOCK_EX / LOCK_NB: el unico read-write lock del laboratorio que lo '
                . 'provee el sistema operativo y coordina procesos, no hilos.',
            'routes' => [
                '/health' => 'Estado basico del servicio.',
                '/migrate-blocking?rows=20000&readers=8' => 'Un solo LOCK_EX por toda la migracion.',
                '/migrate-expand-contract?rows=20000&readers=8&batch=2000&pause_ms=5' => 'Cuatro fases, LOCK_EX corto por lote.',
                '/migration/state' => 'Fase actual, progreso del backfill y estado del feature flag.',
                '/backfill?batch=2000' => 'Un lote suelto.',
                '/diagnostics/summary' => 'Comparativa entre variantes.',
                '/reset-lab' => 'Vuelve la tabla al esquema viejo.',
            ],
        ];
    } elseif ($uri === '/health') {
        $payload = ['status' => 'ok', 'stack' => envOr('APP_STACK', 'PHP 8.3'), 'case' => CASE_NAME];
    } elseif ($uri === '/migrate-blocking') {
        $payload = runMigration('blocking', $rows, $readers, $msPer1k, $batch, $pauseMs);
    } elseif ($uri === '/migrate-expand-contract') {
        $payload = runMigration('expand_contract', $rows, $readers, $msPer1k, $batch, $pauseMs);
    } elseif ($uri === '/migration/state') {
        $payload = migrationStateSummary();
    } elseif ($uri === '/backfill') {
        $payload = backfillStep($batch, $msPer1k);
    } elseif ($uri === '/diagnostics/summary') {
        $payload = diagnosticsSummary();
    } elseif ($uri === '/reset-lab') {
        writeState(initialState());
        resetTable($rows);
        $payload = ['status' => 'reset', 'message' => 'Tabla, fase y metricas reiniciadas.'];
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
