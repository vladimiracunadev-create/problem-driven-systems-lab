<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$workerName = envOr('WORKER_NAME', 'report-refresh');
$lookbackDays = max(7, (int) envOr('WORKER_LOOKBACK_DAYS', '45'));
$intervalSeconds = max(5, (int) envOr('WORKER_INTERVAL_SECONDS', '20'));

function markWorker(PDO $pdo, string $workerName, string $status, ?float $durationMs, string $message): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO worker_state (worker_name, last_heartbeat, last_status, last_duration_ms, last_message)
         VALUES (?, NOW(), ?, ?, ?)
         ON CONFLICT (worker_name)
         DO UPDATE SET last_heartbeat = EXCLUDED.last_heartbeat,
                       last_status = EXCLUDED.last_status,
                       last_duration_ms = EXCLUDED.last_duration_ms,
                       last_message = EXCLUDED.last_message'
    );
    $stmt->execute([$workerName, $status, $durationMs, $message]);
}

function insertRun(PDO $pdo, string $workerName): int
{
    $stmt = $pdo->prepare('INSERT INTO job_runs (worker_name, status, started_at, note) VALUES (?, ?, NOW(), ?) RETURNING id');
    $stmt->execute([$workerName, 'running', 'refresh started']);
    return (int) $stmt->fetchColumn();
}

function finishRun(PDO $pdo, int $runId, string $status, float $durationMs, int $rowsWritten, string $note): void
{
    $stmt = $pdo->prepare('UPDATE job_runs SET status = ?, finished_at = NOW(), duration_ms = ?, rows_written = ?, note = ? WHERE id = ?');
    $stmt->execute([$status, round($durationMs, 2), $rowsWritten, $note, $runId]);
}

$pdo = db();
markWorker($pdo, $workerName, 'starting', null, 'worker booting');

while (true) {
    $runId = insertRun($pdo, $workerName);
    $started = microtime(true);
    $rowsWritten = 0;

    try {
        $pdo->beginTransaction();

        $deleteStmt = $pdo->prepare('DELETE FROM customer_daily_summary WHERE order_date >= CURRENT_DATE - CAST(? AS integer)');
        $deleteStmt->execute([$lookbackDays]);

        $insertSql = "
            INSERT INTO customer_daily_summary (customer_id, order_date, total_amount, order_count, refreshed_at)
            SELECT o.customer_id,
                   DATE(o.created_at) AS order_date,
                   ROUND(SUM(o.total_amount), 2) AS total_amount,
                   COUNT(*) AS order_count,
                   NOW() AS refreshed_at
            FROM orders o
            WHERE o.status = 'paid'
              AND o.created_at >= NOW() - (CAST(? AS integer) * INTERVAL '1 day')
            GROUP BY o.customer_id, DATE(o.created_at)
        ";
        $insertStmt = $pdo->prepare($insertSql);
        $insertStmt->execute([$lookbackDays]);
        $rowsWritten = $insertStmt->rowCount();

        $pdo->commit();

        $durationMs = (microtime(true) - $started) * 1000;
        markWorker($pdo, $workerName, 'ok', $durationMs, 'summary refreshed');
        finishRun($pdo, $runId, 'success', $durationMs, $rowsWritten, 'summary refresh complete');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $durationMs = (microtime(true) - $started) * 1000;
        markWorker($pdo, $workerName, 'error', $durationMs, $e->getMessage());
        finishRun($pdo, $runId, 'error', $durationMs, $rowsWritten, $e->getMessage());
    }

    sleep($intervalSeconds);
}
