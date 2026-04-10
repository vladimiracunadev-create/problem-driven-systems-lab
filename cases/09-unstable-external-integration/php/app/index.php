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
        'ok' => [
            'legacy_status' => 200,
            'hardened_status' => 200,
            'cached_response' => 0,
            'schema_protected' => 0,
            'quota_saved' => 0,
            'hint' => 'Proveedor estable y contrato sin cambios.',
        ],
        'schema_drift' => [
            'legacy_status' => 502,
            'hardened_status' => 200,
            'cached_response' => 0,
            'schema_protected' => 1,
            'quota_saved' => 1,
            'hint' => 'El proveedor cambia nombres o estructura sin avisar.',
        ],
        'rate_limited' => [
            'legacy_status' => 429,
            'hardened_status' => 200,
            'cached_response' => 1,
            'schema_protected' => 0,
            'quota_saved' => 3,
            'hint' => 'El tercero aplica cuota y los llamados directos quedan sin budget.',
        ],
        'partial_payload' => [
            'legacy_status' => 502,
            'hardened_status' => 200,
            'cached_response' => 0,
            'schema_protected' => 1,
            'quota_saved' => 1,
            'hint' => 'La respuesta llega incompleta y obliga a validar o completar con snapshot previo.',
        ],
        'maintenance_window' => [
            'legacy_status' => 503,
            'hardened_status' => 200,
            'cached_response' => 1,
            'schema_protected' => 0,
            'quota_saved' => 4,
            'hint' => 'El proveedor entra en mantenimiento y la continuidad depende de cache o cola.',
        ],
    ];
}

function requestId(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(4));
}

function sanitizeSku(string $sku): string
{
    return preg_match('/^[A-Z0-9-]{4,20}$/', $sku) === 1 ? $sku : 'SKU-100';
}

function productSnapshot(string $sku): array
{
    $seed = array_sum(array_map('ord', str_split($sku)));
    return [
        'sku' => $sku,
        'title' => 'Product ' . $sku,
        'price_usd' => round(14 + (($seed % 11) * 3.25), 2),
        'stock' => 20 + ($seed % 45),
        'provider_version' => 'v1',
    ];
}

function stateSummary(): array
{
    $state = readState();
    return [
        'provider_name' => $state['integration']['provider_name'] ?? 'catalog-hub',
        'rate_limit_budget' => (int) ($state['integration']['rate_limit_budget'] ?? 0),
        'cache' => $state['integration']['cache'] ?? [],
        'contract' => $state['integration']['contract'] ?? [],
        'quarantine_events' => (int) ($state['integration']['quarantine_events'] ?? 0),
        'last_successful_sync' => $state['integration']['last_successful_sync'] ?? null,
    ];
}

function runCatalogFlow(string $mode, string $scenario, string $sku): array
{
    $scenarioMeta = scenarioCatalog()[$scenario];
    $state = readState();
    $flowId = requestId('sync');
    $integration = &$state['integration'];
    $budgetBefore = (int) $integration['rate_limit_budget'];
    $quotaCost = $mode === 'legacy' ? 3 : 1;
    $quotaSaved = $mode === 'hardened' ? (int) $scenarioMeta['quota_saved'] : 0;
    $cachedResponse = $mode === 'hardened' ? (int) $scenarioMeta['cached_response'] : 0;
    $schemaProtected = $mode === 'hardened' ? (int) $scenarioMeta['schema_protected'] : 0;
    $httpStatus = $mode === 'legacy'
        ? (int) $scenarioMeta['legacy_status']
        : (int) $scenarioMeta['hardened_status'];

    usleep((($mode === 'legacy' ? 190 : 120) + random_int(20, 55)) * 1000);

    $integration['rate_limit_budget'] = max(0, min(12, $budgetBefore - $quotaCost + $quotaSaved));
    $integration['cache']['age_seconds'] = $cachedResponse === 1
        ? min(900, (int) $integration['cache']['age_seconds'] + 30)
        : max(0, (int) $integration['cache']['age_seconds'] - 45);

    if ($schemaProtected === 1) {
        $integration['contract']['adapter_version'] = 'v1+mapping';
        $integration['contract']['schema_mappings'] = (int) $integration['contract']['schema_mappings'] + 1;
    }

    if ($httpStatus >= 400) {
        $integration['quarantine_events'] = (int) $integration['quarantine_events'] + 1;
    } else {
        $integration['last_successful_sync'] = gmdate('c');
        $integration['cache']['snapshot_version'] = '2026.04.' . random_int(1, 9);
    }

    writeState($state);
    $summary = stateSummary();
    $product = productSnapshot($sku);
    $product['source'] = $cachedResponse === 1 ? 'cached_snapshot' : 'live_provider';
    if ($schemaProtected === 1) {
        $product['provider_version'] = 'v2-normalized';
    }

    $outcome = $httpStatus >= 400 ? 'failure' : 'success';
    $payload = [
        'mode' => $mode,
        'scenario' => $scenario,
        'sku' => $sku,
        'status' => $httpStatus >= 400 ? 'failed' : 'completed',
        'message' => $mode === 'legacy'
            ? 'Legacy depende del proveedor en linea y absorbe directamente drift, cuota y payload defectuoso.'
            : 'Hardened agrega adapter, cache y protecciones para mantener continuidad frente a cambios externos.',
        'flow_id' => $flowId,
        'cached_response' => (bool) $cachedResponse,
        'schema_protected' => (bool) $schemaProtected,
        'quota_saved' => $quotaSaved,
        'rate_limit_budget_before' => $budgetBefore,
        'rate_limit_budget_after' => $summary['rate_limit_budget'],
        'product' => $product,
        'scenario_hint' => $scenarioMeta['hint'],
        'integration_state' => $summary,
    ];

    if ($httpStatus >= 400) {
        $payload['error'] = 'La integracion directa quedo expuesta al cambio del proveedor sin amortiguacion suficiente.';
    }

    return [
        'http_status' => $httpStatus,
        'payload' => $payload,
        'context' => [
            'mode' => $mode,
            'scenario' => $scenario,
            'sku' => $sku,
            'outcome' => $outcome,
            'cached_response' => (float) $cachedResponse,
            'schema_protected' => (float) $schemaProtected,
            'quota_saved' => (float) $quotaSaved,
            'flow_id' => $flowId,
        ],
    ];
}

