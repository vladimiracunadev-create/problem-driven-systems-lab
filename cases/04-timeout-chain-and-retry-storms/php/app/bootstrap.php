<?php

declare(strict_types=1);

function envOr(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function storageDir(): string
{
    $dir = sys_get_temp_dir() . '/pdsl-case04';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

function telemetryPath(): string
{
    return storageDir() . '/telemetry.json';
}

function dependencyStatePath(): string
{
    return storageDir() . '/dependency-state.json';
}

function initialDependencyState(): array
{
    return [
        'provider' => [
            'name' => 'carrier-gateway',
            'consecutive_failures' => 0,
            'opened_until' => null,
            'last_outcome' => 'unknown',
            'last_latency_ms' => 0.0,
            'last_updated' => null,
            'open_events' => 0,
            'short_circuit_count' => 0,
            'fallback_quote' => [
                'quote_id' => 'cached-quote-seed',
                'amount' => 47.8,
                'currency' => 'USD',
                'source' => 'cached',
                'cached_at' => gmdate('c'),
            ],
        ],
    ];
}

function readDependencyState(): array
{
    $file = dependencyStatePath();
    if (!file_exists($file)) {
        return initialDependencyState();
    }

    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        return initialDependencyState();
    }

    return array_replace_recursive(initialDependencyState(), $data);
}

function writeDependencyState(array $state): void
{
    file_put_contents(dependencyStatePath(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
                'attempts_total' => 0,
                'retries_total' => 0,
                'timeouts_total' => 0,
                'fallbacks_used' => 0,
                'circuit_opens' => 0,
                'short_circuits' => 0,
                'by_scenario' => [],
            ],
            'resilient' => [
                'successes' => 0,
                'failures' => 0,
                'attempts_total' => 0,
                'retries_total' => 0,
                'timeouts_total' => 0,
                'fallbacks_used' => 0,
                'circuit_opens' => 0,
                'short_circuits' => 0,
                'by_scenario' => [],
            ],
        ],
        'incidents' => [],
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
    writeTelemetry(initialTelemetry());
    writeDependencyState(initialDependencyState());
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
            $modeStats = &$telemetry['modes'][$mode];
            $scenario = (string) ($context['scenario'] ?? 'ok');
            $modeStats['by_scenario'][$scenario] = ($modeStats['by_scenario'][$scenario] ?? 0) + 1;
            $modeStats['attempts_total'] += (int) ($context['attempts'] ?? 0);
            $modeStats['retries_total'] += (int) ($context['retries'] ?? 0);
            $modeStats['timeouts_total'] += (int) ($context['timeout_count'] ?? 0);
            $modeStats['fallbacks_used'] += !empty($context['fallback_used']) ? 1 : 0;
            $modeStats['circuit_opens'] += !empty($context['circuit_opened']) ? 1 : 0;
            $modeStats['short_circuits'] += !empty($context['short_circuited']) ? 1 : 0;

            if (($context['outcome'] ?? 'failure') === 'success') {
                $modeStats['successes']++;
            } else {
                $modeStats['failures']++;
            }
        }

        $incident = $context;
        $incident['status_code'] = $status;
        $incident['elapsed_ms'] = round($elapsedMs, 2);
        $incident['timestamp_utc'] = gmdate('c');
        $telemetry['incidents'][] = $incident;
        if (count($telemetry['incidents']) > 50) {
            $telemetry['incidents'] = array_slice($telemetry['incidents'], -50);
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
        $flows = (int) (($stats['successes'] ?? 0) + ($stats['failures'] ?? 0));
        $modes[$mode] = [
            'successes' => (int) ($stats['successes'] ?? 0),
            'failures' => (int) ($stats['failures'] ?? 0),
            'avg_attempts_per_flow' => $flows > 0 ? round(((float) ($stats['attempts_total'] ?? 0)) / $flows, 2) : 0.0,
            'avg_retries_per_flow' => $flows > 0 ? round(((float) ($stats['retries_total'] ?? 0)) / $flows, 2) : 0.0,
            'timeouts_total' => (int) ($stats['timeouts_total'] ?? 0),
            'fallbacks_used' => (int) ($stats['fallbacks_used'] ?? 0),
            'circuit_opens' => (int) ($stats['circuit_opens'] ?? 0),
            'short_circuits' => (int) ($stats['short_circuits'] ?? 0),
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
        'recent_incidents' => array_reverse($telemetry['incidents'] ?? []),
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
