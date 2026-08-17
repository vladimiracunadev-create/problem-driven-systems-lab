<?php

/**
 * Caso 13 — Cache stampede (thundering herd) — stack PHP 8.3.
 *
 * Lo que este stack aporta al caso, y por que su respuesta es distinta a la de
 * los otros seis:
 *
 *   PHP no tiene heap compartido entre requests. Cada peticion arranca un
 *   proceso limpio, corre y muere. Un `Map<key, Promise>` como el de Node, un
 *   `ConcurrentHashMap` como el de Java o un `Mutex<HashMap>` como el de Rust
 *   NO EXISTEN aca: cualquier estructura en memoria se evapora al terminar la
 *   request y el siguiente proceso no la ve.
 *
 *   Consecuencia directa: en PHP el single-flight no puede vivir en el proceso.
 *   Tiene que vivir en la capa de almacenamiento — un lock de archivo con
 *   `flock()`, un `apcu_add()`, un `SET NX` de Redis. Este archivo usa `flock()`
 *   porque es lo unico que existe sin extensiones ni servicios extra.
 *
 *   El patron completo es double-checked locking:
 *     1. leer la cache (sin lock)  → si esta fresca, listo
 *     2. tomar el lock exclusivo
 *     3. VOLVER a leer la cache    → otro proceso pudo llenarla mientras
 *        esperabamos el lock; sin este segundo chequeo el lock serializa la
 *        estampida pero no la evita, y N procesos calculan igual, en fila.
 *     4. calcular, escribir, soltar
 *
 *   El paso 3 es el que la gente omite. Un lock sin double-check convierte una
 *   estampida paralela en una estampida secuencial: el origen recibe las mismas
 *   N consultas, solo que ordenadas.
 */

declare(strict_types=1);

const TTL_BASE_MS = 4000;
const JITTER_PCT = 25;
const SOFT_FRACTION = 0.6;

function envOr(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function storageDir(): string
{
    $dir = sys_get_temp_dir() . '/pdsl-case13';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

function cachePath(): string
{
    return storageDir() . '/cache.json';
}

function metricsPath(): string
{
    return storageDir() . '/metrics.json';
}

function lockPath(string $key): string
{
    return storageDir() . '/flight-' . substr(sha1($key), 0, 16) . '.lock';
}

// ---------------------------------------------------------------------------
// Cache en disco (el equivalente PHP del heap compartido que no existe)
// ---------------------------------------------------------------------------

function readCache(): array
{
    $file = cachePath();
    if (!file_exists($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);

    return is_array($data) ? $data : [];
}

function writeCache(array $cache): void
{
    file_put_contents(cachePath(), json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function nowMs(): float
{
    return microtime(true) * 1000.0;
}

function ttlWithJitter(): array
{
    $spread = (int) (TTL_BASE_MS * JITTER_PCT / 100);
    $jitter = random_int(-$spread, $spread);
    $hard = TTL_BASE_MS + $jitter;

    return [$hard, (int) ($hard * SOFT_FRACTION)];
}

function cacheStore(string $key, string $value): void
{
    [$hard, $soft] = ttlWithJitter();
    $cache = readCache();
    $cache[$key] = [
        'value' => $value,
        'computed_at_ms' => nowMs(),
        'soft_ms' => $soft,
        'hard_ms' => $hard,
    ];
    writeCache($cache);
}

/** Devuelve [valor, estado] con estado en fresh | stale | miss. */
function cacheLookup(string $key): array
{
    $cache = readCache();
    if (!isset($cache[$key])) {
        return ['', 'miss'];
    }
    $entry = $cache[$key];
    $age = nowMs() - (float) $entry['computed_at_ms'];
    if ($age <= (float) $entry['soft_ms']) {
        return [(string) $entry['value'], 'fresh'];
    }
    if ($age <= (float) $entry['hard_ms']) {
        return [(string) $entry['value'], 'stale'];
    }

    return ['', 'miss'];
}

// ---------------------------------------------------------------------------
// Origen: trabajo real, no un sleep
// ---------------------------------------------------------------------------

function digestWork(string $key, int $rounds): string
{
    $h = 0;
    $salt = max(1, strlen($key));
    $iterations = $rounds * 2000;
    for ($i = 0; $i < $iterations; $i++) {
        $h = ($h * 31 + ($i ^ $salt)) & 0xFFFFFFFF;
    }

    return sprintf('%08x', $h);
}

function computeOrigin(string $key, int $rounds): string
{
    $digest = digestWork($key, $rounds);
    cacheStore($key, $digest);

    return $digest;
}

// ---------------------------------------------------------------------------
// Metricas
// ---------------------------------------------------------------------------

function initialMetrics(): array
{
    $slot = [
        'runs' => 0,
        'origin_computations' => 0,
        'cache_hits' => 0,
        'coalesced_waiters' => 0,
        'served_stale' => 0,
        'max_stampede_depth' => 0,
        'wall_samples_ms' => [],
    ];

    return ['naive' => $slot, 'singleflight' => $slot];
}

function readMetrics(): array
{
    $file = metricsPath();
    if (!file_exists($file)) {
        return initialMetrics();
    }
    $data = json_decode((string) file_get_contents($file), true);

    return is_array($data) ? array_replace_recursive(initialMetrics(), $data) : initialMetrics();
}

function writeMetrics(array $metrics): void
{
    file_put_contents(metricsPath(), json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function resetLabState(): void
{
    writeCache([]);
    writeMetrics(initialMetrics());
    foreach (glob(storageDir() . '/flight-*.lock') ?: [] as $lock) {
        @unlink($lock);
    }
}

function percentile(array $values, float $percent): float
{
    if (count($values) === 0) {
        return 0.0;
    }
    sort($values);
    $index = (int) ceil(($percent / 100) * count($values)) - 1;
    $index = max(0, min($index, count($values) - 1));

    return round((float) $values[$index], 2);
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
