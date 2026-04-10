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
        'billing_change' => [
            'legacy_modules' => 6,
            'legacy_risk' => 82,
            'strangler_modules' => 2,
            'strangler_risk' => 28,
            'legacy_status' => 200,
            'strangler_status' => 200,
            'hint' => 'Cambio comun con mucho acoplamiento en legacy y limite de impacto en strangler.',
        ],
        'shared_schema' => [
            'legacy_modules' => 7,
            'legacy_risk' => 94,
            'strangler_modules' => 3,
            'strangler_risk' => 36,
            'legacy_status' => 500,
            'strangler_status' => 200,
            'hint' => 'Legacy rompe por esquema compartido; strangler contiene con ACL y contrato.',
        ],
        'parallel_work' => [
            'legacy_modules' => 5,
            'legacy_risk' => 88,
            'strangler_modules' => 2,
            'strangler_risk' => 24,
            'legacy_status' => 409,
            'strangler_status' => 200,
            'hint' => 'La modernizacion incremental permite trabajo paralelo con menos conflictos.',
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
    $consumers = $state['migration']['consumers'] ?? [];
    $migrated = 0;
    foreach ($consumers as $progress) {
        if ((int) $progress >= 100) {
            $migrated++;
        }
    }

    return [
        'consumers' => $consumers,
        'consumers_total' => count($consumers),
        'consumers_fully_migrated' => $migrated,
        'extracted_module_coverage' => (int) ($state['migration']['extracted_module_coverage'] ?? 0),
        'contract_tests' => (int) ($state['migration']['contract_tests'] ?? 0),
        'anti_corruption_layer_enabled' => (bool) ($state['migration']['anti_corruption_layer_enabled'] ?? false),
        'last_release' => $state['migration']['last_release'] ?? null,
    ];
}

function runChangeFlow(string $mode, string $scenario, string $consumer): array
{
    $scenarioMeta = scenarioCatalog()[$scenario];
    $state = readState();
    $deploymentId = requestId('mod');
    $modulesTouched = $mode === 'legacy' ? $scenarioMeta['legacy_modules'] : $scenarioMeta['strangler_modules'];
    $riskScore = $mode === 'legacy' ? $scenarioMeta['legacy_risk'] : $scenarioMeta['strangler_risk'];
    $blastRadius = ($modulesTouched * 12) + ($mode === 'legacy' ? 18 : 4);
    $elapsedBaseMs = ($modulesTouched * 38) + ($mode === 'legacy' ? 120 : 60);
    usleep(($elapsedBaseMs + random_int(20, 60)) * 1000);

    if ($mode === 'strangler') {
        $currentProgress = (int) ($state['migration']['consumers'][$consumer] ?? 0);
        $state['migration']['consumers'][$consumer] = min(100, $currentProgress + 25);
        $state['migration']['extracted_module_coverage'] = min(92, (int) $state['migration']['extracted_module_coverage'] + 6);
        $state['migration']['contract_tests'] = min(180, (int) $state['migration']['contract_tests'] + 4);
        $state['migration']['last_release'] = 'strangler-' . gmdate('Ymd-His');
        writeState($state);
    }

    $httpStatus = $mode === 'legacy' ? (int) $scenarioMeta['legacy_status'] : (int) $scenarioMeta['strangler_status'];
    $outcome = $httpStatus >= 400 ? 'failure' : 'success';
    $statusText = $httpStatus >= 400 ? 'failed' : 'completed';

    $payload = [
        'mode' => $mode,
        'scenario' => $scenario,
        'consumer' => $consumer,
        'status' => $statusText,
        'message' => $mode === 'legacy'
            ? 'Legacy toca demasiados modulos y concentra mas riesgo por cambio.'
            : 'Strangler reduce blast radius, sube cobertura y mueve el consumidor de forma gradual.',
        'change_id' => $deploymentId,
        'modules_touched' => $modulesTouched,
        'blast_radius_score' => $blastRadius,
        'risk_score' => $riskScore,
        'scenario_hint' => $scenarioMeta['hint'],
        'migration_state' => stateSummary(),
    ];

    if ($httpStatus >= 400) {
        $payload['error'] = $scenario === 'shared_schema'
            ? 'El cambio impacto dependencias no aisladas del monolito.'
            : 'El cambio no pudo avanzar sin bloquear otros equipos o consumidores.';
    }

    return [
        'http_status' => $httpStatus,
        'payload' => $payload,
        'context' => [
            'mode' => $mode,
            'scenario' => $scenario,
            'consumer' => $consumer,
            'outcome' => $outcome,
            'blast_radius_score' => $blastRadius,
            'risk_score' => $riskScore,
            'change_id' => $deploymentId,
        ],
    ];
}

