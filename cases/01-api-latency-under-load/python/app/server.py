from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse
import json
import os
import random
import sqlite3
import tempfile
import threading
import time

APP_STACK = "Python 3.12"
CASE_NAME = "01 - API lenta bajo carga por cuellos de botella reales"
STORAGE_DIR = os.path.join(tempfile.gettempdir(), "pdsl-case01-python")
DB_PATH = os.path.join(STORAGE_DIR, "case01.sqlite3")
METRICS_PATH = os.path.join(STORAGE_DIR, "metrics.json")
WORKER_NAME = "report-refresh-python"
DB_LOCK = threading.RLock()


def ensure_storage():
    os.makedirs(STORAGE_DIR, exist_ok=True)


def db():
    ensure_storage()
    connection = sqlite3.connect(DB_PATH, check_same_thread=False)
    connection.row_factory = sqlite3.Row
    return connection


def seed_database():
    ensure_storage()
    if os.path.exists(DB_PATH):
        return

    rng = random.Random(102030)
    connection = db()
    cur = connection.cursor()
    cur.executescript(
        """
        CREATE TABLE customers (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            tier TEXT NOT NULL,
            region TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE orders (
            id INTEGER PRIMARY KEY,
            customer_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            total_amount REAL NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE customer_daily_summary (
            customer_id INTEGER NOT NULL,
            order_date INTEGER NOT NULL,
            total_amount REAL NOT NULL,
            order_count INTEGER NOT NULL,
            refreshed_at INTEGER NOT NULL,
            PRIMARY KEY (customer_id, order_date)
        );
        CREATE TABLE worker_state (
            worker_name TEXT PRIMARY KEY,
            last_heartbeat INTEGER,
            last_status TEXT NOT NULL,
            last_duration_ms REAL,
            last_message TEXT
        );
        CREATE TABLE job_runs (
            id INTEGER PRIMARY KEY,
            worker_name TEXT NOT NULL,
            status TEXT NOT NULL,
            started_at INTEGER NOT NULL,
            finished_at INTEGER,
            duration_ms REAL,
            rows_written INTEGER,
            note TEXT
        );
        CREATE INDEX idx_orders_created_customer ON orders (created_at, customer_id);
        CREATE INDEX idx_orders_customer_created ON orders (customer_id, created_at DESC);
        CREATE INDEX idx_orders_status_created ON orders (status, created_at);
        CREATE INDEX idx_summary_order_date_customer ON customer_daily_summary (order_date, customer_id);
        """
    )
    now = int(time.time())
    customers = [
        (
            i,
            f"Customer {i}",
            "gold" if i % 10 == 0 else "silver" if i % 3 == 0 else "bronze",
            ["north", "south", "east", "west"][i % 4],
            now - rng.randint(0, 365 * 86400),
        )
        for i in range(1, 1601)
    ]
    cur.executemany("INSERT INTO customers VALUES (?, ?, ?, ?, ?)", customers)

    orders = []
    for i in range(1, 36001):
        orders.append(
            (
                i,
                rng.randint(1, 1600),
                "paid" if rng.random() < 0.88 else "pending",
                round(15 + rng.random() * 1500, 2),
                now - rng.randint(0, 180 * 86400),
            )
        )
    cur.executemany("INSERT INTO orders VALUES (?, ?, ?, ?, ?)", orders)
    cur.execute(
        "INSERT INTO worker_state VALUES (?, ?, ?, ?, ?)",
        (WORKER_NAME, None, "init", None, "worker not started yet"),
    )
    connection.commit()
    connection.close()
    refresh_summary_once("initial seed")


def initial_metrics():
    return {
        "requests": 0,
        "samples_ms": [],
        "routes": {},
        "last_path": None,
        "last_status": 200,
        "last_updated": None,
        "last_db_time_ms": 0,
        "last_db_queries": 0,
        "db_time_samples_ms": [],
        "db_query_samples": [],
        "status_counts": {"2xx": 0, "4xx": 0, "5xx": 0},
    }


def read_metrics():
    ensure_storage()
    if not os.path.exists(METRICS_PATH):
        return initial_metrics()
    try:
        with open(METRICS_PATH, "r", encoding="utf-8") as handle:
            data = json.load(handle)
    except (OSError, json.JSONDecodeError):
        return initial_metrics()
    metrics = initial_metrics()
    metrics.update(data)
    metrics["status_counts"].update(data.get("status_counts", {}))
    return metrics


