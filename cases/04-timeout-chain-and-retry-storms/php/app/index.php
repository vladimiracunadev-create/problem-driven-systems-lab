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
            'hint' => 'Proveedor estable y sin degradacion visible.',
            'description' => 'La dependencia responde rapido y no dispara reintentos.',
        ],
        'slow_provider' => [
            'hint' => 'El proveedor se vuelve mas lento que el timeout esperado.',
            'description' => 'Las respuestas tardan mas de lo saludable y tensionan la cadena.',
        ],
        'flaky_provider' => [
            'hint' => 'El primer intento falla, el siguiente puede recuperarse.',
            'description' => 'Sirve para observar reintentos utiles versus agresivos.',
        ],
        'provider_down' => [
            'hint' => 'La dependencia no responde dentro del timeout y la carga se amplifica.',
            'description' => 'Escenario tipico de tormenta de reintentos y caida en cascada.',
        ],
        'burst_then_recover' => [
            'hint' => 'Hay dos fallos iniciales y luego recuperacion.',
            'description' => 'Permite ver como la estrategia cambia el tiempo total hasta recuperar servicio.',
        ],
    ];
}

function resiliencePolicy(string $mode): array
{
    if ($mode === 'legacy') {
        return [
            'timeout_ms' => 360,
            'max_attempts' => 4,
            'backoff_base_ms' => 0,
            'use_circuit_breaker' => false,
            'allow_fallback' => false,
        ];
    }

    return [
        'timeout_ms' => 220,
        'max_attempts' => 2,
        'backoff_base_ms' => 80,
        'use_circuit_breaker' => true,
        'allow_fallback' => true,
    ];
}

function isCircuitOpen(array $provider): bool
{
    if (($provider['opened_until'] ?? null) === null) {
        return false;
    }

    return strtotime((string) $provider['opened_until']) > time();
}

function calculateBackoffMs(int $attempt, int $baseMs): int
{
    if ($baseMs <= 0) {
        return 0;
    }

    $jitter = random_int(15, 45);
    return (int) (($baseMs * (2 ** max(0, $attempt - 1))) + $jitter);
}

function simulatedQuoteAmount(int $customerId, int $items): float
{
    return round(18.5 + ($items * 7.25) + (($customerId % 7) * 1.35), 2);
}

function providerSuccessPayload(int $customerId, int $items): array
{
    return [
        'quote_id' => 'quote-' . strtoupper(bin2hex(random_bytes(3))),
        'amount' => simulatedQuoteAmount($customerId, $items),
        'currency' => 'USD',
        'source' => 'live',
        'cached_at' => null,
    ];
}

