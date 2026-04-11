<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$started = microtime(true);
$status = 200;
$skipStoreMetrics = false;
$workflowContext = null;

function requestId(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(4));
}

function scenarioCatalog(): array
{
    return [
        'ok' => [
            'hint' => 'El flujo tiene precondiciones correctas y pasa de punta a punta.',
        ],
        'missing_secret' => [
            'hint' => 'Falta un secreto critico en el ambiente de destino.',
        ],
        'config_drift' => [
            'hint' => 'La configuracion de runtime no coincide con la esperada por el release.',
        ],
        'failing_smoke' => [
            'hint' => 'El deploy parece correcto, pero el smoke test falla despues del cambio.',
        ],
        'migration_risk' => [
            'hint' => 'La migracion necesita validacion previa o backfill antes de aplicarse.',
        ],
    ];
}

function sanitizeRelease(string $release): string
{
    return preg_match('/^[A-Za-z0-9._-]{3,32}$/', $release) === 1 ? $release : '2026.04.1';
}

function stepDelay(int $baseMs): float
{
    $elapsedMs = $baseMs + random_int(8, 25);
    usleep($elapsedMs * 1000);
    return (float) $elapsedMs;
}

function appendHistory(array &$state, array $entry): void
{
    $state['history'][] = $entry;
    if (count($state['history']) > 80) {
        $state['history'] = array_slice($state['history'], -80);
    }
}

function runLegacyDeployment(string $environment, string $release, string $scenario): array
{
    $state = readState();
    $env = $state['environments'][$environment];
    $previousRelease = (string) $env['current_release'];
    $deploymentId = requestId('deploy');
    $steps = [];

    $steps[] = ['step' => 'package_release', 'status' => 'ok', 'elapsed_ms' => stepDelay(70)];

    if ($scenario === 'migration_risk') {
        $steps[] = ['step' => 'apply_migration', 'status' => 'error', 'elapsed_ms' => stepDelay(120), 'message' => 'La migracion fallo sobre datos reales y dejo el esquema a medio camino.'];
        $env['schema_version'] = $release . '-partial';
        $env['health'] = 'degraded';
        $env['last_failure_reason'] = 'migration_failed_mid_deploy';
        $env['last_deploy_at'] = gmdate('c');
        $state['environments'][$environment] = $env;

        appendHistory($state, [
            'deployment_id' => $deploymentId,
            'mode' => 'legacy',
            'environment' => $environment,
            'release' => $release,
            'scenario' => $scenario,
            'outcome' => 'failure',
            'rollback_performed' => false,
            'preflight_blocked' => false,
            'timestamp_utc' => gmdate('c'),
        ]);
        writeState($state);

        return [
            'http_status' => 500,
            'payload' => [
                'mode' => 'legacy',
                'environment' => $environment,
                'release' => $release,
                'scenario' => $scenario,
                'status' => 'failed',
                'message' => 'El pipeline legacy aplico la migracion sin validacion previa y rompio el despliegue.',
                'deployment_id' => $deploymentId,
                'steps' => $steps,
                'environment_after' => $env,
            ],
            'context' => [
                'mode' => 'legacy',
                'environment' => $environment,
                'release' => $release,
                'scenario' => $scenario,
                'outcome' => 'failure',
                'rollback_performed' => false,
                'preflight_blocked' => false,
                'deployment_id' => $deploymentId,
            ],
        ];
    }

    $steps[] = ['step' => 'switch_traffic', 'status' => 'ok', 'elapsed_ms' => stepDelay(55)];
    $env['current_release'] = $release;
    $env['last_deploy_at'] = gmdate('c');
    $env['health'] = 'warming';

    $shouldFailSmoke = in_array($scenario, ['missing_secret', 'config_drift', 'failing_smoke'], true);
    if ($shouldFailSmoke) {
        $steps[] = [
            'step' => 'smoke_test',
            'status' => 'error',
            'elapsed_ms' => stepDelay(75),
            'message' => scenarioCatalog()[$scenario]['hint'],
        ];
        $env['health'] = 'degraded';
        $env['last_failure_reason'] = $scenario;
        $state['environments'][$environment] = $env;

        appendHistory($state, [
            'deployment_id' => $deploymentId,
            'mode' => 'legacy',
            'environment' => $environment,
            'release' => $release,
            'scenario' => $scenario,
            'outcome' => 'failure',
            'rollback_performed' => false,
            'preflight_blocked' => false,
            'timestamp_utc' => gmdate('c'),
        ]);
        writeState($state);

        return [
            'http_status' => 502,
            'payload' => [
                'mode' => 'legacy',
                'environment' => $environment,
                'release' => $release,
                'scenario' => $scenario,
                'status' => 'failed',
                'message' => 'Legacy encontro el problema despues de cambiar trafico y dejo el ambiente degradado.',
                'deployment_id' => $deploymentId,
                'previous_release' => $previousRelease,
                'steps' => $steps,
                'environment_after' => $env,
            ],
            'context' => [
                'mode' => 'legacy',
                'environment' => $environment,
                'release' => $release,
                'scenario' => $scenario,
                'outcome' => 'failure',
                'rollback_performed' => false,
                'preflight_blocked' => false,
                'deployment_id' => $deploymentId,
            ],
        ];
    }

    $steps[] = ['step' => 'smoke_test', 'status' => 'ok', 'elapsed_ms' => stepDelay(75)];
    $env['health'] = 'healthy';
    $env['schema_version'] = $release;
    $env['last_good_release'] = $release;
    $env['last_failure_reason'] = null;
    $state['environments'][$environment] = $env;

    appendHistory($state, [
        'deployment_id' => $deploymentId,
        'mode' => 'legacy',
        'environment' => $environment,
        'release' => $release,
        'scenario' => $scenario,
        'outcome' => 'success',
        'rollback_performed' => false,
        'preflight_blocked' => false,
        'timestamp_utc' => gmdate('c'),
    ]);
    writeState($state);

    return [
        'http_status' => 200,
        'payload' => [
            'mode' => 'legacy',
            'environment' => $environment,
            'release' => $release,
            'scenario' => $scenario,
            'status' => 'completed',
            'message' => 'El pipeline legacy logro desplegar, pero sin controles fuertes previos.',
            'deployment_id' => $deploymentId,
            'previous_release' => $previousRelease,
            'steps' => $steps,
            'environment_after' => $env,
        ],
        'context' => [
            'mode' => 'legacy',
            'environment' => $environment,
            'release' => $release,
            'scenario' => $scenario,
            'outcome' => 'success',
            'rollback_performed' => false,
            'preflight_blocked' => false,
            'deployment_id' => $deploymentId,
        ],
    ];
}