def write_metrics(metrics):
    ensure_storage()
    with open(METRICS_PATH, "w", encoding="utf-8") as handle:
        json.dump(metrics, handle, indent=2, ensure_ascii=False)


def percentile(values, percent):
    if not values:
        return 0.0
    ordered = sorted(values)
    index = max(0, min(len(ordered) - 1, int((percent / 100.0) * len(ordered) + 0.999999) - 1))
    return round(float(ordered[index]), 2)


def route_summary(routes):
    summary = {}
    for route, samples in routes.items():
        count = len(samples)
        summary[route] = {
            "count": count,
            "avg_ms": round(sum(samples) / count, 2) if count else 0.0,
            "p95_ms": percentile(samples, 95),
            "p99_ms": percentile(samples, 99),
            "max_ms": round(max(samples), 2) if count else 0.0,
        }
    return dict(sorted(summary.items()))


def metrics_summary(metrics):
    samples = metrics.get("samples_ms", [])
    db_times = metrics.get("db_time_samples_ms", [])
    db_queries = metrics.get("db_query_samples", [])
    count = len(samples)
    return {
        "requests_tracked": metrics.get("requests", 0),
        "sample_count": count,
        "avg_ms": round(sum(samples) / count, 2) if count else 0.0,
        "p95_ms": percentile(samples, 95),
        "p99_ms": percentile(samples, 99),
        "max_ms": round(max(samples), 2) if count else 0.0,
        "last_path": metrics.get("last_path"),
        "last_status": metrics.get("last_status", 200),
        "last_updated": metrics.get("last_updated"),
        "last_db_time_ms": metrics.get("last_db_time_ms", 0),
        "last_db_queries": metrics.get("last_db_queries", 0),
        "avg_db_time_ms": round(sum(db_times) / len(db_times), 2) if db_times else 0.0,
        "p95_db_time_ms": percentile(db_times, 95),
        "avg_db_queries": round(sum(db_queries) / len(db_queries), 2) if db_queries else 0.0,
        "p95_db_queries": percentile(db_queries, 95),
        "status_counts": metrics.get("status_counts", {}),
        "routes": route_summary(metrics.get("routes", {})),
    }


def bucket(status):
    if status >= 500:
        return "5xx"
    if status >= 400:
        return "4xx"
    return "2xx"


