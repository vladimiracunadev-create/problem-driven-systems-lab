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
        'owner_available' => ['legacy' => ['status' => 200, 'mttr' => 18, 'blockers' => 0, 'handoff' => 40], 'distributed' => ['mttr' => 24, 'blockers' => 0, 'handoff' => 72], 'hint' => 'La persona clave esta disponible y responde rapido.'],
        'owner_absent' => ['legacy' => ['status' => 503, 'mttr' => 95, 'blockers' => 3, 'handoff' => 12], 'distributed' => ['mttr' => 34, 'blockers' => 1, 'handoff' => 78], 'hint' => 'La persona clave no esta y se revela el bus factor real del sistema.'],
        'night_shift' => ['legacy' => ['status' => 503, 'mttr' => 88, 'blockers' => 2, 'handoff' => 18], 'distributed' => ['mttr' => 36, 'blockers' => 1, 'handoff' => 74], 'hint' => 'El problema ocurre fuera de horario y obliga a depender de runbooks y backups reales.'],
        'recent_change' => ['legacy' => ['status' => 502, 'mttr' => 72, 'blockers' => 2, 'handoff' => 25], 'distributed' => ['mttr' => 42, 'blockers' => 1, 'handoff' => 70], 'hint' => 'Un cambio reciente tensiona la necesidad de contexto compartido.'],
        'tribal_script' => ['legacy' => ['status' => 500, 'mttr' => 81, 'blockers' => 3, 'handoff' => 15], 'distributed' => ['mttr' => 39, 'blockers' => 1, 'handoff' => 76], 'hint' => 'Existe un procedimiento critico que vive fuera de runbooks y depende de memoria tribal.'],
    ];
}

function requestId(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(4));
}

function stateSummary(): array
{
    $state = readState()['knowledge'];
    $domains = $state['domains'] ?? [];
    $busFactor = 10;
    $coverage = [];
    foreach ($domains as $domain => $meta) {
        $busFactor = min($busFactor, (int) ($meta['backup_people'] ?? 0) + 1);
        $coverage[$domain] = round(((int) $meta['runbook_score'] + (int) $meta['drill_score']) / 2, 2);
    }

    return [
        'domains' => $domains,
        'coverage' => $coverage,
        'docs_indexed' => (int) ($state['docs_indexed'] ?? 0),
        'pairing_sessions' => (int) ($state['pairing_sessions'] ?? 0),
        'drills_completed' => (int) ($state['drills_completed'] ?? 0),
        'bus_factor_min' => $busFactor === 10 ? 0 : $busFactor,
        'last_update' => $state['last_update'] ?? null,
    ];
}

function readinessScore(array $domainState): int
{
    return (int) round(
        ((int) ($domainState['runbook_score'] ?? 0) * 0.45) +
        (((int) ($domainState['backup_people'] ?? 0) + 1) * 18) +
        ((int) ($domainState['drill_score'] ?? 0) * 0.25)
    );
}

function shareKnowledge(string $domain, string $activity): array
{
    $state = readState();
    $domainState = $state['knowledge']['domains'][$domain];

    if ($activity === 'runbook') {
        $domainState['runbook_score'] = min(100, (int) $domainState['runbook_score'] + 20);
        $state['knowledge']['docs_indexed'] = (int) $state['knowledge']['docs_indexed'] + 1;
    } elseif ($activity === 'pairing') {
        $domainState['backup_people'] = min(4, (int) $domainState['backup_people'] + 1);
        $state['knowledge']['pairing_sessions'] = (int) $state['knowledge']['pairing_sessions'] + 1;
    } else {
        $domainState['drill_score'] = min(100, (int) $domainState['drill_score'] + 18);
        $state['knowledge']['drills_completed'] = (int) $state['knowledge']['drills_completed'] + 1;
    }

    $state['knowledge']['domains'][$domain] = $domainState;
    $state['knowledge']['last_update'] = gmdate('c');
    writeState($state);

    return stateSummary();
}

