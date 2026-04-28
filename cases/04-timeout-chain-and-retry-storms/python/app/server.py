from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse
import json
import math
import os
import random
import secrets
import tempfile
import threading
import time

APP_STACK = os.environ.get("APP_STACK", "Python 3.12")
STORAGE_DIR = os.path.join(tempfile.gettempdir(), "pdsl-case04-python")
TELEMETRY_PATH = os.path.join(STORAGE_DIR, "telemetry.json")
DEPENDENCY_PATH = os.path.join(STORAGE_DIR, "dependency_state.json")

_lock = threading.Lock()


# ---------------------------------------------------------------------------
# Storage helpers
# ---------------------------------------------------------------------------

def ensure_storage_dir():
    os.makedirs(STORAGE_DIR, exist_ok=True)


# ---------------------------------------------------------------------------
# Dependency state
# ---------------------------------------------------------------------------

def initial_dependency_state():
    now_str = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    return {
        "provider": {
            "name": "carrier-gateway",
            "consecutive_failures": 0,
            "opened_until": None,
            "last_outcome": "unknown",
            "last_latency_ms": 0.0,
            "last_updated": None,
            "open_events": 0,
            "short_circuit_count": 0,
            "fallback_quote": {
                "quote_id": "cached-quote-seed",
                "amount": 47.8,
                "currency": "USD",
                "source": "cached",
                "cached_at": now_str,
            },
        }
    }


def read_dependency_state():
    ensure_storage_dir()
    if not os.path.exists(DEPENDENCY_PATH):
        return initial_dependency_state()
    try:
        with open(DEPENDENCY_PATH, "r", encoding="utf-8") as fh:
            return json.load(fh)
    except (OSError, json.JSONDecodeError):
        return initial_dependency_state()


def write_dependency_state(state):
    ensure_storage_dir()
    with open(DEPENDENCY_PATH, "w", encoding="utf-8") as fh:
        json.dump(state, fh, indent=2, ensure_ascii=False)


# ---------------------------------------------------------------------------
# Telemetry
# ---------------------------------------------------------------------------