function runControlledDeployment(string $environment, string $release, string $scenario): array
{
    $state = readState();
    $env = $state['environments'][$environment];
    $previousRelease = (string) $env['current_release'];
    $deploymentId = requestId('deploy');
    $steps = [];

    $steps[] = ['step' => 'build_artifact', 'status' => 'ok', 'elapsed_ms' => stepDelay(65)];
    $steps[] = ['step' => 'tests_and_contracts', 'status' => 'ok', 'elapsed_ms' => stepDelay(60)];

    if (in_array($scenario, ['missing_secret', 'config_drift'], true)) {
        $steps[] = [
            'step' => 'preflight_validation',
            'status' => 'blocked',
            'elapsed_ms' => stepDelay(55),
            'message' => scenarioCatalog()[$scenario]['hint'],
        ];

        appendHistory($state, [
            'deployment_id' => $deploymentId,
            'mode' => 'controlled',
            'environment' => $environment,
            'release' => $release,
            'scenario' => $scenario,
            'outcome' => 'failure',
            'rollback_performed' => false,
            'preflight_blocked' => true,
            'timestamp_utc' => gmdate('c'),
        ]);
        writeState($state);

        return [
            'http_status' => 409,
            'payload' => [
                'mode' => 'controlled',
                'environment' => $environment,
                'release' => $release,
                'scenario' => $scenario,
                'status' => 'blocked',
                'message' => 'El pipeline controlado detecto el riesgo antes de tocar el ambiente.',
                'deployment_id' => $deploymentId,
                'previous_release' => $previousRelease,
                'steps' => $steps,
                'environment_after' => $env,
            ],
            'context' => [
                'mode' => 'controlled',
                'environment' => $environment,
                'release' => $release,
                'scenario' => $scenario,
                'outcome' => 'failure',
                'rollback_performed' => false,
                'preflight_blocked' => true,
                'deployment_id' => $deploymentId,
            ],
        ];
    }

    if ($scenario === 'migration_risk') {
        $steps[] = [
            'step' => 'migration_dry_run',
            'status' => 'blocked',
            'elapsed_ms' => stepDelay(70),
            'message' => scenarioCatalog()[$scenario]['hint'],
        ];

        appendHistory($state, [
            'deployment_id' => $deploymentId,
            'mode' => 'controlled',
            'environment' => $environment,
            'release' => $release,
            'scenario' => $scenario,
            'outcome' => 'failure',
            'rollback_performed' => false,
            'preflight_blocked' => true,
            'timestamp_utc' => gmdate('c'),
        ]);
        writeState($state);

        return [
            'http_status' => 409,
            'payload' => [
                'mode' => 'controlled',
                'environment' => $environment,
                'release' => $release,
                'scenario' => $scenario,
                'status' => 'blocked',
                'message' => 'El pipeline controlado detuvo la migracion antes del deploy.',
                'deployment_id' => $deploymentId,
                'previous_release' => $previousRelease,
                'steps' => $steps,
                'environment_after' => $env,
            ],
            'context' => [
                'mode' => 'controlled',
                'environment' => $environment,
                'release' => $release,
                'scenario' => $scenario,
                'outcome' => 'failure',
                'rollback_performed' => false,
                'preflight_blocked' => true,
                'deployment_id' => $deploymentId,
            ],
        ];
    }

    $steps[] = ['step' => 'deploy_canary', 'status' => 'ok', 'elapsed_ms' => stepDelay(60)];

    if ($scenario === 'failing_smoke') {
        $steps[] = [
            'step' => 'smoke_test',
            'status' => 'error',
            'elapsed_ms' => stepDelay(70),
            'message' => scenarioCatalog()[$scenario]['hint'],
        ];
        $steps[] = ['step' => 'rollback', 'status' => 'ok', 'elapsed_ms' => stepDelay(45), 'message' => 'Se revierte al ultimo release sano.'];

        $env['current_release'] = $previousRelease;
        $env['health'] = 'healthy';
        $env['last_failure_reason'] = $scenario;
        $env['last_deploy_at'] = gmdate('c');
        $state['environments'][$environment] = $env;

        appendHistory($state, [
            'deployment_id' => $deploymentId,
            'mode' => 'controlled',
            'environment' => $environment,
            'release' => $release,
            'scenario' => $scenario,
            'outcome' => 'failure',
            'rollback_performed' => true,
            'preflight_blocked' => false,
            'timestamp_utc' => gmdate('c'),
        ]);
        writeState($state);

        return [
            'http_status' => 502,
            'payload' => [
                'mode' => 'controlled',
                'environment' => $environment,
                'release' => $release,
                'scenario' => $scenario,
                'status' => 'rolled_back',
                'message' => 'El smoke test fallo, pero el pipeline controlado hizo rollback automatico.',
                'deployment_id' => $deploymentId,
                'previous_release' => $previousRelease,
                'steps' => $steps,
                'environment_after' => $env,
            ],
            'context' => [
                'mode' => 'controlled',
                'environment' => $environment,
                'release' => $release,
                'scenario' => $scenario,
                'outcome' => 'failure',
                'rollback_performed' => true,
                'preflight_blocked' => false,
                'deployment_id' => $deploymentId,
            ],
        ];
    }

    $steps[] = ['step' => 'smoke_test', 'status' => 'ok', 'elapsed_ms' => stepDelay(70)];
    $steps[] = ['step' => 'promote_release', 'status' => 'ok', 'elapsed_ms' => stepDelay(40)];

    $env['current_release'] = $release;
    $env['schema_version'] = $release;
    $env['health'] = 'healthy';
    $env['last_good_release'] = $release;
    $env['last_failure_reason'] = null;
    $env['last_deploy_at'] = gmdate('c');
    $state['environments'][$environment] = $env;

    appendHistory($state, [
        'deployment_id' => $deploymentId,
        'mode' => 'controlled',
        'environment' => $environment,
        'release' => $release,
        'scenario' => $scenario,
        'outcome' => 'success',
        'rollback_performed' => false,
        'preflight_blocked' => false,
        'timestamp_utc' => gmdate('c'),
    ]);
    writeState($state);

    return [
        'http_status' => 200,
        'payload' => [
            'mode' => 'controlled',
            'environment' => $environment,
            'release' => $release,
            'scenario' => $scenario,
            'status' => 'completed',
            'message' => 'El pipeline controlado valido, desplego y promovio el release de forma segura.',
            'deployment_id' => $deploymentId,
            'previous_release' => $previousRelease,
            'steps' => $steps,
            'environment_after' => $env,
        ],
        'context' => [
            'mode' => 'controlled',
            'environment' => $environment,
            'release' => $release,
            'scenario' => $scenario,
            'outcome' => 'success',
            'rollback_performed' => false,
            'preflight_blocked' => false,
            'deployment_id' => $deploymentId,
        ],
    ];
}

