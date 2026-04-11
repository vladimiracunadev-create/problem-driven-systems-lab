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

function topCustomersLegacy(PDO $pdo, int $days, int $limit, float &$dbTimeMs, int &$dbQueries): array
{
    $sql = "
        SELECT o.customer_id, ROUND(SUM(o.total_amount), 2) AS total_spend, COUNT(*) AS order_count
        FROM orders o
        WHERE DATE(o.created_at) >= CURRENT_DATE - CAST(? AS integer)
          AND o.status = 'paid'
        GROUP BY o.customer_id
        ORDER BY total_spend DESC
        LIMIT ?
    ";
    $rows = timedQuery($pdo, $sql, [$days, $limit], $dbTimeMs, $dbQueries)->fetchAll();

    foreach ($rows as &$row) {
        $customerId = (int) $row['customer_id'];
        $customer = timedQuery(
            $pdo,
            'SELECT id, name, tier, region FROM customers WHERE id = ?',
            [$customerId],
            $dbTimeMs,
            $dbQueries
        )->fetch();

        $recentOrders = timedQuery(
            $pdo,
            'SELECT id, total_amount, status, created_at FROM orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 3',
            [$customerId],
            $dbTimeMs,
            $dbQueries
        )->fetchAll();

        $row['customer'] = $customer;
        $row['recent_orders'] = $recentOrders;
    }
    unset($row);

    return $rows;
}

function topCustomersOptimized(PDO $pdo, int $days, int $limit, float &$dbTimeMs, int &$dbQueries): array
{
    $summarySql = "
        SELECT c.id AS customer_id,
               c.name,
               c.tier,
               c.region,
               ROUND(SUM(s.total_amount), 2) AS total_spend,
               SUM(s.order_count) AS order_count
        FROM customer_daily_summary s
        JOIN customers c ON c.id = s.customer_id
        WHERE s.order_date >= CURRENT_DATE - CAST(? AS integer)
        GROUP BY c.id, c.name, c.tier, c.region
        ORDER BY total_spend DESC
        LIMIT ?
    ";
    $rows = timedQuery($pdo, $summarySql, [$days, $limit], $dbTimeMs, $dbQueries)->fetchAll();

    if (count($rows) === 0) {
        return [];
    }

    $ids = array_map(static fn(array $row): int => (int) $row['customer_id'], $rows);
    $placeholders = placeholderList(count($ids));
    $detailSql = "
        SELECT customer_id, id, total_amount, status, created_at
        FROM (
            SELECT o.customer_id,
                   o.id,
                   o.total_amount,
                   o.status,
                   o.created_at,
                   ROW_NUMBER() OVER (PARTITION BY o.customer_id ORDER BY o.created_at DESC) AS rn
            FROM orders o
            WHERE o.customer_id IN ($placeholders)
        ) ranked
        WHERE rn <= 3
        ORDER BY customer_id, created_at DESC
    ";
    $detailRows = timedQuery($pdo, $detailSql, $ids, $dbTimeMs, $dbQueries)->fetchAll();
    $detailMap = [];
    foreach ($detailRows as $detail) {
        $detailMap[(int) $detail['customer_id']][] = [
            'id' => (int) $detail['id'],
            'total_amount' => (float) $detail['total_amount'],
            'status' => $detail['status'],
            'created_at' => $detail['created_at'],
        ];
    }

    foreach ($rows as &$row) {
        $customerId = (int) $row['customer_id'];
        $row['recent_orders'] = $detailMap[$customerId] ?? [];
    }
    unset($row);

    return $rows;
}

function workerStatus(PDO $pdo, string $workerName, float &$dbTimeMs, int &$dbQueries): array
{
    $state = timedQuery(
        $pdo,
        'SELECT worker_name, last_heartbeat, last_status, last_duration_ms, last_message FROM worker_state WHERE worker_name = ?',
        [$workerName],
        $dbTimeMs,
        $dbQueries
    )->fetch();

    $runs = timedQuery(
        $pdo,
        'SELECT id, status, started_at, finished_at, duration_ms, rows_written, note FROM job_runs WHERE worker_name = ? ORDER BY id DESC LIMIT 5',
        [$workerName],
        $dbTimeMs,
        $dbQueries
    )->fetchAll();

    return [
        'worker' => $state,
        'recent_runs' => $runs,
    ];
}

