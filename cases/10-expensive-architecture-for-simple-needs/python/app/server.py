from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse
import json
import math
import os
import tempfile
import threading
import time

APP_STACK = os.environ.get("APP_STACK", "Python 3.12")
STORAGE_DIR = os.path.join(tempfile.gettempdir(), "pdsl-case10-python")
STATE_PATH = os.path.join(STORAGE_DIR, "state.json")
TELEMETRY_PATH = os.path.join(STORAGE_DIR, "telemetry.json")

_lock = threading.Lock()


# ---------------------------------------------------------------------------
# Storage helpers
# ---------------------------------------------------------------------------

def ensure_storage_dir():
    os.makedirs(STORAGE_DIR, exist_ok=True)


def initial_state():
    return {
        "architecture": {
            "decision_log_count": 0,
            "simplification_backlog": 6,
            "last_feature_release": None,
            "baselines": {
                "complex_services": 8,
                "right_sized_services": 2,
                "complex_monthly_cost_usd": 5400,
                "right_sized_monthly_cost_usd": 850,
            },
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
    return {
        "requests": 0,
        "samples_ms": [],
        "routes": {},
        "last_path": None,
        "last_status": 200,
        "last_updated": None,
        "runs": [],
        "modes": {
            "complex": {
                "successes": 0,
                "failures": 0,
                "monthly_cost_samples": [],
                "service_count_samples": [],
                "lead_time_samples": [],
                "coordination_samples": [],
                "by_scenario": {},
            },
            "right_sized": {
                "successes": 0,
                "failures": 0,
                "monthly_cost_samples": [],
                "service_count_samples": [],
                "lead_time_samples": [],
                "coordination_samples": [],
                "by_scenario": {},
            },
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
# Percentile / math helpers
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
    cost = m.get("monthly_cost_samples", [])
    svc = m.get("service_count_samples", [])
    lead = m.get("lead_time_samples", [])
    coord = m.get("coordination_samples", [])

    def avg(lst):
        return round(sum(lst) / len(lst), 2) if lst else 0.0

    return {
        "successes": m.get("successes", 0),
        "failures": m.get("failures", 0),
        "avg_monthly_cost_usd": avg(cost),
        "avg_service_count": avg(svc),
        "avg_lead_time_days": avg(lead),
        "avg_coordination_points": avg(coord),
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
        "basic_crud": {
            "complex": {
                "status": 200, "services": 8, "cost": 5400, "lead": 11, "coordination": 7, "fit": 18,
                "hint": "El problema real es simple y no justifica una coreografia de servicios.",
            },
            "right_sized": {
                "status": 200, "services": 2, "cost": 850, "lead": 3, "coordination": 2, "fit": 88,
                "hint": "El problema real es simple y no justifica una coreografia de servicios.",
            },
        },
        "small_campaign": {
            "complex": {"status": 200, "services": 9, "cost": 6200, "lead": 14, "coordination": 8, "fit": 22},
            "right_sized": {"status": 200, "services": 3, "cost": 1100, "lead": 4, "coordination": 2, "fit": 82},
        },
        "audit_needed": {
            "complex": {"status": 200, "services": 7, "cost": 5000, "lead": 9, "coordination": 6, "fit": 44},
            "right_sized": {"status": 200, "services": 3, "cost": 1350, "lead": 5, "coordination": 3, "fit": 79},
        },
        "seasonal_peak": {
            "complex": {"status": 502, "services": 10, "cost": 6800, "lead": 16, "coordination": 9, "fit": 30},
            "right_sized": {"status": 200, "services": 4, "cost": 1800, "lead": 6, "coordination": 3, "fit": 76},
        },
    }


# ---------------------------------------------------------------------------
# Core business logic
# ---------------------------------------------------------------------------

def run_feature_flow(mode, scenario, accounts):
    catalog = scenario_catalog()
    if scenario not in catalog:
        scenario = "basic_crud"
    meta = catalog[scenario][mode]

    services_touched = meta["services"] + math.floor(accounts / 250)
    monthly_cost_usd = meta["cost"] + accounts * (2.8 if mode == "complex" else 0.7)
    lead_time_days = meta["lead"]
    coordination_points = meta["coordination"]
    problem_fit_score = meta["fit"]
    http_status = meta["status"]

    list_size = clamp_int(accounts * 15, 100, 8000)

    if mode == "complex":
        # Simulate multi-hop serialisation overhead
        data = [{"id": i, "value": f"item-{i}", "active": True} for i in range(list_size)]
        for _ in range(services_touched):
            data = json.loads(json.dumps(data))
        objects = [type("O", (), d)() for d in data]
        _ = objects  # suppress unused

        if scenario == "seasonal_peak":
            raise RuntimeError("Gateway Timeout: Demasiados hops en pico")
    else:
        # right_sized: O(1) — just grab the first element
        data = [{"id": i, "value": f"item-{i}", "active": True} for i in range(list_size)]
        _ = data[0]

    # Update persistent state
    state = read_state()
    arch = state["architecture"]
    arch["decision_log_count"] = arch.get("decision_log_count", 0) + 1
    if mode == "right_sized":
        arch["simplification_backlog"] = max(0, arch.get("simplification_backlog", 0) - 1)
    arch["last_feature_release"] = f"{mode}-{time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())}"
    write_state(state)

    return {
        "mode": mode,
        "scenario": scenario,
        "accounts": accounts,
        "services_touched": services_touched,
        "monthly_cost_usd": round(monthly_cost_usd, 2),
        "lead_time_days": lead_time_days,
        "coordination_points": coordination_points,
        "problem_fit_score": problem_fit_score,
        "http_status": http_status,
        "hint": meta.get("hint"),
    }


def state_summary():
    state = read_state()
    arch = state["architecture"]
    return {
        "architecture": arch,
        "baselines_delta": {
            "services_ratio": round(
                arch["baselines"]["complex_services"] / max(1, arch["baselines"]["right_sized_services"]), 2
            ),
            "cost_ratio": round(
                arch["baselines"]["complex_monthly_cost_usd"] / max(1, arch["baselines"]["right_sized_monthly_cost_usd"]), 2
            ),
            "monthly_savings_usd": arch["baselines"]["complex_monthly_cost_usd"] - arch["baselines"]["right_sized_monthly_cost_usd"],
        },
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
        mode = run_context["mode"]
        scenario = run_context.get("scenario", "unknown")
        m = tel["modes"].setdefault(mode, initial_telemetry()["modes"]["complex"])

        if run_context.get("outcome") == "success":
            m["successes"] = m.get("successes", 0) + 1
        else:
            m["failures"] = m.get("failures", 0) + 1

        if run_context.get("monthly_cost_usd") is not None:
            m["monthly_cost_samples"].append(run_context["monthly_cost_usd"])
            m["monthly_cost_samples"] = m["monthly_cost_samples"][-500:]
        if run_context.get("services_touched") is not None:
            m["service_count_samples"].append(run_context["services_touched"])
            m["service_count_samples"] = m["service_count_samples"][-500:]
        if run_context.get("lead_time_days") is not None:
            m["lead_time_samples"].append(run_context["lead_time_days"])
            m["lead_time_samples"] = m["lead_time_samples"][-500:]
        if run_context.get("coordination_points") is not None:
            m["coordination_samples"].append(run_context["coordination_points"])
            m["coordination_samples"] = m["coordination_samples"][-500:]

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
    arch = state["architecture"]
    lines = [
        "# HELP app_requests_total Total de requests observados.",
        "# TYPE app_requests_total counter",
        f"app_requests_total {summary['requests_tracked']}",
    ]
    for mode, ms in (summary.get("modes") or {}).items():
        lm = prometheus_label(mode)
        lines.append(f"app_architecture_success_total{{mode=\"{lm}\"}} {ms['successes']}")
        lines.append(f"app_architecture_failure_total{{mode=\"{lm}\"}} {ms['failures']}")
        lines.append(f"app_architecture_avg_monthly_cost_usd{{mode=\"{lm}\"}} {ms['avg_monthly_cost_usd']}")
        lines.append(f"app_architecture_avg_services_touched{{mode=\"{lm}\"}} {ms['avg_service_count']}")
        lines.append(f"app_architecture_avg_lead_time_days{{mode=\"{lm}\"}} {ms['avg_lead_time_days']}")
    lines.append(f"app_simplification_backlog {arch.get('simplification_backlog', 0)}")
    lines.append(f"app_decision_log_count {arch.get('decision_log_count', 0)}")
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
                    "case": "10 - Arquitectura cara para un problema simple",
                    "stack": APP_STACK,
                    "goal": "Comparar el mismo flujo de negocio implementado con arquitectura sobre-ingeniada vs. ajustada al problema real.",
                    "routes": {
                        "/feature-complex?scenario=basic_crud&accounts=120": "Ejecuta el flujo con la arquitectura compleja (multi-servicio).",
                        "/feature-right-sized?scenario=basic_crud&accounts=120": "Ejecuta el flujo con arquitectura justa para el problema.",
                        "/architecture/state": "Estado actual de la arquitectura y metricas de decision.",
                        "/decisions?limit=10": "Ultimas runs registradas.",
                        "/diagnostics/summary": "Resumen de telemetria.",
                        "/metrics": "Metricas JSON.",
                        "/metrics-prometheus": "Metricas en formato Prometheus.",
                        "/reset-lab": "Reinicia estado y telemetria.",
                    },
                    "allowed_scenarios": list(catalog.keys()),
                }

            elif uri == "/health":
                payload = {"status": "ok", "stack": APP_STACK}

            elif uri in ("/feature-complex", "/feature-right-sized"):
                mode = "complex" if uri == "/feature-complex" else "right_sized"
                scenario = query_str(query, "scenario", "basic_crud")
                if scenario not in catalog:
                    scenario = "basic_crud"
                accounts = clamp_int(query_int(query, "accounts", 120), 1, 50000)

                try:
                    with _lock:
                        result = run_feature_flow(mode, scenario, accounts)
                    http_status = result["http_status"]
                    status_code = http_status
                    run_context = {
                        "mode": mode,
                        "scenario": scenario,
                        "outcome": "success" if http_status < 400 else "failure",
                        "monthly_cost_usd": result["monthly_cost_usd"],
                        "services_touched": result["services_touched"],
                        "lead_time_days": result["lead_time_days"],
                        "coordination_points": result["coordination_points"],
                    }
                    payload = {
                        "mode": mode,
                        "scenario": scenario,
                        "accounts": accounts,
                        "services_touched": result["services_touched"],
                        "monthly_cost_usd": result["monthly_cost_usd"],
                        "lead_time_days": result["lead_time_days"],
                        "coordination_points": result["coordination_points"],
                        "problem_fit_score": result["problem_fit_score"],
                        "http_status": result["http_status"],
                        "hint": result.get("hint"),
                    }
                except RuntimeError as exc:
                    status_code = 502
                    run_context = {
                        "mode": mode,
                        "scenario": scenario,
                        "outcome": "failure",
                        "monthly_cost_usd": None,
                        "services_touched": None,
                        "lead_time_days": None,
                        "coordination_points": None,
                    }
                    payload = {
                        "mode": mode,
                        "scenario": scenario,
                        "error": str(exc),
                    }

            elif uri == "/architecture/state":
                payload = state_summary()

            elif uri == "/decisions":
                limit = clamp_int(query_int(query, "limit", 10), 1, 50)
                with _lock:
                    tel = read_telemetry()
                runs = list(reversed(tel.get("runs", [])))[:limit]
                payload = {"limit": limit, "runs": runs}

            elif uri == "/diagnostics/summary":
                with _lock:
                    summary = telemetry_summary(read_telemetry())
                payload = {
                    "case": "10 - Arquitectura cara para un problema simple",
                    "stack": APP_STACK,
                    "metrics": summary,
                    "architecture_state": state_summary(),
                }

            elif uri == "/metrics":
                with _lock:
                    summary = telemetry_summary(read_telemetry())
                payload = {
                    "case": "10 - Arquitectura cara para un problema simple",
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
server = HTTPServer(("0.0.0.0", 8080), Handler)
print("Servidor Python escuchando en 8080")
server.serve_forever()
