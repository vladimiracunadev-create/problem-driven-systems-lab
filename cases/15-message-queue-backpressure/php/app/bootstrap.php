<?php

/**
 * Caso 15 — Backpressure en colas de mensajes — stack PHP 8.3.
 *
 * Lo que este stack aporta, y por que su respuesta es la mas incomoda:
 *
 *   PHP **no tiene cola en proceso**. No hay `queue.Queue`, ni `chan`, ni
 *   `BlockingQueue`, ni `Channel`. Un array dentro de una request desaparece
 *   cuando la request termina, asi que no puede haber un productor y un
 *   consumidor compartiendolo.
 *
 *   Consecuencia directa: en PHP el backpressure **no vive en el lenguaje, vive
 *   en el transporte**. Las tres politicas de este caso existen igual, pero
 *   estan en otra capa:
 *
 *     - bloquear      -> `listen.backlog` de PHP-FPM y el accept queue del
 *                        kernel. Cuando se llena, el kernel deja de aceptar
 *                        conexiones y el cliente ve la espera.
 *     - descartar     -> `pm.max_children` alcanzado: FPM devuelve 502 y el
 *                        request se pierde.
 *     - dead letter   -> la DLQ del broker real (SQS, RabbitMQ, Redis Streams),
 *                        porque la cola de verdad nunca estuvo en PHP.
 *
 *   Este archivo modela la cola dentro de una request para que el contraste
 *   unbounded/bounded sea observable y comparable con los otros seis stacks. La
 *   nota de fidelidad lo dice de frente en `/diagnostics/summary`.
 *
 *   Y hay algo que PHP enseña mejor que nadie justamente por no tener la
 *   primitiva: **el backpressure no es una propiedad de la cola, es una
 *   propiedad del sistema entero**. Si el freno no esta en tu proceso, esta en
 *   el kernel, en el broker o en el balanceador — pero esta en algun lado, y
 *   conviene saber cual antes de que te lo muestre un incidente.
 */

declare(strict_types=1);

const MSG_BYTES = 2048;
const POLICIES = ['block', 'drop_oldest', 'dead_letter'];

function envOr(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function storageDir(): string
{
    $dir = sys_get_temp_dir() . '/pdsl-case15';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

function statePath(): string
{
    return storageDir() . '/state.json';
}

function initialState(): array
{
    $slot = [
        'runs' => 0,
        'produced' => 0,
        'consumed' => 0,
        'dropped' => 0,
        'dead_lettered' => 0,
        'max_queue_depth' => 0,
        'max_oldest_age_ms' => 0.0,
        'producer_blocked_ms' => 0.0,
    ];

    return [
        'metrics' => ['unbounded' => $slot, 'bounded' => $slot],
        'dlq' => [],
        'last_state' => [],
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
