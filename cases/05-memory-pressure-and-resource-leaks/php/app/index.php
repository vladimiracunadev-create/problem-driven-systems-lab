<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$started = microtime(true);
$status = 200;
$skipStoreMetrics = false;
$workflowContext = null;

function scenarioCatalog(): array
{
    return [
        'cache_growth' => [
            'hint' => 'El proceso retiene copias innecesarias de datos.',
            'leak_factor' => 1.45,
            'descriptor_factor' => 0.4,
        ],
        'descriptor_drift' => [
            'hint' => 'El proceso acumula descriptores y handlers sin limpieza.',
            'leak_factor' => 0.7,
            'descriptor_factor' => 1.4,
        ],
        'mixed_pressure' => [
            'hint' => 'Se mezclan buffers retenidos con recursos que no se liberan.',
            'leak_factor' => 1.1,
            'descriptor_factor' => 0.9,
        ],
    ];
}

function requestId(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(4));
}

function allocateBatchMemory(string $mode, int $documents, int $payloadKb): void
{
    $buffers = [];
    for ($i = 0; $i < $documents; $i++) {
        $payload = str_repeat(chr(65 + ($i % 26)), $payloadKb * 1024);

        if ($mode === 'legacy') {
            $buffers[] = $payload;
            $buffers[] = base64_encode($payload);
        } else {
            $buffers[] = substr(hash('sha256', $payload), 0, 16);
            if (count($buffers) > 24) {
                array_shift($buffers);
            }
        }
    }

    unset($buffers, $payload);
}

function runBatch(string $mode, string $scenario, int $documents, int $payloadKb): array
{
    $scenarioMeta = scenarioCatalog()[$scenario];
    $state = readState();
    $modeState = $state['modes'][$mode];
    $thresholds = $state['thresholds'];
    $runId = requestId('run');

    allocateBatchMemory($mode, $documents, $payloadKb);

    $batchKb = $documents * $payloadKb;
    $retainedBeforeKb = (int) ($modeState['retained_kb'] ?? 0);
    $descriptorBefore = (int) ($modeState['descriptor_pressure'] ?? 0);

    if ($mode === 'legacy') {
        $retainedDeltaKb = (int) round($batchKb * (float) $scenarioMeta['leak_factor']);
        $descriptorDelta = max(1, (int) round(($documents / 6) * (float) $scenarioMeta['descriptor_factor']));

        $modeState['retained_kb'] = $retainedBeforeKb + $retainedDeltaKb;
        $modeState['cache_entries'] = (int) ($modeState['cache_entries'] ?? 0) + $documents;
        $modeState['descriptor_pressure'] = $descriptorBefore + $descriptorDelta;
    } else {
        $modeState['retained_kb'] = min(
            3584,
            (int) round(($retainedBeforeKb * 0.45) + max(192, $batchKb * 0.18))
        );
        $modeState['cache_entries'] = min(32, max(4, (int) ceil($documents / 3)));
        $modeState['descriptor_pressure'] = max(0, $descriptorBefore - max(1, (int) ceil($documents / 4)));
        $modeState['gc_cycles'] = (int) ($modeState['gc_cycles'] ?? 0) + 1;
        $modeState['last_cleanup_at'] = gmdate('c');
    }

    $modeState['last_updated'] = gmdate('c');
    $state['modes'][$mode] = $modeState;
    writeState($state);

    $pressureLevel = pressureLevel(
        (int) $modeState['retained_kb'],
        (int) $modeState['descriptor_pressure'],
        $thresholds
    );

    $penaltyMs = (int) round(
        ($documents * 2.8) +
        ($payloadKb * 1.4) +
        (($modeState['retained_kb'] / 256)) +
        (($modeState['descriptor_pressure'] ?? 0) * ($mode === 'legacy' ? 2.1 : 0.7))
    );
    usleep(min(650000, max(30000, $penaltyMs * 1000)));

    $peakRequestKb = (int) ceil(memory_get_peak_usage(true) / 1024);
    $headroomKb = max(0, (int) $thresholds['critical_retained_kb'] - (int) $modeState['retained_kb']);

    $httpStatus = 200;
    $outcome = 'success';
    $message = $mode === 'legacy'
        ? 'Legacy mantiene buffers y recursos sin limpieza agresiva.'
        : 'Optimized limita cache, compacta estado y reduce retencion.';

    if ($mode === 'legacy' && $pressureLevel === 'critical') {
        $httpStatus = 503;
        $outcome = 'failure';
        $message = 'La presion acumulada ya es critica y el proceso deberia reiniciarse o fallara pronto.';
    }

    return [
        'http_status' => $httpStatus,
        'payload' => [
            'mode' => $mode,
            'scenario' => $scenario,
            'status' => $outcome === 'success' ? 'completed' : 'degraded',
            'message' => $message,
            'run_id' => $runId,
            'documents' => $documents,
            'payload_kb' => $payloadKb,
            'peak_request_kb' => $peakRequestKb,
            'pressure_level' => $pressureLevel,
            'state_before' => [
                'retained_kb' => $retainedBeforeKb,
                'descriptor_pressure' => $descriptorBefore,
            ],
            'state_after' => [
                'retained_kb' => (int) $modeState['retained_kb'],
                'cache_entries' => (int) $modeState['cache_entries'],
                'descriptor_pressure' => (int) $modeState['descriptor_pressure'],
                'gc_cycles' => (int) ($modeState['gc_cycles'] ?? 0),
                'last_cleanup_at' => $modeState['last_cleanup_at'] ?? null,
            ],
            'headroom_before_critical_kb' => $headroomKb,
            'scenario_hint' => $scenarioMeta['hint'],
        ],
        'context' => [
            'mode' => $mode,
            'scenario' => $scenario,
            'outcome' => $outcome,
            'documents' => $documents,
            'payload_kb' => $payloadKb,
            'peak_request_kb' => $peakRequestKb,
            'retained_after_kb' => (int) $modeState['retained_kb'],
            'descriptor_pressure' => (int) $modeState['descriptor_pressure'],
            'pressure_level' => $pressureLevel,
            'run_id' => $runId,
        ],
    ];
}