function runIncidentFlow(string $mode, string $scenario, string $domain): array
{
    $scenarioMeta = scenarioCatalog()[$scenario];
    $state = readState();
    $domainState = $state['knowledge']['domains'][$domain];
    $readiness = readinessScore($domainState);
    $flowId = requestId('incident');

    if ($mode === 'legacy') {
        $httpStatus = (int) $scenarioMeta['legacy']['status'];
        $mttr = (int) $scenarioMeta['legacy']['mttr'];
        $blockers = (int) $scenarioMeta['legacy']['blockers'];
        $handoff = (int) $scenarioMeta['legacy']['handoff'];
    } else {
        $mttr = max(15, (int) $scenarioMeta['distributed']['mttr'] - (int) floor($readiness / 12));
        $blockers = max(0, (int) $scenarioMeta['distributed']['blockers'] - (int) floor(((int) $domainState['backup_people'] + 1) / 2));
        $handoff = min(95, (int) $scenarioMeta['distributed']['handoff'] + (int) floor($readiness / 10));
        $httpStatus = ($readiness < 28 && $scenario !== 'owner_available') ? 409 : 200;
    }

    usleep((int) (($mttr * 8) + random_int(20, 55)) * 1000);
    $outcome = $httpStatus >= 400 ? 'failure' : 'success';
    $payload = [
        'mode' => $mode,
        'scenario' => $scenario,
        'domain' => $domain,
        'status' => $httpStatus >= 400 ? 'blocked' : 'resolved',
        'message' => $mode === 'legacy'
            ? 'Legacy depende demasiado de quien ya sabe el camino y sufre cuando ese conocimiento no esta disponible.'
            : 'Distributed combina runbooks, backups y practica para que el incidente no dependa de una sola persona.',
        'incident_id' => $flowId,
        'mttr_min' => $mttr,
        'blocker_count' => $blockers,
        'handoff_quality' => $handoff,
        'readiness_score' => $readiness,
        'scenario_hint' => $scenarioMeta['hint'],
        'knowledge_state' => stateSummary(),
    ];

    if ($httpStatus >= 400) {
        $payload['error'] = 'El conocimiento distribuido todavia no alcanza para resolver el incidente con seguridad sin ayuda critica.';
    }

    return [
        'http_status' => $httpStatus,
        'payload' => $payload,
        'context' => [
            'mode' => $mode,
            'scenario' => $scenario,
            'domain' => $domain,
            'outcome' => $outcome,
            'mttr_min' => (float) $mttr,
            'blocker_count' => (float) $blockers,
            'handoff_quality' => (float) $handoff,
            'flow_id' => $flowId,
        ],
    ];
}

