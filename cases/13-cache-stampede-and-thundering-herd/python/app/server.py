"""Caso 13 — Cache stampede (thundering herd) — stack Python 3.12.

Naive: la clave expira y los N llamadores concurrentes recalculan el origen a
la vez. `origin_computations == concurrency`.
Single-flight: un solo recalculo; el resto espera al mismo resultado.
`origin_computations == 1` sin importar cuantos lleguen.

Primitiva Python distintiva:
    Un dict de vuelos en curso protegido por `threading.Lock`, donde cada entrada
    es un `threading.Event`. El primer hilo que llega crea el Event y calcula; los
    demas encuentran el Event y hacen `wait()`. No hay libreria: `Event` es
    exactamente el "espera a que alguien mas termine" que el patron necesita.

El origen es CPU real (un digest iterativo), no un `sleep`. Un sleep con GIL
seria indistinguible de N sleeps concurrentes y el caso no probaria nada: el
punto es que N recalculos cuestan N veces el trabajo del origen.
"""

from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse
import json
import os
import random
import threading
import time

APP_STACK = os.environ.get("APP_STACK", "Python 3.12")
CASE_NAME = "13 - Cache stampede y thundering herd"

# TTL base y jitter. El jitter es la mitad barata del arreglo: sin el, mil
# claves cargadas en el mismo deploy expiran en el mismo milisegundo.
TTL_BASE_MS = 4000
JITTER_PCT = 25
# Ventana soft: pasada la soft TTL el valor sigue siendo servible mientras un
# solo hilo lo refresca por detras. Pasada la hard TTL ya no se puede servir.
SOFT_FRACTION = 0.6

_state_lock = threading.Lock()
_cache = {}          # key -> {"value","computed_at_ms","soft_ms","hard_ms"}
_inflight = {}       # key -> {"event": Event, "value": str|None}
_inflight_lock = threading.Lock()

_origin_active = 0
_origin_peak = 0
_origin_active_lock = threading.Lock()


def now_ms():
    return time.monotonic() * 1000.0


def initial_metrics():
    def slot():
        return {
            "runs": 0,
            "origin_computations": 0,
            "cache_hits": 0,
            "coalesced_waiters": 0,
            "served_stale": 0,
            "max_stampede_depth": 0,
            "wall_samples_ms": [],
        }

    return {"naive": slot(), "singleflight": slot(), "origin_total": 0}


_metrics = initial_metrics()


# ---------------------------------------------------------------------------
# Origen: trabajo real, no un sleep
# ---------------------------------------------------------------------------

def origin_compute(key, rounds):
    """Recalculo caro del valor. CPU real: 2000 iteraciones por ronda.

    Instrumentado con un contador de ocupacion: `_origin_peak` guarda cuantos
    hilos coincidieron dentro del camino de recomputo durante la rafaga. Ese
    pico es `stampede_depth`, la metrica central del caso.
    """
    global _origin_active, _origin_peak
    with _origin_active_lock:
        _origin_active += 1
        _origin_peak = max(_origin_peak, _origin_active)
    try:
        h = 0
        salt = len(key) or 1
        for i in range(rounds * 2000):
            h = (h * 31 + (i ^ salt)) & 0xFFFFFFFF
        return f"{h:08x}"
    finally:
        with _origin_active_lock:
            _origin_active -= 1


def ttl_with_jitter():
    """TTL base +- jitter. Devuelve (hard_ms, soft_ms, jitter_aplicado_ms)."""
    spread = TTL_BASE_MS * JITTER_PCT // 100
    jitter = random.randint(-spread, spread)
    hard = TTL_BASE_MS + jitter
    return hard, int(hard * SOFT_FRACTION), jitter


def cache_lookup(key):
    """Devuelve (value, estado) con estado en fresh | stale | miss."""
    with _state_lock:
        entry = _cache.get(key)
        if entry is None:
            return None, "miss"
        age = now_ms() - entry["computed_at_ms"]
        if age <= entry["soft_ms"]:
            return entry["value"], "fresh"
        if age <= entry["hard_ms"]:
            return entry["value"], "stale"
        return None, "miss"


def cache_store(key, value):
    hard, soft, jitter = ttl_with_jitter()
    with _state_lock:
        _cache[key] = {
            "value": value,
            "computed_at_ms": now_ms(),
            "soft_ms": soft,
            "hard_ms": hard,
        }
    return jitter


# ---------------------------------------------------------------------------
# Variante naive: sin single-flight
# ---------------------------------------------------------------------------

def caller_naive(key, rounds, out, idx, gate):
    gate.wait()
    started = now_ms()
    _, state = cache_lookup(key)
    # Segunda fase de la barrera: los N llamadores ya leyeron la cache antes de
    # que ninguno escriba. Es lo que pasa de verdad cuando la clave expira con
    # trafico encima — no un artificio para inflar el numero. Ver run_burst().
    gate.wait()
    if state == "fresh":
        out[idx] = {"wait_ms": now_ms() - started, "computed": False, "stale": False, "waited": False}
        return
    # Sin coordinacion: cada llamador que vio el miss recalcula por su cuenta.
    value = origin_compute(key, rounds)
    cache_store(key, value)
    out[idx] = {"wait_ms": now_ms() - started, "computed": True, "stale": False, "waited": False}


