<?php

/**
 * Caso 17 — Migracion de esquema sin downtime — stack PHP 8.3.
 *
 * Lo que este stack aporta, y es el unico del lab que lo tiene:
 *
 *   **`flock()` es un read-write lock de verdad, provisto por el sistema
 *   operativo.** No es una emulacion ni una estructura en memoria:
 *
 *       flock($fh, LOCK_SH)   // lock compartido: varios lectores a la vez
 *       flock($fh, LOCK_EX)   // lock exclusivo: uno solo, y sin lectores
 *
 *   Los otros seis stacks tienen su read-write lock **dentro del proceso**:
 *   `ReentrantReadWriteLock`, `ReaderWriterLockSlim`, `sync.RWMutex`, `RwLock`,
 *   el que Python se construye, y el event loop de Node. Todos coordinan hilos
 *   de un mismo proceso.
 *
 *   El de PHP coordina **procesos distintos**, y es el mismo mecanismo que usan
 *   los motores de base de datos por debajo. Es la version del caso que se
 *   parece mas a lo que realmente pasa: un `ALTER TABLE` no bloquea hilos de tu
 *   aplicacion, bloquea a todos los clientes del motor, esten donde esten.
 *
 *   Y viene con `LOCK_NB` para el intento sin bloqueo, que es lo que permite
 *   darle un deadline al lector — el mismo problema que Go tiene que resolver
 *   con una goroutine y Rust con un spin.
 *
 * Nota de fidelidad: el servidor embebido de PHP es de un solo proceso, asi que
 * los lectores de este caso se recorren en secuencia dentro de una request. La
 * primitiva es real y entre procesos; lo que no es concurrente es el laboratorio.
 */

declare(strict_types=1);

const READ_TIMEOUT_MS = 120;
const PHASES = ['idle', 'expand', 'backfill', 'switch', 'contract', 'done'];

function envOr(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function storageDir(): string
{
    $dir = sys_get_temp_dir() . '/pdsl-case17';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

function statePath(): string
{
    return storageDir() . '/state.json';
}

/** El archivo sobre el que se toman LOCK_SH y LOCK_EX. Es la "tabla". */
function tableLockPath(): string
{
    return storageDir() . '/table.lock';
}

function initialState(): array
{
    $slot = [
        'runs' => 0,
        'lock_held_ms' => 0.0,
        'readers_served' => 0,
        'readers_failed' => 0,
        'max_read_wait_ms' => 0.0,
        'backfill_batches' => 0,
    ];

    return [
        'table' => [
            'rows' => 20000,
            'has_new_column' => false,
            'backfilled' => 0,
            'old_column_dropped' => false,
        ],
        'read_from_new_column' => false,
        'phase' => 'idle',
        'metrics' => ['blocking' => $slot, 'expand_contract' => $slot],
    ];
}

function readState(): array
{
    $file = statePath();
    if (!file_exists($file)) {
        return initialState();
    }
    $data = json_decode((string) file_get_contents($file), true);

    return is_array($data) ? array_replace_recursive(initialState(), $data) : initialState();
}

function writeState(array $state): void
{
    file_put_contents(statePath(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function resetTable(int $rows): void
{
    $state = readState();
    $state['table'] = [
        'rows' => $rows,
        'has_new_column' => false,
        'backfilled' => 0,
        'old_column_dropped' => false,
    ];
    $state['read_from_new_column'] = false;
    $state['phase'] = 'idle';
    writeState($state);
}

function setPhase(string $phase): void
{
    $state = readState();
    $state['phase'] = $phase;
    writeState($state);
}

function nowMs(): float
{
    return microtime(true) * 1000;
}

function clampInt(int $value, int $min, int $max): int
{
    return max($min, min($value, $max));
}

function percentile(array $values, float $percent): float
{
    if (count($values) === 0) {
        return 0.0;
    }
    sort($values);
    $index = (int) ceil(($percent / 100) * count($values)) - 1;

    return round((float) $values[max(0, min($index, count($values) - 1))], 2);
}

function jsonResponse(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
