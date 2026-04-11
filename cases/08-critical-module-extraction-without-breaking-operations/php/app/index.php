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
        'stable' => [
            'bigbang_status' => 200,
            'compatible_status' => 200,
            'bigbang_blast' => 76,
            'compatible_blast' => 28,
            'compatible_proxy_hits' => 1,
            'compatible_progress' => 20,
            'hint' => 'Cambio sano, pero la extraccion gradual igual reduce radio de impacto.',
        ],
        'rule_drift' => [
            'bigbang_status' => 500,
            'compatible_status' => 200,
            'bigbang_blast' => 92,
            'compatible_blast' => 32,
            'compatible_proxy_hits' => 3,
            'compatible_progress' => 15,
            'hint' => 'Los consumidores no esperan exactamente la misma regla o estructura.',
        ],
        'shared_write' => [
            'bigbang_status' => 409,
            'compatible_status' => 200,
            'bigbang_blast' => 88,
            'compatible_blast' => 36,
            'compatible_proxy_hits' => 4,
            'compatible_progress' => 10,
            'hint' => 'Dos rutas escribiendo el mismo recurso exigen proxy y corte progresivo.',
        ],
        'peak_sale' => [
            'bigbang_status' => 502,
            'compatible_status' => 200,
            'bigbang_blast' => 95,
            'compatible_blast' => 34,
            'compatible_proxy_hits' => 5,
            'compatible_progress' => 10,
            'hint' => 'La venta pico castiga cualquier extraccion sin protecciones de compatibilidad.',
        ],
        'partner_contract' => [
            'bigbang_status' => 500,
            'compatible_status' => 200,
            'bigbang_blast' => 90,
            'compatible_blast' => 30,
            'compatible_proxy_hits' => 4,
            'compatible_progress' => 20,
            'hint' => 'El partner externo necesita contrato estable mientras cambia la implementacion.',
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
    $consumers = $state['extraction']['consumers'] ?? [];
    $avgProgress = count($consumers) > 0
        ? round(array_sum(array_map('intval', $consumers)) / count($consumers), 2)
        : 0.0;

    return [
        'consumers' => $consumers,
        'average_cutover_percent' => $avgProgress,
        'contract_tests' => (int) ($state['extraction']['contract_tests'] ?? 0),
        'compatibility_proxy_hits' => (int) ($state['extraction']['compatibility_proxy_hits'] ?? 0),
        'shadow_traffic_percent' => (int) ($state['extraction']['shadow_traffic_percent'] ?? 0),
        'cutover_events' => (int) ($state['extraction']['cutover_events'] ?? 0),
        'last_release' => $state['extraction']['last_release'] ?? null,
    ];
}

function advanceCutover(string $consumer, int $step = 25): array
{
    $state = readState();
    $current = (int) ($state['extraction']['consumers'][$consumer] ?? 0);
    $state['extraction']['consumers'][$consumer] = min(100, $current + $step);
    $state['extraction']['contract_tests'] = min(180, (int) $state['extraction']['contract_tests'] + 5);
    $state['extraction']['shadow_traffic_percent'] = min(95, (int) $state['extraction']['shadow_traffic_percent'] + 10);
    $state['extraction']['cutover_events'] = (int) $state['extraction']['cutover_events'] + 1;
    $state['extraction']['last_release'] = 'cutover-' . gmdate('Ymd-His');
    writeState($state);

    return stateSummary();
}