# ---------------------------------------------------------------------------
# Variante single-flight: un Event por clave en vuelo
# ---------------------------------------------------------------------------

def caller_singleflight(key, rounds, out, idx, gate):
    gate.wait()
    started = now_ms()
    _, state = cache_lookup(key)
    gate.wait()
    if state == "fresh":
        out[idx] = {"wait_ms": now_ms() - started, "computed": False, "stale": False, "waited": False}
        return

    # Soft TTL vencida pero dentro de la hard: el valor viejo sigue siendo
    # servible mientras un solo hilo lo refresca por detras.
    serve_stale = state == "stale"

    # El registro en _inflight ocurre bajo lock y ANTES de tocar el origen. Es
    # el equivalente Python del "Map.set antes del await" de Node: si el lider
    # publicara su Event despues de empezar a calcular, la ventana entre ambos
    # dejaria pasar la estampida completa.
    with _inflight_lock:
        flight = _inflight.get(key)
        leader = flight is None
        if leader:
            flight = {"event": threading.Event(), "value": None}
            _inflight[key] = flight

    if leader:
        did_compute = False
        try:
            # Double check dentro del vuelo. Sin esto el single-flight funciona
            # pero no alcanza: el lider de la primera generacion termina, borra
            # su entrada, y los llamadores que todavia no habian llegado al
            # registro se convierten en lideres de una segunda generacion. Con
            # `cost` chico eso da 3 o 4 recalculos en vez de 1 — no por un bug
            # del patron sino porque falta este `if`.
            _, recheck = cache_lookup(key)
            if recheck != "fresh":
                value = origin_compute(key, rounds)
                cache_store(key, value)
                flight["value"] = value
                did_compute = True
        finally:
            with _inflight_lock:
                _inflight.pop(key, None)
            flight["event"].set()
        out[idx] = {
            "wait_ms": now_ms() - started,
            "computed": did_compute,
            "stale": False,
            "waited": not did_compute,
        }
        return

    if serve_stale:
        # No espera al lider: devuelve el valor viejo de inmediato.
        out[idx] = {"wait_ms": now_ms() - started, "computed": False, "stale": True, "waited": False}
        return

    # Miss duro: espera al lider en vez de recalcular.
    flight["event"].wait(timeout=30)
    out[idx] = {"wait_ms": now_ms() - started, "computed": False, "stale": False, "waited": True}


# ---------------------------------------------------------------------------
# Orquestacion de la rafaga
# ---------------------------------------------------------------------------

def run_burst(variant, key, concurrency, rounds):
    global _origin_peak
    worker = caller_naive if variant == "naive" else caller_singleflight
    out = [None] * concurrency
    with _origin_active_lock:
        _origin_peak = 0

    # Barrera de dos fases: largada comun, y despues un segundo `wait()` que
    # cierra la ventana de lectura de la cache.
    #
    # Sin ella el resultado dependeria del GIL, no del codigo: con `cost` chico
    # el primer hilo termina su digest completo (~4 ms) dentro de su propio
    # quantum, escribe la cache, y los otros siete ya encuentran el valor
    # fresco. `origin_computations` daria 1 y la variante naive pareceria
    # correcta — un falso verde que depende de `sys.setswitchinterval`.
    #
    # La barrera no infla el numero: reproduce lo que pasa de verdad. Cuando una
    # clave caliente expira, los N requests YA estaban en vuelo y todos leyeron
    # la cache antes de que ninguno alcanzara a escribirla.
    gate = threading.Barrier(concurrency)
    threads = [
        threading.Thread(target=worker, args=(key, rounds, out, i, gate))
        for i in range(concurrency)
    ]
    started = now_ms()
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    wall_ms = now_ms() - started

    results = [r for r in out if r]
    computations = sum(1 for r in results if r["computed"])
    stale = sum(1 for r in results if r["stale"])
    waiters = sum(1 for r in results if r["waited"])
    hits = len(results) - computations - stale - waiters
    with _origin_active_lock:
        depth = _origin_peak
    waits = sorted(r["wait_ms"] for r in results)

    with _state_lock:
        slot = _metrics[variant]
        slot["runs"] += 1
        slot["origin_computations"] += computations
        slot["cache_hits"] += hits
        slot["coalesced_waiters"] += waiters
        slot["served_stale"] += stale
        slot["max_stampede_depth"] = max(slot["max_stampede_depth"], depth)
        slot["wall_samples_ms"].append(round(wall_ms, 2))
        slot["wall_samples_ms"] = slot["wall_samples_ms"][-200:]
        _metrics["origin_total"] += computations

    value, _ = cache_lookup(key)
    return {
        "variant": variant,
        "key": key,
        "concurrency": concurrency,
        "cost_rounds": rounds,
        "origin_computations": computations,
        "cache_hits": hits,
        "coalesced_waiters": waiters,
        "served_stale": stale,
        "stampede_depth": depth,
        "wall_ms": round(wall_ms, 2),
        "p99_wait_ms": percentile(waits, 99),
        "max_wait_ms": round(waits[-1], 2) if waits else 0.0,
        "value_digest": value,
        "ttl_base_ms": TTL_BASE_MS,
        "jitter_pct": JITTER_PCT,
        "note": (
            "Sin coordinacion: cada llamador que ve el miss recalcula. El origen recibe la rafaga entera."
            if variant == "naive"
            else "Un Event por clave en vuelo: el lider calcula, el resto espera o recibe el valor stale."
        ),
    }


