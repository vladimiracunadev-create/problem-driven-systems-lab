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
        'basic_crud' => [
            'complex' => ['status' => 200, 'services' => 8, 'cost' => 5400, 'lead' => 11, 'coordination' => 7, 'fit' => 18],
            'right_sized' => ['status' => 200, 'services' => 2, 'cost' => 850, 'lead' => 3, 'coordination' => 2, 'fit' => 88],
            'hint' => 'El problema real es simple y no justifica una coreografia de servicios.',
        ],
        'small_campaign' => [
            'complex' => ['status' => 200, 'services' => 9, 'cost' => 6200, 'lead' => 14, 'coordination' => 8, 'fit' => 22],
            'right_sized' => ['status' => 200, 'services' => 3, 'cost' => 1100, 'lead' => 4, 'coordination' => 2, 'fit' => 82],
            'hint' => 'El alcance de negocio sigue siendo acotado y pide velocidad mas que sofisticacion.',
        ],
        'audit_needed' => [
            'complex' => ['status' => 200, 'services' => 7, 'cost' => 5000, 'lead' => 9, 'coordination' => 6, 'fit' => 44],
            'right_sized' => ['status' => 200, 'services' => 3, 'cost' => 1350, 'lead' => 5, 'coordination' => 3, 'fit' => 79],
            'hint' => 'Incluso con auditoria, puede resolverse con menos capas y menos costo operativo.',
        ],
        'seasonal_peak' => [
            'complex' => ['status' => 502, 'services' => 10, 'cost' => 6800, 'lead' => 16, 'coordination' => 9, 'fit' => 30],
            'right_sized' => ['status' => 200, 'services' => 4, 'cost' => 1800, 'lead' => 6, 'coordination' => 3, 'fit' => 76],
            'hint' => 'La sobrearquitectura introduce mas puntos de falla justo cuando el negocio pide foco y throughput.',
        ],
    ];
}

function requestId(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(4));
}

function stateSummary(): array
{
    $state = readState();
    return [
        'decision_log_count' => (int) ($state['architecture']['decision_log_count'] ?? 0),
        'simplification_backlog' => (int) ($state['architecture']['simplification_backlog'] ?? 0),
        'last_feature_release' => $state['architecture']['last_feature_release'] ?? null,
        'baselines' => $state['architecture']['baselines'] ?? [],
    ];
}

function runFeatureFlow(string $mode, string $scenario, int $accounts): array
{
    $scenarioMeta = scenarioCatalog()[$scenario][$mode];
    $state = readState();
    $flowId = requestId('arch');
    $servicesTouched = (int) $scenarioMeta['services'] + (int) floor($accounts / 250);
    $monthlyCost = (float) $scenarioMeta['cost'] + round($accounts * ($mode === 'complex' ? 2.8 : 0.7), 2);
    $leadTimeDays = (float) $scenarioMeta['lead'];
    $coordinationPoints = (int) $scenarioMeta['coordination'];
    $problemFitScore = (int) $scenarioMeta['fit'];
    $httpStatus = (int) $scenarioMeta['status'];
    $errorMessage = null;
    try {
        // En lugar de un delay falso, hacemos que el CPU y la Memoria realmente sufran iterando DTOs
        if ($mode === 'complex') {
            $dummyEntities = array_fill(0, min(8000, max(100, $accounts * 15)), ['id' => random_int(100, 999)]);
            for ($hop = 0; $hop < $servicesTouched; $hop++) {
                // Serialización pura: Simulando pase de datos por red inter-servicios
                $json = json_encode($dummyEntities);
                $dummyEntities = json_decode($json, true);
                // Simulación de hidratación excesiva de ORMs
                $dummyEntities = array_map(fn($v) => (object)$v, $dummyEntities);
            }
            $val = $dummyEntities[0]->id;
            
            if ($scenario === 'seasonal_peak') {
                throw new \RuntimeException("Gateway Timeout: Demasiados hops serializando en modo complejo bajo pico.");
            }
        } else {
            // Enfoque Right-Sized
            $directData = array_fill(0, min(8000, max(100, $accounts * 15)), ['id' => random_int(100, 999)]);
            $val = $directData[0]['id']; // Extracción lineal plana (O(1))
        }
    } catch (\Throwable $e) {
        $httpStatus = 502;
        $errorMessage = "Error Crítico PHP: " . $e->getMessage();
    }

    $state['architecture']['decision_log_count'] = (int) $state['architecture']['decision_log_count'] + 1;
    if ($mode === 'right_sized') {
        $state['architecture']['simplification_backlog'] = max(0, (int) $state['architecture']['simplification_backlog'] - 1);
    }
    $state['architecture']['last_feature_release'] = $mode . '-' . gmdate('Ymd-His');
    writeState($state);

    $summary = stateSummary();
    $outcome = $httpStatus >= 400 ? 'failure' : 'success';
    $payload = [
        'mode' => $mode,
        'scenario' => $scenario,
        'accounts' => $accounts,
        'status' => $httpStatus >= 400 ? 'failed' : 'completed',
        'message' => $mode === 'complex' && $httpStatus >= 400
            ? $errorMessage
            : ($mode === 'complex' ? 'La solucion encarece innecesariamente con CPU overhead.' : 'Right-sized resuelve la necesidad directo con O(1).'),
        'flow_id' => $flowId,
        'services_touched' => $servicesTouched,
        'monthly_cost_usd' => $monthlyCost,
        'lead_time_days' => $leadTimeDays,
        'coordination_points' => $coordinationPoints,
        'problem_fit_score' => $problemFitScore,
        'scenario_hint' => scenarioCatalog()[$scenario]['hint'],
        'architecture_state' => $summary,
    ];

    if ($httpStatus >= 400) {
        $payload['error'] = 'La complejidad agregada introdujo demasiados puntos de coordinacion y fallo para el valor real del caso.';
    }

    return [
        'http_status' => $httpStatus,
        'payload' => $payload,
        'context' => [
            'mode' => $mode,
            'scenario' => $scenario,
            'accounts' => $accounts,
            'outcome' => $outcome,
            'monthly_cost_usd' => $monthlyCost,
            'services_touched' => $servicesTouched,
            'lead_time_days' => $leadTimeDays,
            'coordination_points' => $coordinationPoints,
            'flow_id' => $flowId,
        ],
    ];
}

