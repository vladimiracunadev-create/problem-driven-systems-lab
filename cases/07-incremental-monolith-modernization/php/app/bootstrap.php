<?php

declare(strict_types=1);

function envOr(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function storageDir(): string
{
    $dir = sys_get_temp_dir() . '/pdsl-case07';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

function statePath(): string
{
    return storageDir() . '/modernization-state.json';
}

function telemetryPath(): string
{
    return storageDir() . '/telemetry.json';
}

function initialState(): array
{
    return [
        'migration' => [
            'consumers' => [
                'web' => 0,
                'mobile' => 0,
                'backoffice' => 0,
            ],
            'extracted_module_coverage' => 18,
            'contract_tests' => 12,
            'anti_corruption_layer_enabled' => true,
            'last_release' => null,
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
                'blast_radius_samples' => [],
                'risk_score_samples' => [],
                'by_scenario' => [],
            ],
            'strangler' => [
                'successes' => 0,
                'failures' => 0,
                'blast_radius_samples' => [],
                'risk_score_samples' => [],
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
            $scenario = (string) ($context['scenario'] ?? 'billing_change');
            $modeBucket['by_scenario'][$scenario] = ($modeBucket['by_scenario'][$scenario] ?? 0) + 1;
            appendModeSample($modeBucket, 'blast_radius_samples', (float) ($context['blast_radius_score'] ?? 0));
            appendModeSample($modeBucket, 'risk_score_samples', (float) ($context['risk_score'] ?? 0));

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
        $blastSamples = $bucket['blast_radius_samples'] ?? [];
        $riskSamples = $bucket['risk_score_samples'] ?? [];
        $modes[$mode] = [
            'successes' => (int) ($bucket['successes'] ?? 0),
            'failures' => (int) ($bucket['failures'] ?? 0),
            'avg_blast_radius_score' => count($blastSamples) > 0 ? round(array_sum($blastSamples) / count($blastSamples), 2) : 0.0,
            'avg_risk_score' => count($riskSamples) > 0 ? round(array_sum($riskSamples) / count($riskSamples), 2) : 0.0,
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