def percentile(values, pct):
    if not values:
        return 0.0
    sv = sorted(values)
    idx = max(0, min(len(sv) - 1, int((pct / 100.0) * len(sv) + 0.999999) - 1))
    return round(float(sv[idx]), 2)


def cache_state():
    with _state_lock:
        entries = {}
        for key, entry in _cache.items():
            age = now_ms() - entry["computed_at_ms"]
            entries[key] = {
                "age_ms": round(age, 2),
                "soft_ttl_ms": entry["soft_ms"],
                "hard_ttl_ms": entry["hard_ms"],
                "soft_expired": age > entry["soft_ms"],
                "hard_expired": age > entry["hard_ms"],
                "value_digest": entry["value"],
            }
    return {
        "entries": entries,
        "ttl_base_ms": TTL_BASE_MS,
        "jitter_pct": JITTER_PCT,
        "soft_fraction": SOFT_FRACTION,
        "inflight_keys": sorted(_inflight.keys()),
    }


def diagnostics():
    with _state_lock:
        variants = {}
        for name in ("naive", "singleflight"):
            s = _metrics[name]
            samples = s["wall_samples_ms"]
            variants[name] = {
                "runs": s["runs"],
                "origin_computations": s["origin_computations"],
                "cache_hits": s["cache_hits"],
                "coalesced_waiters": s["coalesced_waiters"],
                "served_stale": s["served_stale"],
                "max_stampede_depth": s["max_stampede_depth"],
                "avg_wall_ms": round(sum(samples) / len(samples), 2) if samples else 0.0,
                "p99_wall_ms": percentile(samples, 99),
            }
        total = _metrics["origin_total"]
    return {
        "stack": APP_STACK,
        "case": CASE_NAME,
        "variants": variants,
        "origin_total_computations": total,
        "interpretation": {
            "naive": "origin_computations crece linealmente con la concurrencia: el origen ve la rafaga completa.",
            "singleflight": "origin_computations se mantiene en 1 por expiracion, sin importar cuantos llamadores lleguen.",
        },
    }


def reset_lab():
    global _metrics
    with _state_lock:
        _cache.clear()
        _metrics = initial_metrics()
    with _inflight_lock:
        _inflight.clear()


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


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        return

    def send_json(self, status, payload):
        raw = json.dumps(payload, ensure_ascii=False, indent=2).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def do_GET(self):
        parsed = urlparse(self.path)
        uri = parsed.path or "/"
        query = parse_qs(parsed.query)
        status = 200

        key = (query.get("key", ["report-alpha"])[0] or "report-alpha")[:60]
        concurrency = clamp_int(query_int(query, "concurrency", 16), 1, 128)
        rounds = clamp_int(query_int(query, "cost", 40), 1, 400)

        if uri in ("/", "/index"):
            payload = {
                "lab": "Problem-Driven Systems Lab",
                "case": CASE_NAME,
                "stack": APP_STACK,
                "goal": "Mostrar cuantas veces pega el origen cuando una clave caliente expira con N llamadores encima.",
                "python_specific": "dict de vuelos en curso + threading.Event: el lider calcula, el resto hace wait().",
                "routes": {
                    "/health": "Estado basico del servicio.",
                    "/cache-naive?key=report-alpha&concurrency=16&cost=40": "Rafaga sin single-flight.",
                    "/cache-singleflight?key=report-alpha&concurrency=16&cost=40": "Misma rafaga con single-flight, jitter y soft TTL.",
                    "/cache/state": "Edad, soft/hard TTL y claves en vuelo.",
                    "/diagnostics/summary": "Comparativa de origin_computations entre variantes.",
                    "/reset-lab": "Vacia cache y contadores.",
                },
            }
        elif uri == "/health":
            payload = {"status": "ok", "stack": APP_STACK, "case": CASE_NAME}
        elif uri == "/cache-naive":
            payload = run_burst("naive", key, concurrency, rounds)
        elif uri == "/cache-singleflight":
            payload = run_burst("singleflight", key, concurrency, rounds)
        elif uri == "/cache/state":
            payload = cache_state()
        elif uri == "/diagnostics/summary":
            payload = diagnostics()
        elif uri == "/reset-lab":
            reset_lab()
            payload = {"status": "reset", "message": "Cache y metricas reiniciadas."}
        else:
            status = 404
            payload = {"error": "Ruta no encontrada", "path": uri}

        payload["timestamp_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        payload["pid"] = os.getpid()
        self.send_json(status, payload)


PORT = int(os.environ.get("PORT", "8080"))
print(f"Servidor Python escuchando en {PORT}")
HTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
