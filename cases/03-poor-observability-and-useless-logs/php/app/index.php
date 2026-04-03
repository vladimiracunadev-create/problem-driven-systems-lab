<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

final class WorkflowFailure extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $step,
        private readonly string $dependency,
        private readonly int $httpStatus,
        private readonly string $requestId,
        private readonly string $traceId,
        private readonly array $events
    ) {
        parent::__construct($message);
    }

    public function step(): string
    {
        return $this->step;
    }

    public function dependency(): string
    {
        return $this->dependency;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    public function events(): array
    {
        return $this->events;
    }
}

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
        'ok' => ['step' => null, 'dependency' => null, 'http_status' => 200, 'error_class' => null, 'hint' => null],
        'inventory_conflict' => ['step' => 'inventory.reserve', 'dependency' => 'inventory-service', 'http_status' => 503, 'error_class' => 'inventory_conflict', 'hint' => 'Revisar disponibilidad y bloqueos del stock.'],
        'payment_timeout' => ['step' => 'payment.authorize', 'dependency' => 'payment-gateway', 'http_status' => 504, 'error_class' => 'payment_timeout', 'hint' => 'Inspeccionar latencia del gateway y politicas de timeout.'],
        'notification_down' => ['step' => 'notification.dispatch', 'dependency' => 'notification-provider', 'http_status' => 502, 'error_class' => 'notification_dependency_failure', 'hint' => 'Validar el proveedor de notificaciones y su cola de salida.'],
    ];
}

function workflowDefinition(): array
{
    return [
        ['name' => 'cart.validate', 'dependency' => 'internal', 'base_ms' => 18],
        ['name' => 'inventory.reserve', 'dependency' => 'inventory-service', 'base_ms' => 52],
        ['name' => 'payment.authorize', 'dependency' => 'payment-gateway', 'base_ms' => 145],
        ['name' => 'notification.dispatch', 'dependency' => 'notification-provider', 'base_ms' => 36],
    ];
}

function runCheckout(string $mode, string $scenario, int $customerId, int $cartItems): array
{
    $catalog = scenarioCatalog();
    $scenarioMeta = $catalog[$scenario] ?? $catalog['ok'];
    $traceId = requestId('trace');
    $requestId = requestId('req');
    $orderRef = 'ORD-' . strtoupper(bin2hex(random_bytes(3)));
    $events = [];

    if ($mode === 'legacy') {
        appendLegacyLog('checkout started');
        appendLegacyLog('processing customer=' . $customerId);
    } else {
        appendStructuredLog([
            'level' => 'info',
            'event' => 'checkout_started',
            'request_id' => $requestId,
            'trace_id' => $traceId,
            'customer_id' => $customerId,
            'cart_items' => $cartItems,
            'scenario' => $scenario,
            'order_ref' => $orderRef,
        ]);
    }

    foreach (workflowDefinition() as $step) {
        $stepStarted = microtime(true);
        usleep(($step['base_ms'] + random_int(4, 18)) * 1000);
        $elapsedMs = round((microtime(true) - $stepStarted) * 1000, 2);

        if ($scenarioMeta['step'] === $step['name']) {
            $events[] = [
                'step' => $step['name'],
                'dependency' => $step['dependency'],
                'status' => 'error',
                'elapsed_ms' => $elapsedMs,
            ];

            if ($mode === 'legacy') {
                appendLegacyLog('checkout failed');
                appendLegacyLog('external dependency issue');
            } else {
                appendStructuredLog([
                    'level' => 'error',
                    'event' => 'dependency_failed',
                    'request_id' => $requestId,
                    'trace_id' => $traceId,
                    'customer_id' => $customerId,
                    'cart_items' => $cartItems,
                    'scenario' => $scenario,
                    'step' => $step['name'],
                    'dependency' => $step['dependency'],
                    'elapsed_ms' => $elapsedMs,
                    'error_class' => $scenarioMeta['error_class'],
                    'hint' => $scenarioMeta['hint'],
                ]);
            }

            throw new WorkflowFailure(
                'No se pudo completar el checkout.',
                $step['name'],
                $step['dependency'],
                $scenarioMeta['http_status'],
                $requestId,
                $traceId,
                $events
            );
        }

        $events[] = [
            'step' => $step['name'],
            'dependency' => $step['dependency'],
            'status' => 'ok',
            'elapsed_ms' => $elapsedMs,
        ];

        if ($mode === 'legacy') {
            if ($step['name'] === 'payment.authorize') {
                appendLegacyLog('payment step completed');
            }
        } else {
            appendStructuredLog([
                'level' => 'info',
                'event' => 'step_completed',
                'request_id' => $requestId,
                'trace_id' => $traceId,
                'customer_id' => $customerId,
                'cart_items' => $cartItems,
                'scenario' => $scenario,
                'step' => $step['name'],
                'dependency' => $step['dependency'],
                'elapsed_ms' => $elapsedMs,
            ]);
        }
    }

    if ($mode === 'legacy') {
        appendLegacyLog('checkout completed');
    } else {
        appendStructuredLog([
            'level' => 'info',
            'event' => 'checkout_completed',
            'request_id' => $requestId,
            'trace_id' => $traceId,
            'customer_id' => $customerId,
            'cart_items' => $cartItems,
            'scenario' => $scenario,
            'order_ref' => $orderRef,
            'step_count' => count($events),
        ]);
    }

    return [
        'request_id' => $requestId,
        'trace_id' => $traceId,
        'order_ref' => $orderRef,
        'events' => $events,
    ];
}