def store_metrics(path, status, elapsed_ms, db_time_ms, db_queries):
    metrics = read_metrics()
    metrics["requests"] += 1
    metrics["samples_ms"].append(round(elapsed_ms, 2))
    metrics["samples_ms"] = metrics["samples_ms"][-3000:]
    metrics["db_time_samples_ms"].append(round(db_time_ms, 2))
    metrics["db_time_samples_ms"] = metrics["db_time_samples_ms"][-3000:]
    metrics["db_query_samples"].append(db_queries)
    metrics["db_query_samples"] = metrics["db_query_samples"][-3000:]
    metrics["routes"].setdefault(path, [])
    metrics["routes"][path].append(round(elapsed_ms, 2))
    metrics["routes"][path] = metrics["routes"][path][-500:]
    metrics["status_counts"][bucket(status)] = metrics["status_counts"].get(bucket(status), 0) + 1
    metrics["last_path"] = path
    metrics["last_status"] = status
    metrics["last_updated"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    metrics["last_db_time_ms"] = round(db_time_ms, 2)
    metrics["last_db_queries"] = db_queries
    write_metrics(metrics)


def timed_query(connection, sql, params, stats, artificial_roundtrip_ms=0.7):
    started = time.perf_counter()
    with DB_LOCK:
        rows = connection.execute(sql, params).fetchall()
    if artificial_roundtrip_ms:
        time.sleep(artificial_roundtrip_ms / 1000.0)
    stats["db_time_ms"] += (time.perf_counter() - started) * 1000
    stats["db_queries"] += 1
    return [dict(row) for row in rows]


def clamp_int(value, minimum, maximum):
    try:
        parsed = int(value)
    except (TypeError, ValueError):
        parsed = minimum
    return max(minimum, min(maximum, parsed))


def day_bucket(timestamp):
    return int(timestamp // 86400)


def refresh_summary_once(note):
    started = time.perf_counter()
    started_at = int(time.time())
    connection = db()
    with DB_LOCK:
        connection.execute("DELETE FROM customer_daily_summary")
        rows = connection.execute(
            """
            INSERT INTO customer_daily_summary (customer_id, order_date, total_amount, order_count, refreshed_at)
            SELECT customer_id,
                   CAST(created_at / 86400 AS INTEGER) AS order_date,
                   ROUND(SUM(total_amount), 2) AS total_amount,
                   COUNT(*) AS order_count,
                   ?
            FROM orders
            WHERE status = 'paid'
            GROUP BY customer_id, CAST(created_at / 86400 AS INTEGER)
            """,
            (started_at,),
        ).rowcount
        duration_ms = round((time.perf_counter() - started) * 1000, 2)
        connection.execute(
            """
            UPDATE worker_state
            SET last_heartbeat = ?, last_status = ?, last_duration_ms = ?, last_message = ?
            WHERE worker_name = ?
            """,
            (int(time.time()), "ok", duration_ms, note, WORKER_NAME),
        )
        connection.execute(
            "INSERT INTO job_runs (worker_name, status, started_at, finished_at, duration_ms, rows_written, note) VALUES (?, ?, ?, ?, ?, ?, ?)",
            (WORKER_NAME, "ok", started_at, int(time.time()), duration_ms, rows, note),
        )
        connection.commit()
    connection.close()
    return {"rows_written": rows, "duration_ms": duration_ms}


def worker_loop():
    while True:
        time.sleep(20)
        try:
            refresh_summary_once("periodic summary refresh")
        except Exception:
            pass


def top_customers_legacy(connection, days, limit, stats):
    since_day = day_bucket(int(time.time()) - days * 86400)
    rows = timed_query(
        connection,
        """
        SELECT customer_id, ROUND(SUM(total_amount), 2) AS total_spend, COUNT(*) AS order_count
        FROM orders
        WHERE CAST(created_at / 86400 AS INTEGER) >= ? AND status = 'paid'
        GROUP BY customer_id
        ORDER BY total_spend DESC
        LIMIT ?
        """,
        (since_day, limit),
        stats,
        1.2,
    )
    for row in rows:
        customer = timed_query(
            connection,
            "SELECT id, name, tier, region FROM customers WHERE id = ?",
            (row["customer_id"],),
            stats,
        )[0]
        recent = timed_query(
            connection,
            "SELECT id, total_amount, status, created_at FROM orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 3",
            (row["customer_id"],),
            stats,
        )
        row["customer"] = customer
        row["recent_orders"] = recent
    return rows


def top_customers_optimized(connection, days, limit, stats):
    since_day = day_bucket(int(time.time()) - days * 86400)
    rows = timed_query(
        connection,
        """
        SELECT c.id AS customer_id, c.name, c.tier, c.region,
               ROUND(SUM(s.total_amount), 2) AS total_spend,
               SUM(s.order_count) AS order_count
        FROM customer_daily_summary s
        JOIN customers c ON c.id = s.customer_id
        WHERE s.order_date >= ?
        GROUP BY c.id, c.name, c.tier, c.region
        ORDER BY total_spend DESC
        LIMIT ?
        """,
        (since_day, limit),
        stats,
    )
    if not rows:
        return []
    ids = [row["customer_id"] for row in rows]
    placeholders = ",".join("?" for _ in ids)
    details = timed_query(
        connection,
        f"""
        SELECT customer_id, id, total_amount, status, created_at
        FROM (
            SELECT customer_id, id, total_amount, status, created_at,
                   ROW_NUMBER() OVER (PARTITION BY customer_id ORDER BY created_at DESC) AS rn
            FROM orders
            WHERE customer_id IN ({placeholders})
        )
        WHERE rn <= 3
        ORDER BY customer_id, created_at DESC
        """,
        ids,
        stats,
    )
    detail_map = {}
    for detail in details:
        detail_map.setdefault(detail["customer_id"], []).append(detail)
    for row in rows:
        row["recent_orders"] = detail_map.get(row["customer_id"], [])
    return rows


def worker_status(connection, stats):
    state = timed_query(
        connection,
        "SELECT worker_name, last_heartbeat, last_status, last_duration_ms, last_message FROM worker_state WHERE worker_name = ?",
        (WORKER_NAME,),
        stats,
        0,
    )
    runs = timed_query(
        connection,
        "SELECT id, status, started_at, finished_at, duration_ms, rows_written, note FROM job_runs WHERE worker_name = ? ORDER BY id DESC LIMIT 5",
        (WORKER_NAME,),
        stats,
        0,
    )
    return {"worker": state[0] if state else None, "recent_runs": runs}


def database_diagnostics(connection, stats):
    counts = timed_query(
        connection,
        """
        SELECT
            (SELECT COUNT(*) FROM customers) AS customers_count,
            (SELECT COUNT(*) FROM orders) AS orders_count,
            (SELECT COUNT(*) FROM customer_daily_summary) AS summary_count,
            (SELECT COUNT(*) FROM job_runs) AS job_runs_count
        """,
        (),
        stats,
        0,
    )[0]
    longest = timed_query(
        connection,
        "SELECT id, duration_ms, rows_written, started_at, finished_at, note FROM job_runs ORDER BY duration_ms DESC, id DESC LIMIT 5",
        (),
        stats,
        0,
    )
    return {
        "row_counts": {
            "customers": counts["customers_count"],
            "orders": counts["orders_count"],
            "customer_daily_summary": counts["summary_count"],
            "job_runs": counts["job_runs_count"],
        },
        "slowest_worker_runs": longest,
    }


def diagnostics_summary(connection, stats):
    summary = metrics_summary(read_metrics())
    legacy = summary["routes"].get("/report-legacy", {})
    optimized = summary["routes"].get("/report-optimized", {})
    return {
        "case": CASE_NAME,
        "stack": APP_STACK,
        "legacy": legacy,
        "optimized": optimized,
        "delta": {
            "avg_ms": round(legacy.get("avg_ms", 0) - optimized.get("avg_ms", 0), 2),
            "p95_ms": round(legacy.get("p95_ms", 0) - optimized.get("p95_ms", 0), 2),
        },
        "worker": worker_status(connection, stats),
        "database": database_diagnostics(connection, stats),
        "interpretation": {
            "legacy_route_should_be_higher": "La ruta legacy agrega sobre transacciones y luego enriquece con N+1.",
            "worker_pressure_note": "El worker refresca la tabla resumen periodicamente y deja visible la convivencia API/batch.",
        },
    }


def prometheus_label(value):
    return str(value).replace("\\", "\\\\").replace('"', '\\"').replace("\n", " ")


def render_prometheus_metrics(connection):
    stats = {"db_time_ms": 0.0, "db_queries": 0}
    summary = metrics_summary(read_metrics())
    worker = worker_status(connection, stats).get("worker") or {}
    lines = [
        "# HELP app_requests_total Total de requests observados por el laboratorio.",
        "# TYPE app_requests_total counter",
        f"app_requests_total {summary['requests_tracked']}",
        "# HELP app_request_latency_ms Latencia agregada de requests en milisegundos.",
        "# TYPE app_request_latency_ms gauge",
        f"app_request_latency_ms{{stat=\"avg\"}} {summary['avg_ms']}",
        f"app_request_latency_ms{{stat=\"p95\"}} {summary['p95_ms']}",
        f"app_request_latency_ms{{stat=\"p99\"}} {summary['p99_ms']}",
        "# HELP app_db_queries Cantidad de queries por request.",
        "# TYPE app_db_queries gauge",
        f"app_db_queries{{stat=\"avg\"}} {summary['avg_db_queries']}",
        f"app_db_queries{{stat=\"p95\"}} {summary['p95_db_queries']}",
    ]
    for route, route_stats in summary["routes"].items():
        label = prometheus_label(route)
        lines.append(f"app_route_latency_ms{{route=\"{label}\",stat=\"avg\"}} {route_stats['avg_ms']}")
        lines.append(f"app_route_latency_ms{{route=\"{label}\",stat=\"p95\"}} {route_stats['p95_ms']}")
        lines.append(f"app_route_requests_total{{route=\"{label}\"}} {route_stats['count']}")
    if worker:
        lines.append("# HELP app_worker_last_duration_ms Ultima duracion reportada por el worker.")
        lines.append("# TYPE app_worker_last_duration_ms gauge")
        lines.append(f"app_worker_last_duration_ms{{worker=\"{prometheus_label(WORKER_NAME)}\"}} {worker.get('last_duration_ms') or 0}")
    return "\n".join(lines) + "\n"


class Handler(BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        return

    def send_json(self, status, payload):
        body = json.dumps(payload, ensure_ascii=False, indent=2).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def send_text(self, status, body):
        encoded = body.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "text/plain; version=0.0.4; charset=utf-8")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def do_GET(self):
        started = time.perf_counter()
        parsed = urlparse(self.path)
        path = parsed.path or "/"
        query = parse_qs(parsed.query)
        stats = {"db_time_ms": 0.0, "db_queries": 0}
        status = 200
        skip_metrics = False
        connection = db()

        try:
            if path == "/":
                payload = {
                    "lab": "Problem-Driven Systems Lab",
                    "case": CASE_NAME,
                    "stack": APP_STACK,
                    "goal": "Comparar una ruta legacy con agregacion transaccional y N+1 contra una ruta optimizada con tabla resumen y worker concurrente.",
                    "routes": {
                        "/health": "Estado basico del servicio.",
                        "/report-legacy?days=30&limit=20": "Consulta defectuosa sobre tabla transaccional + N+1.",
                        "/report-optimized?days=30&limit=20": "Consulta mejorada contra tabla resumen.",
                        "/batch/status": "Estado del proceso critico concurrente.",
                        "/diagnostics/summary": "Resumen correlacionado entre metricas, worker y base local.",
                        "/job-runs?limit=10": "Ultimas ejecuciones del worker.",
                        "/metrics": "Metricas JSON.",
                        "/metrics-prometheus": "Metricas formato Prometheus.",
                        "/reset-metrics": "Reinicia metricas locales.",
                    },
                }
            elif path == "/health":
                payload = {"status": "ok", "stack": APP_STACK}
            elif path == "/report-legacy":
                days = clamp_int(query.get("days", ["30"])[0], 1, 180)
                limit = clamp_int(query.get("limit", ["20"])[0], 1, 50)
                rows = top_customers_legacy(connection, days, limit, stats)
                payload = {
                    "mode": "legacy",
                    "problem": "Filtro no sargable + patron N+1 + lectura directa desde transaccion.",
                    "days": days,
                    "limit": limit,
                    "result_count": len(rows),
                    "db_queries_in_request": stats["db_queries"],
                    "db_time_ms_in_request": round(stats["db_time_ms"], 2),
                    "data": rows,
                }
            elif path == "/report-optimized":
                days = clamp_int(query.get("days", ["30"])[0], 1, 180)
                limit = clamp_int(query.get("limit", ["20"])[0], 1, 50)
                rows = top_customers_optimized(connection, days, limit, stats)
                payload = {
                    "mode": "optimized",
                    "solution": "Tabla resumen + menos consultas + mejor convivencia con el worker.",
                    "days": days,
                    "limit": limit,
                    "result_count": len(rows),
                    "db_queries_in_request": stats["db_queries"],
                    "db_time_ms_in_request": round(stats["db_time_ms"], 2),
                    "data": rows,
                }
            elif path == "/batch/status":
                payload = worker_status(connection, stats)
            elif path == "/job-runs":
                limit = clamp_int(query.get("limit", ["10"])[0], 1, 50)
                payload = {
                    "limit": limit,
                    "runs": timed_query(
                        connection,
                        "SELECT id, worker_name, status, started_at, finished_at, duration_ms, rows_written, note FROM job_runs ORDER BY id DESC LIMIT ?",
                        (limit,),
                        stats,
                        0,
                    ),
                }
            elif path == "/diagnostics/summary":
                payload = diagnostics_summary(connection, stats)
            elif path == "/metrics":
                payload = {
                    "case": CASE_NAME,
                    "stack": APP_STACK,
                    **metrics_summary(read_metrics()),
                    "note": "Metrica util de laboratorio. No reemplaza observabilidad enterprise completa.",
                }
            elif path == "/metrics-prometheus":
                skip_metrics = True
                self.send_text(200, render_prometheus_metrics(connection))
                return
            elif path == "/reset-metrics":
                write_metrics(initial_metrics())
                payload = {"status": "reset", "message": "Metricas locales reiniciadas."}
            else:
                status = 404
                payload = {"error": "Ruta no encontrada", "path": path}
        except Exception as error:
            status = 500
            payload = {"error": "Fallo al procesar la solicitud", "message": str(error), "path": path}
        finally:
            connection.close()

        elapsed_ms = round((time.perf_counter() - started) * 1000, 2)
        if not skip_metrics and path not in ("/metrics", "/reset-metrics"):
            store_metrics(path, status, elapsed_ms, stats["db_time_ms"], stats["db_queries"])
        payload["elapsed_ms"] = elapsed_ms
        payload["timestamp_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        payload["pid"] = os.getpid()
        self.send_json(status, payload)


seed_database()
threading.Thread(target=worker_loop, daemon=True).start()
PORT = int(os.environ.get("PORT", "8080"))
server = ThreadingHTTPServer(("0.0.0.0", PORT), Handler)
print(f"Servidor Python escuchando en {PORT}")
server.serve_forever()
