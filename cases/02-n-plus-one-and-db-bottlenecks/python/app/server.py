from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse
import json
import os
import random
import sqlite3
import tempfile
import time

APP_STACK = "Python 3.12"
CASE_NAME = "02 - N+1 queries y cuellos de botella en base de datos"
STORAGE_DIR = os.path.join(tempfile.gettempdir(), "pdsl-case02-python")
DB_PATH = os.path.join(STORAGE_DIR, "case02.sqlite3")
METRICS_PATH = os.path.join(STORAGE_DIR, "metrics.json")


def ensure_storage():
    os.makedirs(STORAGE_DIR, exist_ok=True)


def db():
    ensure_storage()
    connection = sqlite3.connect(DB_PATH)
    connection.row_factory = sqlite3.Row
    return connection


def seed_database():
    ensure_storage()
    if os.path.exists(DB_PATH):
        return

    rng = random.Random(20260427)
    connection = db()
    cur = connection.cursor()
    cur.executescript(
        """
        CREATE TABLE customers (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            segment TEXT NOT NULL
        );
        CREATE TABLE categories (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL
        );
        CREATE TABLE products (
            id INTEGER PRIMARY KEY,
            sku TEXT NOT NULL,
            name TEXT NOT NULL,
            category_id INTEGER NOT NULL,
            list_price REAL NOT NULL
        );
        CREATE TABLE orders (
            id INTEGER PRIMARY KEY,
            customer_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            total_amount REAL NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE order_items (
            id INTEGER PRIMARY KEY,
            order_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL,
            unit_price REAL NOT NULL
        );
        CREATE INDEX idx_orders_created_status ON orders (created_at DESC, status);
        CREATE INDEX idx_orders_customer_created ON orders (customer_id, created_at DESC);
        CREATE INDEX idx_order_items_order ON order_items (order_id);
        CREATE INDEX idx_products_category ON products (category_id);
        """
    )

    categories = [(i, f"Category {i}") for i in range(1, 25)]
    customers = [
        (
            i,
            f"Customer {i}",
            f"customer{i}@lab.local",
            "enterprise" if i % 12 == 0 else "mid-market" if i % 4 == 0 else "smb",
        )
        for i in range(1, 901)
    ]
    products = [
        (
            i,
            f"SKU-{i:04d}",
            f"Product {i}",
            1 + ((i - 1) % 24),
            round(15 + rng.random() * 250, 2),
        )
        for i in range(1, 361)
    ]
    cur.executemany("INSERT INTO categories VALUES (?, ?)", categories)
    cur.executemany("INSERT INTO customers VALUES (?, ?, ?, ?)", customers)
    cur.executemany("INSERT INTO products VALUES (?, ?, ?, ?, ?)", products)

    now = int(time.time())
    item_id = 1
    for order_id in range(1, 2601):
        created_at = now - rng.randint(0, 120 * 86400)
        status = "paid" if rng.random() < 0.55 else "shipped" if rng.random() < 0.85 else "pending"
        customer_id = rng.randint(1, 900)
        items = []
        total = 0.0
        for _ in range(rng.randint(2, 6)):
            product_id = rng.randint(1, 360)
            quantity = rng.randint(1, 3)
            unit_price = round(10 + rng.random() * 220, 2)
            total += quantity * unit_price
            items.append((item_id, order_id, product_id, quantity, unit_price))
            item_id += 1
        cur.execute(
            "INSERT INTO orders VALUES (?, ?, ?, ?, ?)",
            (order_id, customer_id, status, round(total, 2), created_at),
        )
        cur.executemany("INSERT INTO order_items VALUES (?, ?, ?, ?, ?)", items)

    connection.commit()
    connection.close()


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
    result = {}
    for route, samples in routes.items():
        count = len(samples)
        result[route] = {
            "count": count,
            "avg_ms": round(sum(samples) / count, 2) if count else 0.0,
            "p95_ms": percentile(samples, 95),
            "p99_ms": percentile(samples, 99),
            "max_ms": round(max(samples), 2) if count else 0.0,
        }
    return dict(sorted(result.items()))


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


def timed_query(connection, sql, params, stats, artificial_roundtrip_ms=0.8):
    started = time.perf_counter()
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