function stateSummary(): array
{
    $state = readState();
    return [
        'environments' => $state['environments'],
        'history' => array_reverse($state['history']),
    ];
}

function diagnosticsSummary(): array
{
    return [
        'case' => '06 - Broken pipeline and fragile delivery',
        'stack' => envOr('APP_STACK', 'PHP 8.3'),
        'state' => stateSummary(),
        'metrics' => telemetrySummary(readTelemetry()),
        'interpretation' => [
            'legacy' => 'Legacy detecta varios problemas demasiado tarde y deja ambientes degradados o esquemas a medio aplicar.',
            'controlled' => 'Controlled mueve validaciones a preflight, hace canary y puede revertir si el fallo aparece despues del deploy.',
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
        $lines[] = 'app_deploy_success_total{mode="' . $label . '"} ' . ($stats['successes'] ?? 0);
        $lines[] = 'app_deploy_failure_total{mode="' . $label . '"} ' . ($stats['failures'] ?? 0);
        $lines[] = 'app_deploy_rollbacks_total{mode="' . $label . '"} ' . ($stats['rollbacks'] ?? 0);
        $lines[] = 'app_deploy_preflight_blocks_total{mode="' . $label . '"} ' . ($stats['preflight_blocks'] ?? 0);
    }

    foreach (($state['environments'] ?? []) as $environment => $env) {
        $label = prometheusLabel((string) $environment);
        $lines[] = 'app_environment_healthy{environment="' . $label . '"} ' . (($env['health'] ?? 'degraded') === 'healthy' ? 1 : 0);
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
            'case' => '06 - Broken pipeline and fragile delivery',
            'stack' => envOr('APP_STACK', 'PHP 8.3'),
            'goal' => 'Comparar un pipeline fragil que falla tarde con otro que bloquea riesgos temprano y sabe hacer rollback.',
            'routes' => [
                '/health' => 'Estado basico del servicio.',
                '/deploy-legacy?environment=staging&release=2026.04.1&scenario=missing_secret' => 'Simula un deploy fragil y tardio.',
                '/deploy-controlled?environment=staging&release=2026.04.1&scenario=missing_secret' => 'Simula un flujo con preflight checks, canary y rollback.',
                '/environments' => 'Estado actual de dev, staging y prod.',
                '/deployments?limit=10' => 'Ultimos despliegues observados por el laboratorio.',
                '/diagnostics/summary' => 'Resumen de despliegues, rollbacks y salud por ambiente.',
                '/metrics' => 'Metricas JSON del laboratorio.',
                '/metrics-prometheus' => 'Metricas en formato Prometheus.',
                '/reset-lab' => 'Reinicia ambientes e historial.',
            ],
            'allowed_scenarios' => array_keys(scenarioCatalog()),
        ];
    } elseif ($uri === '/health') {
        $payload = ['status' => 'ok', 'stack' => envOr('APP_STACK', 'PHP 8.3')];
    } elseif ($uri === '/deploy-legacy' || $uri === '/deploy-controlled') {
        $mode = $uri === '/deploy-legacy' ? 'legacy' : 'controlled';
        $environment = (string) ($query['environment'] ?? 'staging');
        if (!array_key_exists($environment, readState()['environments'])) {
            $environment = 'staging';
        }
        $scenario = (string) ($query['scenario'] ?? 'ok');
        if (!array_key_exists($scenario, scenarioCatalog())) {
            $scenario = 'ok';
        }
        $release = sanitizeRelease((string) ($query['release'] ?? '2026.04.1'));

        $result = $mode === 'legacy'
            ? runLegacyDeployment($environment, $release, $scenario)
            : runControlledDeployment($environment, $release, $scenario);

        $status = (int) $result['http_status'];
        $workflowContext = $result['context'];
        $payload = $result['payload'];
    } elseif ($uri === '/environments') {
        $payload = stateSummary()['environments'];
    } elseif ($uri === '/deployments') {
        $limit = clampInt((int) ($query['limit'] ?? 10), 1, 50);
        $payload = [
            'limit' => $limit,
            'deployments' => array_slice(telemetrySummary(readTelemetry())['recent_deployments'], 0, $limit),
        ];
    } elseif ($uri === '/diagnostics/summary') {
        $payload = diagnosticsSummary();
    } elseif ($uri === '/metrics') {
        $payload = array_merge(
            [
                'case' => '06 - Broken pipeline and fragile delivery',
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
        $payload = ['status' => 'reset', 'message' => 'Ambientes, historial y metricas reiniciados.'];
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
