from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse
import json
import os
import secrets
import tempfile
import threading
import time

APP_STACK = os.environ.get("APP_STACK", "Python 3.12")
STORAGE_DIR = os.path.join(tempfile.gettempdir(), "pdsl-case06-python")
STATE_PATH = os.path.join(STORAGE_DIR, "state.json")
TELEMETRY_PATH = os.path.join(STORAGE_DIR, "telemetry.json")

_lock = threading.Lock()

ENVIRONMENTS = ["dev", "staging", "prod"]
ALLOWED_SCENARIOS = ["ok", "missing_secret", "config_drift", "failing_smoke", "migration_risk"]


# ---------------------------------------------------------------------------
# Storage helpers
# ---------------------------------------------------------------------------

def ensure_storage_dir():
    os.makedirs(STORAGE_DIR, exist_ok=True)


# ---------------------------------------------------------------------------
# State
# ---------------------------------------------------------------------------

def initial_state():
    return {
        "environments": {
            "dev": {
                "current_release": "2026.04.0",
                "schema_version": "dev-baseline",
                "health": "ready",
                "last_good_release": None,
                "last_failure_reason": None,
                "last_deploy_at": None,
            },
            "staging": {
                "current_release": "2026.04.0",
                "schema_version": "staging-baseline",
                "health": "ready",
                "last_good_release": None,
                "last_failure_reason": None,
                "last_deploy_at": None,
            },
            "prod": {
                "current_release": "2026.04.0",
                "schema_version": "prod-baseline",
                "health": "ready",
                "last_good_release": None,
                "last_failure_reason": None,
                "last_deploy_at": None,
            },
        },
        "history": [],
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


# ---------------------------------------------------------------------------
# Telemetry
# ---------------------------------------------------------------------------

def initial_telemetry():
    def mode_metrics():
        return {
            "successes": 0,
            "failures": 0,
            "rollbacks": 0,
            "preflight_blocks": 0,
            "by_scenario": {},
            "by_environment": {},
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
            "controlled": mode_metrics(),
        },
        "deployments": [],
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
    for mode in ("legacy", "controlled"):
        if mode in parsed.get("modes", {}):
            base["modes"][mode].update(parsed["modes"][mode])
    base["routes"] = parsed.get("routes", {})
    base["samples_ms"] = parsed.get("samples_ms", [])
    base["status_counts"] = parsed.get("status_counts", {})
    base["deployments"] = parsed.get("deployments", [])
    return base


def write_telemetry(telemetry):
    ensure_storage_dir()
    with open(TELEMETRY_PATH, "w", encoding="utf-8") as fh:
        json.dump(telemetry, fh, indent=2, ensure_ascii=False)


# ---------------------------------------------------------------------------
# Deploy step simulator
# ---------------------------------------------------------------------------

def simulate_step(name, duration_ms):
    time.sleep(duration_ms / 1000.0)
    return {"step": name, "status": "ok", "elapsed_ms": duration_ms}


# ---------------------------------------------------------------------------
# Legacy deployment flow
# ---------------------------------------------------------------------------

def run_legacy_deployment(environment, release, scenario):
    """
    Steps:
      1. package_release (70ms) — always ok
      2. If missing_secret or migration_risk → raise exception (env degraded, 500)
      3. switch_traffic (55ms) — always ok
      4. smoke_test:
         - If missing_secret, config_drift or failing_smoke → fail → env degraded, 502
         - Otherwise → ok → env healthy, 200
    """
    req_id = f"req-{secrets.token_hex(4)}"
    steps = []
    now_str = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())

    state = read_state()
    env = state["environments"].get(environment, {})
    previous_release = env.get("current_release")

    # Step 1: package_release
    step1 = simulate_step("package_release", 70)
    steps.append(step1)

    # Step 2: pre-traffic failure
    if scenario in ("missing_secret", "migration_risk"):
        reason = "missing_secret: STRIPE_KEY not found in vault" if scenario == "missing_secret" else "migration_risk: destructive ALTER TABLE detected in migration script"
        env["health"] = "degraded"
        env["last_failure_reason"] = reason
        env["last_deploy_at"] = now_str
        state["environments"][environment] = env
        write_state(state)
        steps.append({"step": "deploy_execution", "status": "error", "reason": reason})
        return {
            "mode": "legacy",
            "environment": environment,
            "release": release,
            "scenario": scenario,
            "request_id": req_id,
            "outcome": "failed",
            "steps": steps,
            "rollback_performed": False,
            "preflight_blocked": False,
            "error": reason,
            "http_status": 500,
        }

    # Step 3: switch_traffic
    step3 = simulate_step("switch_traffic", 55)
    steps.append(step3)

    # Update environment to new release
    env["current_release"] = release
    env["last_deploy_at"] = now_str

    # Step 4: smoke_test
    if scenario in ("missing_secret", "config_drift", "failing_smoke"):
        reason_map = {
            "missing_secret": "smoke_test: health check returned 500 — missing secret caused boot failure",
            "config_drift":   "smoke_test: service responded with wrong config — environment drift detected",
            "failing_smoke":  "smoke_test: POST /orders returned 503 — service unstable post-deploy",
        }
        reason = reason_map.get(scenario, "smoke_test: unknown failure")
        env["health"] = "degraded"
        env["last_failure_reason"] = reason
        state["environments"][environment] = env
        write_state(state)
        steps.append({"step": "smoke_test", "status": "error", "reason": reason})
        return {
            "mode": "legacy",
            "environment": environment,
            "release": release,
            "scenario": scenario,
            "request_id": req_id,
            "outcome": "failed",
            "steps": steps,
            "rollback_performed": False,
            "preflight_blocked": False,
            "error": reason,
            "http_status": 502,
        }

    # Success
    steps.append({"step": "smoke_test", "status": "ok", "elapsed_ms": 45})
    env["health"] = "healthy"
    env["last_good_release"] = release
    env["last_failure_reason"] = None
    state["environments"][environment] = env
    write_state(state)
    return {
        "mode": "legacy",
        "environment": environment,
        "release": release,
        "scenario": scenario,
        "request_id": req_id,
        "outcome": "success",
        "steps": steps,
        "rollback_performed": False,
        "preflight_blocked": False,
        "http_status": 200,
    }