function diagnosticsSummary(): array
{
    return [
        'case' => '09 - Unstable external integration',
        'stack' => envOr('APP_STACK', 'PHP 8.3'),
        'integration' => stateSummary(),
        'metrics' => telemetrySummary(readTelemetry()),
        'interpretation' => [
            'legacy' => 'Legacy deja que el proveedor gobierne latencia, contrato y disponibilidad directamente sobre el flujo del negocio.',
            'hardened' => 'Hardened agrega adapter, cache y validacion para desacoplar la operacion de un tercero cambiante.',
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
        $lines[] = 'app_integration_success_total{mode="' . $label . '"} ' . ($bucket['successes'] ?? 0);
        $lines[] = 'app_integration_failure_total{mode="' . $label . '"} ' . ($bucket['failures'] ?? 0);
        $lines[] = 'app_integration_avg_cached_response{mode="' . $label . '"} ' . ($bucket['avg_cached_response'] ?? 0);
        $lines[] = 'app_integration_avg_schema_protection{mode="' . $label . '"} ' . ($bucket['avg_schema_protection'] ?? 0);
        $lines[] = 'app_integration_avg_quota_saved{mode="' . $label . '"} ' . ($bucket['avg_quota_saved'] ?? 0);
    }

    $lines[] = 'app_provider_rate_limit_budget{provider="' . prometheusLabel((string) ($state['provider_name'] ?? 'catalog-hub')) . '"} ' . ($state['rate_limit_budget'] ?? 0);
    $lines[] = 'app_cache_age_seconds ' . ($state['cache']['age_seconds'] ?? 0);
    $lines[] = 'app_quarantine_events_total ' . ($state['quarantine_events'] ?? 0);

    return implode("\n", $lines) . "\n";
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '', $query);

try {
    if ($uri === '/' || $uri === '') {
        $payload = [
            'lab' => 'Problem-Driven Systems Lab',
            'case' => '09 - Unstable external integration',
            'stack' => envOr('APP_STACK', 'PHP 8.3'),
            'goal' => 'Comparar una integracion directa con un tercero cambiante contra una variante endurecida con adapter, validacion y cache.',
            'routes' => [
                '/health' => 'Estado basico del servicio.',
                '/catalog-legacy?scenario=rate_limited&sku=SKU-100' => 'Consume al proveedor sin amortiguacion suficiente.',
                '/catalog-hardened?scenario=rate_limited&sku=SKU-100' => 'Aplica adapter, cache y ahorro de cuota.',
                '/integration/state' => 'Estado actual del proveedor, cache y contrato adaptado.',
                '/sync-events?limit=10' => 'Ultimos flujos observados por el laboratorio.',
                '/diagnostics/summary' => 'Resumen de protecciones frente al tercero.',
                '/metrics' => 'Metricas JSON del laboratorio.',
                '/metrics-prometheus' => 'Metricas en formato Prometheus.',
                '/reset-lab' => 'Reinicia estado y metricas.',
            ],
            'allowed_scenarios' => array_keys(scenarioCatalog()),
        ];
    } elseif ($uri === '/health') {
        $payload = ['status' => 'ok', 'stack' => envOr('APP_STACK', 'PHP 8.3')];
    } elseif ($uri === '/catalog-legacy' || $uri === '/catalog-hardened') {
        $mode = $uri === '/catalog-legacy' ? 'legacy' : 'hardened';
        $scenario = (string) ($query['scenario'] ?? 'rate_limited');
        if (!array_key_exists($scenario, scenarioCatalog())) {
            $scenario = 'rate_limited';
        }
        $sku = sanitizeSku(strtoupper((string) ($query['sku'] ?? 'SKU-100')));

        $result = runCatalogFlow($mode, $scenario, $sku);
        $status = (int) $result['http_status'];
        $workflowContext = $result['context'];
        $payload = $result['payload'];
    } elseif ($uri === '/integration/state') {
        $payload = stateSummary();
    } elseif ($uri === '/sync-events') {
        $limit = clampInt((int) ($query['limit'] ?? 10), 1, 50);
        $payload = [
            'limit' => $limit,
            'events' => array_slice(telemetrySummary(readTelemetry())['recent_runs'], 0, $limit),
        ];
    } elseif ($uri === '/diagnostics/summary') {
        $payload = diagnosticsSummary();
    } elseif ($uri === '/metrics') {
        $payload = array_merge(
            [
                'case' => '09 - Unstable external integration',
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
        $payload = ['status' => 'reset', 'message' => 'Estado de integracion y metricas reiniciados.'];
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
