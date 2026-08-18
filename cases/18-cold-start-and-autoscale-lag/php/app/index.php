<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const CASE_NAME = '18 - Arranque en frio y retraso del autoescalado';

$status = 200;

/**
 * Construye el pool tibio: instancias ya inicializadas Y ya ejercitadas.
 *
 * Las dos mitades importan. Inicializar deja la instancia LISTA. Ejercitarla
 * deja al RUNTIME listo — en los stacks con JIT esa segunda mitad aplana la
 * curva. En PHP no cambia nada, y eso tambien es un resultado.
 */
function buildWarmPool(int $instances, int $ioMs, int $prime, int $iters): array
{
    $t0 = nowMs();
    $pool = [];
    for ($i = 0; $i < $instances; $i++) {
        buildTable();   // el costo de CPU de la inicializacion se paga de verdad
        $pool[] = [
            'id' => "warm-$i",
            'live' => true,
            'ready' => true,
            'ready_at_ms' => round($ioMs + (nowMs() - $t0), 2),
            'served' => intdiv($prime, max(1, $instances)),
        ];
    }
    // El sleep modela la parte de I/O de la inicializacion: abrir el pool,
    // resolver DNS, negociar TLS. Esperar a la red no quema CPU.
    usleep($ioMs * 1000);
    $initMs = nowMs() - $t0;

    for ($i = 0; $i < $prime; $i++) {
        work($iters);
    }

    $state = loadState();
    $state['warm_pool'] = $pool;
    saveState($state);

    return [
        'warm_pool_size' => count($pool),
        'init_ms' => round($initMs, 2),
        'prime_requests' => $prime,
        'warmup_duration_ms' => round(nowMs() - $t0, 2),
    ];
}

/**
 * El balanceador. `byReadiness = false` es el ingenuo: enruta a cualquier
 * instancia VIVA, aunque no este LISTA. Ahi nacen los 503.
 */
function pick(array $pool, bool $byReadiness, int $counter, float $now): ?int
{
    $n = count($pool);
    for ($k = 0; $k < $n; $k++) {
        $i = ($counter + $k) % $n;
        $inst = $pool[$i];
        if ($byReadiness) {
            if ($now >= $inst['ready_at_abs']) {
                return $i;
            }
        } elseif ($inst['live']) {
            return $i;
        }
    }
    return null;
}

