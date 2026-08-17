"""Caso 14 — Agotamiento del pool de conexiones — stack Python 3.12.

Leaky: sin timeout de adquisicion y con `put()` solo en el camino feliz. Cada
excepcion se lleva una conexion que nunca vuelve al pool. Con suficientes
fallos el pool queda vacio para siempre y todo lo que llega despues cuelga.

Managed: pool dimensionado por la ley de Little, timeout de adquisicion
explicito, y devolucion garantizada por un context manager. Los fallos siguen
ocurriendo — pero fallan rapido y no se llevan la conexion.

Primitiva Python distintiva:
    `queue.Queue(maxsize=N)` COMO pool. No es una cola de mensajes: cada
    elemento es una conexion disponible. `get(timeout=...)` es la adquisicion
    con deadline y `put()` es la devolucion. La biblioteca estandar ya trae la
    estructura; lo que hay que aportar es la disciplina de devolverla.

    Y esa disciplina se expresa con `@contextmanager`. El `finally` de un
    generador decorado corre en TODOS los caminos de salida — return, excepcion
    o `break` — que es exactamente la garantia que Java obtiene con
    try-with-resources y .NET con `using`.

Aqui el trabajo del "query" SI es un `sleep`, al reves que en el caso 13.
La razon: una conexion se retiene mientras se espera a la red, no mientras se
quema CPU. Dormir es el modelo fiel del tiempo de retencion; quemar CPU
mediria otra cosa.
"""

from contextlib import contextmanager
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse
import json
import math
import os
import queue
import threading
import time

APP_STACK = os.environ.get("APP_STACK", "Python 3.12")
CASE_NAME = "14 - Agotamiento del pool de conexiones"

ACQUIRE_TIMEOUT_MS = 200
# Sin timeout, la variante leaky colgaria para siempre. El watchdog existe para
# que la demo termine — no es parte del arreglo, es lo que permite medir el
# fallo. En produccion ese "para siempre" es literal.
LEAKY_WATCHDOG_MS = 2000

_lock = threading.Lock()


class Conn:
    """Una conexion del pool. Solo lleva su id y cuantas veces se uso."""

    __slots__ = ("id", "uses")

    def __init__(self, cid):
        self.id = cid
        self.uses = 0


class Pool:
    """Pool de conexiones sobre `queue.Queue`. Cada item es una conexion libre."""

    def __init__(self, size):
        self.size = size
        self._q = queue.Queue(maxsize=size)
        for i in range(size):
            self._q.put(Conn(i + 1))
        self.acquired = 0
        self.released = 0
        self.waiting = 0
        self.waiting_peak = 0
        self._m = threading.Lock()

    # -- primitivas crudas: lo que la variante leaky usa mal --------------

    def acquire(self, timeout_ms):
        with self._m:
            self.waiting += 1
            self.waiting_peak = max(self.waiting_peak, self.waiting)
        try:
            conn = self._q.get(timeout=timeout_ms / 1000.0)
        except queue.Empty:
            return None
        finally:
            with self._m:
                self.waiting -= 1
        with self._m:
            self.acquired += 1
        conn.uses += 1
        return conn

    def release(self, conn):
        if conn is None:
            return
        with self._m:
            self.released += 1
        try:
            self._q.put_nowait(conn)
        except queue.Full:
            pass

    # -- la forma correcta: devolucion garantizada ------------------------

    @contextmanager
    def lease(self, timeout_ms):
        """Adquiere con deadline y devuelve SIEMPRE.

        El `finally` de un generador con @contextmanager corre en todos los
        caminos de salida. Es la version Python de try-with-resources.
        """
        conn = self.acquire(timeout_ms)
        if conn is None:
            raise TimeoutError("pool acquire timeout")
        try:
            yield conn
        finally:
            self.release(conn)

    def available(self):
        return self._q.qsize()

    def leaked(self):
        with self._m:
            return self.acquired - self.released


_pool = None
_pool_size = 0


def build_pool(size):
    global _pool, _pool_size
    _pool = Pool(size)
    _pool_size = size
    return _pool


def initial_metrics():
    def slot():
        return {
            "runs": 0,
            "completed": 0,
            "failed_query": 0,
            "failed_timeout": 0,
            "hung": 0,
            "leaked": 0,
            "wait_samples_ms": [],
        }

    return {"leaky": slot(), "managed": slot()}


_metrics = initial_metrics()


def now_ms():
    return time.monotonic() * 1000.0


def fails(idx, fail_rate):
    """Reparto determinista de fallos.

    `idx % 100 < fail_rate` parece equivalente y no lo es: con 24 requests y
    fail_rate=25 fallarian las 24, porque todos los indices son menores que 25.
    El multiplicador primo dispersa los fallos por toda la tanda, que es como
    llegan de verdad.
    """
    return (idx * 37) % 100 < fail_rate