function stateSummary(): array
{
    $state = readState();
    $thresholds = $state['thresholds'];
    $summary = [
        'thresholds' => $thresholds,
        'modes' => [],
    ];

    foreach (($state['modes'] ?? []) as $mode => $modeState) {
        $summary['modes'][$mode] = [
            'retained_kb' => (int) ($modeState['retained_kb'] ?? 0),
            'cache_entries' => (int) ($modeState['cache_entries'] ?? 0),
            'descriptor_pressure' => (int) ($modeState['descriptor_pressure'] ?? 0),
            'gc_cycles' => (int) ($modeState['gc_cycles'] ?? 0),
            'last_cleanup_at' => $modeState['last_cleanup_at'] ?? null,
            'last_updated' => $modeState['last_updated'] ?? null,
            'pressure_level' => pressureLevel(
                (int) ($modeState['retained_kb'] ?? 0),
                (int) ($modeState['descriptor_pressure'] ?? 0),
                $thresholds
            ),
        ];
    }

    return $summary;
}

function diagnosticsSummary(): array
{
    return [
        'case' => '05 - Memory pressure and resource leaks',
        'stack' => envOr('APP_STACK', 'PHP 8.3'),
        'state' => stateSummary(),
        'metrics' => telemetrySummary(readTelemetry()),
        'interpretation' => [
            'legacy' => 'Legacy simula un proceso largo que conserva buffers y deja crecer la presion hasta volverla visible.',
            'optimized' => 'Optimized limita el tamano util del cache, limpia recursos y mantiene el proceso dentro de umbrales mas estables.',
        ],
    ];
}

function renderPrometheusMetrics(): string
{
    $summary = telemetrySummary(readTelemetry());
    $state = stateSummary();
    $lines = [];
    $lines[] = '# HELP app_requests_total Total de requests observados por el laboratorio.';
    $lines[] = '# TYPE app_requests_total counter';
    $lines[] = 'app_requests_total ' . ($summary['requests_tracked'] ?? 0);

    $lines[] = '# HELP app_request_latency_ms Latencia agregada de requests en milisegundos.';
    $lines[] = '# TYPE app_request_latency_ms gauge';
    $lines[] = 'app_request_latency_ms{stat="avg"} ' . ($summary['avg_ms'] ?? 0);
    $lines[] = 'app_request_latency_ms{stat="p95"} ' . ($summary['p95_ms'] ?? 0);
    $lines[] = 'app_request_latency_ms{stat="p99"} ' . ($summary['p99_ms'] ?? 0);

    foreach (($summary['modes'] ?? []) as $mode => $stats) {
        $label = prometheusLabel((string) $mode);
        $lines[] = 'app_flow_success_total{mode="' . $label . '"} ' . ($stats['successes'] ?? 0);
        $lines[] = 'app_flow_failure_total{mode="' . $label . '"} ' . ($stats['failures'] ?? 0);
        $lines[] = 'app_flow_avg_peak_request_kb{mode="' . $label . '"} ' . ($stats['avg_peak_request_kb'] ?? 0);
        $lines[] = 'app_flow_avg_retained_after_kb{mode="' . $label . '"} ' . ($stats['avg_retained_after_kb'] ?? 0);
    }

    foreach (($state['modes'] ?? []) as $mode => $modeState) {
        $label = prometheusLabel((string) $mode);
        $lines[] = 'app_retained_memory_kb{mode="' . $label . '"} ' . ($modeState['retained_kb'] ?? 0);
        $lines[] = 'app_descriptor_pressure{mode="' . $label . '"} ' . ($modeState['descriptor_pressure'] ?? 0);
        $lines[] = 'app_pressure_level{mode="' . $label . '",level="' . prometheusLabel((string) ($modeState['pressure_level'] ?? 'healthy')) . '"} ' . (($modeState['pressure_level'] ?? 'healthy') === 'critical' ? 2 : (($modeState['pressure_level'] ?? 'healthy') === 'warning' ? 1 : 0));
    }

    return implode("\n", $lines) . "\n";
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '', $query);

