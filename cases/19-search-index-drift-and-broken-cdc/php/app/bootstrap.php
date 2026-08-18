<?php

/**
 * Caso 19 — Deriva del indice de busqueda y CDC roto — stack PHP 8.3.
 *
 * Dual-write: la aplicacion escribe en la base y despues en el indice. Cuando la
 * segunda escritura falla —y falla, porque son dos sistemas sin transaccion
 * comun— nadie se entera. La busqueda sigue respondiendo 200; lo que devuelve
 * esta mal.
 *
 * Outbox + checkpoint + reconciliacion: el cambio se anota junto con la escritura
 * a la base, el consumidor aplica en orden y solo avanza el checkpoint cuando la
 * aplicacion se confirma, y un barrido repara lo que los dos primeros no cubren.
 *
 * Las tres formas de deriva, que no son la misma cosa:
 *
 *   missing  — esta en la base, no en el indice      → la busqueda no lo encuentra
 *   stale    — esta en los dos, con version vieja    → la busqueda lo encuentra mal
 *   orphan   — esta en el indice, borrado en la base → la busqueda devuelve fantasmas
 *
 * Lo que este stack aporta:
 *
 *   **La reconciliacion como trabajo agendado es la forma nativa de PHP de
 *   resolver esto**, y resulta ser tambien la mas honesta. En un runtime
 *   share-nothing no hay proceso de larga vida donde vivir un consumidor de CDC:
 *   el consumidor es un comando que corre cada N minutos y termina.
 *
 *   Eso obliga a que **el checkpoint sea durable desde el primer dia**. En Java,
 *   Go o .NET el consumidor vive en memoria y es tentador dejar el checkpoint
 *   ahi, hasta que el proceso se reinicia. En PHP no hay «ahi»: el estado
 *   sobrevive en almacenamiento o no sobrevive. Este caso lo guarda en un
 *   archivo bajo `flock`, que es exactamente lo que hace un cron real.
 *
 *   Y `array_diff_key` / `array_intersect_key` dan las tres caras de la deriva
 *   sin recorrer a mano — mas corto que Go, mas largo que Python.
 *
 *   La contracara, y hay que decirla: **PHP es el unico de los siete donde nada
 *   ayuda a no ignorar el error**. Rust advierte con `#[must_use]`, Go obliga a
 *   escribir `_ =`, y en PHP `@$indice->escribir($doc)` —o simplemente un
 *   `try/catch` vacio— compila, corre y calla. La unica defensa es disciplina.
 *
 * Nota de fidelidad: el estado vive en un archivo JSON bajo `flock`, no en
 * PostgreSQL ni en Elasticsearch. Lo que importa del caso —que la base y el
 * indice son dos sistemas sin transaccion comun— es igual de cierto asi.
 */

declare(strict_types=1);

const TERMS = ['alfa', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta'];

function envOr(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function storageDir(): string
{
    $dir = sys_get_temp_dir() . '/pdsl-case19';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function statePath(): string
{
    return storageDir() . '/lab.json';
}

function nowMs(): float
{
    return microtime(true) * 1000.0;
}

function emptyState(): array
{
    $slot = ['runs' => 0, 'writes' => 0, 'silent_failures' => 0, 'drift_count' => 0, 'outbox_retried' => 0];
    return [
        'db' => [],        // id => ['version'=>n,'term'=>t,'deleted'=>b,'updated_ms'=>f]
        'index' => [],     // id => ['version'=>n,'term'=>t]
        'outbox' => [],    // [seq => ['id'=>..,'version'=>..,'term'=>..,'deleted'=>..]]
        'checkpoint' => 0, // durable desde el primer dia: en PHP no hay otra opcion
        'seq' => 0,
        'metrics' => ['drifted' => $slot, 'reconciled' => $slot],
    ];
}

function loadState(): array
{
    $path = statePath();
    if (!is_file($path)) {
        return emptyState();
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return emptyState();
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : emptyState();
}

function saveState(array $state): void
{
    file_put_contents(statePath(), json_encode($state), LOCK_EX);
}

/**
 * El indice rechaza una fraccion de las escrituras.
 *
 * El modulo 101 —primo— importa: con 100, las dos escrituras del mismo documento
 * (i e i+keyspace) caen en el mismo residuo y corren siempre la misma suerte, asi
 * que nunca se produce deriva `stale`. Con 101 se separan.
 */
function indexWriteFails(int $idx, int $failRate): bool
{
    return (($idx * 37) % 101) < $failRate;
}

function clampInt(int $v, int $lo, int $hi): int
{
    return max($lo, min($hi, $v));
}

function queryInt(array $q, string $key, int $default): int
{
    if (!isset($q[$key]) || !is_numeric($q[$key])) {
        return $default;
    }
    return (int) $q[$key];
}