function simulateProviderCall(string $scenario, int $attempt, int $timeoutMs): array
{
    switch ($scenario) {
        case 'slow_provider':
            $providerLatencyMs = 640 + random_int(0, 90);
            $waitedMs = min($providerLatencyMs, $timeoutMs);
            usleep($waitedMs * 1000);
            if ($providerLatencyMs > $timeoutMs) {
                return [
                    'ok' => false,
                    'result' => 'timeout',
                    'provider_latency_ms' => $providerLatencyMs,
                    'waited_ms' => $waitedMs,
                    'message' => 'El proveedor responde fuera del deadline.',
                ];
            }
            break;

        case 'flaky_provider':
            if ($attempt === 1) {
                $providerLatencyMs = 520 + random_int(0, 40);
                $waitedMs = min($providerLatencyMs, $timeoutMs);
                usleep($waitedMs * 1000);
                return [
                    'ok' => false,
                    'result' => 'timeout',
                    'provider_latency_ms' => $providerLatencyMs,
                    'waited_ms' => $waitedMs,
                    'message' => 'El primer intento cae en timeout.',
                ];
            }

            $providerLatencyMs = 150 + random_int(0, 45);
            usleep($providerLatencyMs * 1000);
            return [
                'ok' => true,
                'result' => 'success',
                'provider_latency_ms' => $providerLatencyMs,
                'waited_ms' => $providerLatencyMs,
                'message' => 'El proveedor se recupero en el siguiente intento.',
            ];

        case 'provider_down':
            $providerLatencyMs = $timeoutMs + 260 + random_int(0, 80);
            $waitedMs = $timeoutMs;
            usleep($waitedMs * 1000);
            return [
                'ok' => false,
                'result' => 'timeout',
                'provider_latency_ms' => $providerLatencyMs,
                'waited_ms' => $waitedMs,
                'message' => 'La dependencia no responde dentro del timeout configurado.',
            ];

        case 'burst_then_recover':
            if ($attempt <= 2) {
                $providerLatencyMs = $timeoutMs + 140 + random_int(0, 60);
                $waitedMs = $timeoutMs;
                usleep($waitedMs * 1000);
                return [
                    'ok' => false,
                    'result' => 'timeout',
                    'provider_latency_ms' => $providerLatencyMs,
                    'waited_ms' => $waitedMs,
                    'message' => 'Pico transitorio que demora mas de lo permitido.',
                ];
            }

            $providerLatencyMs = 165 + random_int(0, 35);
            usleep($providerLatencyMs * 1000);
            return [
                'ok' => true,
                'result' => 'success',
                'provider_latency_ms' => $providerLatencyMs,
                'waited_ms' => $providerLatencyMs,
                'message' => 'El proveedor se recupera despues del pico inicial.',
            ];

        case 'ok':
        default:
            $providerLatencyMs = 115 + random_int(0, 35);
            usleep($providerLatencyMs * 1000);
            return [
                'ok' => true,
                'result' => 'success',
                'provider_latency_ms' => $providerLatencyMs,
                'waited_ms' => $providerLatencyMs,
                'message' => 'Respuesta normal del proveedor.',
            ];
    }

    $providerLatencyMs = 160 + random_int(0, 20);
    usleep($providerLatencyMs * 1000);
    return [
        'ok' => true,
        'result' => 'success',
        'provider_latency_ms' => $providerLatencyMs,
        'waited_ms' => $providerLatencyMs,
        'message' => 'Respuesta normal del proveedor.',
    ];
}