function diagnosticsSummary(): array
{
    return [
        'case' => '03 - Observabilidad deficiente y logs inutiles',
        'stack' => envOr('APP_STACK', 'PHP 8.3'),
        'metrics' => telemetrySummary(readTelemetry()),
        'answerability' => [
            'legacy' => [
                'request_correlation' => false,
                'failing_step_identified' => false,
                'dependency_identified' => false,
                'latency_breakdown_by_step' => false,
            ],
            'observable' => [
                'request_correlation' => true,
                'failing_step_identified' => true,
                'dependency_identified' => true,
                'latency_breakdown_by_step' => true,
            ],
        ],
        'recent_legacy_logs' => tailLines(legacyLogPath(), 6),
        'recent_observable_logs' => tailLines(observableLogPath(), 6),
    ];
}

function renderPrometheusMetrics(): string
{
    $summary = telemetrySummary(readTelemetry());
    $lines = [];
    $lines[] = '# HELP app_requests_total Total de requests observados por el laboratorio.';
    $lines[] = '# TYPE app_requests_total counter';
    $lines[] = 'app_requests_total ' . ($summary['requests_tracked'] ?? 0);

    $lines[] = '# HELP app_request_latency_ms Latencia agregada de requests en milisegundos.';
    $lines[] = '# TYPE app_request_latency_ms gauge';
    $lines[] = 'app_request_latency_ms{stat="avg"} ' . ($summary['avg_ms'] ?? 0);
    $lines[] = 'app_request_latency_ms{stat="p95"} ' . ($summary['p95_ms'] ?? 0);
    $lines[] = 'app_request_latency_ms{stat="p99"} ' . ($summary['p99_ms'] ?? 0);

    foreach (($summary['successes'] ?? []) as $mode => $count) {
        $lines[] = 'app_workflow_success_total{mode="' . prometheusLabel((string) $mode) . '"} ' . $count;
    }

    foreach (($summary['failures'] ?? []) as $mode => $failureData) {
        $lines[] = 'app_workflow_failures_total{mode="' . prometheusLabel((string) $mode) . '"} ' . ($failureData['total'] ?? 0);

        foreach (($failureData['by_step'] ?? []) as $step => $count) {
            $lines[] = 'app_workflow_failures_by_step_total{mode="' . prometheusLabel((string) $mode) . '",step="' . prometheusLabel((string) $step) . '"} ' . $count;
        }

        foreach (($failureData['by_scenario'] ?? []) as $scenario => $count) {
            $lines[] = 'app_workflow_failures_by_scenario_total{mode="' . prometheusLabel((string) $mode) . '",scenario="' . prometheusLabel((string) $scenario) . '"} ' . $count;
        }
    }

    foreach (($summary['routes'] ?? []) as $route => $stats) {
        $label = prometheusLabel((string) $route);
        $lines[] = 'app_route_latency_ms{route="' . $label . '",stat="avg"} ' . ($stats['avg_ms'] ?? 0);
        $lines[] = 'app_route_latency_ms{route="' . $label . '",stat="p95"} ' . ($stats['p95_ms'] ?? 0);
        $lines[] = 'app_route_requests_total{route="' . $label . '"} ' . ($stats['count'] ?? 0);
    }

    return implode("\n", $lines) . "\n";
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '', $query);

