<?php

declare(strict_types=1);

function envOr(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        envOr('DB_HOST', 'db'),
        envOr('DB_PORT', '5432'),
        envOr('DB_NAME', 'problemlab')
    );

    $pdo = new PDO(
        $dsn,
        envOr('DB_USER', 'problemlab'),
        envOr('DB_PASSWORD', 'problemlab'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    return $pdo;
}

function metricsPath(): string
{
    return __DIR__ . '/metrics-store.json';
}

function initialMetrics(): array
{
    return [
        'requests' => 0,
        'samples_ms' => [],
        'routes' => [],
        'last_path' => null,
        'last_status' => 200,
        'last_updated' => null,
        'last_db_time_ms' => 0,
        'last_db_queries' => 0,
        'status_counts' => [
            '2xx' => 0,
            '4xx' => 0,
            '5xx' => 0,
        ],
        'db_time_samples_ms' => [],
        'db_query_samples' => [],
    ];
}

function readMetrics(): array
{
    $file = metricsPath();
    if (!file_exists($file)) {
        return initialMetrics();
    }

    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        return initialMetrics();
    }

    return array_replace_recursive(initialMetrics(), $data);
}

function writeMetrics(array $metrics): void
{
    file_put_contents(metricsPath(), json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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

function placeholderList(int $count): string
{
    return implode(', ', array_fill(0, $count, '?'));
}

function routeMetricsSummary(array $metrics): array
{
    $routes = [];
    foreach (($metrics['routes'] ?? []) as $route => $samples) {
        $count = count($samples);
        $routes[$route] = [
            'count' => $count,
            'avg_ms' => $count > 0 ? round(array_sum($samples) / $count, 2) : 0.0,
            'p95_ms' => percentile($samples, 95),
            'p99_ms' => percentile($samples, 99),
            'max_ms' => $count > 0 ? round((float) max($samples), 2) : 0.0,
        ];
    }
    ksort($routes);
    return $routes;
}

function bucketKeyForStatus(int $status): string
{
    if ($status >= 500) {
        return '5xx';
    }
    if ($status >= 400) {
        return '4xx';
    }
    return '2xx';
}

function storeRequestMetrics(string $uri, int $status, float $elapsedMs, float $dbTimeMs, int $dbQueries): void
{
    $metrics = readMetrics();
    $metrics['requests'] = ($metrics['requests'] ?? 0) + 1;
    $metrics['samples_ms'][] = round($elapsedMs, 2);
    if (count($metrics['samples_ms']) > 3000) {
        $metrics['samples_ms'] = array_slice($metrics['samples_ms'], -3000);
    }

    $metrics['db_time_samples_ms'][] = round($dbTimeMs, 2);
    if (count($metrics['db_time_samples_ms']) > 3000) {
        $metrics['db_time_samples_ms'] = array_slice($metrics['db_time_samples_ms'], -3000);
    }

    $metrics['db_query_samples'][] = $dbQueries;
    if (count($metrics['db_query_samples']) > 3000) {
        $metrics['db_query_samples'] = array_slice($metrics['db_query_samples'], -3000);
    }

    $metrics['routes'][$uri] = $metrics['routes'][$uri] ?? [];
    $metrics['routes'][$uri][] = round($elapsedMs, 2);
    if (count($metrics['routes'][$uri]) > 500) {
        $metrics['routes'][$uri] = array_slice($metrics['routes'][$uri], -500);
    }

    $bucket = bucketKeyForStatus($status);
    $metrics['status_counts'][$bucket] = ($metrics['status_counts'][$bucket] ?? 0) + 1;
    $metrics['last_path'] = $uri;
    $metrics['last_status'] = $status;
    $metrics['last_updated'] = gmdate('c');
    $metrics['last_db_time_ms'] = round($dbTimeMs, 2);
    $metrics['last_db_queries'] = $dbQueries;
    writeMetrics($metrics);
}

function metricsSummary(array $metrics): array
{
    $samples = $metrics['samples_ms'] ?? [];
    $dbTimeSamples = $metrics['db_time_samples_ms'] ?? [];
    $dbQuerySamples = $metrics['db_query_samples'] ?? [];
    $count = count($samples);

    return [
        'requests_tracked' => $metrics['requests'] ?? 0,
        'sample_count' => $count,
        'avg_ms' => $count > 0 ? round(array_sum($samples) / $count, 2) : 0.0,
        'p95_ms' => percentile($samples, 95),
        'p99_ms' => percentile($samples, 99),
        'max_ms' => $count > 0 ? round((float) max($samples), 2) : 0.0,
        'last_path' => $metrics['last_path'] ?? null,
        'last_status' => $metrics['last_status'] ?? 200,
        'last_updated' => $metrics['last_updated'] ?? null,
        'last_db_time_ms' => $metrics['last_db_time_ms'] ?? 0,
        'last_db_queries' => $metrics['last_db_queries'] ?? 0,
        'avg_db_time_ms' => count($dbTimeSamples) > 0 ? round(array_sum($dbTimeSamples) / count($dbTimeSamples), 2) : 0.0,
        'p95_db_time_ms' => percentile($dbTimeSamples, 95),
        'avg_db_queries' => count($dbQuerySamples) > 0 ? round(array_sum($dbQuerySamples) / count($dbQuerySamples), 2) : 0.0,
        'p95_db_queries' => percentile($dbQuerySamples, 95),
        'status_counts' => $metrics['status_counts'] ?? ['2xx' => 0, '4xx' => 0, '5xx' => 0],
        'routes' => routeMetricsSummary($metrics),
    ];
}

function prometheusLabel(string $value): string
{
    return str_replace(["\\", '"', "\n"], ["\\\\", '\"', ' '], $value);
}

function jsonResponse(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