function runQuoteFlow(string $mode, string $scenario, int $customerId, int $items): array
{
    $policy = resiliencePolicy($mode);
    $requestId = requestId('req');
    $traceId = requestId('trace');
    $state = readDependencyState();
    $provider = $state['provider'];
    $events = [];
    $attempts = 0;
    $timeoutCount = 0;
    $circuitOpened = false;
    $shortCircuited = false;

    if ($policy['use_circuit_breaker'] && isCircuitOpen($provider)) {
        $shortCircuited = true;
        $state['provider']['short_circuit_count'] = (int) ($state['provider']['short_circuit_count'] ?? 0) + 1;
        $state['provider']['last_updated'] = gmdate('c');
        writeDependencyState($state);

        $events[] = [
            'step' => 'carrier.quote',
            'status' => 'short_circuited',
            'message' => 'Circuit breaker abierto: se evita golpear una dependencia inestable.',
        ];

        if (($provider['fallback_quote'] ?? null) !== null) {
            $quote = $provider['fallback_quote'];
            $quote['source'] = 'fallback';

            return [
                'http_status' => 200,
                'payload' => [
                    'mode' => $mode,
                    'scenario' => $scenario,
                    'status' => 'degraded',
                    'message' => 'Se uso fallback porque el circuito estaba abierto.',
                    'request_id' => $requestId,
                    'trace_id' => $traceId,
                    'customer_id' => $customerId,
                    'items' => $items,
                    'quote' => $quote,
                    'attempts' => 0,
                    'retries' => 0,
                    'timeout_count' => 0,
                    'events' => $events,
                    'dependency' => [
                        'circuit_status' => 'open',
                        'opened_until' => $provider['opened_until'] ?? null,
                    ],
                ],
                'context' => [
                    'mode' => $mode,
                    'scenario' => $scenario,
                    'outcome' => 'success',
                    'attempts' => 0,
                    'retries' => 0,
                    'timeout_count' => 0,
                    'fallback_used' => true,
                    'circuit_opened' => false,
                    'short_circuited' => $shortCircuited,
                    'degraded' => true,
                    'request_id' => $requestId,
                    'trace_id' => $traceId,
                    'events' => $events,
                ],
            ];
        }
    }

    for ($attempt = 1; $attempt <= $policy['max_attempts']; $attempt++) {
        $attempts = $attempt;
        $call = simulateProviderCall($scenario, $attempt, $policy['timeout_ms']);
        $events[] = [
            'step' => 'carrier.quote',
            'attempt' => $attempt,
            'timeout_ms' => $policy['timeout_ms'],
            'status' => $call['ok'] ? 'ok' : $call['result'],
            'provider_latency_ms' => $call['provider_latency_ms'],
            'waited_ms' => $call['waited_ms'],
            'message' => $call['message'],
        ];

        $state['provider']['last_latency_ms'] = $call['waited_ms'];
        $state['provider']['last_updated'] = gmdate('c');

        if ($call['ok']) {
            $quote = providerSuccessPayload($customerId, $items);
            $state['provider']['consecutive_failures'] = 0;
            $state['provider']['opened_until'] = null;
            $state['provider']['last_outcome'] = 'success';
            $state['provider']['fallback_quote'] = array_merge($quote, ['source' => 'cached', 'cached_at' => gmdate('c')]);
            writeDependencyState($state);

            return [
                'http_status' => 200,
                'payload' => [
                    'mode' => $mode,
                    'scenario' => $scenario,
                    'status' => 'completed',
                    'request_id' => $requestId,
                    'trace_id' => $traceId,
                    'customer_id' => $customerId,
                    'items' => $items,
                    'quote' => $quote,
                    'attempts' => $attempts,
                    'retries' => max(0, $attempts - 1),
                    'timeout_count' => $timeoutCount,
                    'events' => $events,
                    'dependency' => [
                        'circuit_status' => 'closed',
                        'consecutive_failures' => 0,
                    ],
                ],
                'context' => [
                    'mode' => $mode,
                    'scenario' => $scenario,
                    'outcome' => 'success',
                    'attempts' => $attempts,
                    'retries' => max(0, $attempts - 1),
                    'timeout_count' => $timeoutCount,
                    'fallback_used' => false,
                    'circuit_opened' => $circuitOpened,
                    'short_circuited' => false,
                    'degraded' => false,
                    'request_id' => $requestId,
                    'trace_id' => $traceId,
                    'events' => $events,
                ],
            ];
        }

        if ($call['result'] === 'timeout') {
            $timeoutCount++;
        }

        $state['provider']['consecutive_failures'] = (int) ($state['provider']['consecutive_failures'] ?? 0) + 1;
        $state['provider']['last_outcome'] = $call['result'];

        if ($policy['use_circuit_breaker'] && (int) $state['provider']['consecutive_failures'] >= 2 && !isCircuitOpen($state['provider'])) {
            $state['provider']['opened_until'] = gmdate('c', time() + 30);
            $state['provider']['open_events'] = (int) ($state['provider']['open_events'] ?? 0) + 1;
            $circuitOpened = true;
        }

        writeDependencyState($state);

        if ($attempt < $policy['max_attempts']) {
            $backoffMs = calculateBackoffMs($attempt, $policy['backoff_base_ms']);
            if ($backoffMs > 0) {
                usleep($backoffMs * 1000);
            }
            $events[] = [
                'step' => 'retry.wait',
                'attempt' => $attempt,
                'status' => 'scheduled',
                'backoff_ms' => $backoffMs,
            ];
        }
    }

    if ($policy['allow_fallback'] && ($state['provider']['fallback_quote'] ?? null) !== null) {
        $quote = $state['provider']['fallback_quote'];
        $quote['source'] = 'fallback';

        return [
            'http_status' => 200,
            'payload' => [
                'mode' => $mode,
                'scenario' => $scenario,
                'status' => 'degraded',
                'message' => 'El flujo se completo con fallback para evitar una cascada de fallas.',
                'request_id' => $requestId,
                'trace_id' => $traceId,
                'customer_id' => $customerId,
                'items' => $items,
                'quote' => $quote,
                'attempts' => $attempts,
                'retries' => max(0, $attempts - 1),
                'timeout_count' => $timeoutCount,
                'events' => $events,
                'dependency' => [
                    'circuit_status' => isCircuitOpen($state['provider']) ? 'open' : 'closed',
                    'opened_until' => $state['provider']['opened_until'] ?? null,
                ],
            ],
            'context' => [
                'mode' => $mode,
                'scenario' => $scenario,
                'outcome' => 'success',
                'attempts' => $attempts,
                'retries' => max(0, $attempts - 1),
                'timeout_count' => $timeoutCount,
                'fallback_used' => true,
                'circuit_opened' => $circuitOpened,
                'short_circuited' => false,
                'degraded' => true,
                'request_id' => $requestId,
                'trace_id' => $traceId,
                'events' => $events,
            ],
        ];
    }

    return [
        'http_status' => 504,
        'payload' => [
            'mode' => $mode,
            'scenario' => $scenario,
            'error' => 'Timeout chain detected',
            'message' => $mode === 'legacy'
                ? 'La dependencia agoto los intentos y la request quedo atrapada en una tormenta de retries.'
                : 'La dependencia siguio fallando y no habia fallback suficiente para degradar con seguridad.',
            'request_id' => $requestId,
            'trace_id' => $traceId,
            'customer_id' => $customerId,
            'items' => $items,
            'attempts' => $attempts,
            'retries' => max(0, $attempts - 1),
            'timeout_count' => $timeoutCount,
            'events' => $events,
            'dependency' => [
                'circuit_status' => isCircuitOpen($state['provider']) ? 'open' : 'closed',
                'opened_until' => $state['provider']['opened_until'] ?? null,
                'consecutive_failures' => $state['provider']['consecutive_failures'] ?? 0,
            ],
        ],
        'context' => [
            'mode' => $mode,
            'scenario' => $scenario,
            'outcome' => 'failure',
            'attempts' => $attempts,
            'retries' => max(0, $attempts - 1),
            'timeout_count' => $timeoutCount,
            'fallback_used' => false,
            'circuit_opened' => $circuitOpened,
            'short_circuited' => false,
            'degraded' => false,
            'request_id' => $requestId,
            'trace_id' => $traceId,
            'events' => $events,
        ],
    ];
}