def run_query(conn, query_ms, should_fail):
    """El trabajo que retiene la conexion.

    `sleep` a proposito: una conexion se retiene mientras se espera a la red.
    """
    time.sleep(query_ms / 1000.0)
    if should_fail:
        raise RuntimeError(f"query fallo en la conexion {conn.id}")
    return conn.id


# ---------------------------------------------------------------------------
# Variante leaky: sin timeout de adquisicion, release solo en el camino feliz
# ---------------------------------------------------------------------------

def worker_leaky(idx, pool, query_ms, fail_rate, out):
    started = now_ms()
    # Sin deadline propio: el watchdog es lo unico que impide colgar para siempre.
    conn = pool.acquire(LEAKY_WATCHDOG_MS)
    wait_ms = now_ms() - started
    if conn is None:
        out[idx] = {"outcome": "hung", "wait_ms": wait_ms}
        return

    # El bug: no hay try/finally. Si run_query levanta, la linea de release
    # nunca se ejecuta y la conexion se pierde. No hay error en los logs que
    # diga "se fugo una conexion" — simplemente el pool se achica en silencio.
    try:
        run_query(conn, query_ms, fails(idx, fail_rate))
    except RuntimeError:
        out[idx] = {"outcome": "failed_query", "wait_ms": wait_ms}
        return
    pool.release(conn)
    out[idx] = {"outcome": "completed", "wait_ms": wait_ms}


# ---------------------------------------------------------------------------
# Variante managed: deadline explicito + devolucion garantizada
# ---------------------------------------------------------------------------

def worker_managed(idx, pool, query_ms, fail_rate, out):
    started = now_ms()
    try:
        with pool.lease(ACQUIRE_TIMEOUT_MS) as conn:
            wait_ms = now_ms() - started
            try:
                run_query(conn, query_ms, fails(idx, fail_rate))
            except RuntimeError:
                # La conexion vuelve al pool igual: el finally del context
                # manager corre antes de que la excepcion siga subiendo.
                out[idx] = {"outcome": "failed_query", "wait_ms": wait_ms}
                return
        out[idx] = {"outcome": "completed", "wait_ms": wait_ms}
    except TimeoutError:
        # Falla rapido y con un codigo que el llamador puede interpretar,
        # en vez de colgar hasta que alguien reinicie el proceso.
        out[idx] = {"outcome": "failed_timeout", "wait_ms": now_ms() - started}


# ---------------------------------------------------------------------------
# Ley de Little
# ---------------------------------------------------------------------------

def littles_law(requests, query_ms, wall_ms):
    """pool_size = throughput x tiempo de servicio + buffer."""
    if wall_ms <= 0:
        return {"avg_throughput_rps": 0.0, "avg_query_ms": query_ms, "recommended_pool_size": 1}
    rps = requests / (wall_ms / 1000.0)
    recommended = math.ceil(rps * (query_ms / 1000.0)) + 2
    return {
        "avg_throughput_rps": round(rps, 2),
        "avg_query_ms": query_ms,
        "recommended_pool_size": max(1, recommended),
        "formula": "ceil(throughput_rps * query_s) + 2 de buffer",
    }


def percentile(values, pct):
    if not values:
        return 0.0
    sv = sorted(values)
    idx = max(0, min(len(sv) - 1, int((pct / 100.0) * len(sv) + 0.999999) - 1))
    return round(float(sv[idx]), 2)


# ---------------------------------------------------------------------------
# Orquestacion
# ---------------------------------------------------------------------------

def run_load(variant, requests, pool_size, query_ms, fail_rate):
    pool = build_pool(pool_size)
    worker = worker_leaky if variant == "leaky" else worker_managed
    out = [None] * requests

    started = now_ms()
    threads = [
        threading.Thread(target=worker, args=(i, pool, query_ms, fail_rate, out))
        for i in range(requests)
    ]
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    wall_ms = now_ms() - started

    results = [r for r in out if r]
    counts = {"completed": 0, "failed_query": 0, "failed_timeout": 0, "hung": 0}
    for r in results:
        counts[r["outcome"]] = counts.get(r["outcome"], 0) + 1
    waits = [r["wait_ms"] for r in results]

    with _lock:
        slot = _metrics[variant]
        slot["runs"] += 1
        for k in counts:
            slot[k] += counts[k]
        slot["leaked"] = max(slot["leaked"], pool.leaked())
        slot["wait_samples_ms"].extend(round(w, 2) for w in waits)
        slot["wait_samples_ms"] = slot["wait_samples_ms"][-500:]

    return {
        "variant": variant,
        "requests": requests,
        "pool_size": pool_size,
        "query_ms": query_ms,
        "fail_rate_pct": fail_rate,
        "acquire_timeout_ms": ACQUIRE_TIMEOUT_MS if variant == "managed" else None,
        "completed": counts["completed"],
        "failed_query": counts["failed_query"],
        "failed_timeout": counts["failed_timeout"],
        "hung": counts["hung"],
        "acquired": pool.acquired,
        "released": pool.released,
        "leaked": pool.leaked(),
        "pool_available_after": pool.available(),
        "pool_waiting_peak": pool.waiting_peak,
        "pool_wait_ms_p99": percentile(waits, 99),
        "pool_wait_ms_max": round(max(waits), 2) if waits else 0.0,
        "wall_ms": round(wall_ms, 2),
        "littles_law": littles_law(requests, query_ms, wall_ms),
        "note": (
            "Sin timeout de adquisicion y con release solo en el camino feliz: "
            "cada excepcion se lleva una conexion y el pool se achica en silencio."
            if variant == "leaky"
            else "Context manager con finally garantizado + deadline de adquisicion: "
            "los fallos siguen ocurriendo, pero fallan rapido y devuelven la conexion."
        ),
    }