def recent_orders_legacy(connection, days, limit, stats):
    since = int(time.time()) - days * 86400
    orders = timed_query(
        connection,
        """
        SELECT id, customer_id, status, total_amount, created_at
        FROM orders
        WHERE created_at >= ? AND status IN ('paid', 'shipped')
        ORDER BY created_at DESC
        LIMIT ?
        """,
        (since, limit),
        stats,
    )
    for order in orders:
        customer = timed_query(
            connection,
            "SELECT id, name, email, segment FROM customers WHERE id = ?",
            (order["customer_id"],),
            stats,
        )[0]
        items = timed_query(
            connection,
            "SELECT id, product_id, quantity, unit_price FROM order_items WHERE order_id = ? ORDER BY id ASC",
            (order["id"],),
            stats,
        )
        for item in items:
            product = timed_query(
                connection,
                "SELECT id, sku, name, category_id, list_price FROM products WHERE id = ?",
                (item["product_id"],),
                stats,
            )[0]
            category = timed_query(
                connection,
                "SELECT id, name FROM categories WHERE id = ?",
                (product["category_id"],),
                stats,
            )[0]
            item["product"] = product
            item["category"] = category
        order["customer"] = customer
        order["items"] = items
    return orders


def recent_orders_optimized(connection, days, limit, stats):
    since = int(time.time()) - days * 86400
    orders = timed_query(
        connection,
        """
        SELECT o.id, o.customer_id, o.status, o.total_amount, o.created_at,
               c.name AS customer_name, c.email AS customer_email, c.segment AS customer_segment
        FROM orders o
        JOIN customers c ON c.id = o.customer_id
        WHERE o.created_at >= ? AND o.status IN ('paid', 'shipped')
        ORDER BY o.created_at DESC
        LIMIT ?
        """,
        (since, limit),
        stats,
    )
    if not orders:
        return []
    ids = [order["id"] for order in orders]
    placeholders = ",".join("?" for _ in ids)
    items = timed_query(
        connection,
        f"""
        SELECT oi.order_id, oi.id, oi.quantity, oi.unit_price,
               p.id AS product_id, p.sku, p.name AS product_name, p.list_price,
               c.id AS category_id, c.name AS category_name
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        JOIN categories c ON c.id = p.category_id
        WHERE oi.order_id IN ({placeholders})
        ORDER BY oi.order_id ASC, oi.id ASC
        """,
        ids,
        stats,
    )
    by_order = {}
    for item in items:
        by_order.setdefault(item["order_id"], []).append(
            {
                "id": item["id"],
                "quantity": item["quantity"],
                "unit_price": item["unit_price"],
                "product": {
                    "id": item["product_id"],
                    "sku": item["sku"],
                    "name": item["product_name"],
                    "list_price": item["list_price"],
                },
                "category": {"id": item["category_id"], "name": item["category_name"]},
            }
        )
    for order in orders:
        order["customer"] = {
            "id": order["customer_id"],
            "name": order.pop("customer_name"),
            "email": order.pop("customer_email"),
            "segment": order.pop("customer_segment"),
        }
        order["items"] = by_order.get(order["id"], [])
    return orders


def database_diagnostics(connection, stats):
    counts = timed_query(
        connection,
        """
        SELECT
            (SELECT COUNT(*) FROM customers) AS customers_count,
            (SELECT COUNT(*) FROM categories) AS categories_count,
            (SELECT COUNT(*) FROM products) AS products_count,
            (SELECT COUNT(*) FROM orders) AS orders_count,
            (SELECT COUNT(*) FROM order_items) AS order_items_count
        """,
        (),
        stats,
        0,
    )[0]
    density = timed_query(
        connection,
        """
        SELECT ROUND(AVG(item_count), 2) AS avg_items_per_order,
               MAX(item_count) AS max_items_per_order
        FROM (
            SELECT order_id, COUNT(*) AS item_count
            FROM order_items
            GROUP BY order_id
        )
        """,
        (),
        stats,
        0,
    )[0]
    return {
        "row_counts": {
            "customers": counts["customers_count"],
            "categories": counts["categories_count"],
            "products": counts["products_count"],
            "orders": counts["orders_count"],
            "order_items": counts["order_items_count"],
        },
        "relationships": {
            "avg_items_per_order": density["avg_items_per_order"],
            "max_items_per_order": density["max_items_per_order"],
        },
    }