function dependencySummary(): array
{
    $provider = readDependencyState()['provider'];

    return [
        'provider' => $provider['name'] ?? 'carrier-gateway',
        'circuit_status' => isCircuitOpen($provider) ? 'open' : 'closed',
        'opened_until' => $provider['opened_until'] ?? null,
        'consecutive_failures' => (int) ($provider['consecutive_failures'] ?? 0),
        'last_outcome' => $provider['last_outcome'] ?? 'unknown',
        'last_latency_ms' => (float) ($provider['last_latency_ms'] ?? 0),
        'open_events' => (int) ($provider['open_events'] ?? 0),
        'short_circuit_count' => (int) ($provider['short_circuit_count'] ?? 0),
        'fallback_quote' => $provider['fallback_quote'] ?? null,
    ];
}

function diagnosticsSummary(): array
{
    return [
        'case' => '04 - Timeout chain and retry storms',
        'stack' => envOr('APP_STACK', 'PHP 8.3'),
        'metrics' => telemetrySummary(readTelemetry()),
        'dependency' => dependencySummary(),
        'interpretation' => [
            'legacy' => 'Legacy deja que el timeout se repita varias veces y convierte una falla de proveedor en mas espera, mas intentos y mas carga saliente.',
            'resilient' => 'Resilient acorta timeouts, usa backoff, abre circuito y puede degradar con fallback para contener el incidente.',
        ],
    ];
}

