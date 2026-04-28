from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse
import base64
import gc
import hashlib
import json
import os
import secrets
import sys
import tempfile
import threading
import time
import tracemalloc

APP_STACK = os.environ.get("APP_STACK", "Python 3.12")
STORAGE_DIR = os.path.join(tempfile.gettempdir(), "pdsl-case05-python")
STATE_PATH = os.path.join(STORAGE_DIR, "state.json")
TELEMETRY_PATH = os.path.join(STORAGE_DIR, "telemetry.json")

_lock = threading.Lock()

# Real module-level accumulation — persists across requests like a real leak
_legacy_retained: list = []
LEGACY_HARD_CAP = 2000   # safety cap: prevents real OOM in the container

# Optimized mode keeps only a fixed-size hash ring — bounded by design
_optimized_cache: dict = {}
OPTIMIZED_CACHE_MAX = 24

tracemalloc.start()


# ---------------------------------------------------------------------------
# Memory measurement helpers
# ---------------------------------------------------------------------------

def deep_sizeof(obj) -> int:
    """Recursive sys.getsizeof for lists and dicts — measures actual Python object bytes."""
    size = sys.getsizeof(obj)
    if isinstance(obj, list):
        size += sum(sys.getsizeof(item) for item in obj)
    elif isinstance(obj, dict):
        size += sum(sys.getsizeof(k) + sys.getsizeof(v) for k, v in obj.items())
    return size


def snapshot_kb() -> float:
    """Returns current tracemalloc allocation in KB for this process."""
    snap = tracemalloc.take_snapshot()
    stats = snap.statistics("lineno")
    total_bytes = sum(s.size for s in stats)
    return round(total_bytes / 1024, 2)


# ---------------------------------------------------------------------------
# Storage helpers
# ---------------------------------------------------------------------------

def ensure_storage_dir():
    os.makedirs(STORAGE_DIR, exist_ok=True)


# ---------------------------------------------------------------------------
# State
# ---------------------------------------------------------------------------

def initial_state():
    now_str = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    return {
        "thresholds": {
            "warning_retained_kb": 8192,
            "critical_retained_kb": 16384,
            "warning_descriptors": 60,
            "critical_descriptors": 120,
        },
        "modes": {
            "legacy": {
                "retained_kb": 0.0,
                "retained_bytes": 0,
                "retained_objects": 0,
                "cache_entries": 0,
                "descriptor_pressure": 0,
                "gc_cycles": 0,
                "last_cleanup_at": None,
                "last_updated": None,
            },
            "optimized": {
                "retained_kb": 0.0,
                "retained_bytes": 0,
                "retained_objects": 0,
                "cache_entries": 0,
                "descriptor_pressure": 0,
                "gc_cycles": 1,
                "last_cleanup_at": now_str,
                "last_updated": None,
            },
        },
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
            "documents_total": 0,
            "avg_peak_request_kb_samples": [],
            "avg_retained_after_kb_samples": [],
            "pressure_counts": {"healthy": 0, "warning": 0, "critical": 0},
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
            "optimized": mode_metrics(),
        },
        "runs": [],
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
    for mode in ("legacy", "optimized"):
        if mode in parsed.get("modes", {}):
            base["modes"][mode].update(parsed["modes"][mode])
    base["routes"] = parsed.get("routes", {})
    base["samples_ms"] = parsed.get("samples_ms", [])
    base["status_counts"] = parsed.get("status_counts", {})
    base["runs"] = parsed.get("runs", [])
    return base


def write_telemetry(telemetry):
    ensure_storage_dir()
    with open(TELEMETRY_PATH, "w", encoding="utf-8") as fh:
        json.dump(telemetry, fh, indent=2, ensure_ascii=False)


# ---------------------------------------------------------------------------
# Scenario factors
# ---------------------------------------------------------------------------

SCENARIO_FACTORS = {
    "cache_growth":     {"leak_factor": 1.45, "descriptor_factor": 0.4},
    "descriptor_drift": {"leak_factor": 0.7,  "descriptor_factor": 1.4},
    "mixed_pressure":   {"leak_factor": 1.1,  "descriptor_factor": 0.9},
}

ALLOWED_SCENARIOS = list(SCENARIO_FACTORS.keys())


