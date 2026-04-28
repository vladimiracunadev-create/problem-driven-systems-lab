from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse
import json
import math
import os
import tempfile
import threading
import time

APP_STACK = os.environ.get("APP_STACK", "Python 3.12")
STORAGE_DIR = os.path.join(tempfile.gettempdir(), "pdsl-case11-python")
STATE_PATH = os.path.join(STORAGE_DIR, "state.json")
TELEMETRY_PATH = os.path.join(STORAGE_DIR, "telemetry.json")

# Global state lock (read/write protection)
_lock = threading.Lock()

# Exclusive reporting lock — legacy reports hold this while running;
# write operations do a non-blocking acquire to detect contention.
_report_lock = threading.Lock()


# ---------------------------------------------------------------------------
# Storage helpers
# ---------------------------------------------------------------------------

def ensure_storage_dir():
    os.makedirs(STORAGE_DIR, exist_ok=True)


def initial_state():
    return {
        "reporting": {
            "primary_load": 28,
            "lock_pressure": 12,
            "replica_lag_s": 4,
            "snapshot_freshness_min": 15,
            "queue_depth": 0,
            "total_exports": 0,
            "total_operational_writes": 0,
            "last_report_at": None,
        }
    }


def read_state():
    ensure_storage_dir()
    if not os.path.exists(STATE_PATH):
        return initial_state()
    try:
        with open(STATE_PATH, "r", encoding="utf-8") as fh:
            return json.load(fh)
    except (OSError, json.JSONDecodeError):
        return initial_state()


def write_state(state):
    ensure_storage_dir()
    with open(STATE_PATH, "w", encoding="utf-8") as fh:
        json.dump(state, fh, indent=2, ensure_ascii=False)


def initial_telemetry():
    def mode_slot():
        return {
            "successes": 0,
            "failures": 0,
            "primary_load_samples": [],
            "ops_impact_samples": [],
            "replica_lag_samples": [],
            "by_scenario": {},
        }

    return {
        "requests": 0,
        "samples_ms": [],
        "routes": {},
        "last_path": None,
        "last_status": 200,
        "last_updated": None,
        "runs": [],
        "modes": {
            "legacy": mode_slot(),
            "isolated": mode_slot(),
            "operations": mode_slot(),
        },
    }


def read_telemetry():
    ensure_storage_dir()
    if not os.path.exists(TELEMETRY_PATH):
        return initial_telemetry()
    try:
        with open(TELEMETRY_PATH, "r", encoding="utf-8") as fh:
            parsed = json.load(fh)
    except (OSError, json.JSONDecodeError):
        return initial_telemetry()
    tel = initial_telemetry()
    tel.update(parsed)
    tel["modes"] = parsed.get("modes", initial_telemetry()["modes"])
    tel["routes"] = parsed.get("routes", {})
    tel["samples_ms"] = parsed.get("samples_ms", [])
    tel["runs"] = parsed.get("runs", [])
    return tel


def write_telemetry(tel):
    ensure_storage_dir()
    with open(TELEMETRY_PATH, "w", encoding="utf-8") as fh:
        json.dump(tel, fh, indent=2, ensure_ascii=False)


# ---------------------------------------------------------------------------
# Math / query helpers
# ---------------------------------------------------------------------------

def percentile(values, pct):
    if not values:
        return 0.0
    sv = sorted(values)
    idx = max(0, min(len(sv) - 1, int((pct / 100.0) * len(sv) + 0.999999) - 1))
    return round(float(sv[idx]), 2)


def clamp_int(v, lo, hi):
    return max(lo, min(hi, v))


def query_int(query, key, default):
    vals = query.get(key, [])
    if not vals:
        return default
    try:
        return int(vals[0])
    except ValueError:
        return default


def query_str(query, key, default):
    vals = query.get(key, [])
    return vals[0] if vals else default


def route_metrics_summary(tel):
    routes = {}
    for route, samples in (tel.get("routes") or {}).items():
        values = samples if isinstance(samples, list) else []
        count = len(values)
        routes[route] = {
            "count": count,
            "avg_ms": round(sum(values) / count, 2) if count else 0.0,
            "p95_ms": percentile(values, 95),
            "p99_ms": percentile(values, 99),
            "max_ms": round(max(values), 2) if count else 0.0,
        }
    return dict(sorted(routes.items()))