function diagnosticsSummary(): array
{
    return [
        'case' => '10 - Expensive architecture for simple needs',
        'stack' => envOr('APP_STACK', 'PHP 8.3'),
        'architecture' => stateSummary(),
        'metrics' => telemetrySummary(readTelemetry()),
        'interpretation' => [
            'complex' => 'Complex convierte una necesidad simple en mas costo, mas equipos y mas friccion operacional.',
            'right_sized' => 'Right-sized busca proporcionalidad: la arquitectura acompana al problema real en vez de sobredimensionarlo.',
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
        $lines[] = 'app_architecture_success_total{mode="' . $label . '"} ' . ($bucket['successes'] ?? 0);
        $lines[] = 'app_architecture_failure_total{mode="' . $label . '"} ' . ($bucket['failures'] ?? 0);
        $lines[] = 'app_architecture_avg_monthly_cost_usd{mode="' . $label . '"} ' . ($bucket['avg_monthly_cost_usd'] ?? 0);
        $lines[] = 'app_architecture_avg_services_touched{mode="' . $label . '"} ' . ($bucket['avg_services_touched'] ?? 0);
        $lines[] = 'app_architecture_avg_lead_time_days{mode="' . $label . '"} ' . ($bucket['avg_lead_time_days'] ?? 0);
    }

    $lines[] = 'app_simplification_backlog ' . ($state['simplification_backlog'] ?? 0);
    $lines[] = 'app_decision_log_count ' . ($state['decision_log_count'] ?? 0);

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
            'case' => '10 - Expensive architecture for simple needs',
            'stack' => envOr('APP_STACK', 'PHP 8.3'),
            'goal' => 'Comparar una solucion sobredimensionada contra una ruta proporcional al valor real del problema.',
            'routes' => [
                '/health' => 'Estado basico del servicio.',
                '/feature-complex?scenario=basic_crud&accounts=120' => 'Ejecuta la solucion costosa y sobrecompuesta.',
                '/feature-right-sized?scenario=basic_crud&accounts=120' => 'Ejecuta una variante proporcional al problema.',
                '/architecture/state' => 'Estado actual del backlog de simplificacion y baselines.',
                '/decisions?limit=10' => 'Ultimas decisiones observadas por el laboratorio.',
                '/diagnostics/summary' => 'Resumen de costo, lead time y complejidad agregada.',
                '/metrics' => 'Metricas JSON del laboratorio.',
                '/metrics-prometheus' => 'Metricas en formato Prometheus.',
                '/reset-lab' => 'Reinicia estado y metricas.',
            ],
            'allowed_scenarios' => array_keys(scenarioCatalog()),
        ];
    } elseif ($uri === '/health') {
        $payload = ['status' => 'ok', 'stack' => envOr('APP_STACK', 'PHP 8.3')];
    } elseif ($uri === '/feature-complex' || $uri === '/feature-right-sized') {
        $mode = $uri === '/feature-complex' ? 'complex' : 'right_sized';
        $scenario = (string) ($query['scenario'] ?? 'basic_crud');
        if (!array_key_exists($scenario, scenarioCatalog())) {
            $scenario = 'basic_crud';
        }
        $accounts = clampInt((int) ($query['accounts'] ?? 120), 10, 2000);

        $result = runFeatureFlow($mode, $scenario, $accounts);
        $status = (int) $result['http_status'];
        $workflowContext = $result['context'];
        $payload = $result['payload'];
    } elseif ($uri === '/architecture/state') {
        $payload = stateSummary();
    } elseif ($uri === '/decisions') {
        $limit = clampInt((int) ($query['limit'] ?? 10), 1, 50);
        $payload = [
            'limit' => $limit,
            'decisions' => array_slice(telemetrySummary(readTelemetry())['recent_runs'], 0, $limit),
        ];
    } elseif ($uri === '/diagnostics/summary') {
        $payload = diagnosticsSummary();
    } elseif ($uri === '/metrics') {
        $payload = array_merge(
            [
                'case' => '10 - Expensive architecture for simple needs',
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
        $payload = ['status' => 'reset', 'message' => 'Estado de arquitectura y metricas reiniciados.'];
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
