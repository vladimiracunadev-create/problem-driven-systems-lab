<?php

declare(strict_types=1);

function envOr(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function storageDir(): string
{
    $dir = sys_get_temp_dir() . '/pdsl-case09';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

function statePath(): string
{
    return storageDir() . '/integration-state.json';
}

function telemetryPath(): string
{
    return storageDir() . '/telemetry.json';
}

function initialState(): array
{
    return [
        'integration' => [
            'provider_name' => 'catalog-hub',
            'rate_limit_budget' => 12,
            'cache' => [
                'snapshot_version' => '2026.04',
                'cached_skus' => 48,
                'age_seconds' => 90,
            ],
            'contract' => [
                'provider_version' => 'v1',
                'adapter_version' => 'v1',
                'schema_mappings' => 3,
            ],
            'quarantine_events' => 0,
            'last_successful_sync' => null,
        ],
    ];
}

function readState(): array
{
    $file = statePath();
    if (!file_exists($file)) {
        return initialState();
    }

    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        return initialState();
    }

    return array_replace_recursive(initialState(), $data);
}

function writeState(array $state): void
{
    file_put_contents(statePath(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
        'status_counts' => [
            '2xx' => 0,
            '4xx' => 0,
            '5xx' => 0,
        ],
        'modes' => [
            'legacy' => [
                'successes' => 0,
                'failures' => 0,
                'cached_response_samples' => [],
                'schema_protection_samples' => [],
                'quota_saved_samples' => [],
                'by_scenario' => [],
            ],
            'hardened' => [
                'successes' => 0,
                'failures' => 0,
                'cached_response_samples' => [],
                'schema_protection_samples' => [],
                'quota_saved_samples' => [],
                'by_scenario' => [],
            ],
        ],
        'runs' => [],
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

function resetLabState(): void
{
    writeState(initialState());
    writeTelemetry(initialTelemetry());
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

function appendModeSample(array &$bucket, string $field, float $value): void
{
    $bucket[$field][] = round($value, 2);
    if (count($bucket[$field]) > 500) {
        $bucket[$field] = array_slice($bucket[$field], -500);
    }
}

function recordRequestTelemetry(string $uri, int $status, float $elapsedMs, ?array $context): void
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

    $bucket = bucketKeyForStatus($status);
    $telemetry['status_counts'][$bucket] = ($telemetry['status_counts'][$bucket] ?? 0) + 1;
    $telemetry['last_path'] = $uri;
    $telemetry['last_status'] = $status;
    $telemetry['last_updated'] = gmdate('c');

    if ($context !== null) {
        $mode = (string) ($context['mode'] ?? 'legacy');
        if (isset($telemetry['modes'][$mode])) {
            $modeBucket = &$telemetry['modes'][$mode];
            $scenario = (string) ($context['scenario'] ?? 'ok');
            $modeBucket['by_scenario'][$scenario] = ($modeBucket['by_scenario'][$scenario] ?? 0) + 1;
            appendModeSample($modeBucket, 'cached_response_samples', (float) ($context['cached_response'] ?? 0));
            appendModeSample($modeBucket, 'schema_protection_samples', (float) ($context['schema_protected'] ?? 0));
            appendModeSample($modeBucket, 'quota_saved_samples', (float) ($context['quota_saved'] ?? 0));

            if (($context['outcome'] ?? 'failure') === 'success') {
                $modeBucket['successes']++;
            } else {
                $modeBucket['failures']++;
            }
        }

        $run = $context;
        $run['status_code'] = $status;
        $run['elapsed_ms'] = round($elapsedMs, 2);
        $run['timestamp_utc'] = gmdate('c');
        $telemetry['runs'][] = $run;
        if (count($telemetry['runs']) > 50) {
            $telemetry['runs'] = array_slice($telemetry['runs'], -50);
        }
    }

    writeTelemetry($telemetry);
}

function telemetrySummary(array $telemetry): array
{
    $samples = $telemetry['samples_ms'] ?? [];
    $count = count($samples);
    $modes = [];

    foreach (($telemetry['modes'] ?? []) as $mode => $bucket) {
        $cachedSamples = $bucket['cached_response_samples'] ?? [];
        $schemaSamples = $bucket['schema_protection_samples'] ?? [];
        $quotaSamples = $bucket['quota_saved_samples'] ?? [];
        $modes[$mode] = [
            'successes' => (int) ($bucket['successes'] ?? 0),
            'failures' => (int) ($bucket['failures'] ?? 0),
            'avg_cached_response' => count($cachedSamples) > 0 ? round(array_sum($cachedSamples) / count($cachedSamples), 2) : 0.0,
            'avg_schema_protection' => count($schemaSamples) > 0 ? round(array_sum($schemaSamples) / count($schemaSamples), 2) : 0.0,
            'avg_quota_saved' => count($quotaSamples) > 0 ? round(array_sum($quotaSamples) / count($quotaSamples), 2) : 0.0,
            'by_scenario' => $bucket['by_scenario'] ?? [],
        ];
    }

    return [
        'requests_tracked' => (int) ($telemetry['requests'] ?? 0),
        'sample_count' => $count,
        'avg_ms' => $count > 0 ? round(array_sum($samples) / $count, 2) : 0.0,
        'p95_ms' => percentile($samples, 95),
        'p99_ms' => percentile($samples, 99),
        'max_ms' => $count > 0 ? round((float) max($samples), 2) : 0.0,
        'last_path' => $telemetry['last_path'] ?? null,
        'last_status' => (int) ($telemetry['last_status'] ?? 200),
        'last_updated' => $telemetry['last_updated'] ?? null,
        'status_counts' => $telemetry['status_counts'] ?? ['2xx' => 0, '4xx' => 0, '5xx' => 0],
        'modes' => $modes,
        'routes' => routeMetricsSummary($telemetry),
        'recent_runs' => array_reverse($telemetry['runs'] ?? []),
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
