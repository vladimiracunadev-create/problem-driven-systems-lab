<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$started = microtime(true);
$dbTimeMs = 0.0;
$dbQueries = 0;
$status = 200;
$skipStoreMetrics = false;

function timedQuery(PDO $pdo, string $sql, array $params, float &$dbTimeMs, int &$dbQueries): PDOStatement
{
    $stmt = $pdo->prepare($sql);
    $queryStart = microtime(true);
    $stmt->execute($params);
    $dbTimeMs += (microtime(true) - $queryStart) * 1000;
    $dbQueries++;
    return $stmt;
}

function recentOrdersLegacy(PDO $pdo, int $days, int $limit, float &$dbTimeMs, int &$dbQueries): array
{
    $orders = timedQuery(
        $pdo,
        "
        SELECT id, customer_id, status, total_amount, created_at
        FROM orders
        WHERE created_at >= NOW() - (CAST(? AS integer) * INTERVAL '1 day')
          AND status IN ('paid', 'shipped')
        ORDER BY created_at DESC
        LIMIT ?
        ",
        [$days, $limit],
        $dbTimeMs,
        $dbQueries
    )->fetchAll();

    foreach ($orders as &$order) {
        $customerId = (int) $order['customer_id'];
        $orderId = (int) $order['id'];

        $order['customer'] = timedQuery(
            $pdo,
            'SELECT id, name, email, segment FROM customers WHERE id = ?',
            [$customerId],
            $dbTimeMs,
            $dbQueries
        )->fetch();

        $items = timedQuery(
            $pdo,
            'SELECT id, product_id, quantity, unit_price FROM order_items WHERE order_id = ? ORDER BY id ASC',
            [$orderId],
            $dbTimeMs,
            $dbQueries
        )->fetchAll();

        foreach ($items as &$item) {
            $productId = (int) $item['product_id'];
            $product = timedQuery(
                $pdo,
                'SELECT id, sku, name, category_id, list_price FROM products WHERE id = ?',
                [$productId],
                $dbTimeMs,
                $dbQueries
            )->fetch();

            $item['product'] = $product;
            $item['category'] = timedQuery(
                $pdo,
                'SELECT id, name FROM categories WHERE id = ?',
                [(int) $product['category_id']],
                $dbTimeMs,
                $dbQueries
            )->fetch();
        }
        unset($item);

        $order['items'] = $items;
    }
    unset($order);

    return $orders;
}

function recentOrdersOptimized(PDO $pdo, int $days, int $limit, float &$dbTimeMs, int &$dbQueries): array
{
    $orders = timedQuery(
        $pdo,
        "
        SELECT
            o.id,
            o.customer_id,
            o.status,
            o.total_amount,
            o.created_at,
            c.name AS customer_name,
            c.email AS customer_email,
            c.segment AS customer_segment
        FROM orders o
        JOIN customers c ON c.id = o.customer_id
        WHERE o.created_at >= NOW() - (CAST(? AS integer) * INTERVAL '1 day')
          AND o.status IN ('paid', 'shipped')
        ORDER BY o.created_at DESC
        LIMIT ?
        ",
        [$days, $limit],
        $dbTimeMs,
        $dbQueries
    )->fetchAll();

    if (count($orders) === 0) {
        return [];
    }

    $orderIds = array_map(static fn(array $order): int => (int) $order['id'], $orders);
    $placeholders = placeholderList(count($orderIds));
    $items = timedQuery(
        $pdo,
        "
        SELECT
            oi.order_id,
            oi.id,
            oi.quantity,
            oi.unit_price,
            p.id AS product_id,
            p.sku,
            p.name AS product_name,
            p.list_price,
            c.id AS category_id,
            c.name AS category_name
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        JOIN categories c ON c.id = p.category_id
        WHERE oi.order_id IN ($placeholders)
        ORDER BY oi.order_id ASC, oi.id ASC
        ",
        $orderIds,
        $dbTimeMs,
        $dbQueries
    )->fetchAll();

    $itemsByOrder = [];
    foreach ($items as $item) {
        $itemsByOrder[(int) $item['order_id']][] = [
            'id' => (int) $item['id'],
            'quantity' => (int) $item['quantity'],
            'unit_price' => (float) $item['unit_price'],
            'product' => [
                'id' => (int) $item['product_id'],
                'sku' => $item['sku'],
                'name' => $item['product_name'],
                'list_price' => (float) $item['list_price'],
            ],
            'category' => [
                'id' => (int) $item['category_id'],
                'name' => $item['category_name'],
            ],
        ];
    }

    foreach ($orders as &$order) {
        $orderId = (int) $order['id'];
        $order['customer'] = [
            'id' => (int) $order['customer_id'],
            'name' => $order['customer_name'],
            'email' => $order['customer_email'],
            'segment' => $order['customer_segment'],
        ];
        unset($order['customer_name'], $order['customer_email'], $order['customer_segment']);
        $order['items'] = $itemsByOrder[$orderId] ?? [];
    }
    unset($order);

    return $orders;
}

