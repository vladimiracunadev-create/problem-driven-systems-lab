<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const CASE_NAME = '13 - Cache stampede y thundering herd';

$started = microtime(true);
$status = 200;

/**
 * Variante naive: cada llamador que ve el miss recalcula.
 *
 * No hay lock ni coordinacion. En PHP-FPM real esto son N procesos distintos
 * pegandole al origen al mismo tiempo; aca los N llamadores se recorren en un
 * bucle porque el servidor embebido de PHP es de un solo proceso — y ese es
 * justamente el punto que la nota de fidelidad de mas abajo deja explicito.
 */
function burstNaive(string $key, int $concurrency, int $rounds): array
{
    $computations = 0;
    $hits = 0;
    $waits = [];

    // Los N llamadores ya estaban en vuelo cuando la clave expiro: todos leen
    // la cache antes de que ninguno alcance a escribirla.
    $states = [];
    for ($i = 0; $i < $concurrency; $i++) {
        [, $states[$i]] = cacheLookup($key);
    }

    for ($i = 0; $i < $concurrency; $i++) {
        $t0 = nowMs();
        if ($states[$i] === 'fresh') {
            $hits++;
        } else {
            computeOrigin($key, $rounds);
            $computations++;
        }
        $waits[] = round(nowMs() - $t0, 2);
    }

    return [
        'origin_computations' => $computations,
        'cache_hits' => $hits,
        'coalesced_waiters' => 0,
        'served_stale' => 0,
        'stampede_depth' => $computations,
        'waits' => $waits,
    ];
}

/**
 * Variante single-flight: flock() exclusivo + double-checked locking.
 *
 * El segundo `cacheLookup()` DENTRO del lock es la mitad del patron. Sin el,
 * el lock ordena la estampida en fila pero el origen recibe las N consultas
 * igual: cada proceso entra, encuentra el miss que vio antes de esperar, y
 * calcula.
 */
function burstSingleflight(string $key, int $concurrency, int $rounds): array
{
    $computations = 0;
    $hits = 0;
    $waiters = 0;
    $stale = 0;
    $waits = [];

    $states = [];
    for ($i = 0; $i < $concurrency; $i++) {
        [, $states[$i]] = cacheLookup($key);
    }

    $lock = fopen(lockPath($key), 'c');
    if ($lock === false) {
        throw new RuntimeException('No se pudo abrir el lock de single-flight.');
    }

    for ($i = 0; $i < $concurrency; $i++) {
        $t0 = nowMs();

        if ($states[$i] === 'fresh') {
            $hits++;
            $waits[] = round(nowMs() - $t0, 2);
            continue;
        }

        if ($states[$i] === 'stale' && flock($lock, LOCK_EX | LOCK_NB) === false) {
            // Otro proceso ya esta refrescando y el valor viejo sigue servible:
            // se devuelve sin pagar la latencia del origen.
            $stale++;
            $waits[] = round(nowMs() - $t0, 2);
            continue;
        }

        // Lock exclusivo bloqueante: el resto espera aca.
        flock($lock, LOCK_EX);

        // Double check: la cache pudo llenarse mientras esperabamos el lock.
        [, $recheck] = cacheLookup($key);
        if ($recheck === 'fresh') {
            $waiters++;
        } else {
            computeOrigin($key, $rounds);
            $computations++;
        }
        flock($lock, LOCK_UN);

        $waits[] = round(nowMs() - $t0, 2);
    }

    fclose($lock);

    return [
        'origin_computations' => $computations,
        'cache_hits' => $hits,
        'coalesced_waiters' => $waiters,
        'served_stale' => $stale,
        // Mismo significado que en los otros seis stacks: cuantos llamadores
        // llegaron a entrar al camino de recomputo. Con double check, uno.
        'stampede_depth' => $computations,
        'waits' => $waits,
    ];
}

function runBurst(string $variant, string $key, int $concurrency, int $rounds): array
{
    $t0 = nowMs();
    $result = $variant === 'naive'
        ? burstNaive($key, $concurrency, $rounds)
        : burstSingleflight($key, $concurrency, $rounds);
    $wallMs = round(nowMs() - $t0, 2);

    $metrics = readMetrics();
    $slot = &$metrics[$variant];
    $slot['runs']++;
    $slot['origin_computations'] += $result['origin_computations'];
    $slot['cache_hits'] += $result['cache_hits'];
    $slot['coalesced_waiters'] += $result['coalesced_waiters'];
    $slot['served_stale'] += $result['served_stale'];
    $slot['max_stampede_depth'] = max($slot['max_stampede_depth'], $result['stampede_depth']);
    $slot['wall_samples_ms'][] = $wallMs;
    if (count($slot['wall_samples_ms']) > 200) {
        $slot['wall_samples_ms'] = array_slice($slot['wall_samples_ms'], -200);
    }
    unset($slot);
    writeMetrics($metrics);

    [$value] = cacheLookup($key);

    return [
        'variant' => $variant,
        'key' => $key,
        'concurrency' => $concurrency,
        'cost_rounds' => $rounds,
        'origin_computations' => $result['origin_computations'],
        'cache_hits' => $result['cache_hits'],
        'coalesced_waiters' => $result['coalesced_waiters'],
        'served_stale' => $result['served_stale'],
        'stampede_depth' => $result['stampede_depth'],
        'wall_ms' => $wallMs,
        'p99_wait_ms' => percentile($result['waits'], 99),
        'max_wait_ms' => count($result['waits']) > 0 ? round((float) max($result['waits']), 2) : 0.0,
        'value_digest' => $value,
        'ttl_base_ms' => TTL_BASE_MS,
        'jitter_pct' => JITTER_PCT,
        'note' => $variant === 'naive'
            ? 'Sin coordinacion: cada llamador que vio el miss recalcula. El origen recibe la rafaga entera.'
            : 'flock() exclusivo + double-checked locking: el single-flight vive en el almacenamiento, no en el proceso.',
    ];
}

