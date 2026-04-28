from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse
import json
import logging
import os
import secrets
import tempfile
import time

APP_STACK = "Python 3.12"
STORAGE_DIR = os.path.join(tempfile.gettempdir(), "pdsl-case03-python")
TELEMETRY_PATH = os.path.join(STORAGE_DIR, "telemetry.json")
LEGACY_LOG_PATH = os.path.join(STORAGE_DIR, "legacy.log")
OBSERVABLE_LOG_PATH = os.path.join(STORAGE_DIR, "observable.log")

# ---------------------------------------------------------------------------
# Logging infrastructure — the real Python contrast
# ---------------------------------------------------------------------------

# Fields that belong to the LogRecord internals and should not be forwarded
# into the structured JSON output.
_LOG_RECORD_BUILTINS = frozenset((
    "name", "msg", "args", "levelname", "levelno", "pathname", "filename",
    "module", "exc_info", "exc_text", "stack_info", "lineno", "funcName",
    "created", "msecs", "relativeCreated", "thread", "threadName",
    "processName", "process", "message", "taskName",
))


class JsonFormatter(logging.Formatter):
    """Emits each log record as a single-line JSON object.

    Any field injected via logging.LoggerAdapter.extra (request_id, trace_id,
    step, dependency…) appears at the top level of the JSON alongside the
    standard fields. This is the structural difference between legacy and
    observable: the formatter is what makes a log parseable or opaque.
    """

    def format(self, record: logging.LogRecord) -> str:
        record.message = record.getMessage()
        doc: dict = {
            "timestamp_utc": self.formatTime(record, "%Y-%m-%dT%H:%M:%SZ"),
            "level": record.levelname,
            "event": record.message,
        }
        for key, val in record.__dict__.items():
            if key not in _LOG_RECORD_BUILTINS and not key.startswith("_"):
                doc[key] = val
        return json.dumps(doc, ensure_ascii=False, default=str)


def _build_legacy_logger() -> logging.Logger:
    """Plain-text logger. No correlation IDs. No structure. No context.

    This is what you get when someone just calls logging.basicConfig() and
    never thinks about observability again. The format string is conventional
    but the output is a flat string: unparseable by machines, uncorrelatable
    across concurrent requests.
    """
    logger = logging.getLogger("pdsl.case03.legacy")
    logger.setLevel(logging.DEBUG)
    logger.propagate = False
    if not logger.handlers:
        handler = logging.FileHandler(LEGACY_LOG_PATH, encoding="utf-8")
        handler.setFormatter(
            logging.Formatter(
                fmt="[%(asctime)s] %(levelname)s %(message)s",
                datefmt="%Y-%m-%dT%H:%M:%SZ",
            )
        )
        logger.addHandler(handler)
    return logger


def _build_observable_logger() -> logging.Logger:
    """JSON-structured logger. Every record carries full context.

    Paired with LoggerAdapter (see run_checkout), each log call automatically
    carries request_id and trace_id — injected once at the adapter level,
    propagated to every record without the caller having to repeat them.
    """
    logger = logging.getLogger("pdsl.case03.observable")
    logger.setLevel(logging.DEBUG)
    logger.propagate = False
    if not logger.handlers:
        handler = logging.FileHandler(OBSERVABLE_LOG_PATH, encoding="utf-8")
        handler.setFormatter(JsonFormatter())
        logger.addHandler(handler)
    return logger


def ensure_storage_dir() -> None:
    os.makedirs(STORAGE_DIR, exist_ok=True)


def _reset_file_handler(logger: logging.Logger, log_path: str,
                         formatter: logging.Formatter) -> None:
    """Close existing FileHandler, truncate the file, reattach a new handler."""
    for handler in list(logger.handlers):
        handler.close()
        logger.removeHandler(handler)
    if os.path.exists(log_path):
        os.unlink(log_path)
    new_handler = logging.FileHandler(log_path, encoding="utf-8")
    new_handler.setFormatter(formatter)
    logger.addHandler(new_handler)


# Module-level loggers, initialised once at startup.
ensure_storage_dir()
_legacy_logger = _build_legacy_logger()
_observable_logger = _build_observable_logger()


# ---------------------------------------------------------------------------
# Workflow failure — structured exception carries all diagnostic context
# ---------------------------------------------------------------------------

class WorkflowFailure(Exception):
    def __init__(self, message, step, dependency, http_status, request_id,
                 trace_id, events):
        super().__init__(message)
        self.step = step
        self.dependency = dependency
        self.http_status = http_status
        self.request_id = request_id
        self.trace_id = trace_id
        self.events = events


