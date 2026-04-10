<?php

declare(strict_types=1);

function envOr(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function storageDir(): string
{
    $dir = sys_get_temp_dir() . '/pdsl-case05';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

function statePath(): string
{
    return storageDir() . '/resource-state.json';
}

function telemetryPath(): string
{
    return storageDir() . '/telemetry.json';
}

function initialState(): array
{
    return [
        'thresholds' => [
            'warning_retained_kb' => 8192,
            'critical_retained_kb' => 16384,
            'warning_descriptors' => 60,
            'critical_descriptors' => 120,
        ],
        'modes' => [
            'legacy' => [
                'retained_kb' => 768,
                'cache_entries' => 6,
                'descriptor_pressure' => 2,
                'gc_cycles' => 0,
                'last_cleanup_at' => null,
                'last_updated' => null,
            ],
            'optimized' => [
                'retained_kb' => 512,
                'cache_entries' => 4,
                'descriptor_pressure' => 0,
                'gc_cycles' => 1,
                'last_cleanup_at' => gmdate('c'),
                'last_updated' => null,
            ],
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
                'documents_total' => 0,
                'avg_peak_request_kb_samples' => [],
                'avg_retained_after_kb_samples' => [],
                'pressure_counts' => ['healthy' => 0, 'warning' => 0, 'critical' => 0],
                'by_scenario' => [],
            ],
            'optimized' => [
                'successes' => 0,
                'failures' => 0,
                'documents_total' => 0,
                'avg_peak_request_kb_samples' => [],
                'avg_retained_after_kb_samples' => [],
                'pressure_counts' => ['healthy' => 0, 'warning' => 0, 'critical' => 0],
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

function pressureLevel(int $retainedKb, int $descriptorPressure, array $thresholds): string
{
    if ($retainedKb >= (int) $thresholds['critical_retained_kb'] || $descriptorPressure >= (int) $thresholds['critical_descriptors']) {
        return 'critical';
    }

    if ($retainedKb >= (int) $thresholds['warning_retained_kb'] || $descriptorPressure >= (int) $thresholds['warning_descriptors']) {
        return 'warning';
    }

    return 'healthy';
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
            $stats = &$telemetry['modes'][$mode];
            $scenario = (string) ($context['scenario'] ?? 'mixed_pressure');
            $pressure = (string) ($context['pressure_level'] ?? 'healthy');

            $stats['by_scenario'][$scenario] = ($stats['by_scenario'][$scenario] ?? 0) + 1;
            $stats['documents_total'] += (int) ($context['documents'] ?? 0);
            $stats['avg_peak_request_kb_samples'][] = (int) ($context['peak_request_kb'] ?? 0);
            if (count($stats['avg_peak_request_kb_samples']) > 500) {
                $stats['avg_peak_request_kb_samples'] = array_slice($stats['avg_peak_request_kb_samples'], -500);
            }
            $stats['avg_retained_after_kb_samples'][] = (int) ($context['retained_after_kb'] ?? 0);
            if (count($stats['avg_retained_after_kb_samples']) > 500) {
                $stats['avg_retained_after_kb_samples'] = array_slice($stats['avg_retained_after_kb_samples'], -500);
            }
            $stats['pressure_counts'][$pressure] = ($stats['pressure_counts'][$pressure] ?? 0) + 1;

            if (($context['outcome'] ?? 'failure') === 'success') {
                $stats['successes']++;
            } else {
                $stats['failures']++;
            }
        }

        $run = $context;
        $run['status_code'] = $status;
        $run['elapsed_ms'] = round($elapsedMs, 2);
        $run['timestamp_utc'] = gmdate('c');
        $telemetry['runs'][] = $run;
        if (count($telemetry['runs']) > 60) {
            $telemetry['runs'] = array_slice($telemetry['runs'], -60);
        }
    }

    writeTelemetry($telemetry);
}

function telemetrySummary(array $telemetry): array
{
    $samples = $telemetry['samples_ms'] ?? [];
    $count = count($samples);
    $modes = [];

    foreach (($telemetry['modes'] ?? []) as $mode => $stats) {
        $peakSamples = $stats['avg_peak_request_kb_samples'] ?? [];
        $retainedSamples = $stats['avg_retained_after_kb_samples'] ?? [];
        $modes[$mode] = [
            'successes' => (int) ($stats['successes'] ?? 0),
            'failures' => (int) ($stats['failures'] ?? 0),
            'documents_total' => (int) ($stats['documents_total'] ?? 0),
            'avg_peak_request_kb' => count($peakSamples) > 0 ? round(array_sum($peakSamples) / count($peakSamples), 2) : 0.0,
            'avg_retained_after_kb' => count($retainedSamples) > 0 ? round(array_sum($retainedSamples) / count($retainedSamples), 2) : 0.0,
            'pressure_counts' => $stats['pressure_counts'] ?? ['healthy' => 0, 'warning' => 0, 'critical' => 0],
            'by_scenario' => $stats['by_scenario'] ?? [],
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
