<?php

declare(strict_types=1);

function envOr(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function storageDir(): string
{
    $dir = sys_get_temp_dir() . '/pdsl-case03';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

function telemetryPath(): string
{
    return storageDir() . '/telemetry.json';
}

function legacyLogPath(): string
{
    return storageDir() . '/legacy.log';
}

function observableLogPath(): string
{
    return storageDir() . '/observable.log';
}

function initialTelemetry(): array
{
    return [
        'requests' => 0,
        'samples_ms' => [],
        'routes' => [],
        'last_path' => null,
        'last_status' => 200,
        'last_updated' => null,
        'successes' => [
            'legacy' => 0,
            'observable' => 0,
        ],
        'failures' => [
            'legacy' => ['total' => 0, 'by_step' => [], 'by_scenario' => []],
            'observable' => ['total' => 0, 'by_step' => [], 'by_scenario' => []],
        ],
        'traces' => [],
    ];
}

function readTelemetry(): array
{
    $file = telemetryPath();
    if (!file_exists($file)) {
        return initialTelemetry();
    }

    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        return initialTelemetry();
    }

    return array_replace_recursive(initialTelemetry(), $data);
}

function writeTelemetry(array $telemetry): void
{
    file_put_contents(telemetryPath(), json_encode($telemetry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function resetTelemetryState(): void
{
    writeTelemetry(initialTelemetry());
    foreach ([legacyLogPath(), observableLogPath()] as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }
}

function appendLegacyLog(string $message): void
{
    file_put_contents(
        legacyLogPath(),
        '[' . gmdate('c') . '] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function appendStructuredLog(array $record): void
{
    $record['timestamp_utc'] = gmdate('c');
    file_put_contents(
        observableLogPath(),
        json_encode($record, JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function tailLines(string $path, int $limit): array
{
    if (!file_exists($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    return array_values(array_slice($lines, -$limit));
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

function routeMetricsSummary(array $telemetry): array
{
    $routes = [];
    foreach (($telemetry['routes'] ?? []) as $route => $samples) {
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

function recordRequestTelemetry(string $uri, int $status, float $elapsedMs, ?array $workflowContext): void
{
    $telemetry = readTelemetry();
    $telemetry['requests'] = ($telemetry['requests'] ?? 0) + 1;
    $telemetry['samples_ms'][] = round($elapsedMs, 2);
    if (count($telemetry['samples_ms']) > 3000) {
        $telemetry['samples_ms'] = array_slice($telemetry['samples_ms'], -3000);
    }

    $telemetry['routes'][$uri] = $telemetry['routes'][$uri] ?? [];
    $telemetry['routes'][$uri][] = round($elapsedMs, 2);
    if (count($telemetry['routes'][$uri]) > 500) {
        $telemetry['routes'][$uri] = array_slice($telemetry['routes'][$uri], -500);
    }

    $telemetry['last_path'] = $uri;
    $telemetry['last_status'] = $status;
    $telemetry['last_updated'] = gmdate('c');
    $telemetry['status_bucket'] = bucketKeyForStatus($status);

    if ($workflowContext !== null) {
        $mode = $workflowContext['mode'];
        $scenario = (string) $workflowContext['scenario'];

        if ($workflowContext['outcome'] === 'success') {
            $telemetry['successes'][$mode] = ($telemetry['successes'][$mode] ?? 0) + 1;
        } else {
            $telemetry['failures'][$mode]['total'] = ($telemetry['failures'][$mode]['total'] ?? 0) + 1;

            $step = (string) ($workflowContext['failing_step'] ?? 'unknown');
            $telemetry['failures'][$mode]['by_step'][$step] = ($telemetry['failures'][$mode]['by_step'][$step] ?? 0) + 1;
            $telemetry['failures'][$mode]['by_scenario'][$scenario] = ($telemetry['failures'][$mode]['by_scenario'][$scenario] ?? 0) + 1;
        }

        $trace = $workflowContext;
        $trace['status_code'] = $status;
        $trace['elapsed_ms'] = round($elapsedMs, 2);
        $trace['timestamp_utc'] = gmdate('c');

        $telemetry['traces'][] = $trace;
        if (count($telemetry['traces']) > 40) {
            $telemetry['traces'] = array_slice($telemetry['traces'], -40);
        }
    }

    writeTelemetry($telemetry);
}

function telemetrySummary(array $telemetry): array
{
    $samples = $telemetry['samples_ms'] ?? [];
    $count = count($samples);

    return [
        'requests_tracked' => $telemetry['requests'] ?? 0,
        'sample_count' => $count,
        'avg_ms' => $count > 0 ? round(array_sum($samples) / $count, 2) : 0.0,
        'p95_ms' => percentile($samples, 95),
        'p99_ms' => percentile($samples, 99),
        'max_ms' => $count > 0 ? round((float) max($samples), 2) : 0.0,
        'last_path' => $telemetry['last_path'] ?? null,
        'last_status' => $telemetry['last_status'] ?? 200,
        'last_updated' => $telemetry['last_updated'] ?? null,
        'successes' => $telemetry['successes'] ?? ['legacy' => 0, 'observable' => 0],
        'failures' => $telemetry['failures'] ?? [
            'legacy' => ['total' => 0, 'by_step' => [], 'by_scenario' => []],
            'observable' => ['total' => 0, 'by_step' => [], 'by_scenario' => []],
        ],
        'routes' => routeMetricsSummary($telemetry),
        'recent_traces' => array_reverse($telemetry['traces'] ?? []),
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
