from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse
import json
import math
import os
import random
import tempfile
import threading
import time

APP_STACK = os.environ.get("APP_STACK", "Python 3.12")
STORAGE_DIR = os.path.join(tempfile.gettempdir(), "pdsl-case12-python")
STATE_PATH = os.path.join(STORAGE_DIR, "state.json")
TELEMETRY_PATH = os.path.join(STORAGE_DIR, "telemetry.json")

_lock = threading.Lock()

VALID_DOMAINS = ("billing", "deployments", "integrations", "reporting")
VALID_ACTIVITIES = ("runbook", "pairing", "drill")


# ---------------------------------------------------------------------------
# Storage helpers
# ---------------------------------------------------------------------------

def ensure_storage_dir():
    os.makedirs(STORAGE_DIR, exist_ok=True)


def initial_state():
    return {
        "knowledge": {
            "domains": {
                "billing":      {"runbook_score": 25, "backup_people": 0, "drill_score": 10},
                "deployments":  {"runbook_score": 35, "backup_people": 1, "drill_score": 15},
                "integrations": {"runbook_score": 20, "backup_people": 0, "drill_score": 8},
                "reporting":    {"runbook_score": 30, "backup_people": 1, "drill_score": 12},
            },
            "docs_indexed": 4,
            "pairing_sessions": 0,
            "drills_completed": 0,
            "last_update": None,
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
            "mttr_samples": [],
            "blocker_samples": [],
            "handoff_samples": [],
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
            "distributed": mode_slot(),
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
        "avg_mttr_min": avg(m.get("mttr_samples", [])),
        "avg_blocker_count": avg(m.get("blocker_samples", [])),
        "avg_handoff_quality": avg(m.get("handoff_samples", [])),
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
        "owner_available": {
            "legacy":      {"status": 200, "mttr": 18, "blockers": 0, "handoff": 40},
            "distributed": {"mttr": 24, "blockers": 0, "handoff": 72},
        },
        "owner_absent": {
            "legacy":      {"status": 503, "mttr": 95, "blockers": 3, "handoff": 12},
            "distributed": {"mttr": 34, "blockers": 1, "handoff": 78},
        },
        "night_shift": {
            "legacy":      {"status": 503, "mttr": 88, "blockers": 2, "handoff": 18},
            "distributed": {"mttr": 36, "blockers": 1, "handoff": 74},
        },
        "recent_change": {
            "legacy":      {"status": 502, "mttr": 72, "blockers": 2, "handoff": 25},
            "distributed": {"mttr": 42, "blockers": 1, "handoff": 70},
        },
        "tribal_script": {
            "legacy":      {"status": 500, "mttr": 81, "blockers": 3, "handoff": 15},
            "distributed": {"mttr": 39, "blockers": 1, "handoff": 76},
        },
    }


# ---------------------------------------------------------------------------
# Domain helpers
# ---------------------------------------------------------------------------

def readiness_score(domain_state):
    return round(
        domain_state["runbook_score"] * 0.45
        + (domain_state["backup_people"] + 1) * 18
        + domain_state["drill_score"] * 0.25
    )


def share_knowledge(domain, activity):
    """Mutates state and returns the updated knowledge block. Not telemetrised."""
    with _lock:
        state = read_state()
        know = state["knowledge"]
        dom = know["domains"][domain]

        if activity == "runbook":
            dom["runbook_score"] = min(100, dom["runbook_score"] + 20)
            know["docs_indexed"] = know.get("docs_indexed", 0) + 1
        elif activity == "pairing":
            dom["backup_people"] = min(4, dom["backup_people"] + 1)
            know["pairing_sessions"] = know.get("pairing_sessions", 0) + 1
        elif activity == "drill":
            dom["drill_score"] = min(100, dom["drill_score"] + 18)
            know["drills_completed"] = know.get("drills_completed", 0) + 1

        know["last_update"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        write_state(state)
    return know


def state_summary():
    state = read_state()
    know = state["knowledge"]
    domains = know["domains"]
    coverage = {
        d: round((v["runbook_score"] + v["drill_score"]) / 2, 1)
        for d, v in domains.items()
    }
    bus_factor_min = min((v["backup_people"] + 1) for v in domains.values())
    return {
        "domains": domains,
        "coverage": coverage,
        "docs_indexed": know.get("docs_indexed", 0),
        "pairing_sessions": know.get("pairing_sessions", 0),
        "drills_completed": know.get("drills_completed", 0),
        "bus_factor_min": bus_factor_min,
        "last_update": know.get("last_update"),
    }


# ---------------------------------------------------------------------------
# Core incident logic
# ---------------------------------------------------------------------------

def run_incident_flow(mode, scenario, domain):
    catalog = scenario_catalog()
    if scenario not in catalog:
        scenario = "owner_available"
    if domain not in VALID_DOMAINS:
        domain = "deployments"

    sc = catalog[scenario]

    with _lock:
        state = read_state()
        domain_state = state["knowledge"]["domains"][domain]
        readiness = readiness_score(domain_state)
        backup_people = domain_state.get("backup_people", 0)

    risky = scenario in ("tribal_script", "owner_absent")

    if mode == "legacy":
        http_status = sc["legacy"]["status"]
        mttr = sc["legacy"]["mttr"]
        blockers = sc["legacy"]["blockers"]
        handoff = sc["legacy"]["handoff"]
        exception_triggered = False

        if risky:
            # Simulate fragile tribal knowledge access — deliberately unsafe
            try:
                data = {}
                _ = data["config"]["system"][2]["is_active"]  # KeyError
            except (KeyError, TypeError, IndexError):
                exception_triggered = True
                raise RuntimeError(
                    f"Fallo de ejecucion por deuda tecnica: acceso no seguro a configuracion tribal en dominio '{domain}'"
                )

    else:  # distributed
        dist = sc["distributed"]
        mttr = max(15, dist["mttr"] - math.floor(readiness / 12))
        blockers = max(0, dist["blockers"] - math.floor((backup_people + 1) / 2))
        handoff = min(95, dist["handoff"] + math.floor(readiness / 10))

        if readiness < 28 and scenario != "owner_available":
            http_status = 409
        else:
            http_status = 200

        if risky:
            # Defensive access — safe via .get() / fallback
            data = {}
            _ = data.get("config", {}).get("system", [{}])
            is_active = (data.get("config") or {}).get("system", [{}])
            if isinstance(is_active, list) and len(is_active) > 2:
                _ = is_active[2].get("is_active", False)

    # Simulate incident resolution time
    sleep_ms = mttr * 8 + random.randint(20, 55)
    time.sleep(sleep_ms / 1000)

    return {
        "mode": mode,
        "scenario": scenario,
        "domain": domain,
        "readiness_score": readiness,
        "mttr_min": mttr,
        "blocker_count": blockers,
        "handoff_quality": handoff,
        "http_status": http_status,
    }, http_status


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
                "mttr_samples": [],
                "blocker_samples": [],
                "handoff_samples": [],
                "by_scenario": {},
            }

        m = tel["modes"].setdefault(mode, mode_slot_default())

        if run_context.get("outcome") == "success":
            m["successes"] = m.get("successes", 0) + 1
        else:
            m["failures"] = m.get("failures", 0) + 1

        if run_context.get("mttr_min") is not None:
            m["mttr_samples"].append(run_context["mttr_min"])
            m["mttr_samples"] = m["mttr_samples"][-500:]
        if run_context.get("blocker_count") is not None:
            m["blocker_samples"].append(run_context["blocker_count"])
            m["blocker_samples"] = m["blocker_samples"][-500:]
        if run_context.get("handoff_quality") is not None:
            m["handoff_samples"].append(run_context["handoff_quality"])
            m["handoff_samples"] = m["handoff_samples"][-500:]

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
    ss = state_summary()
    lines = [
        "# HELP app_requests_total Total de requests observados.",
        "# TYPE app_requests_total counter",
        f"app_requests_total {summary['requests_tracked']}",
    ]
    for mode, ms in (summary.get("modes") or {}).items():
        lm = prometheus_label(mode)
        lines.append(f"app_knowledge_success_total{{mode=\"{lm}\"}} {ms['successes']}")
        lines.append(f"app_knowledge_failure_total{{mode=\"{lm}\"}} {ms['failures']}")
        lines.append(f"app_knowledge_avg_mttr_min{{mode=\"{lm}\"}} {ms['avg_mttr_min']}")
        lines.append(f"app_knowledge_avg_blocker_count{{mode=\"{lm}\"}} {ms['avg_blocker_count']}")
        lines.append(f"app_knowledge_avg_handoff_quality{{mode=\"{lm}\"}} {ms['avg_handoff_quality']}")
    for d, v in (ss.get("domains") or {}).items():
        ld = prometheus_label(d)
        lines.append(f"app_domain_runbook_score{{domain=\"{ld}\"}} {v['runbook_score']}")
        lines.append(f"app_domain_backup_people{{domain=\"{ld}\"}} {v['backup_people']}")
        lines.append(f"app_domain_drill_score{{domain=\"{ld}\"}} {v['drill_score']}")
    lines.append(f"app_bus_factor_min {ss['bus_factor_min']}")
    lines.append(f"app_docs_indexed {ss['docs_indexed']}")
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
                    "case": "12 - Punto unico de conocimiento y riesgo operacional",
                    "stack": APP_STACK,
                    "goal": "Demostrar el riesgo operacional de concentrar conocimiento en una sola persona y como distribuirlo reduce el MTTR.",
                    "routes": {
                        "/incident-legacy?scenario=owner_absent&domain=deployments": "Simula incidente con conocimiento concentrado.",
                        "/incident-distributed?scenario=owner_absent&domain=deployments": "Simula incidente con conocimiento distribuido.",
                        "/share-knowledge?domain=deployments&activity=runbook": "Registra actividad de distribucion de conocimiento.",
                        "/knowledge/state": "Estado actual del conocimiento por dominio.",
                        "/incidents?limit=10": "Ultimas runs registradas.",
                        "/diagnostics/summary": "Resumen de telemetria.",
                        "/metrics": "Metricas JSON.",
                        "/metrics-prometheus": "Metricas en formato Prometheus.",
                        "/reset-lab": "Reinicia estado y telemetria.",
                    },
                    "allowed_scenarios": list(catalog.keys()),
                    "allowed_domains": list(VALID_DOMAINS),
                    "allowed_activities": list(VALID_ACTIVITIES),
                }

            elif uri == "/health":
                payload = {"status": "ok", "stack": APP_STACK}

            elif uri in ("/incident-legacy", "/incident-distributed"):
                mode = "legacy" if uri == "/incident-legacy" else "distributed"
                scenario = query_str(query, "scenario", "owner_available")
                if scenario not in catalog:
                    scenario = "owner_available"
                domain = query_str(query, "domain", "deployments")
                if domain not in VALID_DOMAINS:
                    domain = "deployments"

                try:
                    result, status_code = run_incident_flow(mode, scenario, domain)
                    run_context = {
                        "mode": mode,
                        "scenario": scenario,
                        "domain": domain,
                        "outcome": "success" if status_code < 400 else "failure",
                        "mttr_min": result.get("mttr_min"),
                        "blocker_count": result.get("blocker_count"),
                        "handoff_quality": result.get("handoff_quality"),
                    }
                    payload = result
                except RuntimeError as exc:
                    status_code = 500
                    run_context = {
                        "mode": mode,
                        "scenario": scenario,
                        "domain": domain,
                        "outcome": "failure",
                        "mttr_min": None,
                        "blocker_count": None,
                        "handoff_quality": None,
                    }
                    payload = {
                        "mode": mode,
                        "scenario": scenario,
                        "domain": domain,
                        "error": str(exc),
                    }

            elif uri == "/share-knowledge":
                domain = query_str(query, "domain", "deployments")
                activity = query_str(query, "activity", "runbook")
                if domain not in VALID_DOMAINS:
                    status_code = 400
                    payload = {"error": f"Dominio invalido. Usa: {', '.join(VALID_DOMAINS)}"}
                elif activity not in VALID_ACTIVITIES:
                    status_code = 400
                    payload = {"error": f"Actividad invalida. Usa: {', '.join(VALID_ACTIVITIES)}"}
                else:
                    # Does NOT register in main telemetry; no elapsed_ms enrichment needed
                    know = share_knowledge(domain, activity)
                    elapsed_ms = round((time.perf_counter() - started) * 1000, 2)
                    payload_raw = {
                        "status": "knowledge_shared",
                        "domain": domain,
                        "activity": activity,
                        "knowledge": know,
                    }
                    self.send_json(status_code, payload_raw)
                    return  # early return — skip telemetry enrichment

            elif uri == "/knowledge/state":
                payload = state_summary()

            elif uri == "/incidents":
                limit = clamp_int(query_int(query, "limit", 10), 1, 50)
                with _lock:
                    tel = read_telemetry()
                runs = list(reversed(tel.get("runs", [])))[:limit]
                payload = {"limit": limit, "runs": runs}

            elif uri == "/diagnostics/summary":
                with _lock:
                    summary = telemetry_summary(read_telemetry())
                payload = {
                    "case": "12 - Punto unico de conocimiento y riesgo operacional",
                    "stack": APP_STACK,
                    "metrics": summary,
                    "knowledge_state": state_summary(),
                }

            elif uri == "/metrics":
                with _lock:
                    summary = telemetry_summary(read_telemetry())
                payload = {
                    "case": "12 - Punto unico de conocimiento y riesgo operacional",
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