function renderPrometheusMetrics(): string
{
    $summary = telemetrySummary(readTelemetry());
    $dependency = dependencySummary();
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
        $lines[] = 'app_flow_avg_attempts{mode="' . $label . '"} ' . ($stats['avg_attempts_per_flow'] ?? 0);
        $lines[] = 'app_flow_timeouts_total{mode="' . $label . '"} ' . ($stats['timeouts_total'] ?? 0);
        $lines[] = 'app_flow_fallback_total{mode="' . $label . '"} ' . ($stats['fallbacks_used'] ?? 0);
        $lines[] = 'app_flow_circuit_open_total{mode="' . $label . '"} ' . ($stats['circuit_opens'] ?? 0);
        $lines[] = 'app_flow_short_circuit_total{mode="' . $label . '"} ' . ($stats['short_circuits'] ?? 0);
    }

    foreach (($summary['routes'] ?? []) as $route => $stats) {
        $label = prometheusLabel((string) $route);
        $lines[] = 'app_route_latency_ms{route="' . $label . '",stat="avg"} ' . ($stats['avg_ms'] ?? 0);
        $lines[] = 'app_route_latency_ms{route="' . $label . '",stat="p95"} ' . ($stats['p95_ms'] ?? 0);
        $lines[] = 'app_route_requests_total{route="' . $label . '"} ' . ($stats['count'] ?? 0);
    }

    $lines[] = 'dependency_circuit_open{provider="' . prometheusLabel((string) ($dependency['provider'] ?? 'carrier-gateway')) . '"} ' . (($dependency['circuit_status'] ?? 'closed') === 'open' ? 1 : 0);
    $lines[] = 'dependency_short_circuit_total{provider="' . prometheusLabel((string) ($dependency['provider'] ?? 'carrier-gateway')) . '"} ' . ($dependency['short_circuit_count'] ?? 0);

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
            'case' => '04 - Timeout chain and retry storms',
            'stack' => envOr('APP_STACK', 'PHP 8.3'),
            'goal' => 'Comparar una integracion con retries agresivos contra una variante que usa timeout corto, backoff, circuit breaker y fallback.',
            'routes' => [
                '/health' => 'Estado basico del servicio.',
                '/quote-legacy?scenario=provider_down&customer_id=42&items=3' => 'Hace retries agresivos y amplifica el incidente.',
                '/quote-resilient?scenario=provider_down&customer_id=42&items=3' => 'Contiene la degradacion con limites, circuito y fallback.',
                '/dependency/state' => 'Estado actual del proveedor simulado y del circuit breaker.',
                '/incidents?limit=10' => 'Ultimos incidentes observados por el laboratorio.',
                '/diagnostics/summary' => 'Resumen de metricas y postura actual de resiliencia.',
                '/metrics' => 'Metricas JSON del laboratorio.',
                '/metrics-prometheus' => 'Metricas en formato Prometheus.',
                '/reset-lab' => 'Reinicia metricas y estado de la dependencia.',
            ],
            'allowed_scenarios' => array_keys(scenarioCatalog()),
        ];
    } elseif ($uri === '/health') {
        $payload = ['status' => 'ok', 'stack' => envOr('APP_STACK', 'PHP 8.3')];
    } elseif ($uri === '/quote-legacy' || $uri === '/quote-resilient') {
        $mode = $uri === '/quote-legacy' ? 'legacy' : 'resilient';
        $scenario = (string) ($query['scenario'] ?? 'provider_down');
        if (!array_key_exists($scenario, scenarioCatalog())) {
            $scenario = 'provider_down';
        }
        $customerId = clampInt((int) ($query['customer_id'] ?? 42), 1, 5000);
        $items = clampInt((int) ($query['items'] ?? 3), 1, 25);

        $result = runQuoteFlow($mode, $scenario, $customerId, $items);
        $status = (int) $result['http_status'];
        $workflowContext = $result['context'];
        $payload = $result['payload'];
    } elseif ($uri === '/dependency/state') {
        $payload = dependencySummary();
    } elseif ($uri === '/incidents') {
        $limit = clampInt((int) ($query['limit'] ?? 10), 1, 50);
        $payload = [
            'limit' => $limit,
            'incidents' => array_slice(telemetrySummary(readTelemetry())['recent_incidents'], 0, $limit),
        ];
    } elseif ($uri === '/diagnostics/summary') {
        $payload = diagnosticsSummary();
    } elseif ($uri === '/metrics') {
        $payload = array_merge(
            [
                'case' => '04 - Timeout chain and retry storms',
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
        $payload = ['status' => 'reset', 'message' => 'Metricas y estado del proveedor reiniciados.'];
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
