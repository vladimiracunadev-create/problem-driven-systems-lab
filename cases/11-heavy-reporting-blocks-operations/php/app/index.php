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
        'end_of_month' => ['legacy_load' => 52, 'legacy_lock' => 34, 'isolated_queue' => 18, 'isolated_lag' => 22, 'hint' => 'Cierre de mes con gran volumen y prioridad financiera.'],
        'finance_audit' => ['legacy_load' => 46, 'legacy_lock' => 28, 'isolated_queue' => 14, 'isolated_lag' => 18, 'hint' => 'Auditoria que necesita cortes historicos consistentes.'],
        'ad_hoc_export' => ['legacy_load' => 35, 'legacy_lock' => 20, 'isolated_queue' => 10, 'isolated_lag' => 12, 'hint' => 'Export solicitado por negocio sin planificacion previa.'],
        'mixed_peak' => ['legacy_load' => 62, 'legacy_lock' => 38, 'isolated_queue' => 24, 'isolated_lag' => 24, 'hint' => 'Reporting pesado compitiendo justo cuando la operacion transaccional esta en pico.'],
    ];
}

function requestId(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(4));
}

function pressureLevel(array $state): string
{
    $load = (int) ($state['primary_load'] ?? 0);
    $locks = (int) ($state['lock_pressure'] ?? 0);
    if ($load >= 90 || $locks >= 75) {
        return 'critical';
    }
    if ($load >= 65 || $locks >= 45) {
        return 'warning';
    }

    return 'healthy';
}

function stateSummary(): array
{
    $state = readState()['reporting'];
    $state['pressure_level'] = pressureLevel($state);
    return $state;
}

function runReportFlow(string $mode, string $scenario, int $rows): array
{
    $scenarioMeta = scenarioCatalog()[$scenario];
    $state = readState();
    $reporting = &$state['reporting'];
    $flowId = requestId('report');
    $primaryBefore = (int) $reporting['primary_load'];
    $lockBefore = (int) $reporting['lock_pressure'];

    $critical = false;
    $httpStatus = 200;
    $errorMessage = null;

    try {
        $lockFile = sys_get_temp_dir() . '/db_mock_table.lock';
        $fp = fopen($lockFile, 'c+');

        if ($mode === 'legacy') {
            flock($fp, LOCK_EX); // Toma lock exclusivo
            $reporting['primary_load'] = min(100, $primaryBefore + (int) $scenarioMeta['legacy_load'] + (int) floor($rows / 150000));
            // Suspendemos I/O real emulando un volcado DML masivo que bloquea la tabla "física" real
            usleep((int) (1200 + min(3000, floor($rows / 1000))) * 1000);
            flock($fp, LOCK_UN);
        } else {
            // Isolated read
            usleep(150 * 1000); 
            $reporting['queue_depth'] = min(120, (int) $reporting['queue_depth'] + (int) $scenarioMeta['isolated_queue']);
            $reporting['replica_lag_s'] = min(180, (int) $reporting['replica_lag_s'] + (int) $scenarioMeta['isolated_lag']);
        }
        fclose($fp);
    } catch (\Throwable $e) {
        $critical = true;
        $httpStatus = 503;
        $errorMessage = "Error I/O: " . $e->getMessage();
    }

    $reporting['total_exports'] = (int) $reporting['total_exports'] + 1;
    $reporting['last_report_at'] = gmdate('c');
    writeState($state);

    $summary = stateSummary();
    if ($mode === 'legacy' && $summary['pressure_level'] === 'critical') {
        $critical = true;
        $httpStatus = 503;
        $errorMessage = 'Critical Pressure Reached';
    }
    $outcome = $httpStatus >= 400 ? 'failure' : 'success';
    $opsImpactMs = (int) round(($summary['primary_load'] * 3.1) + ($summary['lock_pressure'] * 2.4));

    $payload = [
        'mode' => $mode,
        'scenario' => $scenario,
        'rows' => $rows,
        'status' => $httpStatus >= 400 ? 'failed' : 'completed',
        'message' => $mode === 'legacy' && $httpStatus >= 400
            ? $errorMessage
            : ($mode === 'legacy' ? 'El lock bloqueó I/O físico durante segundos.' : 'Isolated liberó el lock y mandó tarea a background.'),
        'flow_id' => $flowId,
        'primary_load_before' => $primaryBefore,
        'primary_load_after' => $summary['primary_load'],
        'lock_pressure_before' => $lockBefore,
        'lock_pressure_after' => $summary['lock_pressure'],
        'replica_lag_s' => $summary['replica_lag_s'],
        'queue_depth' => $summary['queue_depth'],
        'ops_latency_impact_ms' => $opsImpactMs,
        'scenario_hint' => $scenarioMeta['hint'],
        'reporting_state' => $summary,
    ];

    if ($httpStatus >= 400) {
        $payload['error'] = 'El mecanismo de lock bloqueó duramente los escritores concurrentes.';
    }

    return [
        'http_status' => $httpStatus,
        'payload' => $payload,
        'context' => [
            'mode' => $mode,
            'scenario' => $scenario,
            'rows' => $rows,
            'outcome' => $outcome,
            'primary_load_after' => (float) $summary['primary_load'],
            'ops_latency_impact_ms' => (float) $opsImpactMs,
            'replica_lag_s' => (float) $summary['replica_lag_s'],
            'flow_id' => $flowId,
        ],
    ];
}