function databaseDiagnostics(PDO $pdo, float &$dbTimeMs, int &$dbQueries): array
{
    $counts = timedQuery(
        $pdo,
        "
        SELECT
            (SELECT COUNT(*) FROM customers) AS customers_count,
            (SELECT COUNT(*) FROM orders) AS orders_count,
            (SELECT COUNT(*) FROM customer_daily_summary) AS summary_count,
            (SELECT COUNT(*) FROM job_runs) AS job_runs_count
        ",
        [],
        $dbTimeMs,
        $dbQueries
    )->fetch();

    $dbSize = timedQuery(
        $pdo,
        'SELECT pg_size_pretty(pg_database_size(current_database())) AS database_size_pretty, pg_database_size(current_database()) AS database_size_bytes',
        [],
        $dbTimeMs,
        $dbQueries
    )->fetch();

    $longest = timedQuery(
        $pdo,
        "
        SELECT id, duration_ms, rows_written, started_at, finished_at, note
        FROM job_runs
        ORDER BY duration_ms DESC NULLS LAST, id DESC
        LIMIT 5
        ",
        [],
        $dbTimeMs,
        $dbQueries
    )->fetchAll();

    return [
        'row_counts' => [
            'customers' => (int) ($counts['customers_count'] ?? 0),
            'orders' => (int) ($counts['orders_count'] ?? 0),
            'customer_daily_summary' => (int) ($counts['summary_count'] ?? 0),
            'job_runs' => (int) ($counts['job_runs_count'] ?? 0),
        ],
        'database_size' => [
            'pretty' => $dbSize['database_size_pretty'] ?? null,
            'bytes' => (int) ($dbSize['database_size_bytes'] ?? 0),
        ],
        'slowest_worker_runs' => $longest,
    ];
}

function summaryComparison(PDO $pdo, string $workerName, float &$dbTimeMs, int &$dbQueries): array
{
    $worker = workerStatus($pdo, $workerName, $dbTimeMs, $dbQueries);
    $db = databaseDiagnostics($pdo, $dbTimeMs, $dbQueries);
    $metrics = metricsSummary(readMetrics());

    $legacy = $metrics['routes']['/report-legacy'] ?? ['count' => 0, 'avg_ms' => 0.0, 'p95_ms' => 0.0, 'p99_ms' => 0.0, 'max_ms' => 0.0];
    $optimized = $metrics['routes']['/report-optimized'] ?? ['count' => 0, 'avg_ms' => 0.0, 'p95_ms' => 0.0, 'p99_ms' => 0.0, 'max_ms' => 0.0];

    $deltaP95 = ($legacy['p95_ms'] ?? 0) - ($optimized['p95_ms'] ?? 0);
    $deltaAvg = ($legacy['avg_ms'] ?? 0) - ($optimized['avg_ms'] ?? 0);

    return [
        'case' => '01 - API lenta bajo carga por cuellos de botella reales',
        'stack' => envOr('APP_STACK', 'PHP 8.3'),
        'legacy' => $legacy,
        'optimized' => $optimized,
        'delta' => [
            'avg_ms' => round($deltaAvg, 2),
            'p95_ms' => round($deltaP95, 2),
        ],
        'worker' => $worker,
        'database' => $db,
        'interpretation' => [
            'legacy_route_should_be_higher' => 'Si todo está funcionando como laboratorio real, /report-legacy debería mostrar más consultas y peor latencia que /report-optimized.',
            'worker_pressure_note' => 'El worker introduce competencia real sobre la base de datos; si aumenta su duración o baja su heartbeat, el costo del diseño defectuoso se hace más visible.',
        ],
    ];
}