function runScenario(string $variant, int $requests, int $instances, int $clients, int $ioMs, int $paceMs, int $iters, int $prime): array
{
    $state = loadState();
    $warmInfo = null;
    $t0 = nowMs();

    if ($variant === 'cold') {
        // El autoescalador reacciona tarde: las instancias arrancan CON el
        // trafico encima. La parte de CPU se paga aqui; el instante de
        // disponibilidad queda `io_ms` mas adelante.
        $pool = [];
        for ($i = 0; $i < $instances; $i++) {
            buildTable();
            $pool[] = [
                'id' => "cold-$i",
                'live' => true,
                'ready_at_abs' => nowMs() + $ioMs,
                'served' => 0,
            ];
        }
        $byReadiness = false;   // el balanceador ingenuo mira /health
        $coldStarts = $instances;
    } else {
        if (count($state['warm_pool'] ?? []) < $instances) {
            $warmInfo = buildWarmPool($instances, $ioMs, $prime, $iters);
            $state = loadState();
        }
        $pool = [];
        foreach (array_slice($state['warm_pool'], 0, $instances) as $w) {
            $pool[] = [
                'id' => $w['id'],
                'live' => true,
                'ready_at_abs' => 0.0,   // el pool ya estaba listo antes del trafico
                'served' => $w['served'],
            ];
        }
        $byReadiness = true;    // el balanceador correcto mira /ready
        $coldStarts = 0;
    }

    $ordered = [];
    $served = 0;
    $rejected = 0;
    // El servidor embebido es de un solo proceso: los clientes se intercalan en
    // secuencia. La primitiva y el trabajo son reales; lo que no es concurrente
    // es el laboratorio.
    for ($k = 0; $k < $requests; $k++) {
        $client = $k % $clients;
        $now = nowMs();
        $idx = pick($pool, $byReadiness, $client + intdiv($k, $clients), $now);
        if ($idx === null || $now < $pool[$idx]['ready_at_abs']) {
            // El proceso esta vivo, el healthcheck da verde, y la peticion se
            // cae igual. Ninguna alerta de disponibilidad de proceso dispara.
            $rejected++;
        } else {
            $st = nowMs();
            work($iters);
            $pool[$idx]['served']++;
            $ordered[] = nowMs() - $st;
            $served++;
        }
        if ($paceMs > 0 && ($k % $clients) === $clients - 1) {
            usleep($paceMs * 1000);
        }
    }
    $wall = nowMs() - $t0;

    $first100 = array_slice($ordered, 0, 100);
    $after1000 = count($ordered) > 1000
        ? array_slice($ordered, 1000)
        : (count($ordered) > 100 ? array_slice($ordered, -100) : $ordered);

    $p99First = percentile($first100, 99);
    $p99After = percentile($after1000, 99);

    $readyAt = 0.0;
    foreach ($pool as $p) {
        $gap = $variant === 'cold' ? (float) $ioMs : 0.0;
        $readyAt = max($readyAt, $gap);
    }

    $fleet = [];
    foreach ($pool as $p) {
        $fleet[] = [
            'id' => $p['id'],
            'live' => true,
            'ready' => nowMs() >= $p['ready_at_abs'],
            'ready_at_ms' => $variant === 'cold' ? (float) $ioMs : 0.0,
            'requests_served' => $p['served'],
        ];
    }

    $state = loadState();
    $state['fleet'] = $fleet;
    $slot = &$state['metrics'][$variant];
    $slot['runs']++;
    $slot['served'] += $served;
    $slot['rejected_cold_start'] += $rejected;
    $slot['cold_starts'] += $coldStarts;
    $slot['max_ready_at_ms'] = max((float) $slot['max_ready_at_ms'], $readyAt);
    unset($slot);
    saveState($state);

    $payload = [
        'variant' => $variant,
        'instances' => $instances,
        'requests' => $requests,
        'clients' => $clients,
        'lb_routes_by' => $byReadiness ? 'readiness (/ready)' : 'liveness (/health)',
        'cold_start_count' => $coldStarts,
        'warm_pool_size' => count($state['warm_pool'] ?? []),
        'ready_at_ms' => round($readyAt, 2),
        'health_vs_ready_gap_ms' => $coldStarts > 0 ? round($readyAt, 2) : 0.0,
        'first_response_ms' => count($ordered) > 0 ? round($ordered[0], 3) : 0.0,
        'p99_first_100_ms' => $p99First,
        'p99_after_1000_ms' => $p99After,
        'warmup_speedup_x' => $p99After > 0 ? round($p99First / $p99After, 2) : 1.0,
        'p50_ms' => percentile($ordered, 50),
        'served' => $served,
        'rejected_cold_start' => $rejected,
        'availability_pct' => round($served * 100 / max(1, $served + $rejected), 2),
        'work_iters' => $iters,
        'io_ms' => $ioMs,
        'pace_ms' => $paceMs,
        'wall_ms' => round($wall, 2),
    ];
    if ($warmInfo !== null) {
        $payload['warm_pool_built_now'] = $warmInfo;
    }
    $payload['note'] = $variant === 'cold'
        ? 'El proceso esta vivo desde el milisegundo cero y /health lo confirma, pero la instancia no sirve nada '
          . 'hasta terminar de inicializar. El balanceador que enruta por liveness manda trafico a ese hueco: los '
          . '503 salen de una instancia que ninguna alerta considera caida.'
        : 'El pool ya estaba inicializado y ya ejercitado, y el balanceador enruta por readiness. Ninguna peticion '
          . 'cae en una instancia a medio levantar: 0 rechazos y la latencia parte donde la otra variante recien termina.';
    $payload['php_note'] = 'PHP arranca en frio en CADA peticion por diseño: el modelo share-nothing descarta todo '
        . 'el estado al terminar. Opcache es lo que evita que eso sea catastrofico, y es el equivalente exacto de '
        . 'ReadyToRun o AppCDS — con la diferencia de que viene activado de fabrica y su cache la comparten los '
        . 'procesos, no los hilos. El JIT de PHP 8.3 existe pero viene apagado, y por eso warmup_speedup_x ronda 1.0.';
    return $payload;
}

function readyState(): array
{
    $state = loadState();
    $fleet = $state['fleet'] ?? [];
    $allReady = count($fleet) > 0;
    foreach ($fleet as $i) {
        if (!$i['ready']) {
            $allReady = false;
        }
    }
    return [
        'ready' => $allReady,
        'instances' => $fleet,
        'warm_pool_size' => count($state['warm_pool'] ?? []),
        'note' => '`/health` responde 200 apenas el proceso arranca. `/ready` responde 200 recien cuando la '
            . 'instancia puede servir. Si el balanceador mira la primera en vez de la segunda, el hueco entre las '
            . 'dos es tiempo de caida que nadie registra como caida.',
    ];
}

