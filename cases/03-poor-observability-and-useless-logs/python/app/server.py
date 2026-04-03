from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse
import json
import os
import secrets
import tempfile
import time

APP_STACK = "Python 3.12"
STORAGE_DIR = os.path.join(tempfile.gettempdir(), "pdsl-case03-python")
TELEMETRY_PATH = os.path.join(STORAGE_DIR, "telemetry.json")
LEGACY_LOG_PATH = os.path.join(STORAGE_DIR, "legacy.log")
OBSERVABLE_LOG_PATH = os.path.join(STORAGE_DIR, "observable.log")


class WorkflowFailure(Exception):
    def __init__(self, message, step, dependency, http_status, request_id, trace_id, events):
        super().__init__(message)
        self.step = step
        self.dependency = dependency
        self.http_status = http_status
        self.request_id = request_id
        self.trace_id = trace_id
        self.events = events


def ensure_storage_dir():
    os.makedirs(STORAGE_DIR, exist_ok=True)


def initial_telemetry():
    return {
        "requests": 0,
        "samples_ms": [],
        "routes": {},
        "last_path": None,
        "last_status": 200,
        "last_updated": None,
        "status_bucket": "2xx",
        "successes": {
            "legacy": 0,
            "observable": 0,
        },
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
        with open(TELEMETRY_PATH, "r", encoding="utf-8") as handle:
            parsed = json.load(handle)
    except (OSError, json.JSONDecodeError):
        return initial_telemetry()

    telemetry = initial_telemetry()
    telemetry.update(parsed)
    telemetry["successes"].update(parsed.get("successes", {}))
    telemetry["failures"]["legacy"].update(parsed.get("failures", {}).get("legacy", {}))
    telemetry["failures"]["observable"].update(parsed.get("failures", {}).get("observable", {}))
    telemetry["routes"] = parsed.get("routes", {})
    telemetry["traces"] = parsed.get("traces", [])
    telemetry["samples_ms"] = parsed.get("samples_ms", [])
    return telemetry


def write_telemetry(telemetry):
    ensure_storage_dir()
    with open(TELEMETRY_PATH, "w", encoding="utf-8") as handle:
        json.dump(telemetry, handle, indent=2, ensure_ascii=False)


def reset_telemetry_state():
    write_telemetry(initial_telemetry())
    for target in (LEGACY_LOG_PATH, OBSERVABLE_LOG_PATH):
        if os.path.exists(target):
            os.unlink(target)


def append_legacy_log(message):
    ensure_storage_dir()
    with open(LEGACY_LOG_PATH, "a", encoding="utf-8") as handle:
        handle.write(f"[{time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())}] {message}\n")


def append_structured_log(record):
    ensure_storage_dir()
    structured = dict(record)
    structured["timestamp_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    with open(OBSERVABLE_LOG_PATH, "a", encoding="utf-8") as handle:
        handle.write(json.dumps(structured, ensure_ascii=False) + "\n")


def tail_lines(target_path, limit):
    if not os.path.exists(target_path):
        return []

    with open(target_path, "r", encoding="utf-8") as handle:
        lines = [line.rstrip("\n") for line in handle.readlines() if line.strip()]
    return lines[-limit:]


def percentile(values, percent):
    if not values:
        return 0.0

    sorted_values = sorted(values)
    index = max(0, min(len(sorted_values) - 1, int((percent / 100.0) * len(sorted_values) + 0.999999) - 1))
    return round(float(sorted_values[index]), 2)


def clamp_int(value, minimum, maximum):
    return max(minimum, min(maximum, value))


def request_id(prefix):
    return f"{prefix}-{secrets.token_hex(4)}"


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
        "successes": telemetry.get("successes", {"legacy": 0, "observable": 0}),
        "failures": telemetry.get("failures", initial_telemetry()["failures"]),
        "routes": route_metrics_summary(telemetry),
        "recent_traces": list(reversed(telemetry.get("traces", []))),
    }


def record_request_telemetry(uri, status, elapsed_ms, workflow_context):
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
    telemetry["status_bucket"] = bucket_key_for_status(status)

    if workflow_context is not None:
        mode = workflow_context["mode"]
        scenario = workflow_context["scenario"]
        if workflow_context["outcome"] == "success":
            telemetry["successes"][mode] = telemetry["successes"].get(mode, 0) + 1
        else:
            telemetry["failures"][mode]["total"] = telemetry["failures"][mode].get("total", 0) + 1
            step = workflow_context.get("failing_step") or "unknown"
            telemetry["failures"][mode]["by_step"][step] = telemetry["failures"][mode]["by_step"].get(step, 0) + 1
            telemetry["failures"][mode]["by_scenario"][scenario] = telemetry["failures"][mode]["by_scenario"].get(scenario, 0) + 1

        trace = dict(workflow_context)
        trace["status_code"] = status
        trace["elapsed_ms"] = round(elapsed_ms, 2)
        trace["timestamp_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        telemetry["traces"].append(trace)
        telemetry["traces"] = telemetry["traces"][-40:]

    write_telemetry(telemetry)


def scenario_catalog():
    return {
        "ok": {"step": None, "dependency": None, "http_status": 200, "error_class": None, "hint": None},
        "inventory_conflict": {
            "step": "inventory.reserve",
            "dependency": "inventory-service",
            "http_status": 503,
            "error_class": "inventory_conflict",
            "hint": "Revisar disponibilidad y bloqueos del stock.",
        },
        "payment_timeout": {
            "step": "payment.authorize",
            "dependency": "payment-gateway",
            "http_status": 504,
            "error_class": "payment_timeout",
            "hint": "Inspeccionar latencia del gateway y politicas de timeout.",
        },
        "notification_down": {
            "step": "notification.dispatch",
            "dependency": "notification-provider",
            "http_status": 502,
            "error_class": "notification_dependency_failure",
            "hint": "Validar el proveedor de notificaciones y su cola de salida.",
        },
    }


def workflow_definition():
    return [
        {"name": "cart.validate", "dependency": "internal", "base_ms": 18},
        {"name": "inventory.reserve", "dependency": "inventory-service", "base_ms": 52},
        {"name": "payment.authorize", "dependency": "payment-gateway", "base_ms": 145},
        {"name": "notification.dispatch", "dependency": "notification-provider", "base_ms": 36},
    ]


def run_checkout(mode, scenario, customer_id, cart_items):
    catalog = scenario_catalog()
    scenario_meta = catalog.get(scenario, catalog["ok"])
    trace_id = request_id("trace")
    req_id = request_id("req")
    order_ref = f"ORD-{secrets.token_hex(3).upper()}"
    events = []

    if mode == "legacy":
        append_legacy_log("checkout started")
        append_legacy_log(f"processing customer={customer_id}")
    else:
        append_structured_log({
            "level": "info",
            "event": "checkout_started",
            "request_id": req_id,
            "trace_id": trace_id,
            "customer_id": customer_id,
            "cart_items": cart_items,
            "scenario": scenario,
            "order_ref": order_ref,
        })

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
                append_legacy_log("checkout failed")
                append_legacy_log("external dependency issue")
            else:
                append_structured_log({
                    "level": "error",
                    "event": "dependency_failed",
                    "request_id": req_id,
                    "trace_id": trace_id,
                    "customer_id": customer_id,
                    "cart_items": cart_items,
                    "scenario": scenario,
                    "step": step["name"],
                    "dependency": step["dependency"],
                    "elapsed_ms": elapsed_ms,
                    "error_class": scenario_meta["error_class"],
                    "hint": scenario_meta["hint"],
                })

            raise WorkflowFailure(
                "No se pudo completar el checkout.",
                step["name"],
                step["dependency"],
                scenario_meta["http_status"],
                req_id,
                trace_id,
                events,
            )

        events.append({
            "step": step["name"],
            "dependency": step["dependency"],
            "status": "ok",
            "elapsed_ms": elapsed_ms,
        })

        if mode == "legacy":
            if step["name"] == "payment.authorize":
                append_legacy_log("payment step completed")
        else:
            append_structured_log({
                "level": "info",
                "event": "step_completed",
                "request_id": req_id,
                "trace_id": trace_id,
                "customer_id": customer_id,
                "cart_items": cart_items,
                "scenario": scenario,
                "step": step["name"],
                "dependency": step["dependency"],
                "elapsed_ms": elapsed_ms,
            })

    if mode == "legacy":
        append_legacy_log("checkout completed")
    else:
        append_structured_log({
            "level": "info",
            "event": "checkout_completed",
            "request_id": req_id,
            "trace_id": trace_id,
            "customer_id": customer_id,
            "cart_items": cart_items,
            "scenario": scenario,
            "order_ref": order_ref,
            "step_count": len(events),
        })

    return {
        "request_id": req_id,
        "trace_id": trace_id,
        "order_ref": order_ref,
        "events": events,
    }


def diagnostics_summary():
    telemetry = telemetry_summary(read_telemetry())
    return {
        "case": "03 - Observabilidad deficiente y logs inutiles",
        "stack": APP_STACK,
        "metrics": telemetry,
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


def prometheus_label(value):
    return value.replace("\\", "\\\\").replace("\"", "\\\"").replace("\n", " ")


def render_prometheus_metrics():
    summary = telemetry_summary(read_telemetry())
    lines = [
        "# HELP app_requests_total Total de requests observados por el laboratorio.",
        "# TYPE app_requests_total counter",
        f"app_requests_total {summary.get('requests_tracked', 0)}",
        "# HELP app_request_latency_ms Latencia agregada de requests en milisegundos.",
        "# TYPE app_request_latency_ms gauge",
        f"app_request_latency_ms{{stat=\"avg\"}} {summary.get('avg_ms', 0)}",
        f"app_request_latency_ms{{stat=\"p95\"}} {summary.get('p95_ms', 0)}",
        f"app_request_latency_ms{{stat=\"p99\"}} {summary.get('p99_ms', 0)}",
    ]

    for mode, count in (summary.get("successes") or {}).items():
        lines.append(f"app_workflow_success_total{{mode=\"{prometheus_label(mode)}\"}} {count}")

    for mode, failure_data in (summary.get("failures") or {}).items():
        lines.append(f"app_workflow_failures_total{{mode=\"{prometheus_label(mode)}\"}} {failure_data.get('total', 0)}")
        for step, count in (failure_data.get("by_step") or {}).items():
            lines.append(
                f"app_workflow_failures_by_step_total{{mode=\"{prometheus_label(mode)}\",step=\"{prometheus_label(step)}\"}} {count}"
            )
        for scenario, count in (failure_data.get("by_scenario") or {}).items():
            lines.append(
                f"app_workflow_failures_by_scenario_total{{mode=\"{prometheus_label(mode)}\",scenario=\"{prometheus_label(scenario)}\"}} {count}"
            )

    for route, stats in (summary.get("routes") or {}).items():
        label = prometheus_label(route)
        lines.append(f"app_route_latency_ms{{route=\"{label}\",stat=\"avg\"}} {stats.get('avg_ms', 0)}")
        lines.append(f"app_route_latency_ms{{route=\"{label}\",stat=\"p95\"}} {stats.get('p95_ms', 0)}")
        lines.append(f"app_route_requests_total{{route=\"{label}\"}} {stats.get('count', 0)}")

    return "\n".join(lines) + "\n"


def query_int(query, key, default):
    values = query.get(key, [])
    if not values:
        return default
    try:
        return int(values[0])
    except ValueError:
        return default


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
            if uri == "/" or uri == "":
                payload = {
                    "lab": "Problem-Driven Systems Lab",
                    "case": "03 - Observabilidad deficiente y logs inutiles",
                    "stack": APP_STACK,
                    "goal": "Comparar un flujo con logs pobres contra el mismo flujo con telemetria util, correlation IDs y trazas locales.",
                    "routes": {
                        "/health": "Estado basico del servicio.",
                        "/checkout-legacy?scenario=payment_timeout&customer_id=42&cart_items=3": "Ejecuta el flujo con evidencia pobre y poco accionable.",
                        "/checkout-observable?scenario=payment_timeout&customer_id=42&cart_items=3": "Ejecuta el flujo con logs estructurados, request_id y trazabilidad.",
                        "/logs/legacy?tail=20": "Ultimas lineas del log legacy.",
                        "/logs/observable?tail=20": "Ultimas lineas del log estructurado.",
                        "/traces?limit=10": "Ultimos rastros locales del laboratorio.",
                        "/diagnostics/summary": "Resumen de telemetria y capacidad de diagnostico.",
                        "/metrics": "Metricas JSON del proceso.",
                        "/metrics-prometheus": "Metricas en formato Prometheus.",
                        "/reset-observability": "Reinicia logs y telemetria local.",
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
                        "mode": mode,
                        "scenario": scenario,
                        "outcome": "success",
                        "failing_step": None,
                        "dependency": None,
                        "request_id": result["request_id"],
                        "trace_id": result["trace_id"],
                        "customer_id": customer_id,
                        "cart_items": cart_items,
                        "events": result["events"],
                    }
                    payload = {
                        "mode": mode,
                        "scenario": scenario,
                        "status": "completed",
                        "customer_id": customer_id,
                        "cart_items": cart_items,
                        "order_ref": result["order_ref"],
                        "events": result["events"],
                    }
                    if mode == "observable":
                        payload["request_id"] = result["request_id"]
                        payload["trace_id"] = result["trace_id"]
                except WorkflowFailure as failure:
                    status_code = failure.http_status
                    workflow_context = {
                        "mode": mode,
                        "scenario": scenario,
                        "outcome": "failure",
                        "failing_step": failure.step,
                        "dependency": failure.dependency,
                        "request_id": failure.request_id if mode == "observable" else None,
                        "trace_id": failure.trace_id if mode == "observable" else None,
                        "customer_id": customer_id,
                        "cart_items": cart_items,
                        "events": failure.events,
                    }
                    payload = {
                        "mode": mode,
                        "scenario": scenario,
                        "error": "Checkout fallido",
                        "message": "Fallo el checkout. Usa request_id y trace_id para correlacionar el incidente."
                        if mode == "observable"
                        else "No se pudo completar la operacion.",
                    }
                    if mode == "observable":
                        payload["request_id"] = failure.request_id
                        payload["trace_id"] = failure.trace_id
                        payload["failed_step"] = failure.step
                        payload["dependency"] = failure.dependency
            elif uri == "/logs/legacy":
                tail = clamp_int(query_int(query, "tail", 20), 1, 200)
                payload = {"mode": "legacy", "tail": tail, "lines": tail_lines(LEGACY_LOG_PATH, tail)}
            elif uri == "/logs/observable":
                tail = clamp_int(query_int(query, "tail", 20), 1, 200)
                payload = {"mode": "observable", "tail": tail, "lines": tail_lines(OBSERVABLE_LOG_PATH, tail)}
            elif uri == "/traces":
                limit = clamp_int(query_int(query, "limit", 10), 1, 50)
                payload = {"limit": limit, "traces": telemetry_summary(read_telemetry())["recent_traces"][:limit]}
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
            payload = {
                "error": "Fallo al procesar la solicitud",
                "message": str(error),
                "path": uri,
            }

        elapsed_ms = round((time.perf_counter() - started) * 1000, 2)
        if not skip_store_metrics and uri not in ("/metrics", "/reset-observability"):
            record_request_telemetry(uri, status_code, elapsed_ms, workflow_context)

        payload["elapsed_ms"] = elapsed_ms
        payload["timestamp_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        payload["pid"] = os.getpid()
        self.send_json(status_code, payload)


ensure_storage_dir()
server = HTTPServer(("0.0.0.0", 8080), Handler)
print("Servidor Python escuchando en 8080")
server.serve_forever()