function renderPrometheusMetrics(PDO $pdo, string $workerName): string
{
    $metrics = metricsSummary(readMetrics());
    $worker = workerStatus($pdo, $workerName, $dbTimeMs, $dbQueries);
    $lines = [];
    $lines[] = '# HELP app_requests_total Total de requests observados por el laboratorio.';
    $lines[] = '# TYPE app_requests_total counter';
    $lines[] = 'app_requests_total ' . ($metrics['requests_tracked'] ?? 0);

    $lines[] = '# HELP app_request_latency_ms Latencia agregada de requests en milisegundos.';
    $lines[] = '# TYPE app_request_latency_ms gauge';
    $lines[] = 'app_request_latency_ms{stat="avg"} ' . ($metrics['avg_ms'] ?? 0);
    $lines[] = 'app_request_latency_ms{stat="p95"} ' . ($metrics['p95_ms'] ?? 0);
    $lines[] = 'app_request_latency_ms{stat="p99"} ' . ($metrics['p99_ms'] ?? 0);
    $lines[] = 'app_request_latency_ms{stat="max"} ' . ($metrics['max_ms'] ?? 0);

    $lines[] = '# HELP app_db_time_ms Tiempo agregado de DB por request en milisegundos.';
    $lines[] = '# TYPE app_db_time_ms gauge';
    $lines[] = 'app_db_time_ms{stat="avg"} ' . ($metrics['avg_db_time_ms'] ?? 0);
    $lines[] = 'app_db_time_ms{stat="p95"} ' . ($metrics['p95_db_time_ms'] ?? 0);

    $lines[] = '# HELP app_db_queries Cantidad de queries por request.';
    $lines[] = '# TYPE app_db_queries gauge';
    $lines[] = 'app_db_queries{stat="avg"} ' . ($metrics['avg_db_queries'] ?? 0);
    $lines[] = 'app_db_queries{stat="p95"} ' . ($metrics['p95_db_queries'] ?? 0);

    foreach (($metrics['status_counts'] ?? []) as $bucket => $count) {
        $lines[] = 'app_status_total{bucket="' . prometheusLabel((string) $bucket) . '"} ' . $count;
    }

    foreach (($metrics['routes'] ?? []) as $route => $stats) {
        $label = prometheusLabel((string) $route);
        $lines[] = 'app_route_latency_ms{route="' . $label . '",stat="avg"} ' . ($stats['avg_ms'] ?? 0);
        $lines[] = 'app_route_latency_ms{route="' . $label . '",stat="p95"} ' . ($stats['p95_ms'] ?? 0);
        $lines[] = 'app_route_latency_ms{route="' . $label . '",stat="p99"} ' . ($stats['p99_ms'] ?? 0);
        $lines[] = 'app_route_requests_total{route="' . $label . '"} ' . ($stats['count'] ?? 0);
    }

    $workerState = $worker['worker'] ?? null;
    if (is_array($workerState)) {
        $lastDuration = $workerState['last_duration_ms'] ?? 0;
        $status = (string) ($workerState['last_status'] ?? 'unknown');
        $lines[] = '# HELP app_worker_last_duration_ms Última duración reportada por el worker.';
        $lines[] = '# TYPE app_worker_last_duration_ms gauge';
        $lines[] = 'app_worker_last_duration_ms{worker="' . prometheusLabel($workerName) . '"} ' . (float) $lastDuration;
        $lines[] = '# HELP app_worker_status Estado lógico del worker. 1=ok, 0=otro estado.';
        $lines[] = '# TYPE app_worker_status gauge';
        $lines[] = 'app_worker_status{worker="' . prometheusLabel($workerName) . '",status="' . prometheusLabel($status) . '"} ' . ($status === 'ok' ? 1 : 0);
    }

    return implode("
", $lines) . "
";
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '', $query);
$workerName = envOr('WORKER_NAME', 'report-refresh');

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
            'case' => '01 - API lenta bajo carga por cuellos de botella reales',
            'stack' => envOr('APP_STACK', 'PHP 8.3'),
            'goal' => 'Comparar una ruta legacy con N+1 y filtro no sargable versus una ruta optimizada con tabla resumen y worker concurrente.',
            'routes' => [
                '/health' => 'Estado básico del servicio.',
                '/report-legacy?days=30&limit=20' => 'Consulta defectuosa sobre tabla transaccional + N+1.',
                '/report-optimized?days=30&limit=20' => 'Consulta mejorada contra tabla resumen.',
                '/batch/status' => 'Estado del proceso crítico concurrente.',
                '/diagnostics/summary' => 'Resumen correlacionado entre métricas, worker y base de datos.',
                '/job-runs?limit=10' => 'Últimas ejecuciones del proceso batch.',
                '/metrics' => 'Métricas JSON del proceso de aplicación.',
                '/metrics-prometheus' => 'Métricas en formato Prometheus.',
                '/reset-metrics' => 'Reinicia métricas locales.',
            ],
            'observability' => [
                'prometheus' => 'http://localhost:9091',
                'grafana' => 'http://localhost:3001',
                'postgres_exporter' => 'http://localhost:9187/metrics',
            ],
        ];
    } elseif ($uri === '/health') {
        $payload = ['status' => 'ok', 'stack' => envOr('APP_STACK', 'PHP 8.3')];
    } elseif ($uri === '/report-legacy') {
        $days = clampInt((int) ($query['days'] ?? 30), 1, 180);
        $limit = clampInt((int) ($query['limit'] ?? 20), 1, 50);
        $rows = topCustomersLegacy($pdo, $days, $limit, $dbTimeMs, $dbQueries);
        $payload = [
            'mode' => 'legacy',
            'problem' => 'Filtro no sargable + patrón N+1 + lectura directa desde transacción.',
            'days' => $days,
            'limit' => $limit,
            'result_count' => count($rows),
            'db_queries_in_request' => $dbQueries,
            'db_time_ms_in_request' => round($dbTimeMs, 2),
            'data' => $rows,
        ];
    } elseif ($uri === '/report-optimized') {
        $days = clampInt((int) ($query['days'] ?? 30), 1, 180);
        $limit = clampInt((int) ($query['limit'] ?? 20), 1, 50);
        $rows = topCustomersOptimized($pdo, $days, $limit, $dbTimeMs, $dbQueries);
        $payload = [
            'mode' => 'optimized',
            'solution' => 'Tabla resumen + menos consultas + mejor convivencia con el worker.',
            'days' => $days,
            'limit' => $limit,
            'result_count' => count($rows),
            'db_queries_in_request' => $dbQueries,
            'db_time_ms_in_request' => round($dbTimeMs, 2),
            'data' => $rows,
        ];
    } elseif ($uri === '/batch/status') {
        $payload = workerStatus($pdo, $workerName, $dbTimeMs, $dbQueries);
    } elseif ($uri === '/job-runs') {
        $limit = clampInt((int) ($query['limit'] ?? 10), 1, 50);
        $runs = timedQuery(
            $pdo,
            'SELECT id, worker_name, status, started_at, finished_at, duration_ms, rows_written, note FROM job_runs ORDER BY id DESC LIMIT ?',
            [$limit],
            $dbTimeMs,
            $dbQueries
        )->fetchAll();
        $payload = [
            'limit' => $limit,
            'runs' => $runs,
        ];
    } elseif ($uri === '/diagnostics/summary') {
        $payload = summaryComparison($pdo, $workerName, $dbTimeMs, $dbQueries);
    } elseif ($uri === '/metrics') {
        $payload = array_merge(
            [
                'case' => '01 - API lenta bajo carga por cuellos de botella reales',
                'stack' => envOr('APP_STACK', 'PHP 8.3'),
            ],
            metricsSummary(readMetrics()),
            [
                'note' => 'Métrica útil de laboratorio. Se complementa con /metrics-prometheus, Prometheus, Grafana y el exporter de PostgreSQL.',
            ]
        );
    } elseif ($uri === '/metrics-prometheus') {
        $skipStoreMetrics = true;
        $text = renderPrometheusMetrics($pdo, $workerName);
        http_response_code(200);
        header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
        echo $text;
        return;
    } elseif ($uri === '/reset-metrics') {
        writeMetrics(initialMetrics());
        $payload = ['status' => 'reset', 'message' => 'Métricas locales reiniciadas.'];
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