def pool_state():
    if _pool is None:
        return {"initialized": False, "hint": "Ejecuta /pool-leaky o /pool-managed primero."}
    return {
        "initialized": True,
        "pool_size": _pool.size,
        "available": _pool.available(),
        "acquired_total": _pool.acquired,
        "released_total": _pool.released,
        "leaked": _pool.leaked(),
        "waiting_now": _pool.waiting,
        "waiting_peak": _pool.waiting_peak,
        "acquire_timeout_ms": ACQUIRE_TIMEOUT_MS,
        "leaky_watchdog_ms": LEAKY_WATCHDOG_MS,
    }


def diagnostics():
    with _lock:
        variants = {}
        for name in ("leaky", "managed"):
            s = _metrics[name]
            samples = s["wait_samples_ms"]
            variants[name] = {
                "runs": s["runs"],
                "completed": s["completed"],
                "failed_query": s["failed_query"],
                "failed_timeout": s["failed_timeout"],
                "hung": s["hung"],
                "max_leaked": s["leaked"],
                "avg_wait_ms": round(sum(samples) / len(samples), 2) if samples else 0.0,
                "p99_wait_ms": percentile(samples, 99),
            }
    return {
        "stack": APP_STACK,
        "case": CASE_NAME,
        "variants": variants,
        "pool": pool_state(),
        "interpretation": {
            "leaky": "leaked > 0 y hung > 0: las conexiones perdidas en el camino de excepcion no vuelven, y lo que llega despues espera a algo que ya no existe.",
            "managed": "leaked = 0 siempre. Los fallos de query siguen contandose, pero la conexion vuelve al pool y el que no alcanza recibe un timeout rapido en vez de colgarse.",
            "python_note": "queue.Queue COMO pool y @contextmanager para la devolucion: la stdlib trae la estructura, el finally del generador aporta la disciplina.",
        },
    }


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
        global _metrics
        parsed = urlparse(self.path)
        uri = parsed.path or "/"
        query = parse_qs(parsed.query)
        status = 200

        requests_n = clamp_int(query_int(query, "requests", 24), 1, 200)
        pool_size = clamp_int(query_int(query, "pool", 4), 1, 64)
        query_ms = clamp_int(query_int(query, "query_ms", 25), 1, 500)
        fail_rate = clamp_int(query_int(query, "fail_rate", 25), 0, 100)

        if uri in ("/", "/index"):
            payload = {
                "lab": "Problem-Driven Systems Lab",
                "case": CASE_NAME,
                "stack": APP_STACK,
                "goal": "Mostrar como un pool chico sin timeout de adquisicion y con fugas en el camino de excepcion deja de dar conexiones para siempre.",
                "python_specific": "queue.Queue(maxsize=N) COMO pool + @contextmanager para que el release corra en todos los caminos de salida.",
                "routes": {
                    "/health": "Estado basico del servicio.",
                    "/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25": "Sin deadline y con fuga en excepciones.",
                    "/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25": "Con deadline de adquisicion y devolucion garantizada.",
                    "/pool/state": "Tamaño, disponibles, adquiridas, devueltas y fugadas.",
                    "/diagnostics/summary": "Comparativa entre variantes + ley de Little.",
                    "/reset-lab": "Reconstruye el pool y limpia contadores.",
                },
            }
        elif uri == "/health":
            payload = {"status": "ok", "stack": APP_STACK, "case": CASE_NAME}
        elif uri == "/pool-leaky":
            payload = run_load("leaky", requests_n, pool_size, query_ms, fail_rate)
        elif uri == "/pool-managed":
            payload = run_load("managed", requests_n, pool_size, query_ms, fail_rate)
        elif uri == "/pool/state":
            payload = pool_state()
        elif uri == "/diagnostics/summary":
            payload = diagnostics()
        elif uri == "/reset-lab":
            with _lock:
                _metrics = initial_metrics()
            build_pool(pool_size)
            payload = {"status": "reset", "message": "Pool reconstruido y metricas reiniciadas."}
        else:
            status = 404
            payload = {"error": "Ruta no encontrada", "path": uri}

        payload["timestamp_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        payload["pid"] = os.getpid()
        self.send_json(status, payload)


build_pool(4)
PORT = int(os.environ.get("PORT", "8080"))
print(f"Servidor Python escuchando en {PORT}")
HTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