function runWriteFlow(int $orders): array
{
    $state = readState();
    $reporting = &$state['reporting'];
    $flowId = requestId('write');
    $primaryLoad = (int) $reporting['primary_load'];
    $lockPressure = (int) $reporting['lock_pressure'];
    $latencyMs = (int) round(35 + ($orders * 2.1) + ($primaryLoad * 1.6) + ($lockPressure * 1.3));
    
    $httpStatus = 200;
    
    // Test físico: intentamos bloquear la tabla para transacciones
    $lockFile = sys_get_temp_dir() . '/db_mock_table.lock';
    $fp = fopen($lockFile, 'c+');
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        // Otro proceso tiene el Lock Exclusivo (probablemente Reporting)
        $httpStatus = 503;
    } else {
        if ($primaryLoad >= 90 || $lockPressure >= 75) {
            $httpStatus = 503;
        } else {
            usleep(min(500000, $latencyMs * 1000));
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    $outcome = $httpStatus >= 400 ? 'failure' : 'success';

    $reporting['primary_load'] = min(100, $primaryLoad + max(1, (int) ceil($orders / 8)));
    $reporting['total_operational_writes'] = (int) $reporting['total_operational_writes'] + $orders;
    writeState($state);

    $summary = stateSummary();
    $payload = [
        'mode' => 'operations',
        'scenario' => 'write_path',
        'orders' => $orders,
        'status' => $httpStatus >= 400 ? 'failed' : 'completed',
        'message' => $httpStatus >= 400
            ? 'La operacion ya siente el bloqueo del reporting y la escritura queda degradada.'
            : 'La escritura sigue viva, pero el costo ya refleja la presion que deja el reporting sobre la operacion.',
        'flow_id' => $flowId,
        'write_latency_ms' => $latencyMs,
        'reporting_state' => $summary,
    ];

    return [
        'http_status' => $httpStatus,
        'payload' => $payload,
        'context' => [
            'mode' => 'operations',
            'scenario' => 'write_path',
            'orders' => $orders,
            'outcome' => $outcome,
            'primary_load_after' => (float) $summary['primary_load'],
            'ops_latency_impact_ms' => (float) $latencyMs,
            'replica_lag_s' => (float) $summary['replica_lag_s'],
            'flow_id' => $flowId,
        ],
    ];
}

function diagnosticsSummary(): array
{
    return [
        'case' => '11 - Heavy reporting blocks operations',
        'stack' => envOr('APP_STACK', 'PHP 8.3'),
        'reporting' => stateSummary(),
        'metrics' => telemetrySummary(readTelemetry()),
        'interpretation' => [
            'legacy' => 'Legacy mezcla analitica y operacion sobre el mismo primario, por eso cada export pesado castiga tambien las escrituras.',
            'isolated' => 'Isolated mueve presion a cola, replica o snapshot para que la operacion transaccional conserve mas aire.',
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

    foreach (($summary['modes'] ?? []) as $mode => $bucket) {
        $label = prometheusLabel((string) $mode);
        $lines[] = 'app_reporting_success_total{mode="' . $label . '"} ' . ($bucket['successes'] ?? 0);
        $lines[] = 'app_reporting_failure_total{mode="' . $label . '"} ' . ($bucket['failures'] ?? 0);
        $lines[] = 'app_reporting_avg_primary_load{mode="' . $label . '"} ' . ($bucket['avg_primary_load_after'] ?? 0);
        $lines[] = 'app_reporting_avg_ops_impact_ms{mode="' . $label . '"} ' . ($bucket['avg_ops_latency_impact_ms'] ?? 0);
        $lines[] = 'app_reporting_avg_replica_lag_s{mode="' . $label . '"} ' . ($bucket['avg_replica_lag_s'] ?? 0);
    }

    $lines[] = 'app_primary_load ' . ($state['primary_load'] ?? 0);
    $lines[] = 'app_lock_pressure ' . ($state['lock_pressure'] ?? 0);
    $lines[] = 'app_replica_lag_seconds ' . ($state['replica_lag_s'] ?? 0);
    $lines[] = 'app_reporting_queue_depth ' . ($state['queue_depth'] ?? 0);

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
            'case' => '11 - Heavy reporting blocks operations',
            'stack' => envOr('APP_STACK', 'PHP 8.3'),
            'goal' => 'Comparar reporting directo sobre el primario contra una ruta aislada que protege la operacion.',
            'routes' => [
                '/health' => 'Estado basico del servicio.',
                '/report-legacy?scenario=end_of_month&rows=600000' => 'Ejecuta el reporte sobre la operacion transaccional.',
                '/report-isolated?scenario=end_of_month&rows=600000' => 'Ejecuta el reporte por una ruta mas aislada.',
                '/order-write?orders=25' => 'Simula la escritura operativa mientras el reporting deja carga acumulada.',
                '/reporting/state' => 'Estado actual de carga, locks, replica y cola.',
                '/activity?limit=10' => 'Ultimas actividades observadas por el laboratorio.',
                '/diagnostics/summary' => 'Resumen de competencia entre reporting y operacion.',
                '/metrics' => 'Metricas JSON del laboratorio.',
                '/metrics-prometheus' => 'Metricas en formato Prometheus.',
                '/reset-lab' => 'Reinicia estado y metricas.',
            ],
            'allowed_scenarios' => array_keys(scenarioCatalog()),
        ];
    } elseif ($uri === '/health') {
        $payload = ['status' => 'ok', 'stack' => envOr('APP_STACK', 'PHP 8.3')];
    } elseif ($uri === '/report-legacy' || $uri === '/report-isolated') {
        $mode = $uri === '/report-legacy' ? 'legacy' : 'isolated';
        $scenario = (string) ($query['scenario'] ?? 'end_of_month');
        if (!array_key_exists($scenario, scenarioCatalog())) {
            $scenario = 'end_of_month';
        }
        $rows = clampInt((int) ($query['rows'] ?? 600000), 50000, 1500000);

        $result = runReportFlow($mode, $scenario, $rows);
        $status = (int) $result['http_status'];
        $workflowContext = $result['context'];
        $payload = $result['payload'];
    } elseif ($uri === '/order-write') {
        $orders = clampInt((int) ($query['orders'] ?? 25), 1, 500);
        $result = runWriteFlow($orders);
        $status = (int) $result['http_status'];
        $workflowContext = $result['context'];
        $payload = $result['payload'];
    } elseif ($uri === '/reporting/state') {
        $payload = stateSummary();
    } elseif ($uri === '/activity') {
        $limit = clampInt((int) ($query['limit'] ?? 10), 1, 50);
        $payload = [
            'limit' => $limit,
            'activity' => array_slice(telemetrySummary(readTelemetry())['recent_runs'], 0, $limit),
        ];
    } elseif ($uri === '/diagnostics/summary') {
        $payload = diagnosticsSummary();
    } elseif ($uri === '/metrics') {
        $payload = array_merge(
            [
                'case' => '11 - Heavy reporting blocks operations',
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
        $payload = ['status' => 'reset', 'message' => 'Estado de reporting y metricas reiniciados.'];
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