try {
    // ── Browser UI ───────────────────────────────────────────────────────────
    if (($uri === '/' || $uri === '') && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html')) {
        require __DIR__ . '/ui.php';
        exit;
    }
    // ─────────────────────────────────────────────────────────────────────────

    if ($uri === '/' || $uri === '') {
        $payload = [
            'lab' => 'Problem-Driven Systems Lab',
            'case' => '05 - Memory pressure and resource leaks',
            'stack' => envOr('APP_STACK', 'PHP 8.3'),
            'goal' => 'Comparar un proceso que retiene memoria y recursos con otro que compacta estado y limita el crecimiento.',
            'routes' => [
                '/health' => 'Estado basico del servicio.',
                '/batch-legacy?scenario=mixed_pressure&documents=24&payload_kb=64' => 'Simula buffers retenidos y recursos sin liberar.',
                '/batch-optimized?scenario=mixed_pressure&documents=24&payload_kb=64' => 'Simula procesamiento con limpieza y limites.',
                '/state' => 'Estado actual acumulado por modo.',
                '/runs?limit=10' => 'Ultimas ejecuciones observadas.',
                '/diagnostics/summary' => 'Resumen de presion, memoria y tendencia.',
                '/metrics' => 'Metricas JSON del laboratorio.',
                '/metrics-prometheus' => 'Metricas en formato Prometheus.',
                '/reset-lab' => 'Reinicia estado y metricas.',
            ],
            'allowed_scenarios' => array_keys(scenarioCatalog()),
        ];
    } elseif ($uri === '/health') {
        $payload = ['status' => 'ok', 'stack' => envOr('APP_STACK', 'PHP 8.3')];
    } elseif ($uri === '/batch-legacy' || $uri === '/batch-optimized') {
        $mode = $uri === '/batch-legacy' ? 'legacy' : 'optimized';
        $scenario = (string) ($query['scenario'] ?? 'mixed_pressure');
        if (!array_key_exists($scenario, scenarioCatalog())) {
            $scenario = 'mixed_pressure';
        }
        $documents = clampInt((int) ($query['documents'] ?? 24), 1, 60);
        $payloadKb = clampInt((int) ($query['payload_kb'] ?? 64), 8, 128);

        $result = runBatch($mode, $scenario, $documents, $payloadKb);
        $status = (int) $result['http_status'];
        $workflowContext = $result['context'];
        $payload = $result['payload'];
    } elseif ($uri === '/state') {
        $payload = stateSummary();
    } elseif ($uri === '/runs') {
        $limit = clampInt((int) ($query['limit'] ?? 10), 1, 50);
        $payload = [
            'limit' => $limit,
            'runs' => array_slice(telemetrySummary(readTelemetry())['recent_runs'], 0, $limit),
        ];
    } elseif ($uri === '/diagnostics/summary') {
        $payload = diagnosticsSummary();
    } elseif ($uri === '/metrics') {
        $payload = array_merge(
            [
                'case' => '05 - Memory pressure and resource leaks',
                'stack' => envOr('APP_STACK', 'PHP 8.3'),
            ],
            telemetrySummary(readTelemetry())
        );
    } elseif ($uri === '/metrics-prometheus') {
        $skipStoreMetrics = true;
        http_response_code(200);
        header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
        echo renderPrometheusMetrics();
        return;
    } elseif ($uri === '/reset-lab') {
        resetLabState();
        $payload = ['status' => 'reset', 'message' => 'Estado de recursos y metricas reiniciados.'];
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

$elapsedMs = round((microtime(true) - $started) * 1000, 2);
if (!$skipStoreMetrics && $uri !== '/metrics' && $uri !== '/reset-lab') {
    recordRequestTelemetry($uri, $status, $elapsedMs, $workflowContext);
}
$payload['elapsed_ms'] = $elapsedMs;
$payload['timestamp_utc'] = gmdate('c');
$payload['pid'] = getmypid();
jsonResponse($status, $payload);
