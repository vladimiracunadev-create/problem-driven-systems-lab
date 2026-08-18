<?php

/**
 * Caso 18 — Arranque en frio y retraso del autoescalado — stack PHP 8.3.
 *
 * Frio: el autoescalador levanta instancias cuando el trafico ya subio. El
 * proceso queda vivo al instante y `/health` responde 200 — pero la instancia
 * no sirve nada hasta terminar de inicializar. El balanceador que mira liveness
 * en vez de readiness manda trafico a ese hueco. Ahi nacen los 503.
 *
 * Templado: pool tibio ya inicializado y ya ejercitado, y balanceador que
 * enruta por `/ready`.
 *
 * Lo que este stack aporta, y no lo tiene ningun otro del laboratorio:
 *
 *   **PHP arranca en frio en CADA peticion, por diseño.** El modelo es
 *   share-nothing: la peticion termina, el proceso descarta todo el estado, y
 *   la siguiente empieza de cero. Lo que en Java es un problema de despliegue,
 *   en PHP seria un problema de cada request — si no fuera por opcache.
 *
 *   `opcache` compila cada archivo `.php` a opcodes UNA vez y los guarda en
 *   memoria compartida entre todos los workers de FPM. Es el equivalente exacto
 *   de ReadyToRun de .NET o de AppCDS de Java, con dos diferencias:
 *   viene activado de fabrica en cualquier imagen oficial, y su cache la
 *   comparten los procesos, no los hilos.
 *
 *   El corolario incomodo: **cada worker nuevo de FPM vuelve a pagar la parte
 *   que opcache no cubre** — construir el contenedor de servicios, leer
 *   configuracion, abrir el pool. `pm.start_servers` y `pm.min_spare_servers`
 *   son el pool tibio de PHP, y son configuracion, no codigo.
 *
 *   Sobre el JIT: PHP 8.3 tiene uno (`opcache.jit`), pero viene **apagado** por
 *   defecto y solo paga en codigo CPU-bound. Por eso `warmup_speedup_x` sale
 *   cerca de 1.0 aqui: no hay curva de calentamiento que medir, igual que en
 *   Python, y por la misma razon de fondo.
 *
 * Nota de fidelidad: el servidor embebido de PHP es de un solo proceso, asi que
 * el arranque de las instancias no puede correr en paralelo con el trafico. Se
 * modela con un instante de disponibilidad: la instancia declara `ready_at` y
 * toda peticion anterior a ese instante se rechaza. El costo de CPU de la
 * inicializacion si se ejecuta de verdad; lo que se modela es el solapamiento.
 */

declare(strict_types=1);

const WORK_ITERS = 3000;         // calibrado para ~0.3 ms por peticion
const INIT_TABLE_ROWS = 20000;   // parte de CPU de la inicializacion: trabajo real

function envOr(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function storageDir(): string
{
    $dir = sys_get_temp_dir() . '/pdsl-case18';
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

/**
 * Trabajo por peticion: lazo entero puro, sin sleep, sin I/O.
 *
 * Identico en los siete stacks. Lo que cambia es lo que el runtime hace con el
 * mismo codigo repetido mil veces — que es lo que este caso mide. En PHP no
 * cambia nada, porque el JIT viene apagado.
 */
function work(int $iters): int
{
    $h = 2166136261;
    for ($i = 0; $i < $iters; $i++) {
        $h = (($h ^ $i) * 16777619) & 0xFFFFFFFF;
    }
    return $h;
}

/** La parte de CPU de la inicializacion: construir la tabla. Trabajo de verdad. */
function buildTable(): array
{
    $table = array_fill(0, 256, 0);
    $h = 2166136261;
    for ($i = 0; $i < INIT_TABLE_ROWS; $i++) {
        $h = (($h ^ $i) * 16777619) & 0xFFFFFFFF;
        $table[$h & 0xFF] = $h;
    }
    return $table;
}

function emptyState(): array
{
    $slot = ['runs' => 0, 'served' => 0, 'rejected_cold_start' => 0, 'cold_starts' => 0, 'max_ready_at_ms' => 0.0];
    return [
        'warm_pool' => [],
        'fleet' => [],
        'metrics' => ['cold' => $slot, 'warmed' => $slot],
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

function percentile(array $values, float $pct): float
{
    if (count($values) === 0) {
        return 0.0;
    }
    sort($values);
    $idx = (int) ceil($pct / 100 * count($values)) - 1;
    $idx = max(0, min(count($values) - 1, $idx));
    return round((float) $values[$idx], 3);
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