function cacheStateSummary(): array
{
    $entries = [];
    foreach (readCache() as $key => $entry) {
        $age = nowMs() - (float) $entry['computed_at_ms'];
        $entries[$key] = [
            'age_ms' => round($age, 2),
            'soft_ttl_ms' => (int) $entry['soft_ms'],
            'hard_ttl_ms' => (int) $entry['hard_ms'],
            'soft_expired' => $age > (float) $entry['soft_ms'],
            'hard_expired' => $age > (float) $entry['hard_ms'],
            'value_digest' => (string) $entry['value'],
        ];
    }
    ksort($entries);

    $inflight = [];
    foreach (glob(storageDir() . '/flight-*.lock') ?: [] as $path) {
        $inflight[] = basename($path);
    }

    return [
        'entries' => $entries,
        'ttl_base_ms' => TTL_BASE_MS,
        'jitter_pct' => JITTER_PCT,
        'soft_fraction' => SOFT_FRACTION,
        'lock_files' => $inflight,
    ];
}

function diagnosticsSummary(): array
{
    $metrics = readMetrics();
    $variants = [];
    $total = 0;
    foreach (['naive', 'singleflight'] as $name) {
        $slot = $metrics[$name];
        $samples = $slot['wall_samples_ms'];
        $variants[$name] = [
            'runs' => (int) $slot['runs'],
            'origin_computations' => (int) $slot['origin_computations'],
            'cache_hits' => (int) $slot['cache_hits'],
            'coalesced_waiters' => (int) $slot['coalesced_waiters'],
            'served_stale' => (int) $slot['served_stale'],
            'max_stampede_depth' => (int) $slot['max_stampede_depth'],
            'avg_wall_ms' => count($samples) > 0 ? round(array_sum($samples) / count($samples), 2) : 0.0,
            'p99_wall_ms' => percentile($samples, 99),
        ];
        $total += (int) $slot['origin_computations'];
    }

    return [
        'stack' => envOr('APP_STACK', 'PHP 8.3'),
        'case' => CASE_NAME,
        'variants' => $variants,
        'origin_total_computations' => $total,
        'interpretation' => [
            'naive' => 'origin_computations crece linealmente con la concurrencia: el origen ve la rafaga completa.',
            'singleflight' => 'origin_computations se mantiene en 1 por expiracion, sin importar cuantos llamadores lleguen.',
            'php_note' => 'PHP no comparte heap entre requests: el single-flight vive en el almacenamiento (flock) y el double-check dentro del lock es lo que evita la estampida en fila.',
        ],
        'fidelity_note' => 'El servidor embebido de PHP es de un solo proceso, asi que los N llamadores se recorren en secuencia. La primitiva que se demuestra — lock de almacenamiento + double check — es exactamente la que hace falta bajo PHP-FPM con N procesos reales.',
    ];
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '', $query);

$key = substr((string) ($query['key'] ?? 'report-alpha'), 0, 60);
if ($key === '') {
    $key = 'report-alpha';
}
$concurrency = clampInt((int) ($query['concurrency'] ?? 16), 1, 128);
$rounds = clampInt((int) ($query['cost'] ?? 40), 1, 400);

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
            'goal' => 'Mostrar cuantas veces pega el origen cuando una clave caliente expira con N llamadores encima.',
            'php_specific' => 'Sin heap compartido entre requests, el single-flight vive en el almacenamiento: flock() exclusivo + double-checked locking.',
            'routes' => [
                '/health' => 'Estado basico del servicio.',
                '/cache-naive?key=report-alpha&concurrency=16&cost=40' => 'Rafaga sin single-flight.',
                '/cache-singleflight?key=report-alpha&concurrency=16&cost=40' => 'Misma rafaga con flock + double check, jitter y soft TTL.',
                '/cache/state' => 'Edad, soft/hard TTL y locks presentes.',
                '/diagnostics/summary' => 'Comparativa de origin_computations entre variantes.',
                '/reset-lab' => 'Vacia cache, locks y contadores.',
            ],
        ];
    } elseif ($uri === '/health') {
        $payload = ['status' => 'ok', 'stack' => envOr('APP_STACK', 'PHP 8.3'), 'case' => CASE_NAME];
    } elseif ($uri === '/cache-naive') {
        $payload = runBurst('naive', $key, $concurrency, $rounds);
    } elseif ($uri === '/cache-singleflight') {
        $payload = runBurst('singleflight', $key, $concurrency, $rounds);
    } elseif ($uri === '/cache/state') {
        $payload = cacheStateSummary();
    } elseif ($uri === '/diagnostics/summary') {
        $payload = diagnosticsSummary();
    } elseif ($uri === '/reset-lab') {
        resetLabState();
        $payload = ['status' => 'reset', 'message' => 'Cache, locks y metricas reiniciados.'];
    } else {
        $status = 404;
        $payload = ['error' => 'Ruta no encontrada', 'path' => $uri];
    }
} catch (Throwable $e) {
    $status = 500;
    $payload = [
        'error' => 'Fallo al procesar la solicitud',
        'message' => $e->getMessage(),
        'path' => $uri,
    ];
}

$payload['elapsed_ms'] = round((microtime(true) - $started) * 1000, 2);
$payload['timestamp_utc'] = gmdate('c');
$payload['pid'] = getmypid();
jsonResponse($status, $payload);