function runExtractionFlow(string $mode, string $scenario, string $consumer): array
{
    $scenarioMeta = scenarioCatalog()[$scenario];
    $state = readState();
    $flowId = requestId('extract');
    
    // Asignaciones base (proxy hits si todo sale bien)
    $blastRadius = $mode === 'bigbang'
        ? (int) $scenarioMeta['bigbang_blast']
        : (int) $scenarioMeta['compatible_blast'];
    $compatibilityHits = $mode === 'compatible' ? (int) $scenarioMeta['compatible_proxy_hits'] : 0;

    usleep((($mode === 'bigbang' ? 240 : 140) + random_int(20, 60)) * 1000);

    $httpStatus = 200;
    $errorMessage = null;

    try {
        if ($mode === 'bigbang') {
            if ($scenario === 'rule_drift') {
                $payload = ['cost_usd' => 150]; // Inyección desde el consumidor no migrado
                // El modulo extraido asume un contrato nuevo (usando 'price')
                $finalPrice = $payload['price'] * 1.21; // Desatará Warning: Undefined array key
                if (!isset($payload['price'])) throw new \InvalidArgumentException("Modulo extraído asume key 'price', payload traía key 'cost_usd'. Contrato roto.");
            } elseif ($scenario === 'shared_write' || $scenario === 'peak_sale' || $scenario === 'partner_contract') {
                throw new \RuntimeException("Modulo crasheó: " . $scenarioMeta['hint']);
            }
        } elseif ($mode === 'compatible') {
             // El proxy compatible analiza y adapta en caliente (Adapter Pattern)
            if ($scenario === 'rule_drift') {
                $payload = ['cost_usd' => 150];
                $payload['price'] = $payload['cost_usd']; // Proxy alinea el contrato
                $finalPrice = $payload['price'] * 1.21; // Operacion segura
            }

            $current = (int) ($state['extraction']['consumers'][$consumer] ?? 0);
            $state['extraction']['consumers'][$consumer] = min(100, $current + (int) $scenarioMeta['compatible_progress']);
            $state['extraction']['contract_tests'] = min(180, (int) $state['extraction']['contract_tests'] + 6);
            $state['extraction']['compatibility_proxy_hits'] = (int) $state['extraction']['compatibility_proxy_hits'] + $compatibilityHits;
            $state['extraction']['shadow_traffic_percent'] = min(95, (int) $state['extraction']['shadow_traffic_percent'] + 8);
            $state['extraction']['cutover_events'] = (int) $state['extraction']['cutover_events'] + 1;
            $state['extraction']['last_release'] = 'compat-' . gmdate('Ymd-His');
            writeState($state);
        }
    } catch (\Throwable $e) {
        $httpStatus = $scenario === 'shared_write' ? 409 : ($scenario === 'peak_sale' ? 502 : 500);
        $errorMessage = "Excepción en código " . get_class($e) . " -> " . $e->getMessage();
    }

    $summary = stateSummary();
    $outcome = $httpStatus >= 400 ? 'failure' : 'success';
    $payload = [
        'mode' => $mode,
        'scenario' => $scenario,
        'consumer' => $consumer,
        'status' => $httpStatus >= 400 ? 'failed' : 'completed',
        'message' => $mode === 'bigbang' && $httpStatus >= 400
            ? $errorMessage
            : 'Compatible usa proxy, intercepta las llamadas, transforma contratos y hace cutover gradual sin romper código.',
        'flow_id' => $flowId,
        'blast_radius_score' => $blastRadius,
        'compatibility_proxy_hits' => $compatibilityHits,
        'consumer_progress_after' => $summary['consumers'][$consumer] ?? 0,
        'scenario_hint' => $scenarioMeta['hint'],
        'extraction_state' => $summary,
    ];

    if ($httpStatus >= 400) {
        $payload['error'] = 'La extracción explotó de forma dura. Excepción capturada en log.';
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
            'compatibility_hits' => $compatibilityHits,
            'consumer_progress' => (float) ($summary['consumers'][$consumer] ?? 0),
            'flow_id' => $flowId,
        ],
    ];
}