def diagnostics_summary(connection, stats):
    summary = metrics_summary(read_metrics())
    legacy = summary["routes"].get("/orders-legacy", {})
    optimized = summary["routes"].get("/orders-optimized", {})
    return {
        "case": CASE_NAME,
        "stack": APP_STACK,
        "legacy": legacy,
        "optimized": optimized,
        "delta": {
            "avg_ms": round(legacy.get("avg_ms", 0) - optimized.get("avg_ms", 0), 2),
            "p95_ms": round(legacy.get("p95_ms", 0) - optimized.get("p95_ms", 0), 2),
        },
        "database": database_diagnostics(connection, stats),
        "interpretation": {
            "legacy_should_issue_many_queries": "La ruta legacy consulta cliente, items, producto y categoria dentro de bucles.",
            "optimized_should_be_stable": "La ruta optimized mantiene una lectura base y otra lectura de detalles agrupados.",
        },
    }


def prometheus_label(value):
    return str(value).replace("\\", "\\\\").replace('"', '\\"').replace("\n", " ")


def render_prometheus_metrics():
    summary = metrics_summary(read_metrics())
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
    for route, stats in summary["routes"].items():
        label = prometheus_label(route)
        lines.append(f"app_route_latency_ms{{route=\"{label}\",stat=\"avg\"}} {stats['avg_ms']}")
        lines.append(f"app_route_latency_ms{{route=\"{label}\",stat=\"p95\"}} {stats['p95_ms']}")
        lines.append(f"app_route_requests_total{{route=\"{label}\"}} {stats['count']}")
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
                    "goal": "Comparar N+1 contra lecturas consolidadas usando el mismo dataset local.",
                    "routes": {
                        "/health": "Estado basico del servicio.",
                        "/orders-legacy?days=30&limit=20": "Version con N+1 sobre pedidos, cliente, items, producto y categoria.",
                        "/orders-optimized?days=30&limit=20": "Version consolidada con lecturas agrupadas.",
                        "/diagnostics/summary": "Resumen entre metricas y densidad relacional.",
                        "/metrics": "Metricas JSON.",
                        "/metrics-prometheus": "Metricas formato Prometheus.",
                        "/reset-metrics": "Reinicia metricas locales.",
                    },
                }
            elif path == "/health":
                payload = {"status": "ok", "stack": APP_STACK}
            elif path == "/orders-legacy":
                days = clamp_int(query.get("days", ["30"])[0], 1, 180)
                limit = clamp_int(query.get("limit", ["20"])[0], 1, 60)
                orders = recent_orders_legacy(connection, days, limit, stats)
                payload = {
                    "mode": "legacy",
                    "problem": "N+1 sobre multiples relaciones con round-trips por pedido e item.",
                    "days": days,
                    "limit": limit,
                    "result_count": len(orders),
                    "db_queries_in_request": stats["db_queries"],
                    "db_time_ms_in_request": round(stats["db_time_ms"], 2),
                    "data": orders,
                }
            elif path == "/orders-optimized":
                days = clamp_int(query.get("days", ["30"])[0], 1, 180)
                limit = clamp_int(query.get("limit", ["20"])[0], 1, 60)
                orders = recent_orders_optimized(connection, days, limit, stats)
                payload = {
                    "mode": "optimized",
                    "solution": "Carga consolidada de pedidos y detalles para evitar round-trips repetidos.",
                    "days": days,
                    "limit": limit,
                    "result_count": len(orders),
                    "db_queries_in_request": stats["db_queries"],
                    "db_time_ms_in_request": round(stats["db_time_ms"], 2),
                    "data": orders,
                }
            elif path == "/diagnostics/summary":
                payload = diagnostics_summary(connection, stats)
            elif path == "/metrics":
                payload = {"case": CASE_NAME, "stack": APP_STACK, **metrics_summary(read_metrics())}
            elif path == "/metrics-prometheus":
                skip_metrics = True
                self.send_text(200, render_prometheus_metrics())
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
server = ThreadingHTTPServer(("0.0.0.0", 8080), Handler)
print("Servidor Python escuchando en 8080")
server.serve_forever()
