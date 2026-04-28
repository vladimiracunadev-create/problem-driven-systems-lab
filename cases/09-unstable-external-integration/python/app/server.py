from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse
import json
import os
import re
import random
import threading
import tempfile
import time

APP_STACK = os.environ.get("APP_STACK", "Python 3.12")
STORAGE_DIR = os.path.join(tempfile.gettempdir(), "pdsl-case09-python")
STATE_PATH = os.path.join(STORAGE_DIR, "state.json")
TELEMETRY_PATH = os.path.join(STORAGE_DIR, "telemetry.json")
RUNS_PATH = os.path.join(STORAGE_DIR, "runs.json")

_lock = threading.Lock()

_SKU_PATTERN = re.compile(r'^[A-Z0-9\-]{4,20}$')


# ---------------------------------------------------------------------------
# Storage helpers
# ---------------------------------------------------------------------------

def ensure_storage_dir():
    os.makedirs(STORAGE_DIR, exist_ok=True)


def initial_state():
    return {
        "integration": {
            "provider_name": "catalog-hub",
            "rate_limit_budget": 12,
            "cache": {
                "snapshot_version": "2026.04",
                "cached_skus": 48,
                "age_seconds": 90,
            },
            "contract": {
                "provider_version": "v1",
                "adapter_version": "v1",
                "schema_mappings": 3,
            },
            "quarantine_events": 0,
            "last_successful_sync": None,
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
            "legacy": {
                "successes": 0,
                "failures": 0,
                "cached_response_samples": [],
                "schema_protection_samples": [],
                "quota_saved_samples": [],
                "by_scenario": {},
            },
            "hardened": {
                "successes": 0,
                "failures": 0,
                "cached_response_samples": [],
                "schema_protection_samples": [],
                "quota_saved_samples": [],
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
    for mode in ("legacy", "hardened"):
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
            mode = flow_context.get("mode", "legacy")
            scenario = flow_context.get("scenario", "unknown")
            md = telemetry["modes"].setdefault(mode, initial_telemetry()["modes"]["legacy"].copy())

            if flow_context.get("outcome") == "success":
                md["successes"] = md.get("successes", 0) + 1
            else:
                md["failures"] = md.get("failures", 0) + 1

            for field in ("cached_response_samples", "schema_protection_samples", "quota_saved_samples"):
                key = field.replace("_samples", "")
                val = flow_context.get(key)
                if val is not None:
                    md.setdefault(field, [])
                    md[field].append(val)
                    md[field] = md[field][-500:]

            by_sc = md.setdefault("by_scenario", {})
            by_sc[scenario] = by_sc.get(scenario, 0) + 1

        write_telemetry(telemetry)


# ---------------------------------------------------------------------------
# Scenario definitions
# ---------------------------------------------------------------------------

SCENARIOS = {
    "ok":                 {"legacy_status": 200, "hardened_status": 200, "cached_response": 0, "schema_protected": 0, "quota_saved": 0},
    "schema_drift":       {"legacy_status": 502, "hardened_status": 200, "cached_response": 0, "schema_protected": 1, "quota_saved": 1},
    "rate_limited":       {"legacy_status": 429, "hardened_status": 200, "cached_response": 1, "schema_protected": 0, "quota_saved": 3},
    "partial_payload":    {"legacy_status": 502, "hardened_status": 200, "cached_response": 0, "schema_protected": 1, "quota_saved": 1},
    "maintenance_window": {"legacy_status": 503, "hardened_status": 200, "cached_response": 1, "schema_protected": 0, "quota_saved": 4},
}


def sanitize_sku(raw_sku):
    sku = (raw_sku or "SKU-100").strip().upper()
    if not _SKU_PATTERN.match(sku):
        return "SKU-100"
    return sku


def product_snapshot(sku):
    seed = sum(ord(c) for c in sku)
    return {
        "sku": sku,
        "title": f"Product {sku}",
        "price_usd": round(14 + (seed % 11) * 3.25, 2),
        "stock": 20 + seed % 45,
        "provider_version": "v1",
    }


def run_catalog_flow(mode, scenario, sku):
    sc = SCENARIOS.get(scenario, SCENARIOS["ok"])
    quota_cost = 3 if mode == "legacy" else 1

    if mode == "hardened":
        cached_response = sc["cached_response"]
        schema_protected = sc["schema_protected"]
        quota_saved = sc["quota_saved"]
    else:
        cached_response = 0
        schema_protected = 0
        quota_saved = 0

    # Simulate latency
    time.sleep((random.randint(40, 100)) / 1000.0)

    product = None
    source = None
    http_status = 200

    if mode == "legacy":
        if scenario in ("maintenance_window", "rate_limited"):
            if scenario == "rate_limited":
                http_status = 429
            else:
                http_status = 503
            raise RuntimeError("file_get_contents() Failed: Connection timed out")
        if scenario in ("schema_drift", "partial_payload"):
            http_status = 502
            raise ValueError("Undefined key 'price_usd'. Schema drift no mitigado.")
        # ok path
        product = product_snapshot(sku)
        source = "live_provider"
        http_status = 200

    else:  # hardened
        if scenario in ("maintenance_window", "rate_limited"):
            # Capture and fall back to cache
            product = product_snapshot(sku)
            source = "cached_snapshot"
            http_status = 200
        elif scenario in ("schema_drift", "partial_payload"):
            # Adapt contract in flight
            product = product_snapshot(sku)
            source = "live_provider"
            http_status = 200
        else:
            product = product_snapshot(sku)
            source = "live_provider"
            http_status = 200

    # Update state
    with _lock:
        state = read_state()
        integ = state["integration"]
        budget = integ.get("rate_limit_budget", 12)
        integ["rate_limit_budget"] = max(0, min(12, budget - quota_cost + quota_saved))

        # success side-effects
        cache = integ.setdefault("cache", {})
        cache["age_seconds"] = max(0, cache.get("age_seconds", 90) - 45)
        integ["last_successful_sync"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())

        if schema_protected:
            contract = integ.setdefault("contract", {})
            contract["adapter_version"] = "v1+mapping"
            contract["schema_mappings"] = contract.get("schema_mappings", 3) + 1

        write_state(state)

    return {
        "http_status": http_status,
        "cached_response": cached_response,
        "schema_protected": schema_protected,
        "quota_saved": quota_saved,
        "product": product,
        "source": source,
    }


def state_summary():
    state = read_state()
    integ = state["integration"]
    return {
        "provider_name": integ.get("provider_name", "catalog-hub"),
        "rate_limit_budget": integ.get("rate_limit_budget", 12),
        "cache": integ.get("cache", {}),
        "contract": integ.get("contract", {}),
        "quarantine_events": integ.get("quarantine_events", 0),
        "last_successful_sync": integ.get("last_successful_sync"),
    }


def diagnostics_summary():
    summary = telemetry_summary(read_telemetry())
    modes = summary.get("modes", {})
    result = {
        "case": "09 - Integración externa inestable",
        "stack": APP_STACK,
        "metrics": summary,
        "integration_state": state_summary(),
        "comparison": {},
    }
    for mode in ("legacy", "hardened"):
        md = modes.get(mode, {})
        cached = md.get("cached_response_samples", [])
        schema = md.get("schema_protection_samples", [])
        quota = md.get("quota_saved_samples", [])
        result["comparison"][mode] = {
            "successes": md.get("successes", 0),
            "failures": md.get("failures", 0),
            "avg_cached_response": round(sum(cached) / len(cached), 2) if cached else 0.0,
            "avg_schema_protection": round(sum(schema) / len(schema), 2) if schema else 0.0,
            "avg_quota_saved": round(sum(quota) / len(quota), 2) if quota else 0.0,
            "by_scenario": md.get("by_scenario", {}),
        }
    return result


def prometheus_label(value):
    return value.replace("\\", "\\\\").replace('"', '\\"').replace("\n", " ")


def render_prometheus_metrics():
    summary = telemetry_summary(read_telemetry())
    modes = summary.get("modes", {})
    integ = state_summary()
    lines = [
        "# HELP app_requests_total Total requests tracked.",
        "# TYPE app_requests_total counter",
        f"app_requests_total {summary.get('requests_tracked', 0)}",
        "# HELP app_integration_success_total Successful integration flows by mode.",
        "# TYPE app_integration_success_total counter",
    ]
    for mode in ("legacy", "hardened"):
        md = modes.get(mode, {})
        lines.append(f'app_integration_success_total{{mode="{prometheus_label(mode)}"}} {md.get("successes", 0)}')
    lines += ["# HELP app_integration_failure_total Failed integration flows by mode.", "# TYPE app_integration_failure_total counter"]
    for mode in ("legacy", "hardened"):
        md = modes.get(mode, {})
        lines.append(f'app_integration_failure_total{{mode="{prometheus_label(mode)}"}} {md.get("failures", 0)}')
    lines += ["# HELP app_integration_avg_cached_response Average cached response rate by mode.", "# TYPE app_integration_avg_cached_response gauge"]
    for mode in ("legacy", "hardened"):
        md = modes.get(mode, {})
        cached = md.get("cached_response_samples", [])
        avg = round(sum(cached) / len(cached), 2) if cached else 0.0
        lines.append(f'app_integration_avg_cached_response{{mode="{prometheus_label(mode)}"}} {avg}')
    lines += ["# HELP app_integration_avg_schema_protection Average schema protection rate by mode.", "# TYPE app_integration_avg_schema_protection gauge"]
    for mode in ("legacy", "hardened"):
        md = modes.get(mode, {})
        schema = md.get("schema_protection_samples", [])
        avg = round(sum(schema) / len(schema), 2) if schema else 0.0
        lines.append(f'app_integration_avg_schema_protection{{mode="{prometheus_label(mode)}"}} {avg}')
    lines += ["# HELP app_integration_avg_quota_saved Average quota saved by mode.", "# TYPE app_integration_avg_quota_saved gauge"]
    for mode in ("legacy", "hardened"):
        md = modes.get(mode, {})
        quota = md.get("quota_saved_samples", [])
        avg = round(sum(quota) / len(quota), 2) if quota else 0.0
        lines.append(f'app_integration_avg_quota_saved{{mode="{prometheus_label(mode)}"}} {avg}')
    provider = integ.get("provider_name", "catalog-hub")
    lines += [
        "# HELP app_provider_rate_limit_budget Remaining rate limit budget.", "# TYPE app_provider_rate_limit_budget gauge",
        f'app_provider_rate_limit_budget{{provider="{prometheus_label(provider)}"}} {integ.get("rate_limit_budget", 12)}',
        "# HELP app_cache_age_seconds Cache snapshot age in seconds.", "# TYPE app_cache_age_seconds gauge",
        f'app_cache_age_seconds {integ.get("cache", {}).get("age_seconds", 90)}',
        "# HELP app_quarantine_events_total Total quarantine events.", "# TYPE app_quarantine_events_total counter",
        f'app_quarantine_events_total {integ.get("quarantine_events", 0)}',
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
                    "case": "09 - Integración externa inestable",
                    "stack": APP_STACK,
                    "goal": "Comparar un cliente legacy frágil vs. un cliente hardened con cache, protección de schema y gestión de quota.",
                    "routes": {
                        "/health": "Estado básico del servicio.",
                        "/catalog-legacy?scenario=rate_limited&sku=SKU-100": "Consulta al proveedor sin resiliencia.",
                        "/catalog-hardened?scenario=rate_limited&sku=SKU-100": "Consulta al proveedor con resiliencia.",
                        "/integration/state": "Estado actual de la integración.",
                        "/sync-events?limit=10": "Últimas ejecuciones de sincronización.",
                        "/diagnostics/summary": "Resumen de telemetría y comparación.",
                        "/metrics": "Métricas JSON del laboratorio.",
                        "/metrics-prometheus": "Métricas en formato Prometheus.",
                        "/reset-lab": "Reinicia estado y telemetría.",
                    },
                    "allowed_scenarios": list(SCENARIOS.keys()),
                }

            elif uri == "/health":
                payload = {"status": "ok", "stack": APP_STACK}

            elif uri in ("/catalog-legacy", "/catalog-hardened"):
                mode = "legacy" if uri == "/catalog-legacy" else "hardened"
                scenario = query.get("scenario", ["ok"])[0]
                if scenario not in SCENARIOS:
                    scenario = "ok"
                raw_sku = query.get("sku", ["SKU-100"])[0]
                sku = sanitize_sku(raw_sku)

                try:
                    result = run_catalog_flow(mode, scenario, sku)
                    status_code = result["http_status"]
                    flow_context = {
                        "mode": mode,
                        "scenario": scenario,
                        "sku": sku,
                        "outcome": "success",
                        "cached_response": result["cached_response"],
                        "schema_protected": result["schema_protected"],
                        "quota_saved": result["quota_saved"],
                    }
                    payload = {
                        "mode": mode,
                        "scenario": scenario,
                        "sku": sku,
                        "status": "ok",
                        "source": result["source"],
                        "cached_response": bool(result["cached_response"]),
                        "schema_protected": bool(result["schema_protected"]),
                        "quota_saved": result["quota_saved"],
                        "product": result["product"],
                        "integration_state": state_summary(),
                    }

                except (RuntimeError, ValueError) as exc:
                    sc = SCENARIOS.get(scenario, SCENARIOS["ok"])
                    if mode == "legacy":
                        if scenario == "rate_limited":
                            status_code = 429
                        elif scenario == "maintenance_window":
                            status_code = 503
                        else:
                            status_code = 502
                    else:
                        status_code = 500

                    # Quarantine event on failure
                    with _lock:
                        state = read_state()
                        integ = state["integration"]
                        integ["quarantine_events"] = integ.get("quarantine_events", 0) + 1
                        write_state(state)

                    flow_context = {
                        "mode": mode,
                        "scenario": scenario,
                        "sku": sku,
                        "outcome": "failure",
                        "cached_response": 0,
                        "schema_protected": 0,
                        "quota_saved": 0,
                        "error": str(exc),
                    }
                    payload = {
                        "mode": mode,
                        "scenario": scenario,
                        "sku": sku,
                        "status": "error",
                        "error": str(exc),
                        "integration_state": state_summary(),
                    }

                # Append run
                with _lock:
                    runs = read_runs()
                    run_record = {
                        "mode": mode,
                        "scenario": scenario,
                        "sku": sku,
                        "outcome": flow_context.get("outcome"),
                        "status_code": status_code,
                        "cached_response": flow_context.get("cached_response", 0),
                        "schema_protected": flow_context.get("schema_protected", 0),
                        "quota_saved": flow_context.get("quota_saved", 0),
                        "timestamp_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                    }
                    if flow_context.get("outcome") == "failure":
                        run_record["error"] = flow_context.get("error")
                    runs.append(run_record)
                    runs = runs[-50:]
                    write_runs(runs)

            elif uri == "/integration/state":
                payload = state_summary()

            elif uri == "/sync-events":
                limit = clamp_int(query_int(query, "limit", 10), 1, 50)
                runs = read_runs()
                payload = {"limit": limit, "total": len(runs), "sync_events": list(reversed(runs))[:limit]}

            elif uri == "/diagnostics/summary":
                payload = diagnostics_summary()

            elif uri == "/metrics":
                payload = {
                    "case": "09 - Integración externa inestable",
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