# ---------------------------------------------------------------------------
# Controlled deployment flow
# ---------------------------------------------------------------------------

def run_controlled_deployment(environment, release, scenario):
    """
    Steps:
      1. build_artifact (65ms) — always ok
      2. tests_and_contracts (60ms) — always ok
      3. preflight_validation:
         - If missing_secret, config_drift or migration_risk → blocked → 409
      4. migration_dry_run (only if migration_risk and not already blocked) → blocked → 409
      5. deploy_canary (60ms) — always ok
      6. smoke_test:
         - If failing_smoke → rollback → 502
         - Otherwise → promote_release → env healthy, 200
    """
    req_id = f"req-{secrets.token_hex(4)}"
    steps = []
    now_str = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())

    state = read_state()
    env = state["environments"].get(environment, {})
    previous_release = env.get("current_release")

    # Step 1: build_artifact
    steps.append(simulate_step("build_artifact", 65))

    # Step 2: tests_and_contracts
    steps.append(simulate_step("tests_and_contracts", 60))

    # Step 3: preflight_validation
    if scenario in ("missing_secret", "config_drift", "migration_risk"):
        reason_map = {
            "missing_secret": "preflight_validation: secret STRIPE_KEY missing from target environment vault",
            "config_drift":   "preflight_validation: config checksum mismatch — staging diverged from expected baseline",
            "migration_risk": "preflight_validation: migration script contains DROP COLUMN — manual review required",
        }
        reason = reason_map[scenario]
        steps.append({"step": "preflight_validation", "status": "blocked", "reason": reason})
        # env unchanged
        state["environments"][environment] = env
        write_state(state)
        return {
            "mode": "controlled",
            "environment": environment,
            "release": release,
            "scenario": scenario,
            "request_id": req_id,
            "outcome": "blocked",
            "steps": steps,
            "rollback_performed": False,
            "preflight_blocked": True,
            "error": reason,
            "http_status": 409,
        }

    # Step 4: (alternative) migration_dry_run — only reached if not blocked above
    # Since migration_risk is already handled, this path is for future extension.
    # Shown here for completeness but not reachable in current scenario set.

    # Step 5: deploy_canary
    steps.append(simulate_step("deploy_canary", 60))
    env["current_release"] = release
    env["last_deploy_at"] = now_str

    # Step 6: smoke_test
    if scenario == "failing_smoke":
        # Rollback
        env["current_release"] = previous_release
        env["health"] = env.get("health", "ready")  # keep previous health
        env["last_failure_reason"] = "smoke_test: canary health check failed — automatic rollback to " + str(previous_release)
        env["last_deploy_at"] = now_str
        state["environments"][environment] = env
        write_state(state)
        steps.append({
            "step": "smoke_test",
            "status": "error",
            "reason": "canary health check failed",
        })
        steps.append({
            "step": "rollback",
            "status": "ok",
            "reverted_to": previous_release,
        })
        return {
            "mode": "controlled",
            "environment": environment,
            "release": release,
            "scenario": scenario,
            "request_id": req_id,
            "outcome": "rolled_back",
            "steps": steps,
            "rollback_performed": True,
            "preflight_blocked": False,
            "reverted_to": previous_release,
            "error": "smoke_test failed — automatic rollback executed",
            "http_status": 502,
        }

    # Success: promote
    steps.append({"step": "smoke_test", "status": "ok", "elapsed_ms": 45})
    steps.append({"step": "promote_release", "status": "ok", "release": release})
    env["health"] = "healthy"
    env["last_good_release"] = release
    env["last_failure_reason"] = None
    state["environments"][environment] = env
    write_state(state)
    return {
        "mode": "controlled",
        "environment": environment,
        "release": release,
        "scenario": scenario,
        "request_id": req_id,
        "outcome": "success",
        "steps": steps,
        "rollback_performed": False,
        "preflight_blocked": False,
        "http_status": 200,
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
            scenario = flow_context.get("scenario", "unknown")
            env_name = flow_context.get("environment", "unknown")
            outcome = flow_context.get("outcome", "failed")

            if outcome == "success":
                m["successes"] = m.get("successes", 0) + 1
            else:
                m["failures"] = m.get("failures", 0) + 1

            if flow_context.get("rollback_performed"):
                m["rollbacks"] = m.get("rollbacks", 0) + 1
            if flow_context.get("preflight_blocked"):
                m["preflight_blocks"] = m.get("preflight_blocks", 0) + 1

            m["by_scenario"].setdefault(scenario, {"successes": 0, "failures": 0, "rollbacks": 0, "preflight_blocks": 0})
            bs = m["by_scenario"][scenario]
            if outcome == "success":
                bs["successes"] += 1
            else:
                bs["failures"] += 1
            if flow_context.get("rollback_performed"):
                bs["rollbacks"] += 1
            if flow_context.get("preflight_blocked"):
                bs["preflight_blocks"] += 1

            m["by_environment"].setdefault(env_name, {"successes": 0, "failures": 0})
            be = m["by_environment"][env_name]
            if outcome == "success":
                be["successes"] += 1
            else:
                be["failures"] += 1

            deploy_entry = {
                "mode": mode,
                "environment": env_name,
                "release": flow_context.get("release"),
                "scenario": scenario,
                "outcome": outcome,
                "rollback_performed": flow_context.get("rollback_performed", False),
                "preflight_blocked": flow_context.get("preflight_blocked", False),
                "http_status": status,
                "elapsed_ms": round(elapsed_ms, 2),
                "timestamp_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            }
            telemetry["deployments"].append(deploy_entry)
            telemetry["deployments"] = telemetry["deployments"][-80:]

        write_telemetry(telemetry)


def prometheus_label(v):
    return v.replace("\\", "\\\\").replace('"', '\\"').replace("\n", " ")


def render_prometheus_metrics():
    with _lock:
        summary = telemetry_summary(read_telemetry())
        state = read_state()

    lines = [
        "# HELP app_requests_total Total de requests observados.",
        "# TYPE app_requests_total counter",
        f"app_requests_total {summary.get('requests_tracked', 0)}",
    ]

    modes_data = summary.get("modes", {})
    for mode, m in modes_data.items():
        lm = prometheus_label(mode)
        lines += [
            f'app_deploy_success_total{{mode="{lm}"}} {m.get("successes", 0)}',
            f'app_deploy_failure_total{{mode="{lm}"}} {m.get("failures", 0)}',
            f'app_deploy_rollbacks_total{{mode="{lm}"}} {m.get("rollbacks", 0)}',
            f'app_deploy_preflight_blocks_total{{mode="{lm}"}} {m.get("preflight_blocks", 0)}',
        ]

    for env_name, env_data in (state.get("environments") or {}).items():
        le = prometheus_label(env_name)
        healthy = 1 if env_data.get("health") == "healthy" else 0
        lines.append(f'app_environment_healthy{{environment="{le}"}} {healthy}')

    return "\n".join(lines) + "\n"


# ---------------------------------------------------------------------------
# Handler
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
        flow_context = None

        try:
            if uri in ("/", ""):
                payload = {
                    "lab": "Problem-Driven Systems Lab",
                    "case": "06 - Pipeline roto y entrega fragil",
                    "stack": APP_STACK,
                    "goal": "Comparar un deploy ad-hoc (legacy) contra un pipeline controlado con preflight, canary y rollback automatico.",
                    "routes": {
                        "/health": "Estado basico.",
                        "/deploy-legacy?environment=staging&release=2026.04.1&scenario=missing_secret": "Deploy legacy sin validaciones previas.",
                        "/deploy-controlled?environment=staging&release=2026.04.1&scenario=missing_secret": "Deploy controlado con preflight y rollback automatico.",
                        "/environments": "Estado actual de todos los entornos.",
                        "/deployments?limit=10": "Historial de despliegues.",
                        "/diagnostics/summary": "Resumen completo.",
                        "/metrics": "Metricas JSON.",
                        "/metrics-prometheus": "Metricas en formato Prometheus.",
                        "/reset-lab": "Reinicia estado y telemetria.",
                    },
                    "allowed_scenarios": ALLOWED_SCENARIOS,
                    "environments": ENVIRONMENTS,
                }

            elif uri == "/health":
                payload = {"status": "ok", "stack": APP_STACK}

            elif uri in ("/deploy-legacy", "/deploy-controlled"):
                mode = "legacy" if uri == "/deploy-legacy" else "controlled"
                scenario = query.get("scenario", ["ok"])[0]
                if scenario not in ALLOWED_SCENARIOS:
                    scenario = "ok"
                environment = query.get("environment", ["staging"])[0]
                if environment not in ENVIRONMENTS:
                    environment = "staging"
                release = query.get("release", ["2026.04.1"])[0]

                with _lock:
                    if mode == "legacy":
                        result = run_legacy_deployment(environment, release, scenario)
                    else:
                        result = run_controlled_deployment(environment, release, scenario)

                status_code = result["http_status"]
                flow_context = {
                    "mode": mode,
                    "scenario": scenario,
                    "environment": environment,
                    "release": release,
                    "outcome": result["outcome"],
                    "rollback_performed": result.get("rollback_performed", False),
                    "preflight_blocked": result.get("preflight_blocked", False),
                }
                payload = {k: v for k, v in result.items() if k != "http_status"}

            elif uri == "/environments":
                with _lock:
                    state = read_state()
                payload = {"environments": state["environments"]}

            elif uri == "/deployments":
                limit = clamp_int(query_int(query, "limit", 10), 1, 80)
                with _lock:
                    telemetry = read_telemetry()
                deployments = list(reversed(telemetry.get("deployments", [])))[:limit]
                payload = {"limit": limit, "deployments": deployments}

            elif uri == "/diagnostics/summary":
                with _lock:
                    summary = telemetry_summary(read_telemetry())
                    state = read_state()
                payload = {
                    "case": "06 - Pipeline roto y entrega fragil",
                    "stack": APP_STACK,
                    "metrics": summary,
                    "environments": state["environments"],
                }

            elif uri == "/metrics":
                with _lock:
                    summary = telemetry_summary(read_telemetry())
                payload = {
                    "case": "06 - Pipeline roto y entrega fragil",
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
                    write_state(initial_state())
                    write_telemetry(initial_telemetry())
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
PORT = int(os.environ.get("PORT", "8080"))
server = HTTPServer(("0.0.0.0", PORT), Handler)
print(f"Servidor Python escuchando en {PORT}")
server.serve_forever()
