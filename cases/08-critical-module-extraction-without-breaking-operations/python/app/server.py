from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse
import json
import os
import random
import threading
import tempfile
import time

APP_STACK = os.environ.get("APP_STACK", "Python 3.12")
STORAGE_DIR = os.path.join(tempfile.gettempdir(), "pdsl-case08-python")
STATE_PATH = os.path.join(STORAGE_DIR, "state.json")
TELEMETRY_PATH = os.path.join(STORAGE_DIR, "telemetry.json")
RUNS_PATH = os.path.join(STORAGE_DIR, "runs.json")

_lock = threading.Lock()


# ---------------------------------------------------------------------------
# Storage helpers
# ---------------------------------------------------------------------------

def ensure_storage_dir():
    os.makedirs(STORAGE_DIR, exist_ok=True)


def initial_state():
    return {
        "extraction": {
            "consumers": {"checkout": 0, "marketplace": 0, "backoffice": 0, "partner_api": 0},
            "contract_tests": 14,
            "compatibility_proxy_hits": 0,
            "shadow_traffic_percent": 15,
            "cutover_events": 0,
            "last_release": None,
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
        "status_counts": {},
        "modes": {
            "bigbang": {
                "successes": 0,
                "failures": 0,
                "blast_radius_samples": [],
                "compatibility_hit_samples": [],
                "consumer_progress_samples": [],
                "by_scenario": {},
            },
            "compatible": {
                "successes": 0,
                "failures": 0,
                "blast_radius_samples": [],
                "compatibility_hit_samples": [],
                "consumer_progress_samples": [],
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
            data = json.load(fh)
    except (OSError, json.JSONDecodeError):
        return initial_telemetry()
    base = initial_telemetry()
    base.update({k: data[k] for k in ("requests", "samples_ms", "routes",
                                       "last_path", "last_status", "last_updated",
                                       "status_counts") if k in data})
    for mode in ("bigbang", "compatible"):
        if mode in data.get("modes", {}):
            base["modes"][mode].update(data["modes"][mode])
    return base


def write_telemetry(telemetry):
    ensure_storage_dir()
    with open(TELEMETRY_PATH, "w", encoding="utf-8") as fh:
        json.dump(telemetry, fh, indent=2, ensure_ascii=False)


def read_runs():
    ensure_storage_dir()
    if not os.path.exists(RUNS_PATH):
        return []
    try:
        with open(RUNS_PATH, "r", encoding="utf-8") as fh:
            return json.load(fh)
    except (OSError, json.JSONDecodeError):
        return []


def write_runs(runs):
    ensure_storage_dir()
    with open(RUNS_PATH, "w", encoding="utf-8") as fh:
        json.dump(runs, fh, indent=2, ensure_ascii=False)


def reset_all():
    write_state(initial_state())
    write_telemetry(initial_telemetry())
    write_runs([])


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def clamp_int(value, minimum, maximum):
    return max(minimum, min(maximum, value))


def query_int(query, key, default):
    values = query.get(key, [])
    if not values:
        return default
    try:
        return int(values[0])
    except ValueError:
        return default


def percentile(values, pct):
    if not values:
        return 0.0
    sorted_v = sorted(values)
    idx = max(0, min(len(sorted_v) - 1, int((pct / 100.0) * len(sorted_v) + 0.999999) - 1))
    return round(float(sorted_v[idx]), 2)


def bucket_key_for_status(status):
    if status >= 500:
        return "5xx"
    if status >= 400:
        return "4xx"
    return "2xx"


def route_metrics_summary(telemetry):
    routes = {}
    for route, samples in (telemetry.get("routes") or {}).items():
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


def telemetry_summary(telemetry):
    samples = telemetry.get("samples_ms") or []
    count = len(samples)
    return {
        "requests_tracked": telemetry.get("requests", 0),
        "sample_count": count,
        "avg_ms": round(sum(samples) / count, 2) if count else 0.0,
        "p95_ms": percentile(samples, 95),
        "p99_ms": percentile(samples, 99),
        "max_ms": round(max(samples), 2) if count else 0.0,
        "last_path": telemetry.get("last_path"),
        "last_status": telemetry.get("last_status", 200),
        "last_updated": telemetry.get("last_updated"),
        "status_counts": telemetry.get("status_counts", {}),
        "modes": telemetry.get("modes", initial_telemetry()["modes"]),
        "routes": route_metrics_summary(telemetry),
    }


def record_request_telemetry(uri, status, elapsed_ms, flow_context):
    with _lock:
        telemetry = read_telemetry()
        telemetry["requests"] = telemetry.get("requests", 0) + 1
        telemetry["samples_ms"].append(round(elapsed_ms, 2))
        telemetry["samples_ms"] = telemetry["samples_ms"][-3000:]

        telemetry["routes"].setdefault(uri, [])
        telemetry["routes"][uri].append(round(elapsed_ms, 2))
        telemetry["routes"][uri] = telemetry["routes"][uri][-500:]

        telemetry["last_path"] = uri
        telemetry["last_status"] = status
        telemetry["last_updated"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())

        bucket = bucket_key_for_status(status)
        telemetry["status_counts"][bucket] = telemetry["status_counts"].get(bucket, 0) + 1

        if flow_context is not None:
            mode = flow_context.get("mode", "bigbang")
            scenario = flow_context.get("scenario", "unknown")
            md = telemetry["modes"].setdefault(mode, initial_telemetry()["modes"]["bigbang"].copy())

            if flow_context.get("outcome") == "success":
                md["successes"] = md.get("successes", 0) + 1
            else:
                md["failures"] = md.get("failures", 0) + 1

            blast = flow_context.get("blast_radius")
            if blast is not None:
                md.setdefault("blast_radius_samples", [])
                md["blast_radius_samples"].append(blast)
                md["blast_radius_samples"] = md["blast_radius_samples"][-500:]

            hits = flow_context.get("compatibility_hits")
            if hits is not None:
                md.setdefault("compatibility_hit_samples", [])
                md["compatibility_hit_samples"].append(hits)
                md["compatibility_hit_samples"] = md["compatibility_hit_samples"][-500:]

            progress = flow_context.get("consumer_progress")
            if progress is not None:
                md.setdefault("consumer_progress_samples", [])
                md["consumer_progress_samples"].append(progress)
                md["consumer_progress_samples"] = md["consumer_progress_samples"][-500:]

            by_sc = md.setdefault("by_scenario", {})
            by_sc[scenario] = by_sc.get(scenario, 0) + 1

        write_telemetry(telemetry)


# ---------------------------------------------------------------------------
# Scenario definitions
# ---------------------------------------------------------------------------

SCENARIOS = {
    "stable":           {"bigbang_status": 200, "compatible_status": 200, "bigbang_blast": 76, "compatible_blast": 28, "compatible_proxy_hits": 1,  "compatible_progress": 20, "bigbang_error": None},
    "rule_drift":       {"bigbang_status": 500, "compatible_status": 200, "bigbang_blast": 92, "compatible_blast": 32, "compatible_proxy_hits": 3,  "compatible_progress": 15, "bigbang_error": "Módulo asume key 'price', payload traía 'cost_usd'. Contrato roto."},
    "shared_write":     {"bigbang_status": 409, "compatible_status": 200, "bigbang_blast": 88, "compatible_blast": 36, "compatible_proxy_hits": 4,  "compatible_progress": 10, "bigbang_error": "Conflicto de escritura compartida: dos módulos actualizaron el mismo registro simultáneamente."},
    "peak_sale":        {"bigbang_status": 502, "compatible_status": 200, "bigbang_blast": 95, "compatible_blast": 34, "compatible_proxy_hits": 5,  "compatible_progress": 10, "bigbang_error": "Bad Gateway: el módulo extraído no soportó la carga del peak de ventas."},
    "partner_contract": {"bigbang_status": 500, "compatible_status": 200, "bigbang_blast": 90, "compatible_blast": 30, "compatible_proxy_hits": 4,  "compatible_progress": 20, "bigbang_error": "Contrato de partner roto: el API externo esperaba el schema anterior."},
}

VALID_CONSUMERS = ("checkout", "marketplace", "backoffice", "partner_api")


def run_extraction_flow(mode, scenario, consumer):
    sc = SCENARIOS.get(scenario, SCENARIOS["stable"])

    if mode == "bigbang":
        blast_radius = sc["bigbang_blast"]
        compatibility_hits = 0
        consumer_progress = 0
        http_status = sc["bigbang_status"]
    else:
        blast_radius = sc["compatible_blast"]
        compatibility_hits = sc["compatible_proxy_hits"]
        consumer_progress = sc["compatible_progress"]
        http_status = sc["compatible_status"]

    time.sleep(((240 if mode == "bigbang" else 140) + random.randint(20, 60)) / 1000.0)

    # Big-bang failures
    if mode == "bigbang":
        if scenario == "rule_drift":
            raise ValueError(sc["bigbang_error"])
        if scenario in ("shared_write", "peak_sale", "partner_contract"):
            raise RuntimeError(sc["bigbang_error"])

    # Compatible success path: proxy adaptation + state update
    if mode == "compatible":
        with _lock:
            state = read_state()
            ext = state["extraction"]
            consumers = ext["consumers"]
            if consumer in consumers:
                consumers[consumer] = min(100, consumers[consumer] + consumer_progress)
            ext["contract_tests"] = ext.get("contract_tests", 14) + 6
            ext["compatibility_proxy_hits"] = ext.get("compatibility_proxy_hits", 0) + compatibility_hits
            ext["shadow_traffic_percent"] = min(100, ext.get("shadow_traffic_percent", 15) + 8)
            ext["cutover_events"] = ext.get("cutover_events", 0) + 1
            ext["last_release"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
            write_state(state)

    return {
        "blast_radius": blast_radius,
        "compatibility_hits": compatibility_hits,
        "consumer_progress": consumer_progress,
        "http_status": http_status,
    }


def advance_cutover(consumer):
    """Advances consumer cutover; does NOT register telemetry."""
    with _lock:
        state = read_state()
        ext = state["extraction"]
        consumers = ext["consumers"]
        if consumer in consumers:
            consumers[consumer] = min(100, consumers[consumer] + 25)
        ext["contract_tests"] = ext.get("contract_tests", 14) + 5
        ext["shadow_traffic_percent"] = min(100, ext.get("shadow_traffic_percent", 15) + 10)
        ext["cutover_events"] = ext.get("cutover_events", 0) + 1
        write_state(state)
    return state_summary()


def state_summary():
    state = read_state()
    ext = state["extraction"]
    consumers = ext.get("consumers", {})
    values = list(consumers.values())
    avg_cutover = round(sum(values) / len(values), 2) if values else 0.0
    return {
        "consumers": consumers,
        "average_cutover_percent": avg_cutover,
        "contract_tests": ext.get("contract_tests", 14),
        "compatibility_proxy_hits": ext.get("compatibility_proxy_hits", 0),
        "shadow_traffic_percent": ext.get("shadow_traffic_percent", 15),
        "cutover_events": ext.get("cutover_events", 0),
        "last_release": ext.get("last_release"),
    }


def diagnostics_summary():
    summary = telemetry_summary(read_telemetry())
    modes = summary.get("modes", {})
    result = {
        "case": "08 - Extracción de módulo crítico sin romper operación",
        "stack": APP_STACK,
        "metrics": summary,
        "extraction_state": state_summary(),
        "comparison": {},
    }
    for mode in ("bigbang", "compatible"):
        md = modes.get(mode, {})
        blast_samples = md.get("blast_radius_samples", [])
        hit_samples = md.get("compatibility_hit_samples", [])
        result["comparison"][mode] = {
            "successes": md.get("successes", 0),
            "failures": md.get("failures", 0),
            "avg_blast_radius": round(sum(blast_samples) / len(blast_samples), 2) if blast_samples else 0.0,
            "avg_compatibility_hits": round(sum(hit_samples) / len(hit_samples), 2) if hit_samples else 0.0,
            "by_scenario": md.get("by_scenario", {}),
        }
    return result


def prometheus_label(value):
    return value.replace("\\", "\\\\").replace('"', '\\"').replace("\n", " ")


def render_prometheus_metrics():
    summary = telemetry_summary(read_telemetry())
    modes = summary.get("modes", {})
    ext = state_summary()
    lines = [
        "# HELP app_requests_total Total requests tracked.",
        "# TYPE app_requests_total counter",
        f"app_requests_total {summary.get('requests_tracked', 0)}",
        "# HELP app_extraction_success_total Successful extraction flows by mode.",
        "# TYPE app_extraction_success_total counter",
    ]
    for mode in ("bigbang", "compatible"):
        md = modes.get(mode, {})
        lines.append(f'app_extraction_success_total{{mode="{prometheus_label(mode)}"}} {md.get("successes", 0)}')
    lines += ["# HELP app_extraction_failure_total Failed extraction flows by mode.", "# TYPE app_extraction_failure_total counter"]
    for mode in ("bigbang", "compatible"):
        md = modes.get(mode, {})
        lines.append(f'app_extraction_failure_total{{mode="{prometheus_label(mode)}"}} {md.get("failures", 0)}')
    lines += ["# HELP app_extraction_avg_blast_radius Average blast radius by mode.", "# TYPE app_extraction_avg_blast_radius gauge"]
    for mode in ("bigbang", "compatible"):
        md = modes.get(mode, {})
        blast = md.get("blast_radius_samples", [])
        avg = round(sum(blast) / len(blast), 2) if blast else 0.0
        lines.append(f'app_extraction_avg_blast_radius{{mode="{prometheus_label(mode)}"}} {avg}')
    lines += ["# HELP app_extraction_avg_proxy_hits Average compatibility proxy hits by mode.", "# TYPE app_extraction_avg_proxy_hits gauge"]
    for mode in ("bigbang", "compatible"):
        md = modes.get(mode, {})
        hits = md.get("compatibility_hit_samples", [])
        avg = round(sum(hits) / len(hits), 2) if hits else 0.0
        lines.append(f'app_extraction_avg_proxy_hits{{mode="{prometheus_label(mode)}"}} {avg}')
    lines += ["# HELP app_consumer_cutover_progress Cutover progress per consumer (0-100).", "# TYPE app_consumer_cutover_progress gauge"]
    for consumer, progress in ext.get("consumers", {}).items():
        lines.append(f'app_consumer_cutover_progress{{consumer="{prometheus_label(consumer)}"}} {progress}')
    lines += [
        "# HELP app_contract_tests_total Total contract tests.", "# TYPE app_contract_tests_total counter",
        f'app_contract_tests_total {ext.get("contract_tests", 14)}',
        "# HELP app_compatibility_proxy_hits_total Total compatibility proxy hits.", "# TYPE app_compatibility_proxy_hits_total counter",
        f'app_compatibility_proxy_hits_total {ext.get("compatibility_proxy_hits", 0)}',
        "# HELP app_shadow_traffic_percent Shadow traffic percentage.", "# TYPE app_shadow_traffic_percent gauge",
        f'app_shadow_traffic_percent {ext.get("shadow_traffic_percent", 15)}',
    ]
    return "\n".join(lines) + "\n"


class Handler(BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        return

    def send_json(self, status_code, payload):
        body = json.dumps(payload, ensure_ascii=False, indent=2).encode("utf-8")
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def send_text(self, status_code, body):
        encoded = body.encode("utf-8")
        self.send_response(status_code)
        self.send_header("Content-Type", "text/plain; version=0.0.4; charset=utf-8")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def do_GET(self):
        started = time.perf_counter()
        parsed = urlparse(self.path)
        uri = parsed.path or "/"
        query = parse_qs(parsed.query)
        status_code = 200
        payload = {}
        skip_store_metrics = False
        flow_context = None

        try:
            if uri in ("/", ""):
                payload = {
                    "lab": "Problem-Driven Systems Lab",
                    "case": "08 - Extracción de módulo crítico sin romper operación",
                    "stack": APP_STACK,
                    "goal": "Comparar big-bang extraction vs. compatible extraction con proxy de compatibilidad.",
                    "routes": {
                        "/health": "Estado básico del servicio.",
                        "/pricing-bigbang?scenario=rule_drift&consumer=checkout": "Extracción big-bang del módulo de precios.",
                        "/pricing-compatible?scenario=rule_drift&consumer=checkout": "Extracción compatible con proxy de adaptación.",
                        "/cutover/advance?consumer=checkout": "Avanza cutover de un consumidor.",
                        "/extraction/state": "Estado actual de la extracción.",
                        "/flows?limit=10": "Últimas ejecuciones de flujo.",
                        "/diagnostics/summary": "Resumen de telemetría y comparación.",
                        "/metrics": "Métricas JSON del laboratorio.",
                        "/metrics-prometheus": "Métricas en formato Prometheus.",
                        "/reset-lab": "Reinicia estado y telemetría.",
                    },
                    "allowed_scenarios": list(SCENARIOS.keys()),
                    "allowed_consumers": list(VALID_CONSUMERS),
                }

            elif uri == "/health":
                payload = {"status": "ok", "stack": APP_STACK}

            elif uri in ("/pricing-bigbang", "/pricing-compatible"):
                mode = "bigbang" if uri == "/pricing-bigbang" else "compatible"
                scenario = query.get("scenario", ["stable"])[0]
                if scenario not in SCENARIOS:
                    scenario = "stable"
                consumer = query.get("consumer", ["checkout"])[0]
                if consumer not in VALID_CONSUMERS:
                    consumer = "checkout"

                try:
                    result = run_extraction_flow(mode, scenario, consumer)
                    status_code = result["http_status"]
                    flow_context = {
                        "mode": mode,
                        "scenario": scenario,
                        "consumer": consumer,
                        "outcome": "success",
                        "blast_radius": result["blast_radius"],
                        "compatibility_hits": result["compatibility_hits"],
                        "consumer_progress": result["consumer_progress"],
                    }
                    payload = {
                        "mode": mode,
                        "scenario": scenario,
                        "consumer": consumer,
                        "status": "completed",
                        "blast_radius": result["blast_radius"],
                        "compatibility_hits": result["compatibility_hits"],
                    }
                    if mode == "compatible":
                        payload["extraction_state"] = state_summary()
                        payload["proxy_adaptation"] = scenario in ("rule_drift",)

                except (ValueError, RuntimeError) as exc:
                    sc = SCENARIOS.get(scenario, SCENARIOS["stable"])
                    if scenario == "shared_write":
                        status_code = 409
                    elif scenario == "peak_sale":
                        status_code = 502
                    else:
                        status_code = 500
                    blast_radius = sc["bigbang_blast"]
                    flow_context = {
                        "mode": mode,
                        "scenario": scenario,
                        "consumer": consumer,
                        "outcome": "failure",
                        "blast_radius": blast_radius,
                        "compatibility_hits": 0,
                        "consumer_progress": 0,
                        "error": str(exc),
                    }
                    payload = {
                        "mode": mode,
                        "scenario": scenario,
                        "consumer": consumer,
                        "status": "error",
                        "error": str(exc),
                        "blast_radius": blast_radius,
                    }

                # Append run
                with _lock:
                    runs = read_runs()
                    run_record = {
                        "mode": mode,
                        "scenario": scenario,
                        "consumer": consumer,
                        "outcome": flow_context.get("outcome"),
                        "status_code": status_code,
                        "blast_radius": flow_context.get("blast_radius"),
                        "compatibility_hits": flow_context.get("compatibility_hits", 0),
                        "timestamp_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                    }
                    if flow_context.get("outcome") == "failure":
                        run_record["error"] = flow_context.get("error")
                    runs.append(run_record)
                    runs = runs[-50:]
                    write_runs(runs)

            elif uri == "/cutover/advance":
                consumer = query.get("consumer", ["checkout"])[0]
                if consumer not in VALID_CONSUMERS:
                    consumer = "checkout"
                summary = advance_cutover(consumer)
                payload = {
                    "status": "advanced",
                    "consumer": consumer,
                    "extraction_state": summary,
                }

            elif uri == "/extraction/state":
                payload = state_summary()

            elif uri == "/flows":
                limit = clamp_int(query_int(query, "limit", 10), 1, 50)
                runs = read_runs()
                payload = {"limit": limit, "total": len(runs), "flows": list(reversed(runs))[:limit]}

            elif uri == "/diagnostics/summary":
                payload = diagnostics_summary()

            elif uri == "/metrics":
                payload = {
                    "case": "08 - Extracción de módulo crítico sin romper operación",
                    "stack": APP_STACK,
                    **telemetry_summary(read_telemetry()),
                }

            elif uri == "/metrics-prometheus":
                skip_store_metrics = True
                self.send_text(200, render_prometheus_metrics())
                return

            elif uri == "/reset-lab":
                skip_store_metrics = True
                reset_all()
                payload = {"status": "reset", "message": "Estado y telemetría reiniciados."}

            else:
                status_code = 404
                payload = {"error": "Ruta no encontrada", "path": uri}

        except Exception as error:
            status_code = 500
            payload = {"error": "Fallo al procesar la solicitud", "message": str(error), "path": uri}

        elapsed_ms = round((time.perf_counter() - started) * 1000, 2)
        if not skip_store_metrics and uri not in ("/metrics",):
            record_request_telemetry(uri, status_code, elapsed_ms, flow_context)

        payload["elapsed_ms"] = elapsed_ms
        payload["timestamp_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        payload["pid"] = os.getpid()
        self.send_json(status_code, payload)


ensure_storage_dir()
server = HTTPServer(("0.0.0.0", 8080), Handler)
print("Servidor Python escuchando en 8080")
server.serve_forever()