try {
    if ($uri === '/' || $uri === '') {
        $payload = [
            'lab' => 'Problem-Driven Systems Lab',
            'case' => '03 - Observabilidad deficiente y logs inutiles',
            'stack' => envOr('APP_STACK', 'PHP 8.3'),
            'goal' => 'Comparar un flujo con logs pobres contra el mismo flujo con telemetria util, correlation IDs y trazas locales.',
            'routes' => [
                '/health' => 'Estado basico del servicio.',
                '/checkout-legacy?scenario=payment_timeout&customer_id=42&cart_items=3' => 'Ejecuta el flujo con evidencia pobre y poco accionable.',
                '/checkout-observable?scenario=payment_timeout&customer_id=42&cart_items=3' => 'Ejecuta el flujo con logs estructurados, request_id y trazabilidad.',
                '/logs/legacy?tail=20' => 'Ultimas lineas del log legacy.',
                '/logs/observable?tail=20' => 'Ultimas lineas del log estructurado.',
                '/traces?limit=10' => 'Ultimos rastros locales del laboratorio.',
                '/diagnostics/summary' => 'Resumen de telemetria y capacidad de diagnostico.',
                '/metrics' => 'Metricas JSON del proceso.',
                '/metrics-prometheus' => 'Metricas en formato Prometheus.',
                '/reset-observability' => 'Reinicia logs y telemetria local.',
            ],
            'allowed_scenarios' => array_keys(scenarioCatalog()),
        ];
    } elseif ($uri === '/health') {
        $payload = ['status' => 'ok', 'stack' => envOr('APP_STACK', 'PHP 8.3')];
    } elseif ($uri === '/checkout-legacy' || $uri === '/checkout-observable') {
        $mode = $uri === '/checkout-legacy' ? 'legacy' : 'observable';
        $scenario = (string) ($query['scenario'] ?? 'ok');
        if (!array_key_exists($scenario, scenarioCatalog())) {
            $scenario = 'ok';
        }
        $customerId = clampInt((int) ($query['customer_id'] ?? 42), 1, 5000);
        $cartItems = clampInt((int) ($query['cart_items'] ?? 3), 1, 25);

        try {
            $result = runCheckout($mode, $scenario, $customerId, $cartItems);
            $workflowContext = [
                'mode' => $mode,
                'scenario' => $scenario,
                'outcome' => 'success',
                'failing_step' => null,
                'dependency' => null,
                'request_id' => $result['request_id'],
                'trace_id' => $result['trace_id'],
                'customer_id' => $customerId,
                'cart_items' => $cartItems,
                'events' => $result['events'],
            ];
            $payload = [
                'mode' => $mode,
                'scenario' => $scenario,
                'status' => 'completed',
                'customer_id' => $customerId,
                'cart_items' => $cartItems,
                'order_ref' => $result['order_ref'],
                'events' => $result['events'],
            ];
            if ($mode === 'observable') {
                $payload['request_id'] = $result['request_id'];
                $payload['trace_id'] = $result['trace_id'];
            }
        } catch (WorkflowFailure $failure) {
            $status = $failure->httpStatus();
            $workflowContext = [
                'mode' => $mode,
                'scenario' => $scenario,
                'outcome' => 'failure',
                'failing_step' => $failure->step(),
                'dependency' => $failure->dependency(),
                'request_id' => $mode === 'observable' ? $failure->requestId() : null,
                'trace_id' => $mode === 'observable' ? $failure->traceId() : null,
                'customer_id' => $customerId,
                'cart_items' => $cartItems,
                'events' => $failure->events(),
            ];

            $payload = [
                'mode' => $mode,
                'scenario' => $scenario,
                'error' => 'Checkout fallido',
                'message' => $mode === 'observable'
                    ? 'Fallo el checkout. Usa request_id y trace_id para correlacionar el incidente.'
                    : 'No se pudo completar la operacion.',
            ];

            if ($mode === 'observable') {
                $payload['request_id'] = $workflowContext['request_id'];
                $payload['trace_id'] = $workflowContext['trace_id'];
                $payload['failed_step'] = $failure->step();
                $payload['dependency'] = $failure->dependency();
            }
        }
    } elseif ($uri === '/logs/legacy') {
        $tail = clampInt((int) ($query['tail'] ?? 20), 1, 200);
        $payload = ['mode' => 'legacy', 'tail' => $tail, 'lines' => tailLines(legacyLogPath(), $tail)];
    } elseif ($uri === '/logs/observable') {
        $tail = clampInt((int) ($query['tail'] ?? 20), 1, 200);
        $payload = ['mode' => 'observable', 'tail' => $tail, 'lines' => tailLines(observableLogPath(), $tail)];
    } elseif ($uri === '/traces') {
        $limit = clampInt((int) ($query['limit'] ?? 10), 1, 50);
        $summary = telemetrySummary(readTelemetry());
        $payload = ['limit' => $limit, 'traces' => array_slice($summary['recent_traces'], 0, $limit)];
    } elseif ($uri === '/diagnostics/summary') {
        $payload = diagnosticsSummary();
    } elseif ($uri === '/metrics') {
        $payload = array_merge(
            [
                'case' => '03 - Observabilidad deficiente y logs inutiles',
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
    } elseif ($uri === '/reset-observability') {
        resetTelemetryState();
        $payload = ['status' => 'reset', 'message' => 'Logs y telemetria reiniciados.'];
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
if (!$skipStoreMetrics && $uri !== '/metrics' && $uri !== '/reset-observability') {
    recordRequestTelemetry($uri, $status, $elapsedMs, $workflowContext);
}
$payload['elapsed_ms'] = $elapsedMs;
$payload['timestamp_utc'] = gmdate('c');
$payload['pid'] = getmypid();
jsonResponse($status, $payload);