function diagnostics(string $stack): array
{
    $state = loadState();
    return [
        'stack' => $stack,
        'case' => CASE_NAME,
        'variants' => $state['metrics'],
        'fleet' => readyState(),
        'fidelity' => [
            'medido' => 'La curva de calentamiento. El trabajo por peticion es un lazo entero puro sin sleep, '
                . 'identico en los 7 stacks; p99_first_100_ms vs p99_after_1000_ms es lo que ese runtime hace de verdad.',
            'modelado' => 'La parte de I/O de la inicializacion (abrir pool, DNS, TLS) es un sleep de io_ms. Y en '
                . 'PHP, ademas, el solapamiento entre el arranque y el trafico: el servidor embebido es de un solo '
                . 'proceso, asi que la instancia declara un instante de disponibilidad en vez de arrancar en paralelo.',
            'real' => 'La parte de CPU de la inicializacion construye una tabla de 20.000 entradas por instancia. '
                . 'Eso si es trabajo, y se paga en cada arranque.',
        ],
        'interpretation' => [
            'cold' => 'rejected_cold_start > 0 con el proceso vivo todo el tiempo. health_vs_ready_gap_ms es la '
                . 'ventana exacta en la que el balanceador mando trafico a una instancia que no podia servirlo.',
            'warmed' => 'rejected_cold_start = 0. El pool ya estaba, y el balanceador enruta por readiness.',
            'php_note' => 'El pool tibio de PHP no es codigo: es `pm.start_servers` y `pm.min_spare_servers` en la '
                . 'configuracion de FPM. Cada worker nuevo vuelve a pagar lo que opcache no cubre — construir el '
                . 'contenedor de servicios, leer configuracion, abrir el pool.',
        ],
    ];
}

// ---------------------------------------------------------------------------
// Enrutado
// ---------------------------------------------------------------------------

$stack = envOr('APP_STACK', 'PHP 8.3');
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$q = $_GET;

$requests = clampInt(queryInt($q, 'requests', 2400), 100, 20000);
$instances = clampInt(queryInt($q, 'instances', 3), 1, 32);
$clients = clampInt(queryInt($q, 'clients', 8), 1, 64);
$ioMs = clampInt(queryInt($q, 'io_ms', 150), 0, 5000);
$paceMs = clampInt(queryInt($q, 'pace_ms', 1), 0, 100);
$iters = clampInt(queryInt($q, 'work_iters', WORK_ITERS), 100, 5000000);
$prime = clampInt(queryInt($q, 'prime', 1500), 0, 100000);

if ($uri === '/' || $uri === '/index' || $uri === '/index.php') {
    require __DIR__ . '/ui.php';
    exit;
}

switch ($uri) {
    case '/health':
        $payload = [
            'status' => 'ok',
            'stack' => $stack,
            'case' => CASE_NAME,
            'note' => 'Liveness. Esto responde 200 aunque la instancia no pueda servir una sola peticion.',
        ];
        break;
    case '/ready':
        $payload = readyState();
        break;
    case '/boot-cold':
        $payload = runScenario('cold', $requests, $instances, $clients, $ioMs, $paceMs, $iters, $prime);
        break;
    case '/boot-warmed':
        $payload = runScenario('warmed', $requests, $instances, $clients, $ioMs, $paceMs, $iters, $prime);
        break;
    case '/warmup':
        $payload = buildWarmPool($instances, $ioMs, $prime, $iters);
        $payload['status'] = 'warm';
        $payload['note'] = 'Inicializar deja la instancia lista. Ejercitarla deja al runtime listo. Las dos mitades '
            . 'hacen falta, y solo la segunda depende del lenguaje.';
        break;
    case '/diagnostics/summary':
        $payload = diagnostics($stack);
        break;
    case '/reset-lab':
        saveState(emptyState());
        $payload = ['status' => 'reset', 'message' => 'Flota, pool tibio y metricas reiniciados.'];
        break;
    default:
        $status = 404;
        $payload = ['error' => 'Ruta no encontrada', 'path' => $uri];
}

$payload['timestamp_utc'] = gmdate('Y-m-d\TH:i:s\Z');
$payload['pid'] = getmypid();

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