# ---------------------------------------------------------------------------
# Telemetry persistence
# ---------------------------------------------------------------------------

def initial_telemetry():
    return {
        "requests": 0,
        "samples_ms": [],
        "routes": {},
        "last_path": None,
        "last_status": 200,
        "last_updated": None,
        "status_bucket": "2xx",
        "successes": {"legacy": 0, "observable": 0},
        "failures": {
            "legacy": {"total": 0, "by_step": {}, "by_scenario": {}},
            "observable": {"total": 0, "by_step": {}, "by_scenario": {}},
        },
        "traces": [],
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

    t = initial_telemetry()
    t.update(parsed)
    t["successes"].update(parsed.get("successes", {}))
    t["failures"]["legacy"].update(parsed.get("failures", {}).get("legacy", {}))
    t["failures"]["observable"].update(parsed.get("failures", {}).get("observable", {}))
    t["routes"] = parsed.get("routes", {})
    t["traces"] = parsed.get("traces", [])
    t["samples_ms"] = parsed.get("samples_ms", [])
    return t


def write_telemetry(t):
    ensure_storage_dir()
    with open(TELEMETRY_PATH, "w", encoding="utf-8") as fh:
        json.dump(t, fh, indent=2, ensure_ascii=False)


def reset_telemetry_state():
    write_telemetry(initial_telemetry())
    plain_fmt = logging.Formatter(
        fmt="[%(asctime)s] %(levelname)s %(message)s",
        datefmt="%Y-%m-%dT%H:%M:%SZ",
    )
    _reset_file_handler(_legacy_logger, LEGACY_LOG_PATH, plain_fmt)
    _reset_file_handler(_observable_logger, OBSERVABLE_LOG_PATH, JsonFormatter())


# ---------------------------------------------------------------------------
# Log read-back (for /logs/* endpoints)
# ---------------------------------------------------------------------------

def tail_lines(path, limit):
    if not os.path.exists(path):
        return []
    with open(path, "r", encoding="utf-8") as fh:
        lines = [ln.rstrip("\n") for ln in fh.readlines() if ln.strip()]
    return lines[-limit:]


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def percentile(values, pct):
    if not values:
        return 0.0
    sv = sorted(values)
    idx = max(0, min(len(sv) - 1, int((pct / 100.0) * len(sv) + 0.999999) - 1))
    return round(float(sv[idx]), 2)


def clamp_int(value, lo, hi):
    return max(lo, min(hi, value))


def request_id(prefix):
    return f"{prefix}-{secrets.token_hex(4)}"


def bucket_key_for_status(status):
    if status >= 500:
        return "5xx"
    if status >= 400:
        return "4xx"
    return "2xx"


def route_metrics_summary(t):
    routes = {}
    for route, samples in (t.get("routes") or {}).items():
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


def telemetry_summary(t):
    samples = t.get("samples_ms") or []
    count = len(samples)
    return {
        "requests_tracked": t.get("requests", 0),
        "sample_count": count,
        "avg_ms": round(sum(samples) / count, 2) if count else 0.0,
        "p95_ms": percentile(samples, 95),
        "p99_ms": percentile(samples, 99),
        "max_ms": round(max(samples), 2) if count else 0.0,
        "last_path": t.get("last_path"),
        "last_status": t.get("last_status", 200),
        "last_updated": t.get("last_updated"),
        "successes": t.get("successes", {}),
        "failures": t.get("failures", initial_telemetry()["failures"]),
        "routes": route_metrics_summary(t),
        "recent_traces": list(reversed(t.get("traces", []))),
    }


def record_request_telemetry(uri, status, elapsed_ms, workflow_context):
    t = read_telemetry()
    t["requests"] = t.get("requests", 0) + 1
    t["samples_ms"].append(round(elapsed_ms, 2))
    t["samples_ms"] = t["samples_ms"][-3000:]
    t["routes"].setdefault(uri, [])
    t["routes"][uri].append(round(elapsed_ms, 2))
    t["routes"][uri] = t["routes"][uri][-500:]
    t["last_path"] = uri
    t["last_status"] = status
    t["last_updated"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    t["status_bucket"] = bucket_key_for_status(status)

    if workflow_context is not None:
        mode = workflow_context["mode"]
        scenario = workflow_context["scenario"]
        if workflow_context["outcome"] == "success":
            t["successes"][mode] = t["successes"].get(mode, 0) + 1
        else:
            t["failures"][mode]["total"] = t["failures"][mode].get("total", 0) + 1
            step = workflow_context.get("failing_step") or "unknown"
            t["failures"][mode]["by_step"][step] = t["failures"][mode]["by_step"].get(step, 0) + 1
            t["failures"][mode]["by_scenario"][scenario] = t["failures"][mode]["by_scenario"].get(scenario, 0) + 1

        trace = dict(workflow_context)
        trace["status_code"] = status
        trace["elapsed_ms"] = round(elapsed_ms, 2)
        trace["timestamp_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        t["traces"].append(trace)
        t["traces"] = t["traces"][-40:]

    write_telemetry(t)


# ---------------------------------------------------------------------------
# Scenario and workflow definitions
# ---------------------------------------------------------------------------

def scenario_catalog():
    return {
        "ok": {"step": None, "dependency": None, "http_status": 200,
               "error_class": None, "hint": None},
        "inventory_conflict": {
            "step": "inventory.reserve", "dependency": "inventory-service",
            "http_status": 503, "error_class": "inventory_conflict",
            "hint": "Revisar disponibilidad y bloqueos del stock.",
        },
        "payment_timeout": {
            "step": "payment.authorize", "dependency": "payment-gateway",
            "http_status": 504, "error_class": "payment_timeout",
            "hint": "Inspeccionar latencia del gateway y politicas de timeout.",
        },
        "notification_down": {
            "step": "notification.dispatch",
            "dependency": "notification-provider",
            "http_status": 502, "error_class": "notification_dependency_failure",
            "hint": "Validar el proveedor de notificaciones y su cola de salida.",
        },
    }


def workflow_definition():
    return [
        {"name": "cart.validate",        "dependency": "internal",              "base_ms": 18},
        {"name": "inventory.reserve",    "dependency": "inventory-service",     "base_ms": 52},
        {"name": "payment.authorize",    "dependency": "payment-gateway",       "base_ms": 145},
        {"name": "notification.dispatch","dependency": "notification-provider", "base_ms": 36},
    ]


# ---------------------------------------------------------------------------
# Core checkout flow
# ---------------------------------------------------------------------------

def run_checkout(mode, scenario, customer_id, cart_items):
    catalog = scenario_catalog()
    scenario_meta = catalog.get(scenario, catalog["ok"])
    trace_id = request_id("trace")
    req_id = request_id("req")
    order_ref = f"ORD-{secrets.token_hex(3).upper()}"
    events = []

    if mode == "legacy":
        # No correlation, no structure, no context.
        # A developer who never thought about observability after the first sprint.
        _legacy_logger.info("checkout started")
        _legacy_logger.info("processing customer=%s", customer_id)
    else:
        # LoggerAdapter injects request_id + trace_id into *every* log call
        # automatically. The caller never has to pass them again.
        adapter = logging.LoggerAdapter(
            _observable_logger,
            extra={
                "request_id": req_id,
                "trace_id": trace_id,
                "customer_id": customer_id,
            },
        )
        adapter.info(
            "checkout_started",
            extra={
                "cart_items": cart_items,
                "scenario": scenario,
                "order_ref": order_ref,
            },
        )

    for step in workflow_definition():
        started = time.perf_counter()
        time.sleep((step["base_ms"] + 4) / 1000.0)
        elapsed_ms = round((time.perf_counter() - started) * 1000, 2)

        if scenario_meta["step"] == step["name"]:
            events.append({
                "step": step["name"],
                "dependency": step["dependency"],
                "status": "error",
                "elapsed_ms": elapsed_ms,
            })
            if mode == "legacy":
                _legacy_logger.error("checkout failed")
                _legacy_logger.error("external dependency issue")
            else:
                adapter.error(
                    "dependency_failed",
                    extra={
                        "step": step["name"],
                        "dependency": step["dependency"],
                        "elapsed_ms": elapsed_ms,
                        "error_class": scenario_meta["error_class"],
                        "hint": scenario_meta["hint"],
                        "scenario": scenario,
                        "cart_items": cart_items,
                    },
                )
            raise WorkflowFailure(
                "No se pudo completar el checkout.",
                step["name"], step["dependency"],
                scenario_meta["http_status"],
                req_id, trace_id, events,
            )

        events.append({
            "step": step["name"],
            "dependency": step["dependency"],
            "status": "ok",
            "elapsed_ms": elapsed_ms,
        })

        if mode == "legacy":
            if step["name"] == "payment.authorize":
                _legacy_logger.info("payment step completed")
        else:
            adapter.info(
                "step_completed",
                extra={
                    "step": step["name"],
                    "dependency": step["dependency"],
                    "elapsed_ms": elapsed_ms,
                    "scenario": scenario,
                    "cart_items": cart_items,
                },
            )

    if mode == "legacy":
        _legacy_logger.info("checkout completed")
    else:
        adapter.info(
            "checkout_completed",
            extra={
                "order_ref": order_ref,
                "step_count": len(events),
                "scenario": scenario,
                "cart_items": cart_items,
            },
        )

    return {"request_id": req_id, "trace_id": trace_id,
            "order_ref": order_ref, "events": events}


# ---------------------------------------------------------------------------
# Diagnostics
# ---------------------------------------------------------------------------

def diagnostics_summary():
    t = telemetry_summary(read_telemetry())
    return {
        "case": "03 - Observabilidad deficiente y logs inutiles",
        "stack": APP_STACK,
        "metrics": t,
        "answerability": {
            "legacy": {
                "request_correlation": False,
                "failing_step_identified": False,
                "dependency_identified": False,
                "latency_breakdown_by_step": False,
            },
            "observable": {
                "request_correlation": True,
                "failing_step_identified": True,
                "dependency_identified": True,
                "latency_breakdown_by_step": True,
            },
        },
        "recent_legacy_logs": tail_lines(LEGACY_LOG_PATH, 6),
        "recent_observable_logs": tail_lines(OBSERVABLE_LOG_PATH, 6),
    }


def prometheus_label(v):
    return v.replace("\\", "\\\\").replace('"', '\\"').replace("\n", " ")


def render_prometheus_metrics():
    summary = telemetry_summary(read_telemetry())
    lines = [
        "# HELP app_requests_total Total de requests observados.",
        "# TYPE app_requests_total counter",
        f"app_requests_total {summary.get('requests_tracked', 0)}",
        "# HELP app_request_latency_ms Latencia de requests en milisegundos.",
        "# TYPE app_request_latency_ms gauge",
        f"app_request_latency_ms{{stat=\"avg\"}} {summary.get('avg_ms', 0)}",
        f"app_request_latency_ms{{stat=\"p95\"}} {summary.get('p95_ms', 0)}",
        f"app_request_latency_ms{{stat=\"p99\"}} {summary.get('p99_ms', 0)}",
    ]
    for mode, count in (summary.get("successes") or {}).items():
        lines.append(f'app_workflow_success_total{{mode="{prometheus_label(mode)}"}} {count}')
    for mode, fd in (summary.get("failures") or {}).items():
        lines.append(f'app_workflow_failures_total{{mode="{prometheus_label(mode)}"}} {fd.get("total", 0)}')
        for step, count in (fd.get("by_step") or {}).items():
            lines.append(
                f'app_workflow_failures_by_step_total{{mode="{prometheus_label(mode)}",'
                f'step="{prometheus_label(step)}"}} {count}'
            )
    for route, stats in (summary.get("routes") or {}).items():
        lbl = prometheus_label(route)
        lines.append(f'app_route_latency_ms{{route="{lbl}",stat="avg"}} {stats.get("avg_ms", 0)}')
        lines.append(f'app_route_latency_ms{{route="{lbl}",stat="p95"}} {stats.get("p95_ms", 0)}')
        lines.append(f'app_route_requests_total{{route="{lbl}"}} {stats.get("count", 0)}')
    return "\n".join(lines) + "\n"


def query_int(query, key, default):
    vals = query.get(key, [])
    if not vals:
        return default
    try:
        return int(vals[0])
    except ValueError:
        return default


# ---------------------------------------------------------------------------
# HTTP handler
# ---------------------------------------------------------------------------

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
        workflow_context = None

        try:
            if uri in ("/", ""):
                payload = {
                    "lab": "Problem-Driven Systems Lab",
                    "case": "03 - Observabilidad deficiente y logs inutiles",
                    "stack": APP_STACK,
                    "goal": "Contrastar logging.basicConfig sin estructura contra logging.LoggerAdapter con JsonFormatter y correlation IDs.",
                    "routes": {
                        "/health": "Estado basico.",
                        "/checkout-legacy?scenario=payment_timeout&customer_id=42&cart_items=3":
                            "Flujo con logs planos: sin correlacion, sin estructura.",
                        "/checkout-observable?scenario=payment_timeout&customer_id=42&cart_items=3":
                            "Flujo con LoggerAdapter + JsonFormatter: correlation ID en cada linea.",
                        "/logs/legacy?tail=20": "Ultimas lineas del log legacy.",
                        "/logs/observable?tail=20": "Ultimas lineas del log JSON estructurado.",
                        "/traces?limit=10": "Ultimos rastros locales.",
                        "/diagnostics/summary": "Resumen de telemetria y capacidad de diagnostico.",
                        "/metrics": "Metricas JSON.",
                        "/metrics-prometheus": "Metricas en formato Prometheus.",
                        "/reset-observability": "Reinicia logs y telemetria.",
                    },
                    "allowed_scenarios": list(scenario_catalog().keys()),
                }
            elif uri == "/health":
                payload = {"status": "ok", "stack": APP_STACK}

            elif uri in ("/checkout-legacy", "/checkout-observable"):
                mode = "legacy" if uri == "/checkout-legacy" else "observable"
                catalog = scenario_catalog()
                scenario = query.get("scenario", ["ok"])[0]
                if scenario not in catalog:
                    scenario = "ok"
                customer_id = clamp_int(query_int(query, "customer_id", 42), 1, 5000)
                cart_items = clamp_int(query_int(query, "cart_items", 3), 1, 25)

                try:
                    result = run_checkout(mode, scenario, customer_id, cart_items)
                    workflow_context = {
                        "mode": mode, "scenario": scenario, "outcome": "success",
                        "failing_step": None, "dependency": None,
                        "request_id": result["request_id"],
                        "trace_id": result["trace_id"],
                        "customer_id": customer_id,
                        "cart_items": cart_items,
                        "events": result["events"],
                    }
                    payload = {
                        "mode": mode, "scenario": scenario, "status": "completed",
                        "customer_id": customer_id, "cart_items": cart_items,
                        "order_ref": result["order_ref"],
                        "events": result["events"],
                    }
                    if mode == "observable":
                        payload["request_id"] = result["request_id"]
                        payload["trace_id"] = result["trace_id"]
                except WorkflowFailure as failure:
                    status_code = failure.http_status
                    workflow_context = {
                        "mode": mode, "scenario": scenario, "outcome": "failure",
                        "failing_step": failure.step, "dependency": failure.dependency,
                        "request_id": failure.request_id if mode == "observable" else None,
                        "trace_id": failure.trace_id if mode == "observable" else None,
                        "customer_id": customer_id,
                        "cart_items": cart_items,
                        "events": failure.events,
                    }
                    payload = {
                        "mode": mode, "scenario": scenario,
                        "error": "Checkout fallido",
                        "message": (
                            "Fallo el checkout. Usa request_id y trace_id para correlacionar."
                            if mode == "observable"
                            else "No se pudo completar la operacion."
                        ),
                    }
                    if mode == "observable":
                        payload["request_id"] = failure.request_id
                        payload["trace_id"] = failure.trace_id
                        payload["failed_step"] = failure.step
                        payload["dependency"] = failure.dependency

            elif uri == "/logs/legacy":
                tail = clamp_int(query_int(query, "tail", 20), 1, 200)
                payload = {"mode": "legacy", "tail": tail,
                           "lines": tail_lines(LEGACY_LOG_PATH, tail)}
            elif uri == "/logs/observable":
                tail = clamp_int(query_int(query, "tail", 20), 1, 200)
                payload = {"mode": "observable", "tail": tail,
                           "lines": tail_lines(OBSERVABLE_LOG_PATH, tail)}
            elif uri == "/traces":
                limit = clamp_int(query_int(query, "limit", 10), 1, 50)
                payload = {"limit": limit,
                           "traces": telemetry_summary(read_telemetry())["recent_traces"][:limit]}
            elif uri == "/diagnostics/summary":
                payload = diagnostics_summary()
            elif uri == "/metrics":
                payload = {
                    "case": "03 - Observabilidad deficiente y logs inutiles",
                    "stack": APP_STACK,
                    **telemetry_summary(read_telemetry()),
                }
            elif uri == "/metrics-prometheus":
                skip_store_metrics = True
                self.send_text(200, render_prometheus_metrics())
                return
            elif uri == "/reset-observability":
                reset_telemetry_state()
                payload = {"status": "reset", "message": "Logs y telemetria reiniciados."}
            else:
                status_code = 404
                payload = {"error": "Ruta no encontrada", "path": uri}

        except Exception as error:
            status_code = 500
            payload = {"error": "Fallo al procesar la solicitud",
                       "message": str(error), "path": uri}

        elapsed_ms = round((time.perf_counter() - started) * 1000, 2)
        if not skip_store_metrics and uri not in ("/metrics", "/reset-observability"):
            record_request_telemetry(uri, status_code, elapsed_ms, workflow_context)

        payload["elapsed_ms"] = elapsed_ms
        payload["timestamp_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        payload["pid"] = os.getpid()
        self.send_json(status_code, payload)


PORT = int(os.environ.get("PORT", "8080"))
server = HTTPServer(("0.0.0.0", PORT), Handler)
print(f"Servidor Python escuchando en {PORT}")
server.serve_forever()