def initial_telemetry():
    def mode_metrics():
        return {
            "successes": 0,
            "failures": 0,
            "attempts_total": 0,
            "retries_total": 0,
            "timeouts_total": 0,
            "fallbacks_used": 0,
            "circuit_opens": 0,
            "short_circuits": 0,
            "by_scenario": {},
        }

    return {
        "requests": 0,
        "samples_ms": [],
        "routes": {},
        "status_counts": {},
        "last_path": None,
        "last_status": 200,
        "last_updated": None,
        "modes": {
            "legacy": mode_metrics(),
            "resilient": mode_metrics(),
        },
        "recent_incidents": [],
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

    base = initial_telemetry()
    base.update(parsed)
    # Deep-merge modes
    for mode in ("legacy", "resilient"):
        if mode in parsed.get("modes", {}):
            base["modes"][mode].update(parsed["modes"][mode])
    base["routes"] = parsed.get("routes", {})
    base["samples_ms"] = parsed.get("samples_ms", [])
    base["status_counts"] = parsed.get("status_counts", {})
    base["recent_incidents"] = parsed.get("recent_incidents", [])
    return base


def write_telemetry(telemetry):
    ensure_storage_dir()
    with open(TELEMETRY_PATH, "w", encoding="utf-8") as fh:
        json.dump(telemetry, fh, indent=2, ensure_ascii=False)


# ---------------------------------------------------------------------------
# Resilience policies
# ---------------------------------------------------------------------------

POLICIES = {
    "legacy": {
        "timeout_ms": 360,
        "max_attempts": 4,
        "backoff_base_ms": 0,
        "use_circuit_breaker": False,
        "allow_fallback": False,
    },
    "resilient": {
        "timeout_ms": 220,
        "max_attempts": 2,
        "backoff_base_ms": 80,
        "use_circuit_breaker": True,
        "allow_fallback": True,
    },
}

CB_OPEN_THRESHOLD = 2        # consecutive failures to open
CB_OPEN_DURATION_S = 30.0    # seconds the breaker stays open


# ---------------------------------------------------------------------------
# Scenario simulator
# ---------------------------------------------------------------------------

def simulate_provider_call(scenario, attempt):
    """Returns (latency_ms, success: bool)."""
    if scenario == "ok":
        latency = random.uniform(115, 150)
        return latency, True

    if scenario == "slow_provider":
        latency = random.uniform(640, 730)
        return latency, False     # will always exceed timeout

    if scenario == "flaky_provider":
        if attempt == 1:
            latency = random.uniform(520, 560)
            return latency, False
        latency = random.uniform(150, 195)
        return latency, True

    if scenario == "provider_down":
        # Returns timeout + small overhead so every attempt times out
        return None, False   # caller handles as hard timeout

    if scenario == "burst_then_recover":
        if attempt <= 2:
            latency = random.uniform(500, 580)
            return latency, False
        latency = random.uniform(130, 180)
        return latency, True

    # default: treat like ok
    latency = random.uniform(115, 150)
    return latency, True


def backoff_for_attempt(policy, attempt):
    base = policy["backoff_base_ms"]
    if base == 0:
        return 0
    jitter = random.uniform(15, 45)
    return base * (2 ** max(0, attempt - 1)) + jitter


# ---------------------------------------------------------------------------
# Core quote flow
# ---------------------------------------------------------------------------

def run_quote(mode, scenario, customer_id, items):
    policy = POLICIES[mode]
    timeout_ms = policy["timeout_ms"]
    max_attempts = policy["max_attempts"]
    use_cb = policy["use_circuit_breaker"]
    allow_fallback = policy["allow_fallback"]

    req_id = f"req-{secrets.token_hex(4)}"
    trace_id = f"trace-{secrets.token_hex(4)}"
    events = []
    timeout_count = 0
    attempt = 0
    final_quote = None
    final_status = "failed"
    http_status = 200
    circuit_opened_this_call = False
    short_circuited = False

    with _lock:
        dep_state = read_dependency_state()
        provider = dep_state["provider"]

        # Circuit breaker open check
        if use_cb and provider.get("opened_until"):
            opened_until_epoch = provider["opened_until"]
            now_epoch = time.time()
            if now_epoch < opened_until_epoch:
                # Circuit is open — short circuit
                provider["short_circuit_count"] = provider.get("short_circuit_count", 0) + 1
                dep_state["provider"] = provider
                write_dependency_state(dep_state)
                short_circuited = True
            else:
                # Circuit expired — close it
                provider["opened_until"] = None
                provider["consecutive_failures"] = 0
                dep_state["provider"] = provider
                write_dependency_state(dep_state)

    if short_circuited:
        events.append({"event": "circuit_open", "action": "short_circuit", "attempt": 0})
        if allow_fallback:
            with _lock:
                dep_state = read_dependency_state()
                fallback_q = dep_state["provider"]["fallback_quote"]
            fallback_q = dict(fallback_q)
            fallback_q["source"] = "fallback"
            final_quote = fallback_q
            final_status = "degraded"
            http_status = 200
        else:
            final_status = "failed"
            http_status = 503

        return _build_quote_result(
            mode, scenario, req_id, trace_id, customer_id, items,
            final_quote, final_status, http_status,
            attempt, max(0, attempt - 1), timeout_count, events,
            dep_state["provider"], True
        )

    # Attempt loop
    while attempt < max_attempts:
        attempt += 1

        # Backoff before retry (not first attempt)
        if attempt > 1:
            wait_ms = backoff_for_attempt(policy, attempt - 1)
            if wait_ms > 0:
                time.sleep(wait_ms / 1000.0)
                events.append({"event": "backoff", "attempt": attempt, "wait_ms": round(wait_ms, 2)})

        # Simulate provider call
        call_start = time.perf_counter()

        if scenario == "provider_down":
            # Always times out exactly at timeout_ms
            time.sleep(timeout_ms / 1000.0)
            actual_ms = timeout_ms
            timed_out = True
            provider_ok = False
        else:
            latency_ms, provider_ok = simulate_provider_call(scenario, attempt)
            timed_out = latency_ms > timeout_ms
            sleep_ms = min(latency_ms, timeout_ms + 5) if timed_out else latency_ms
            time.sleep(sleep_ms / 1000.0)
            actual_ms = round((time.perf_counter() - call_start) * 1000, 2)

        if timed_out or not provider_ok:
            timeout_count += 1
            events.append({
                "event": "attempt_failed",
                "attempt": attempt,
                "reason": "timeout" if timed_out else "provider_error",
                "elapsed_ms": actual_ms,
            })

            with _lock:
                dep_state = read_dependency_state()
                provider = dep_state["provider"]
                provider["consecutive_failures"] = provider.get("consecutive_failures", 0) + 1
                provider["last_outcome"] = "failure"
                provider["last_latency_ms"] = round(actual_ms, 2)
                provider["last_updated"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())

                if use_cb and provider["consecutive_failures"] >= CB_OPEN_THRESHOLD and not provider.get("opened_until"):
                    provider["opened_until"] = time.time() + CB_OPEN_DURATION_S
                    provider["open_events"] = provider.get("open_events", 0) + 1
                    circuit_opened_this_call = True
                    events.append({"event": "circuit_opened", "attempt": attempt, "consecutive_failures": provider["consecutive_failures"]})

                dep_state["provider"] = provider
                write_dependency_state(dep_state)
        else:
            events.append({
                "event": "attempt_success",
                "attempt": attempt,
                "elapsed_ms": actual_ms,
            })

            with _lock:
                dep_state = read_dependency_state()
                provider = dep_state["provider"]
                provider["consecutive_failures"] = 0
                provider["opened_until"] = None
                provider["last_outcome"] = "success"
                provider["last_latency_ms"] = round(actual_ms, 2)
                provider["last_updated"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
                dep_state["provider"] = provider
                write_dependency_state(dep_state)

            quote_id = f"quote-{secrets.token_hex(5)}"
            final_quote = {
                "quote_id": quote_id,
                "amount": 47.8,
                "currency": "USD",
                "source": "live",
            }
            final_status = "completed"
            http_status = 200
            break

        # If circuit just opened and we allow fallback — stop retrying
        if circuit_opened_this_call and allow_fallback:
            break

    if final_quote is None:
        # All attempts exhausted
        if allow_fallback:
            with _lock:
                dep_state = read_dependency_state()
                fallback_q = dict(dep_state["provider"]["fallback_quote"])
            fallback_q["source"] = "fallback"
            final_quote = fallback_q
            final_status = "degraded"
            http_status = 200
            events.append({"event": "fallback_used"})
        else:
            final_status = "failed"
            http_status = 503

    with _lock:
        dep_state = read_dependency_state()

    return _build_quote_result(
        mode, scenario, req_id, trace_id, customer_id, items,
        final_quote, final_status, http_status,
        attempt, max(0, attempt - 1), timeout_count, events,
        dep_state["provider"], False
    )


def _build_quote_result(mode, scenario, req_id, trace_id, customer_id, items,
                        quote, status, http_status,
                        attempts, retries, timeout_count, events,
                        provider, short_circuited):
    opened_until = provider.get("opened_until")
    circuit_open = bool(opened_until and time.time() < opened_until)
    return {
        "result": {
            "mode": mode,
            "scenario": scenario,
            "status": status,
            "request_id": req_id,
            "trace_id": trace_id,
            "customer_id": customer_id,
            "items": items,
            "quote": quote,
            "attempts": attempts,
            "retries": retries,
            "timeout_count": timeout_count,
            "events": events,
            "dependency": {
                "name": provider.get("name"),
                "circuit_status": "open" if circuit_open else "closed",
                "consecutive_failures": provider.get("consecutive_failures", 0),
                "last_outcome": provider.get("last_outcome"),
                "last_latency_ms": provider.get("last_latency_ms"),
                "last_updated": provider.get("last_updated"),
            },
        },
        "http_status": http_status,
        "short_circuited": short_circuited,
        "fallback_used": (quote or {}).get("source") in ("fallback", "cached"),
    }


# ---------------------------------------------------------------------------
# Telemetry helpers
# ---------------------------------------------------------------------------

def percentile(values, pct):
    if not values:
        return 0.0
    sv = sorted(values)
    idx = max(0, min(len(sv) - 1, int((pct / 100.0) * len(sv) + 0.999999) - 1))
    return round(float(sv[idx]), 2)


def clamp_int(value, lo, hi):
    return max(lo, min(hi, value))


def query_int(query, key, default):
    vals = query.get(key, [])
    if not vals:
        return default
    try:
        return int(vals[0])
    except ValueError:
        return default


def bucket_status(status):
    if status >= 500:
        return "5xx"
    if status >= 400:
        return "4xx"
    return "2xx"


def route_metrics_summary(telemetry):
    result = {}
    for route, samples in (telemetry.get("routes") or {}).items():
        values = samples if isinstance(samples, list) else []
        count = len(values)
        result[route] = {
            "count": count,
            "avg_ms": round(sum(values) / count, 2) if count else 0.0,
            "p95_ms": percentile(values, 95),
            "p99_ms": percentile(values, 99),
            "max_ms": round(max(values), 2) if count else 0.0,
        }
    return dict(sorted(result.items()))


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
        "modes": telemetry.get("modes", {}),
        "routes": route_metrics_summary(telemetry),
        "recent_incidents": list(reversed(telemetry.get("recent_incidents", [])))[:50],
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
        bucket = bucket_status(status)
        telemetry["status_counts"][bucket] = telemetry["status_counts"].get(bucket, 0) + 1

        if flow_context is not None:
            mode = flow_context["mode"]
            m = telemetry["modes"][mode]
            m["attempts_total"] = m.get("attempts_total", 0) + flow_context.get("attempts", 0)
            m["retries_total"] = m.get("retries_total", 0) + flow_context.get("retries", 0)
            m["timeouts_total"] = m.get("timeouts_total", 0) + flow_context.get("timeout_count", 0)

            if flow_context.get("short_circuited"):
                m["short_circuits"] = m.get("short_circuits", 0) + 1
            if flow_context.get("circuit_opened"):
                m["circuit_opens"] = m.get("circuit_opens", 0) + 1
            if flow_context.get("fallback_used"):
                m["fallbacks_used"] = m.get("fallbacks_used", 0) + 1

            scenario = flow_context.get("scenario", "unknown")
            outcome = flow_context.get("outcome", "failure")
            if outcome == "success" or flow_context.get("status") == "completed":
                m["successes"] = m.get("successes", 0) + 1
            else:
                m["failures"] = m.get("failures", 0) + 1

            m["by_scenario"].setdefault(scenario, {"successes": 0, "failures": 0})
            if outcome == "success" or flow_context.get("status") == "completed":
                m["by_scenario"][scenario]["successes"] += 1
            else:
                m["by_scenario"][scenario]["failures"] += 1

            incident = {
                "mode": mode,
                "scenario": scenario,
                "status": flow_context.get("status"),
                "http_status": status,
                "attempts": flow_context.get("attempts", 0),
                "retries": flow_context.get("retries", 0),
                "timeout_count": flow_context.get("timeout_count", 0),
                "fallback_used": flow_context.get("fallback_used", False),
                "elapsed_ms": round(elapsed_ms, 2),
                "timestamp_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            }
            telemetry["recent_incidents"].append(incident)
            telemetry["recent_incidents"] = telemetry["recent_incidents"][-50:]

        write_telemetry(telemetry)


def prometheus_label(v):
    return v.replace("\\", "\\\\").replace('"', '\\"').replace("\n", " ")


def render_prometheus_metrics():
    with _lock:
        summary = telemetry_summary(read_telemetry())
        dep_state = read_dependency_state()

    lines = [
        "# HELP app_requests_total Total de requests observados.",
        "# TYPE app_requests_total counter",
        f"app_requests_total {summary.get('requests_tracked', 0)}",
    ]

    modes_data = summary.get("modes", {})
    for mode, m in modes_data.items():
        lm = prometheus_label(mode)
        lines += [
            f'app_flow_success_total{{mode="{lm}"}} {m.get("successes", 0)}',
            f'app_flow_failure_total{{mode="{lm}"}} {m.get("failures", 0)}',
            f'app_flow_timeouts_total{{mode="{lm}"}} {m.get("timeouts_total", 0)}',
            f'app_flow_fallback_total{{mode="{lm}"}} {m.get("fallbacks_used", 0)}',
            f'app_flow_circuit_open_total{{mode="{lm}"}} {m.get("circuit_opens", 0)}',
        ]
        attempts = m.get("attempts_total", 0)
        successes = m.get("successes", 0)
        avg_att = round(attempts / max(1, successes + m.get("failures", 0)), 3)
        lines.append(f'app_flow_avg_attempts{{mode="{lm}"}} {avg_att}')

    provider = dep_state.get("provider", {})
    pname = prometheus_label(provider.get("name", "unknown"))
    opened_until = provider.get("opened_until")
    cb_open = 1 if (opened_until and time.time() < opened_until) else 0
    lines.append(f'dependency_circuit_open{{provider="{pname}"}} {cb_open}')

    return "\n".join(lines) + "\n"


# ---------------------------------------------------------------------------
# Handler
# ---------------------------------------------------------------------------

ALLOWED_SCENARIOS = ["ok", "slow_provider", "flaky_provider", "provider_down", "burst_then_recover"]


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
                    "case": "04 - Cadena de timeouts y tormentas de reintentos",
                    "stack": APP_STACK,
                    "goal": "Comparar una politica de reintentos agresiva (legacy) contra una resiliente con circuit breaker y fallback.",
                    "routes": {
                        "/health": "Estado basico del servicio.",
                        "/quote-legacy?scenario=slow_provider&customer_id=42&items=3": "Flujo legacy: 4 reintentos sin backoff ni circuit breaker.",
                        "/quote-resilient?scenario=slow_provider&customer_id=42&items=3": "Flujo resiliente: CB, backoff exponencial, fallback.",
                        "/dependency/state": "Estado actual del proveedor carrier-gateway.",
                        "/incidents?limit=10": "Ultimos incidentes registrados.",
                        "/diagnostics/summary": "Resumen completo de telemetria.",
                        "/metrics": "Metricas JSON.",
                        "/metrics-prometheus": "Metricas en formato Prometheus.",
                        "/reset-lab": "Reinicia estado y telemetria.",
                    },
                    "allowed_scenarios": ALLOWED_SCENARIOS,
                }

            elif uri == "/health":
                payload = {"status": "ok", "stack": APP_STACK}

            elif uri in ("/quote-legacy", "/quote-resilient"):
                mode = "legacy" if uri == "/quote-legacy" else "resilient"
                scenario = query.get("scenario", ["ok"])[0]
                if scenario not in ALLOWED_SCENARIOS:
                    scenario = "ok"
                customer_id = clamp_int(query_int(query, "customer_id", 42), 1, 5000)
                items = clamp_int(query_int(query, "items", 3), 1, 50)

                result_bundle = run_quote(mode, scenario, customer_id, items)
                result = result_bundle["result"]
                status_code = result_bundle["http_status"]

                flow_context = {
                    "mode": mode,
                    "scenario": scenario,
                    "status": result["status"],
                    "outcome": "success" if result["status"] == "completed" else "failure",
                    "attempts": result["attempts"],
                    "retries": result["retries"],
                    "timeout_count": result["timeout_count"],
                    "fallback_used": result_bundle["fallback_used"],
                    "short_circuited": result_bundle["short_circuited"],
                    "circuit_opened": any(e.get("event") == "circuit_opened" for e in result["events"]),
                }
                payload = result

            elif uri == "/dependency/state":
                with _lock:
                    dep_state = read_dependency_state()
                provider = dep_state["provider"]
                opened_until = provider.get("opened_until")
                circuit_open = bool(opened_until and time.time() < opened_until)
                payload = {
                    **provider,
                    "circuit_status": "open" if circuit_open else "closed",
                }

            elif uri == "/incidents":
                limit = clamp_int(query_int(query, "limit", 10), 1, 50)
                with _lock:
                    telemetry = read_telemetry()
                incidents = list(reversed(telemetry.get("recent_incidents", [])))[:limit]
                payload = {"limit": limit, "incidents": incidents}

            elif uri == "/diagnostics/summary":
                with _lock:
                    summary = telemetry_summary(read_telemetry())
                    dep_state = read_dependency_state()
                provider = dep_state["provider"]
                opened_until = provider.get("opened_until")
                circuit_open = bool(opened_until and time.time() < opened_until)
                payload = {
                    "case": "04 - Cadena de timeouts y tormentas de reintentos",
                    "stack": APP_STACK,
                    "metrics": summary,
                    "dependency": {
                        **provider,
                        "circuit_status": "open" if circuit_open else "closed",
                    },
                    "policies": POLICIES,
                }

            elif uri == "/metrics":
                with _lock:
                    summary = telemetry_summary(read_telemetry())
                payload = {
                    "case": "04 - Cadena de timeouts y tormentas de reintentos",
                    "stack": APP_STACK,
                    **summary,
                }

            elif uri == "/metrics-prometheus":
                skip_store_metrics = True
                self.send_text(200, render_prometheus_metrics())
                return

            elif uri == "/reset-lab":
                skip_store_metrics = True
                with _lock:
                    write_telemetry(initial_telemetry())
                    write_dependency_state(initial_dependency_state())
                payload = {"status": "reset", "message": "Estado y telemetria reiniciados."}

            else:
                status_code = 404
                payload = {"error": "Ruta no encontrada", "path": uri}

        except Exception as error:
            status_code = 500
            payload = {
                "error": "Fallo al procesar la solicitud",
                "message": str(error),
                "path": uri,
            }

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
