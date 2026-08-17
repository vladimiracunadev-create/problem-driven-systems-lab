<?php

/**
 * Caso 14 — Agotamiento del pool de conexiones — stack PHP 8.3.
 *
 * Lo que este stack aporta al caso, y por que su version del bug es distinta:
 *
 *   PHP arranca un proceso limpio por request y lo mata al terminar. Eso hace
 *   que una conexion fugada DENTRO de una request se recupere sola: el proceso
 *   muere y el sistema operativo reclama el socket. Es la razon por la que
 *   media industria PHP nunca vio este bug.
 *
 *   Hasta que aparecen las **conexiones persistentes**. `PDO::ATTR_PERSISTENT`
 *   hace que la conexion sobreviva al final del script y quede pegada al worker
 *   de PHP-FPM. Ahi el modelo de "el proceso limpia por mi" deja de aplicar:
 *   una conexion en mal estado, o una transaccion sin cerrar, se queda en ese
 *   worker y contamina todas las requests que le toquen despues.
 *
 *   La version PHP del agotamiento de pool no es "el pool se vacia": es
 *   `max_children` de FPM multiplicado por conexiones persistentes contra
 *   `max_connections` del motor. Con 50 workers y una persistente cada uno, la
 *   base ve 50 conexiones abiertas aunque el trafico sea de 3 req/s.
 *
 *   Este caso modela el pool dentro de una request para que el contraste
 *   leaky/managed sea observable y comparable con los otros seis stacks. La
 *   nota de fidelidad lo dice de frente en `/diagnostics/summary`.
 *
 * El "query" es un `usleep` a proposito, al reves que en el caso 13. Una
 * conexion se retiene mientras se espera a la red, no mientras se quema CPU.
 */

declare(strict_types=1);

const ACQUIRE_TIMEOUT_MS = 200;

function envOr(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function storageDir(): string
{
    $dir = sys_get_temp_dir() . '/pdsl-case14';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

function metricsPath(): string
{
    return storageDir() . '/metrics.json';
}

// ---------------------------------------------------------------------------
// Pool
// ---------------------------------------------------------------------------

final class Conn
{
    public int $uses = 0;

    public function __construct(public readonly int $id)
    {
    }
}

final class Pool
{
    /** @var list<Conn> */
    private array $free = [];
    public int $acquired = 0;
    public int $released = 0;
    public int $waitingPeak = 0;

    public function __construct(public readonly int $size)
    {
        for ($i = 1; $i <= $size; $i++) {
            $this->free[] = new Conn($i);
        }
    }

    /** Devuelve null si no hay conexion disponible. */
    public function acquire(): ?Conn
    {
        if ($this->free === []) {
            // Un solo proceso: esperar no puede ayudar, porque nadie mas va a
            // devolver nada mientras este bucle corre. En PHP-FPM el que espera
            // es otro proceso, y esa espera SI tiene sentido.
            $this->waitingPeak++;
            return null;
        }
        $conn = array_pop($this->free);
        $conn->uses++;
        $this->acquired++;

        return $conn;
    }

    public function release(?Conn $conn): void
    {
        if ($conn === null) {
            return;
        }
        $this->released++;
        $this->free[] = $conn;
    }

    public function available(): int
    {
        return count($this->free);
    }

    public function leaked(): int
    {
        return $this->acquired - $this->released;
    }
}

// ---------------------------------------------------------------------------
// Trabajo
// ---------------------------------------------------------------------------

/**
 * Reparto determinista de fallos.
 *
 * `$idx % 100 < $failRate` parece equivalente y no lo es: con 24 requests y
 * failRate=25 fallarian las 24, porque todos los indices son menores que 25.
 */
function fails(int $idx, int $failRate): bool
{
    return ($idx * 37) % 100 < $failRate;
}

/** El trabajo que retiene la conexion: una espera, no CPU. */
function runQuery(Conn $conn, int $queryMs, bool $shouldFail): void
{
    usleep($queryMs * 1000);
    if ($shouldFail) {
        throw new RuntimeException("query fallo en la conexion {$conn->id}");
    }
}

// ---------------------------------------------------------------------------
// Metricas
// ---------------------------------------------------------------------------

function initialMetrics(): array
{
    $slot = [
        'runs' => 0,
        'completed' => 0,
        'failed_query' => 0,
        'failed_timeout' => 0,
        'hung' => 0,
        'max_leaked' => 0,
        'wait_samples_ms' => [],
    ];

    return ['leaky' => $slot, 'managed' => $slot];
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
    writeMetrics(initialMetrics());
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

function littlesLaw(int $requests, int $queryMs, float $wallMs): array
{
    if ($wallMs <= 0) {
        return ['avg_throughput_rps' => 0.0, 'avg_query_ms' => $queryMs, 'recommended_pool_size' => 1];
    }
    $rps = $requests / ($wallMs / 1000);

    return [
        'avg_throughput_rps' => round($rps, 2),
        'avg_query_ms' => $queryMs,
        'recommended_pool_size' => max(1, (int) ceil($rps * ($queryMs / 1000)) + 2),
        'formula' => 'ceil(throughput_rps * query_s) + 2 de buffer',
    ];
}

function jsonResponse(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