function databaseDiagnostics(PDO $pdo, float &$dbTimeMs, int &$dbQueries): array
{
    $counts = timedQuery(
        $pdo,
        "
        SELECT
            (SELECT COUNT(*) FROM customers) AS customers_count,
            (SELECT COUNT(*) FROM categories) AS categories_count,
            (SELECT COUNT(*) FROM products) AS products_count,
            (SELECT COUNT(*) FROM orders) AS orders_count,
            (SELECT COUNT(*) FROM order_items) AS order_items_count
        ",
        [],
        $dbTimeMs,
        $dbQueries
    )->fetch();

    $density = timedQuery(
        $pdo,
        "
        SELECT
            ROUND(AVG(item_count), 2) AS avg_items_per_order,
            MAX(item_count) AS max_items_per_order
        FROM (
            SELECT order_id, COUNT(*) AS item_count
            FROM order_items
            GROUP BY order_id
        ) item_stats
        ",
        [],
        $dbTimeMs,
        $dbQueries
    )->fetch();

    return [
        'row_counts' => [
            'customers' => (int) ($counts['customers_count'] ?? 0),
            'categories' => (int) ($counts['categories_count'] ?? 0),
            'products' => (int) ($counts['products_count'] ?? 0),
            'orders' => (int) ($counts['orders_count'] ?? 0),
            'order_items' => (int) ($counts['order_items_count'] ?? 0),
        ],
        'relationships' => [
            'avg_items_per_order' => (float) ($density['avg_items_per_order'] ?? 0),
            'max_items_per_order' => (int) ($density['max_items_per_order'] ?? 0),
        ],
    ];
}

function diagnosticsSummary(PDO $pdo, float &$dbTimeMs, int &$dbQueries): array
{
    $metrics = metricsSummary(readMetrics());
    $legacy = $metrics['routes']['/orders-legacy'] ?? ['count' => 0, 'avg_ms' => 0.0, 'p95_ms' => 0.0, 'p99_ms' => 0.0, 'max_ms' => 0.0];
    $optimized = $metrics['routes']['/orders-optimized'] ?? ['count' => 0, 'avg_ms' => 0.0, 'p95_ms' => 0.0, 'p99_ms' => 0.0, 'max_ms' => 0.0];

    return [
        'case' => '02 - N+1 queries y cuellos de botella en base de datos',
        'stack' => envOr('APP_STACK', 'PHP 8.3'),
        'legacy' => $legacy,
        'optimized' => $optimized,
        'delta' => [
            'avg_ms' => round(($legacy['avg_ms'] ?? 0) - ($optimized['avg_ms'] ?? 0), 2),
            'p95_ms' => round(($legacy['p95_ms'] ?? 0) - ($optimized['p95_ms'] ?? 0), 2),
        ],
        'database' => databaseDiagnostics($pdo, $dbTimeMs, $dbQueries),
        'interpretation' => [
            'legacy_should_issue_many_queries' => 'La ruta legacy consulta cliente, items, producto y categoria dentro de bucles. El costo crece con cada pedido y con cada item.',
            'optimized_should_be_stable' => 'La ruta optimized mantiene una lectura base y otra lectura de detalles agrupados. El numero de queries no deberia crecer linealmente con el numero de items.',
        ],
    ];
}