function diagnosticsSummary(): array
{
    return [
        'case' => '08 - Critical module extraction without breaking operations',
        'stack' => envOr('APP_STACK', 'PHP 8.3'),
        'extraction' => stateSummary(),
        'metrics' => telemetrySummary(readTelemetry()),
        'interpretation' => [
            'bigbang' => 'Big bang puede cerrar la deuda mas rapido en el papel, pero aumenta incompatibilidades, radio de impacto y riesgo de corte.',
            'compatible' => 'Compatible desacopla el modulo con proxy, contratos y cutover gradual para no romper checkout, partners ni backoffice.',
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
        $lines[] = 'app_extraction_success_total{mode="' . $label . '"} ' . ($bucket['successes'] ?? 0);
        $lines[] = 'app_extraction_failure_total{mode="' . $label . '"} ' . ($bucket['failures'] ?? 0);
        $lines[] = 'app_extraction_avg_blast_radius{mode="' . $label . '"} ' . ($bucket['avg_blast_radius_score'] ?? 0);
        $lines[] = 'app_extraction_avg_proxy_hits{mode="' . $label . '"} ' . ($bucket['avg_compatibility_hits'] ?? 0);
    }

    foreach (($state['consumers'] ?? []) as $consumer => $progress) {
        $lines[] = 'app_consumer_cutover_progress{consumer="' . prometheusLabel((string) $consumer) . '"} ' . $progress;
    }

    $lines[] = 'app_contract_tests_total ' . ($state['contract_tests'] ?? 0);
    $lines[] = 'app_compatibility_proxy_hits_total ' . ($state['compatibility_proxy_hits'] ?? 0);
    $lines[] = 'app_shadow_traffic_percent ' . ($state['shadow_traffic_percent'] ?? 0);

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
            'case' => '08 - Critical module extraction without breaking operations',
            'stack' => envOr('APP_STACK', 'PHP 8.3'),
            'goal' => 'Comparar una extraccion big bang con una ruta compatible que usa proxy, contratos y cutover progresivo por consumidor.',
            'routes' => [
                '/health' => 'Estado basico del servicio.',
                '/pricing-bigbang?scenario=rule_drift&consumer=checkout' => 'Intenta extraer el modulo critico de una vez.',
                '/pricing-compatible?scenario=rule_drift&consumer=checkout' => 'Mueve el modulo con compatibilidad y corte gradual.',
                '/cutover/advance?consumer=checkout' => 'Fuerza un avance manual del cutover por consumidor.',
                '/extraction/state' => 'Estado actual de contratos, proxy y progreso por consumidor.',
                '/flows?limit=10' => 'Ultimos flujos observados por el laboratorio.',
                '/diagnostics/summary' => 'Resumen de compatibilidad, radio de impacto y ritmo de extraccion.',
                '/metrics' => 'Metricas JSON del laboratorio.',
                '/metrics-prometheus' => 'Metricas en formato Prometheus.',
                '/reset-lab' => 'Reinicia estado y metricas.',
            ],
            'allowed_scenarios' => array_keys(scenarioCatalog()),
        ];
    } elseif ($uri === '/health') {
        $payload = ['status' => 'ok', 'stack' => envOr('APP_STACK', 'PHP 8.3')];
    } elseif ($uri === '/pricing-bigbang' || $uri === '/pricing-compatible') {
        $mode = $uri === '/pricing-bigbang' ? 'bigbang' : 'compatible';
        $scenario = (string) ($query['scenario'] ?? 'rule_drift');
        if (!array_key_exists($scenario, scenarioCatalog())) {
            $scenario = 'rule_drift';
        }
        $consumer = (string) ($query['consumer'] ?? 'checkout');
        if (!array_key_exists($consumer, stateSummary()['consumers'])) {
            $consumer = 'checkout';
        }

        $result = runExtractionFlow($mode, $scenario, $consumer);
        $status = (int) $result['http_status'];
        $workflowContext = $result['context'];
        $payload = $result['payload'];
    } elseif ($uri === '/cutover/advance') {
        $consumer = (string) ($query['consumer'] ?? 'checkout');
        if (!array_key_exists($consumer, stateSummary()['consumers'])) {
            $consumer = 'checkout';
        }
        $payload = [
            'status' => 'advanced',
            'consumer' => $consumer,
            'extraction_state' => advanceCutover($consumer),
        ];
    } elseif ($uri === '/extraction/state') {
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
                'case' => '08 - Critical module extraction without breaking operations',
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
        $payload = ['status' => 'reset', 'message' => 'Estado de extraccion y metricas reiniciados.'];
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
if (!$skipStoreMetrics && $uri !== '/metrics' && $uri !== '/reset-lab' && $uri !== '/cutover/advance') {
    recordRequestTelemetry($uri, $status, $elapsedMs, $workflowContext);
}
$payload['elapsed_ms'] = $elapsedMs;
$payload['timestamp_utc'] = gmdate('c');
$payload['pid'] = getmypid();
jsonResponse($status, $payload);