def mode_summary(m):
    def avg(lst):
        return round(sum(lst) / len(lst), 2) if lst else 0.0

    return {
        "successes": m.get("successes", 0),
        "failures": m.get("failures", 0),
        "avg_primary_load": avg(m.get("primary_load_samples", [])),
        "avg_ops_impact_ms": avg(m.get("ops_impact_samples", [])),
        "avg_replica_lag_s": avg(m.get("replica_lag_samples", [])),
        "by_scenario": m.get("by_scenario", {}),
    }


def telemetry_summary(tel):
    samples = tel.get("samples_ms") or []
    count = len(samples)
    return {
        "requests_tracked": tel.get("requests", 0),
        "sample_count": count,
        "avg_ms": round(sum(samples) / count, 2) if count else 0.0,
        "p95_ms": percentile(samples, 95),
        "p99_ms": percentile(samples, 99),
        "max_ms": round(max(samples), 2) if count else 0.0,
        "last_path": tel.get("last_path"),
        "last_status": tel.get("last_status", 200),
        "last_updated": tel.get("last_updated"),
        "modes": {k: mode_summary(v) for k, v in (tel.get("modes") or {}).items()},
        "routes": route_metrics_summary(tel),
    }


# ---------------------------------------------------------------------------
# Scenario catalog
# ---------------------------------------------------------------------------

def scenario_catalog():
    return {
        "end_of_month":  {"legacy_load": 52, "legacy_lock": 34, "isolated_queue": 18, "isolated_lag": 22},
        "finance_audit": {"legacy_load": 46, "legacy_lock": 28, "isolated_queue": 14, "isolated_lag": 18},
        "ad_hoc_export": {"legacy_load": 35, "legacy_lock": 20, "isolated_queue": 10, "isolated_lag": 12},
        "mixed_peak":    {"legacy_load": 62, "legacy_lock": 38, "isolated_queue": 24, "isolated_lag": 24},
    }


# ---------------------------------------------------------------------------
# Pressure level
# ---------------------------------------------------------------------------

def pressure_level(reporting):
    pl = reporting.get("primary_load", 0)
    lp = reporting.get("lock_pressure", 0)
    if pl >= 90 or lp >= 75:
        return "critical"
    if pl >= 65 or lp >= 45:
        return "warning"
    return "healthy"


# ---------------------------------------------------------------------------
# Core business logic
# ---------------------------------------------------------------------------