function renderPrometheusMetrics(): string
{
    $metrics = metricsSummary(readMetrics());
    $lines = [];
    $lines[] = '# HELP app_requests_total Total de requests observados por el laboratorio.';
    $lines[] = '# TYPE app_requests_total counter';
    $lines[] = 'app_requests_total ' . ($metrics['requests_tracked'] ?? 0);

    $lines[] = '# HELP app_request_latency_ms Latencia agregada de requests en milisegundos.';
    $lines[] = '# TYPE app_request_latency_ms gauge';
    $lines[] = 'app_request_latency_ms{stat="avg"} ' . ($metrics['avg_ms'] ?? 0);
    $lines[] = 'app_request_latency_ms{stat="p95"} ' . ($metrics['p95_ms'] ?? 0);
    $lines[] = 'app_request_latency_ms{stat="p99"} ' . ($metrics['p99_ms'] ?? 0);

    $lines[] = '# HELP app_db_time_ms Tiempo agregado de DB por request en milisegundos.';
    $lines[] = '# TYPE app_db_time_ms gauge';
    $lines[] = 'app_db_time_ms{stat="avg"} ' . ($metrics['avg_db_time_ms'] ?? 0);
    $lines[] = 'app_db_time_ms{stat="p95"} ' . ($metrics['p95_db_time_ms'] ?? 0);

    $lines[] = '# HELP app_db_queries Cantidad de queries por request.';
    $lines[] = '# TYPE app_db_queries gauge';
    $lines[] = 'app_db_queries{stat="avg"} ' . ($metrics['avg_db_queries'] ?? 0);
    $lines[] = 'app_db_queries{stat="p95"} ' . ($metrics['p95_db_queries'] ?? 0);

    foreach (($metrics['routes'] ?? []) as $route => $stats) {
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
    $pdo = db();

    // ── Browser UI ───────────────────────────────────────────────────────────
    if (($uri === '/' || $uri === '') && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html')) {
        require __DIR__ . '/ui.php';
        exit;
    }
    // ─────────────────────────────────────────────────────────────────────────

    if ($uri === '/' || $uri === '') {
        $payload = [
            'lab' => 'Problem-Driven Systems Lab',
            'case' => '02 - N+1 queries y cuellos de botella en base de datos',
            'stack' => envOr('APP_STACK', 'PHP 8.3'),
            'goal' => 'Comparar una ruta legacy con relaciones cargadas dentro de bucles contra una ruta optimized con lecturas consolidadas.',
            'routes' => [
                '/health' => 'Estado basico del servicio.',
                '/orders-legacy?days=30&limit=20' => 'Version con N+1 sobre pedidos, cliente, items, producto y categoria.',
                '/orders-optimized?days=30&limit=20' => 'Version consolidada con lecturas agrupadas.',
                '/diagnostics/summary' => 'Resumen entre metricas y densidad relacional de la base.',
                '/metrics' => 'Metricas JSON del proceso de aplicacion.',
                '/metrics-prometheus' => 'Metricas en formato Prometheus.',
                '/reset-metrics' => 'Reinicia metricas locales.',
            ],
        ];
    } elseif ($uri === '/health') {
        $payload = ['status' => 'ok', 'stack' => envOr('APP_STACK', 'PHP 8.3')];
    } elseif ($uri === '/orders-legacy') {
        $days = clampInt((int) ($query['days'] ?? 30), 1, 180);
        $limit = clampInt((int) ($query['limit'] ?? 20), 1, 60);
        $orders = recentOrdersLegacy($pdo, $days, $limit, $dbTimeMs, $dbQueries);
        $payload = [
            'mode' => 'legacy',
            'problem' => 'N+1 sobre multiples relaciones con round-trips por pedido e item.',
            'days' => $days,
            'limit' => $limit,
            'result_count' => count($orders),
            'db_queries_in_request' => $dbQueries,
            'db_time_ms_in_request' => round($dbTimeMs, 2),
            'data' => $orders,
        ];
    } elseif ($uri === '/orders-optimized') {
        $days = clampInt((int) ($query['days'] ?? 30), 1, 180);
        $limit = clampInt((int) ($query['limit'] ?? 20), 1, 60);
        $orders = recentOrdersOptimized($pdo, $days, $limit, $dbTimeMs, $dbQueries);
        $payload = [
            'mode' => 'optimized',
            'solution' => 'Carga consolidada de pedidos y detalles para evitar round-trips repetidos.',
            'days' => $days,
            'limit' => $limit,
            'result_count' => count($orders),
            'db_queries_in_request' => $dbQueries,
            'db_time_ms_in_request' => round($dbTimeMs, 2),
            'data' => $orders,
        ];
    } elseif ($uri === '/diagnostics/summary') {
        $payload = diagnosticsSummary($pdo, $dbTimeMs, $dbQueries);
    } elseif ($uri === '/metrics') {
        $payload = array_merge(
            [
                'case' => '02 - N+1 queries y cuellos de botella en base de datos',
                'stack' => envOr('APP_STACK', 'PHP 8.3'),
            ],
            metricsSummary(readMetrics())
        );
    } elseif ($uri === '/metrics-prometheus') {
        $skipStoreMetrics = true;
        http_response_code(200);
        header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
        echo renderPrometheusMetrics();
        return;
    } elseif ($uri === '/reset-metrics') {
        writeMetrics(initialMetrics());
        $payload = ['status' => 'reset', 'message' => 'Metricas locales reiniciadas.'];
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
if (!$skipStoreMetrics && $uri !== '/metrics' && $uri !== '/reset-metrics') {
    storeRequestMetrics($uri, $status, $elapsedMs, $dbTimeMs, $dbQueries);
}
$payload['elapsed_ms'] = $elapsedMs;
$payload['timestamp_utc'] = gmdate('c');
$payload['pid'] = getmypid();
jsonResponse($status, $payload);