function diagnosticsSummary(): array
{
    return [
        'case' => '07 - Incremental monolith modernization',
        'stack' => envOr('APP_STACK', 'PHP 8.3'),
        'migration' => stateSummary(),
        'metrics' => telemetrySummary(readTelemetry()),
        'interpretation' => [
            'legacy' => 'Legacy mantiene demasiado acoplamiento y convierte cambios locales en regresiones de alto radio.',
            'strangler' => 'Strangler mueve consumidores gradualmente, sube contratos y limita el costo de cada corte.',
        ],
    ];
}

function renderPrometheusMetrics(): string
{
    $summary = telemetrySummary(readTelemetry());
    $migration = stateSummary();
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
        $lines[] = 'app_change_success_total{mode="' . $label . '"} ' . ($bucket['successes'] ?? 0);
        $lines[] = 'app_change_failure_total{mode="' . $label . '"} ' . ($bucket['failures'] ?? 0);
        $lines[] = 'app_change_avg_blast_radius{mode="' . $label . '"} ' . ($bucket['avg_blast_radius_score'] ?? 0);
        $lines[] = 'app_change_avg_risk{mode="' . $label . '"} ' . ($bucket['avg_risk_score'] ?? 0);
    }

    foreach (($migration['consumers'] ?? []) as $consumer => $progress) {
        $lines[] = 'app_consumer_migration_progress{consumer="' . prometheusLabel((string) $consumer) . '"} ' . $progress;
    }

    $lines[] = 'app_extracted_module_coverage ' . ($migration['extracted_module_coverage'] ?? 0);
    $lines[] = 'app_contract_tests_total ' . ($migration['contract_tests'] ?? 0);

    return implode("\n", $lines) . "\n";
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '', $query);

try {
    if ($uri === '/' || $uri === '') {
        $payload = [
            'lab' => 'Problem-Driven Systems Lab',
            'case' => '07 - Incremental monolith modernization',
            'stack' => envOr('APP_STACK', 'PHP 8.3'),
            'goal' => 'Comparar el costo de cambiar un monolito acoplado contra una estrategia strangler con ACL, contratos y migracion gradual.',
            'routes' => [
                '/health' => 'Estado basico del servicio.',
                '/change-legacy?scenario=shared_schema&consumer=web' => 'Ejecuta un cambio sobre el monolito acoplado.',
                '/change-strangler?scenario=shared_schema&consumer=web' => 'Ejecuta el mismo cambio sobre una ruta incremental.',
                '/migration/state' => 'Estado actual de cobertura y progreso por consumidor.',
                '/flows?limit=10' => 'Ultimos cambios observados por el laboratorio.',
                '/diagnostics/summary' => 'Resumen de riesgo, blast radius y progreso de modernizacion.',
                '/metrics' => 'Metricas JSON del laboratorio.',
                '/metrics-prometheus' => 'Metricas en formato Prometheus.',
                '/reset-lab' => 'Reinicia estado y metricas.',
            ],
            'allowed_scenarios' => array_keys(scenarioCatalog()),
        ];
    } elseif ($uri === '/health') {
        $payload = ['status' => 'ok', 'stack' => envOr('APP_STACK', 'PHP 8.3')];
    } elseif ($uri === '/change-legacy' || $uri === '/change-strangler') {
        $mode = $uri === '/change-legacy' ? 'legacy' : 'strangler';
        $scenario = (string) ($query['scenario'] ?? 'billing_change');
        if (!array_key_exists($scenario, scenarioCatalog())) {
            $scenario = 'billing_change';
        }
        $consumer = (string) ($query['consumer'] ?? 'web');
        if (!array_key_exists($consumer, stateSummary()['consumers'])) {
            $consumer = 'web';
        }

        $result = runChangeFlow($mode, $scenario, $consumer);
        $status = (int) $result['http_status'];
        $workflowContext = $result['context'];
        $payload = $result['payload'];
    } elseif ($uri === '/migration/state') {
        $payload = stateSummary();
    } elseif ($uri === '/flows') {
        $limit = clampInt((int) ($query['limit'] ?? 10), 1, 50);
        $payload = [
            'limit' => $limit,
            'flows' => array_slice(telemetrySummary(readTelemetry())['recent_runs'], 0, $limit),
        ];
    } elseif ($uri === '/diagnostics/summary') {
        $payload = diagnosticsSummary();
    } elseif ($uri === '/metrics') {
        $payload = array_merge(
            [
                'case' => '07 - Incremental monolith modernization',
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
        $payload = ['status' => 'reset', 'message' => 'Estado de modernizacion y metricas reiniciados.'];
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