# ---------------------------------------------------------------------------
# Pressure level helper
# ---------------------------------------------------------------------------

def pressure_level(retained_kb, descriptor, thresholds):
    crit_kb = thresholds.get("critical_retained_kb", 16384)
    warn_kb = thresholds.get("warning_retained_kb", 8192)
    crit_d  = thresholds.get("critical_descriptors", 120)
    warn_d  = thresholds.get("warning_descriptors", 60)
    if retained_kb >= crit_kb or descriptor >= crit_d:
        return "critical"
    if retained_kb >= warn_kb or descriptor >= warn_d:
        return "warning"
    return "healthy"


# ---------------------------------------------------------------------------
# Core batch logic — real memory accumulation and real measurement
# ---------------------------------------------------------------------------

def run_batch(mode, scenario, documents, payload_kb):
    global _legacy_retained, _optimized_cache

    factors = SCENARIO_FACTORS.get(scenario, SCENARIO_FACTORS["mixed_pressure"])
    descriptor_factor = factors["descriptor_factor"]

    state = read_state()
    thresholds = state["thresholds"]
    ms = state["modes"][mode]

    # Snapshot before
    mem_before_kb = snapshot_kb()
    retained_kb_before = ms.get("retained_kb", 0.0)
    descriptor_before = ms.get("descriptor_pressure", 0)

    peak_request_kb = documents * payload_kb

    if mode == "legacy":
        # Real accumulation: b64-encoded blobs appended to module-level list.
        # This list grows across requests — it IS the leak.
        new_blobs: list[str] = []
        for _ in range(documents):
            raw = secrets.token_bytes(max(1, payload_kb * 1024 // 8))
            b64 = base64.b64encode(raw).decode("ascii")
            new_blobs.append(b64)

        # Apply hard cap to avoid real OOM — real leak, but bounded for safety
        _legacy_retained.extend(new_blobs)
        if len(_legacy_retained) > LEGACY_HARD_CAP:
            _legacy_retained = _legacy_retained[-LEGACY_HARD_CAP:]

        # Measure actual bytes held by the retained list
        retained_bytes = deep_sizeof(_legacy_retained)
        retained_kb_after = retained_bytes / 1024

        ms["retained_bytes"] = retained_bytes
        ms["retained_kb"] = round(retained_kb_after, 2)
        ms["retained_objects"] = len(_legacy_retained)
        ms["cache_entries"] = ms.get("cache_entries", 0) + documents
        ms["descriptor_pressure"] = ms.get("descriptor_pressure", 0) + max(1, round(documents / 6 * descriptor_factor))

    else:
        # Real bounded cache: only keep last OPTIMIZED_CACHE_MAX digests.
        # `del raw` + gc.collect() ensures buffers are actually freed.
        for i in range(documents):
            raw = secrets.token_bytes(max(1, payload_kb * 1024 // 8))
            digest = hashlib.sha256(raw).hexdigest()[:16]
            _optimized_cache[digest] = True
            del raw   # explicit release — GC can reclaim immediately

        # Evict old entries beyond the bounded cap
        if len(_optimized_cache) > OPTIMIZED_CACHE_MAX:
            keys_to_drop = list(_optimized_cache.keys())[:-OPTIMIZED_CACHE_MAX]
            for k in keys_to_drop:
                del _optimized_cache[k]

        gc_count = gc.collect()   # explicit GC cycle — frees unreachable objects

        retained_bytes = deep_sizeof(_optimized_cache)
        retained_kb_after = retained_bytes / 1024

        ms["retained_bytes"] = retained_bytes
        ms["retained_kb"] = round(retained_kb_after, 2)
        ms["retained_objects"] = len(_optimized_cache)
        ms["cache_entries"] = len(_optimized_cache)
        ms["descriptor_pressure"] = max(0, ms.get("descriptor_pressure", 0) - max(1, documents // 4))
        ms["gc_cycles"] = ms.get("gc_cycles", 0) + 1
        ms["last_cleanup_at"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())

    ms["last_updated"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())

    # Snapshot after — real tracemalloc delta
    mem_after_kb = snapshot_kb()
    descriptor_after = ms["descriptor_pressure"]
    level = pressure_level(retained_kb_after, descriptor_after, thresholds)
    http_status = 503 if (mode == "legacy" and level == "critical") else 200

    # Proportional penalty sleep based on real retained count
    penalty_ms = (
        documents * 2.8
        + payload_kb * 1.4
        + retained_kb_after / 256
        + descriptor_after * (2.1 if mode == "legacy" else 0.7)
    )
    sleep_ms = min(650, max(30, penalty_ms))
    time.sleep(sleep_ms / 1000.0)

    state["modes"][mode] = ms
    write_state(state)

    return {
        "mode": mode,
        "scenario": scenario,
        "documents": documents,
        "payload_kb": payload_kb,
        "status": "degraded" if http_status == 503 else "ok",
        "pressure_level": level,
        "memory": {
            "peak_request_kb": round(peak_request_kb, 2),
            "retained_kb_before": round(retained_kb_before, 2),
            "retained_kb_after": round(retained_kb_after, 2),
            "retained_bytes": ms["retained_bytes"],
            "retained_objects": ms["retained_objects"],
            "tracemalloc_before_kb": mem_before_kb,
            "tracemalloc_after_kb": mem_after_kb,
            "tracemalloc_delta_kb": round(mem_after_kb - mem_before_kb, 2),
            "cache_entries": ms["cache_entries"],
            "descriptor_pressure": descriptor_after,
            "gc_cycles": ms.get("gc_cycles", 0),
        },
        "thresholds": thresholds,
        "http_status": http_status,
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


def state_summary():
    state = read_state()
    thresholds = state["thresholds"]
    summary = {
        "thresholds": thresholds,
        "modes": {},
        "process": {
            "legacy_retained_objects": len(_legacy_retained),
            "legacy_retained_bytes": deep_sizeof(_legacy_retained),
            "optimized_cache_objects": len(_optimized_cache),
            "optimized_cache_bytes": deep_sizeof(_optimized_cache),
            "tracemalloc_current_kb": snapshot_kb(),
        },
    }
    for mode, ms in state.get("modes", {}).items():
        level = pressure_level(ms["retained_kb"], ms.get("descriptor_pressure", 0), thresholds)
        summary["modes"][mode] = {**ms, "pressure_level": level}
    return summary


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
            m["documents_total"] = m.get("documents_total", 0) + flow_context.get("documents", 0)

            peak = flow_context.get("peak_request_kb", 0)
            retained = flow_context.get("retained_kb_after", 0)
            m["avg_peak_request_kb_samples"].append(round(peak, 2))
            m["avg_peak_request_kb_samples"] = m["avg_peak_request_kb_samples"][-500:]
            m["avg_retained_after_kb_samples"].append(round(retained, 2))
            m["avg_retained_after_kb_samples"] = m["avg_retained_after_kb_samples"][-500:]

            level = flow_context.get("pressure_level", "healthy")
            m["pressure_counts"][level] = m["pressure_counts"].get(level, 0) + 1

            scenario = flow_context.get("scenario", "unknown")
            if status < 500:
                m["successes"] = m.get("successes", 0) + 1
            else:
                m["failures"] = m.get("failures", 0) + 1

            m["by_scenario"].setdefault(scenario, {"runs": 0, "failures": 0})
            m["by_scenario"][scenario]["runs"] += 1
            if status >= 500:
                m["by_scenario"][scenario]["failures"] += 1

            run_entry = {
                "mode": mode,
                "scenario": scenario,
                "documents": flow_context.get("documents", 0),
                "payload_kb": flow_context.get("payload_kb", 0),
                "pressure_level": level,
                "retained_kb_after": round(retained, 2),
                "http_status": status,
                "elapsed_ms": round(elapsed_ms, 2),
                "timestamp_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            }
            telemetry["runs"].append(run_entry)
            telemetry["runs"] = telemetry["runs"][-80:]

        write_telemetry(telemetry)


def prometheus_label(v):
    return v.replace("\\", "\\\\").replace('"', '\\"').replace("\n", " ")


def render_prometheus_metrics():
    with _lock:
        summary = telemetry_summary(read_telemetry())
        st = state_summary()

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
        ]

    for mode, ms in (st.get("modes") or {}).items():
        lm = prometheus_label(mode)
        lines += [
            f'app_retained_memory_kb{{mode="{lm}"}} {round(ms.get("retained_kb", 0), 2)}',
            f'app_retained_objects{{mode="{lm}"}} {ms.get("retained_objects", 0)}',
            f'app_descriptor_pressure{{mode="{lm}"}} {ms.get("descriptor_pressure", 0)}',
        ]
        for level in ("healthy", "warning", "critical"):
            val = (modes_data.get(mode) or {}).get("pressure_counts", {}).get(level, 0)
            lines.append(f'app_pressure_level{{mode="{lm}",level="{level}"}} {val}')

    proc = st.get("process", {})
    lines += [
        f'app_tracemalloc_current_kb {proc.get("tracemalloc_current_kb", 0)}',
        f'app_legacy_retained_objects {proc.get("legacy_retained_objects", 0)}',
        f'app_optimized_cache_objects {proc.get("optimized_cache_objects", 0)}',
    ]

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
                    "case": "05 - Presion de memoria y fugas de recursos",
                    "stack": APP_STACK,
                    "goal": "Comparar un procesamiento que acumula memoria sin limpiar (legacy) contra uno que controla su huella (optimized).",
                    "measurement": "tracemalloc + sys.getsizeof() para medicion real de bytes Python",
                    "routes": {
                        "/health": "Estado basico.",
                        "/batch-legacy?scenario=mixed_pressure&documents=24&payload_kb=64": "Procesa documentos con el modo legacy (acumula en lista de modulo — fuga real).",
                        "/batch-optimized?scenario=mixed_pressure&documents=24&payload_kb=64": "Procesa documentos con el modo optimizado (cache acotado + gc.collect()).",
                        "/state": "Estado actual de memoria y descriptores por modo, con medicion real de proceso.",
                        "/runs?limit=10": "Ultimas runs registradas.",
                        "/diagnostics/summary": "Resumen completo de telemetria.",
                        "/metrics": "Metricas JSON.",
                        "/metrics-prometheus": "Metricas en formato Prometheus.",
                        "/reset-lab": "Reinicia estado, telemetria y listas de modulo.",
                    },
                    "allowed_scenarios": ALLOWED_SCENARIOS,
                }

            elif uri == "/health":
                payload = {"status": "ok", "stack": APP_STACK}

            elif uri in ("/batch-legacy", "/batch-optimized"):
                mode = "legacy" if uri == "/batch-legacy" else "optimized"
                scenario = query.get("scenario", ["mixed_pressure"])[0]
                if scenario not in ALLOWED_SCENARIOS:
                    scenario = "mixed_pressure"
                documents = clamp_int(query_int(query, "documents", 24), 1, 200)
                payload_kb = clamp_int(query_int(query, "payload_kb", 64), 1, 512)

                with _lock:
                    result = run_batch(mode, scenario, documents, payload_kb)

                status_code = result["http_status"]
                flow_context = {
                    "mode": mode,
                    "scenario": scenario,
                    "documents": documents,
                    "payload_kb": payload_kb,
                    "pressure_level": result["pressure_level"],
                    "peak_request_kb": result["memory"]["peak_request_kb"],
                    "retained_kb_after": result["memory"]["retained_kb_after"],
                }
                payload = result
                del payload["http_status"]

            elif uri == "/state":
                with _lock:
                    payload = state_summary()

            elif uri == "/runs":
                limit = clamp_int(query_int(query, "limit", 10), 1, 80)
                with _lock:
                    telemetry = read_telemetry()
                runs = list(reversed(telemetry.get("runs", [])))[:limit]
                payload = {"limit": limit, "runs": runs}

            elif uri == "/diagnostics/summary":
                with _lock:
                    summary = telemetry_summary(read_telemetry())
                    st = state_summary()
                payload = {
                    "case": "05 - Presion de memoria y fugas de recursos",
                    "stack": APP_STACK,
                    "metrics": summary,
                    "state": st,
                    "scenario_factors": SCENARIO_FACTORS,
                }

            elif uri == "/metrics":
                with _lock:
                    summary = telemetry_summary(read_telemetry())
                payload = {
                    "case": "05 - Presion de memoria y fugas de recursos",
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
                    _legacy_retained.clear()
                    _optimized_cache.clear()
                    gc.collect()
                    write_state(initial_state())
                    write_telemetry(initial_telemetry())
                payload = {
                    "status": "reset",
                    "message": "Estado, telemetria y listas de modulo reiniciados.",
                }

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