def run_report_flow_legacy(scenario, rows):
    """
    Holds _report_lock for the full duration, simulating the exclusive lock
    that heavy reporting places on the primary database.
    Returns (result_dict, http_status).
    """
    catalog = scenario_catalog()
    if scenario not in catalog:
        scenario = "end_of_month"
    sc = catalog[scenario]

    # Acquire the exclusive report lock (blocking — legacy always waits)
    _report_lock.acquire(blocking=True)
    try:
        with _lock:
            state = read_state()
            rep = state["reporting"]
            load_before = rep.get("primary_load", 0)
            lock_before = rep.get("lock_pressure", 0)

            rep["primary_load"] = min(100, load_before + sc["legacy_load"] + math.floor(rows / 150000))
            rep["lock_pressure"] = min(100, lock_before + sc["legacy_lock"])
            rep["total_exports"] = rep.get("total_exports", 0) + 1
            rep["last_report_at"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
            write_state(state)

        level = pressure_level(rep)

        # Simulate the I/O lock sleep (released AFTER sleep, still holding lock)
        sleep_s = min(3.5, max(1.2, 1.2 + min(3.0, rows / 1_000_000)))
        time.sleep(sleep_s)

        http_status = 503 if level == "critical" else 200
        ops_latency_impact_ms = rep["primary_load"] * 3.1 + rep["lock_pressure"] * 2.4

        return {
            "mode": "legacy",
            "scenario": scenario,
            "rows": rows,
            "primary_load": rep["primary_load"],
            "lock_pressure": rep["lock_pressure"],
            "pressure_level": level,
            "ops_latency_impact_ms": round(ops_latency_impact_ms, 2),
            "sleep_s": round(sleep_s, 3),
        }, http_status
    finally:
        _report_lock.release()


def run_report_flow_isolated(scenario, rows):
    """
    Sends the report to an async queue quickly; replica lag builds up instead.
    Does NOT hold the primary lock.
    Returns (result_dict, http_status).
    """
    catalog = scenario_catalog()
    if scenario not in catalog:
        scenario = "end_of_month"
    sc = catalog[scenario]

    time.sleep(0.15)  # fast: just enqueues

    with _lock:
        state = read_state()
        rep = state["reporting"]
        rep["queue_depth"] = min(120, rep.get("queue_depth", 0) + sc["isolated_queue"])
        rep["replica_lag_s"] = min(180, rep.get("replica_lag_s", 0) + sc["isolated_lag"])
        rep["total_exports"] = rep.get("total_exports", 0) + 1
        rep["last_report_at"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        write_state(state)

    ops_latency_impact_ms = rep.get("primary_load", 0) * 3.1 + rep.get("lock_pressure", 0) * 2.4

    return {
        "mode": "isolated",
        "scenario": scenario,
        "rows": rows,
        "queue_depth": rep["queue_depth"],
        "replica_lag_s": rep["replica_lag_s"],
        "ops_latency_impact_ms": round(ops_latency_impact_ms, 2),
    }, 200


def run_write_flow(orders):
    """
    Tries a non-blocking acquire of _report_lock. If it fails, the primary is
    busy with a legacy report and the write is degraded (503).
    """
    # Non-blocking acquire: if legacy report holds the lock → 503
    got_lock = _report_lock.acquire(blocking=False)
    if not got_lock:
        # Lock is held by a legacy report — write degraded
        with _lock:
            state = read_state()
            rep = state["reporting"]
        return {
            "mode": "operations",
            "orders": orders,
            "status": "degraded",
            "reason": "Primary locked by legacy report",
            "primary_load": rep.get("primary_load", 0),
            "lock_pressure": rep.get("lock_pressure", 0),
        }, 503

    # We got the lock — release it immediately (we only needed the check)
    _report_lock.release()

    with _lock:
        state = read_state()
        rep = state["reporting"]
        primary_load = rep.get("primary_load", 0)
        lock_pressure = rep.get("lock_pressure", 0)

    latency_ms = 35 + orders * 2.1 + primary_load * 1.6 + lock_pressure * 1.3
    pressure = pressure_level(rep)

    if primary_load >= 90 or lock_pressure >= 75:
        http_status = 503
        status_label = "degraded"
    else:
        http_status = 200
        status_label = "ok"
        time.sleep(min(0.5, latency_ms / 1000))

    with _lock:
        state = read_state()
        rep = state["reporting"]
        rep["primary_load"] = min(100, rep.get("primary_load", 0) + max(1, math.ceil(orders / 8)))
        rep["total_operational_writes"] = rep.get("total_operational_writes", 0) + orders
        write_state(state)

    return {
        "mode": "operations",
        "orders": orders,
        "status": status_label,
        "latency_ms": round(latency_ms, 2),
        "primary_load": rep["primary_load"],
        "lock_pressure": rep.get("lock_pressure", 0),
        "pressure_level": pressure,
    }, http_status


def state_summary():
    state = read_state()
    rep = state["reporting"]
    return {
        "reporting": rep,
        "pressure_level": pressure_level(rep),
    }


# ---------------------------------------------------------------------------
# Telemetry recording
# ---------------------------------------------------------------------------

def record_request(uri, status, elapsed_ms, run_context):
    tel = read_telemetry()
    tel["requests"] = tel.get("requests", 0) + 1
    tel["samples_ms"].append(round(elapsed_ms, 2))
    tel["samples_ms"] = tel["samples_ms"][-3000:]
    tel["routes"].setdefault(uri, [])
    tel["routes"][uri].append(round(elapsed_ms, 2))
    tel["routes"][uri] = tel["routes"][uri][-500:]
    tel["last_path"] = uri
    tel["last_status"] = status
    tel["last_updated"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())

    if run_context:
        mode = run_context.get("mode", "legacy")
        scenario = run_context.get("scenario", "unknown")

        def mode_slot_default():
            return {
                "successes": 0,
                "failures": 0,
                "primary_load_samples": [],
                "ops_impact_samples": [],
                "replica_lag_samples": [],
                "by_scenario": {},
            }

        m = tel["modes"].setdefault(mode, mode_slot_default())

        if run_context.get("outcome") == "success":
            m["successes"] = m.get("successes", 0) + 1
        else:
            m["failures"] = m.get("failures", 0) + 1

        if run_context.get("primary_load") is not None:
            m["primary_load_samples"].append(run_context["primary_load"])
            m["primary_load_samples"] = m["primary_load_samples"][-500:]
        if run_context.get("ops_latency_impact_ms") is not None:
            m["ops_impact_samples"].append(run_context["ops_latency_impact_ms"])
            m["ops_impact_samples"] = m["ops_impact_samples"][-500:]
        if run_context.get("replica_lag_s") is not None:
            m["replica_lag_samples"].append(run_context["replica_lag_s"])
            m["replica_lag_samples"] = m["replica_lag_samples"][-500:]

        if scenario != "unknown":
            m["by_scenario"][scenario] = m["by_scenario"].get(scenario, 0) + 1

        run_entry = dict(run_context)
        run_entry["status_code"] = status
        run_entry["elapsed_ms"] = round(elapsed_ms, 2)
        run_entry["timestamp_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        tel["runs"].append(run_entry)
        tel["runs"] = tel["runs"][-40:]

    write_telemetry(tel)


# ---------------------------------------------------------------------------
# Prometheus
# ---------------------------------------------------------------------------

def prometheus_label(v):
    return str(v).replace("\\", "\\\\").replace('"', '\\"').replace("\n", " ")


def render_prometheus_metrics():
    tel = read_telemetry()
    summary = telemetry_summary(tel)
    state = read_state()
    rep = state["reporting"]
    lines = [
        "# HELP app_requests_total Total de requests observados.",
        "# TYPE app_requests_total counter",
        f"app_requests_total {summary['requests_tracked']}",
    ]
    for mode, ms in (summary.get("modes") or {}).items():
        lm = prometheus_label(mode)
        lines.append(f"app_reporting_success_total{{mode=\"{lm}\"}} {ms['successes']}")
        lines.append(f"app_reporting_failure_total{{mode=\"{lm}\"}} {ms['failures']}")
        lines.append(f"app_reporting_avg_primary_load{{mode=\"{lm}\"}} {ms['avg_primary_load']}")
        lines.append(f"app_reporting_avg_ops_impact_ms{{mode=\"{lm}\"}} {ms['avg_ops_impact_ms']}")
        lines.append(f"app_reporting_avg_replica_lag_s{{mode=\"{lm}\"}} {ms['avg_replica_lag_s']}")
    lines.append(f"app_primary_load {rep.get('primary_load', 0)}")
    lines.append(f"app_lock_pressure {rep.get('lock_pressure', 0)}")
    lines.append(f"app_replica_lag_seconds {rep.get('replica_lag_s', 0)}")
    lines.append(f"app_reporting_queue_depth {rep.get('queue_depth', 0)}")
    return "\n".join(lines) + "\n"


# ---------------------------------------------------------------------------
# HTTP Handler
# ---------------------------------------------------------------------------

class Handler(BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        return

    def send_json(self, status, body):
        raw = json.dumps(body, ensure_ascii=False, indent=2).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def send_text(self, status, body):
        raw = body.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "text/plain; version=0.0.4; charset=utf-8")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def do_GET(self):
        started = time.perf_counter()
        parsed = urlparse(self.path)
        uri = parsed.path or "/"
        query = parse_qs(parsed.query)
        status_code = 200
        payload = {}
        skip_store_metrics = False
        run_context = None

        try:
            catalog = scenario_catalog()

            if uri in ("/", ""):
                payload = {
                    "lab": "Problem-Driven Systems Lab",
                    "case": "11 - Reportes pesados que bloquean la operacion",
                    "stack": APP_STACK,
                    "goal": "Demostrar como los reportes pesados en la BD primaria degradan la operacion transaccional y como el patron de aislamiento lo resuelve.",
                    "routes": {
                        "/report-legacy?scenario=end_of_month&rows=600000": "Ejecuta reporte legacy (bloquea primary).",
                        "/report-isolated?scenario=end_of_month&rows=600000": "Ejecuta reporte aislado (enqueue a replica).",
                        "/order-write?orders=25": "Escribe ordenes operacionales.",
                        "/reporting/state": "Estado actual del sistema de reporting.",
                        "/activity?limit=10": "Ultimas runs registradas.",
                        "/diagnostics/summary": "Resumen de telemetria.",
                        "/metrics": "Metricas JSON.",
                        "/metrics-prometheus": "Metricas en formato Prometheus.",
                        "/reset-lab": "Reinicia estado y telemetria.",
                    },
                    "allowed_scenarios": list(catalog.keys()),
                }

            elif uri == "/health":
                payload = {"status": "ok", "stack": APP_STACK}

            elif uri == "/report-legacy":
                scenario = query_str(query, "scenario", "end_of_month")
                if scenario not in catalog:
                    scenario = "end_of_month"
                rows = clamp_int(query_int(query, "rows", 600000), 1000, 10_000_000)

                # run_report_flow_legacy acquires _report_lock internally
                result, status_code = run_report_flow_legacy(scenario, rows)

                run_context = {
                    "mode": "legacy",
                    "scenario": scenario,
                    "outcome": "success" if status_code < 400 else "failure",
                    "primary_load": result.get("primary_load"),
                    "ops_latency_impact_ms": result.get("ops_latency_impact_ms"),
                    "replica_lag_s": None,
                }
                payload = result

            elif uri == "/report-isolated":
                scenario = query_str(query, "scenario", "end_of_month")
                if scenario not in catalog:
                    scenario = "end_of_month"
                rows = clamp_int(query_int(query, "rows", 600000), 1000, 10_000_000)

                result, status_code = run_report_flow_isolated(scenario, rows)

                run_context = {
                    "mode": "isolated",
                    "scenario": scenario,
                    "outcome": "success" if status_code < 400 else "failure",
                    "primary_load": None,
                    "ops_latency_impact_ms": result.get("ops_latency_impact_ms"),
                    "replica_lag_s": result.get("replica_lag_s"),
                }
                payload = result

            elif uri == "/order-write":
                orders = clamp_int(query_int(query, "orders", 25), 1, 10000)

                result, status_code = run_write_flow(orders)

                run_context = {
                    "mode": "operations",
                    "scenario": "write",
                    "outcome": "success" if status_code < 400 else "failure",
                    "primary_load": result.get("primary_load"),
                    "ops_latency_impact_ms": result.get("latency_ms"),
                    "replica_lag_s": None,
                }
                payload = result

            elif uri == "/reporting/state":
                payload = state_summary()

            elif uri == "/activity":
                limit = clamp_int(query_int(query, "limit", 10), 1, 50)
                with _lock:
                    tel = read_telemetry()
                runs = list(reversed(tel.get("runs", [])))[:limit]
                payload = {"limit": limit, "runs": runs}

            elif uri == "/diagnostics/summary":
                with _lock:
                    summary = telemetry_summary(read_telemetry())
                payload = {
                    "case": "11 - Reportes pesados que bloquean la operacion",
                    "stack": APP_STACK,
                    "metrics": summary,
                    "reporting_state": state_summary(),
                }

            elif uri == "/metrics":
                with _lock:
                    summary = telemetry_summary(read_telemetry())
                payload = {
                    "case": "11 - Reportes pesados que bloquean la operacion",
                    "stack": APP_STACK,
                    **summary,
                }
                skip_store_metrics = True

            elif uri == "/metrics-prometheus":
                skip_store_metrics = True
                with _lock:
                    prom = render_prometheus_metrics()
                self.send_text(200, prom)
                return

            elif uri == "/reset-lab":
                skip_store_metrics = True
                with _lock:
                    write_state(initial_state())
                    write_telemetry(initial_telemetry())
                payload = {"status": "reset", "message": "Estado y telemetria reiniciados."}

            else:
                status_code = 404
                payload = {"error": "Ruta no encontrada", "path": uri}

        except Exception as exc:
            status_code = 500
            payload = {"error": "Error interno", "message": str(exc), "path": uri}

        elapsed_ms = round((time.perf_counter() - started) * 1000, 2)

        if not skip_store_metrics and uri not in ("/metrics",):
            with _lock:
                record_request(uri, status_code, elapsed_ms, run_context)

        payload["elapsed_ms"] = elapsed_ms
        payload["timestamp_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        payload["pid"] = os.getpid()
        self.send_json(status_code, payload)


ensure_storage_dir()
PORT = int(os.environ.get("PORT", "8080"))
server = HTTPServer(("0.0.0.0", PORT), Handler)
print(f"Servidor Python escuchando en {PORT}")
server.serve_forever()