function diagnosticsSummary(): array
{
    return [
        'case' => '12 - Single point of knowledge and operational risk',
        'stack' => envOr('APP_STACK', 'PHP 8.3'),
        'knowledge' => stateSummary(),
        'metrics' => telemetrySummary(readTelemetry()),
        'interpretation' => [
            'legacy' => 'Legacy expone el riesgo de depender de memoria tribal y de personas unicas para resolver incidentes.',
            'distributed' => 'Distributed baja el bus factor con runbooks, pairing y drills para que la continuidad no quede amarrada a una sola persona.',
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
        $lines[] = 'app_knowledge_success_total{mode="' . $label . '"} ' . ($bucket['successes'] ?? 0);
        $lines[] = 'app_knowledge_failure_total{mode="' . $label . '"} ' . ($bucket['failures'] ?? 0);
        $lines[] = 'app_knowledge_avg_mttr_min{mode="' . $label . '"} ' . ($bucket['avg_mttr_min'] ?? 0);
        $lines[] = 'app_knowledge_avg_blocker_count{mode="' . $label . '"} ' . ($bucket['avg_blocker_count'] ?? 0);
        $lines[] = 'app_knowledge_avg_handoff_quality{mode="' . $label . '"} ' . ($bucket['avg_handoff_quality'] ?? 0);
    }

    foreach (($state['domains'] ?? []) as $domain => $meta) {
        $label = prometheusLabel((string) $domain);
        $lines[] = 'app_domain_runbook_score{domain="' . $label . '"} ' . ($meta['runbook_score'] ?? 0);
        $lines[] = 'app_domain_backup_people{domain="' . $label . '"} ' . ($meta['backup_people'] ?? 0);
        $lines[] = 'app_domain_drill_score{domain="' . $label . '"} ' . ($meta['drill_score'] ?? 0);
    }

    $lines[] = 'app_bus_factor_min ' . ($state['bus_factor_min'] ?? 0);
    $lines[] = 'app_docs_indexed ' . ($state['docs_indexed'] ?? 0);

    return implode("\n", $lines) . "\n";
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '', $query);

try {
    if ($uri === '/' || $uri === '') {
        $payload = [
            'lab' => 'Problem-Driven Systems Lab',
            'case' => '12 - Single point of knowledge and operational risk',
            'stack' => envOr('APP_STACK', 'PHP 8.3'),
            'goal' => 'Comparar dependencia de conocimiento tribal contra una postura con runbooks, pairing y drills.',
            'routes' => [
                '/health' => 'Estado basico del servicio.',
                '/incident-legacy?scenario=owner_absent&domain=deployments' => 'Ejecuta un incidente bajo dependencia fuerte de una sola persona.',
                '/incident-distributed?scenario=owner_absent&domain=deployments' => 'Ejecuta el mismo incidente con conocimiento mas distribuido.',
                '/share-knowledge?domain=deployments&activity=runbook' => 'Sube madurez del dominio con runbook, pairing o drill.',
                '/knowledge/state' => 'Estado actual de cobertura, backups y bus factor.',
                '/incidents?limit=10' => 'Ultimos incidentes observados por el laboratorio.',
                '/diagnostics/summary' => 'Resumen de bus factor, handoff y continuidad operacional.',
                '/metrics' => 'Metricas JSON del laboratorio.',
                '/metrics-prometheus' => 'Metricas en formato Prometheus.',
                '/reset-lab' => 'Reinicia estado y metricas.',
            ],
            'allowed_scenarios' => array_keys(scenarioCatalog()),
        ];
    } elseif ($uri === '/health') {
        $payload = ['status' => 'ok', 'stack' => envOr('APP_STACK', 'PHP 8.3')];
    } elseif ($uri === '/incident-legacy' || $uri === '/incident-distributed') {
        $mode = $uri === '/incident-legacy' ? 'legacy' : 'distributed';
        $scenario = (string) ($query['scenario'] ?? 'owner_absent');
        if (!array_key_exists($scenario, scenarioCatalog())) {
            $scenario = 'owner_absent';
        }
        $domain = (string) ($query['domain'] ?? 'deployments');
        if (!array_key_exists($domain, stateSummary()['domains'])) {
            $domain = 'deployments';
        }

        $result = runIncidentFlow($mode, $scenario, $domain);
        $status = (int) $result['http_status'];
        $workflowContext = $result['context'];
        $payload = $result['payload'];
    } elseif ($uri === '/share-knowledge') {
        $domain = (string) ($query['domain'] ?? 'deployments');
        if (!array_key_exists($domain, stateSummary()['domains'])) {
            $domain = 'deployments';
        }
        $activity = (string) ($query['activity'] ?? 'runbook');
        if (!in_array($activity, ['runbook', 'pairing', 'drill'], true)) {
            $activity = 'runbook';
        }
        $payload = [
            'status' => 'updated',
            'domain' => $domain,
            'activity' => $activity,
            'knowledge_state' => shareKnowledge($domain, $activity),
        ];
    } elseif ($uri === '/knowledge/state') {
        $payload = stateSummary();
    } elseif ($uri === '/incidents') {
        $limit = clampInt((int) ($query['limit'] ?? 10), 1, 50);
        $payload = [
            'limit' => $limit,
            'incidents' => array_slice(telemetrySummary(readTelemetry())['recent_runs'], 0, $limit),
        ];
    } elseif ($uri === '/diagnostics/summary') {
        $payload = diagnosticsSummary();
    } elseif ($uri === '/metrics') {
        $payload = array_merge(
            [
                'case' => '12 - Single point of knowledge and operational risk',
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
        $payload = ['status' => 'reset', 'message' => 'Estado de conocimiento y metricas reiniciados.'];
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
if (!$skipStoreMetrics && $uri !== '/metrics' && $uri !== '/reset-lab' && $uri !== '/share-knowledge') {
    recordRequestTelemetry($uri, $status, $elapsedMs, $workflowContext);
}
$payload['elapsed_ms'] = $elapsedMs;
$payload['timestamp_utc'] = gmdate('c');
$payload['pid'] = getmypid();
jsonResponse($status, $payload);
