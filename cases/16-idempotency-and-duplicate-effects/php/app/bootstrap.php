<?php

/**
 * Caso 16 — Idempotencia y efectos duplicados — stack PHP 8.3.
 *
 * Lo que este stack aporta, y por que su version es la unica que sobrevive a
 * varias replicas:
 *
 *   PHP no comparte heap entre requests. El `ConcurrentHashMap` de Java, el
 *   `ConcurrentDictionary` de .NET, el `sync.Map` de Go y el `Map` de Node
 *   **no existen** aca: cualquier tabla de idempotencia en memoria se evapora
 *   al terminar la request y el siguiente proceso no la ve.
 *
 *   Consecuencia directa: en PHP la clave de idempotencia tiene que vivir en el
 *   almacenamiento, y la operacion atomica la aporta el motor:
 *
 *       INSERT INTO idempotency_keys (key, response)
 *       VALUES (:key, NULL)
 *       ON CONFLICT (key) DO NOTHING
 *       RETURNING id;
 *
 *   Si devuelve una fila, ganaste y sos el primero. Si no devuelve nada, la
 *   clave ya estaba y esto es un reintento. Es exactamente `putIfAbsent`,
 *   `TryAdd`, `LoadOrStore` y `entry()` — pero garantizado por un `UNIQUE` del
 *   motor en vez de por el heap de un proceso.
 *
 *   Y aca esta el punto incomodo del caso: **la version PHP es la unica de las
 *   siete que sigue siendo correcta con veinte replicas**. Las otras seis
 *   resuelven la carrera dentro de su proceso; con dos pods, cada uno tiene su
 *   tabla y ninguno ve las claves del otro, asi que el mismo pago se cobra dos
 *   veces — una por pod.
 *
 *   El stack que peor puntua en fit de primitivas es el que tiene la respuesta
 *   que escala. Esa tension vale mas que el ranking.
 *
 * Aqui la tabla se modela en un archivo con `flock()` porque el lab no monta
 * PostgreSQL para este caso. La semantica es la misma: una operacion atomica
 * sobre almacenamiento compartido entre procesos.
 */

declare(strict_types=1);

const DEDUPE_WINDOW_MS = 24 * 60 * 60 * 1000;
const MAX_ROWS = 200;

function envOr(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function storageDir(): string
{
    $dir = sys_get_temp_dir() . '/pdsl-case16';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

function statePath(): string
{
    return storageDir() . '/state.json';
}

function lockPath(): string
{
    return storageDir() . '/idempotency.lock';
}

function initialState(): array
{
    $slot = [
        'runs' => 0,
        'attempts' => 0,
        'charges_applied' => 0,
        'duplicates_prevented' => 0,
        'duplicates_applied' => 0,
        'idempotency_hits' => 0,
        'side_effects_emitted' => 0,
        'overcharged_cents' => 0,
    ];

    return [
        'ledger' => [],
        'idempotency' => [],
        'outbox' => [],
        'delivered' => [],
        'metrics' => ['unsafe' => $slot, 'idempotent' => $slot],
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

function resetLabState(): void
{
    writeState(initialState());
    @unlink(lockPath());
}

function nowMs(): float
{
    return microtime(true) * 1000;
}

function clampInt(int $value, int $min, int $max): int
{
    return max($min, min($value, $max));
}

function jsonResponse(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
