"""Caso 18 — Arranque en frio y retraso del autoescalado — stack Python 3.12.

Frio: el autoescalador levanta instancias nuevas cuando el trafico ya subio. La
instancia arranca, el proceso queda vivo al instante — y el balanceador, que
mira `/health`, le empieza a mandar trafico ANTES de que termine de
inicializarse. Ese hueco entre "vivo" y "listo" es donde viven los 503.

Templado: hay un pool tibio ya inicializado y ya ejercitado, y el balanceador
enruta por `/ready`, no por `/health`. Ninguna peticion cae en una instancia que
todavia esta levantandose.

Que es real y que esta modelado — importa, porque este caso mide el runtime:

    **La curva de calentamiento se MIDE, no se simula.** El trabajo por peticion
    es un lazo entero puro, identico en los siete stacks, sin `sleep` de ninguna
    clase. `p99_first_100_ms` contra `p99_after_1000_ms` es lo que ese runtime
    hace de verdad con el mismo codigo repetido.

    **La inicializacion esta partida en dos.** La parte de CPU (construir la
    tabla de configuracion) es trabajo real. La parte de I/O —abrir conexiones,
    DNS, TLS— es un `sleep` de `io_ms`, porque esperar a la red no quema CPU y
    porque tenerlo fijo es lo que hace comparables a los siete.

Primitiva Python distintiva:

    **Python no tiene JIT.** Ni tiered compilation, ni OSR, ni deoptimizacion.
    CPython compila a bytecode una vez y lo interpreta siempre igual. Es la
    unica familia del laboratorio, junto a PHP, donde `p99_first_100_ms` y
    `p99_after_1000_ms` salen practicamente iguales — y eso es a la vez su
    virtud y su techo: no hay calentamiento porque no hay nada que calentar.

    Lo que si cuesta en Python es el ARRANQUE: `import` compila a `.pyc`,
    ejecuta el modulo entero y resuelve el arbol de dependencias. Un Django con
    200 modulos tarda segundos, y no hay artefacto compilado al que escapar.
"""

from http.server import BaseHTTPRequestHandler, HTTPServer
from socketserver import ThreadingMixIn
from urllib.parse import parse_qs, urlparse
import json
import os
import threading
import time

APP_STACK = os.environ.get("APP_STACK", "Python 3.12")
CASE_NAME = "18 - Arranque en frio y retraso del autoescalado"

WORK_ITERS = 3000        # calibrado para ~0.3 ms por peticion en este runtime
INIT_TABLE_ROWS = 20000  # parte de CPU de la inicializacion: trabajo real


class Instance:
    """Una instancia del servicio. Vive apenas arranca; esta lista mucho despues."""

    def __init__(self, iid):
        self.id = iid
        self.live = True          # el proceso arranco: /health responde 200 YA
        self.ready = False        # todavia no: falta inicializar
        self.live_at = now_ms()
        self.ready_at = None
        self.served = 0
        self.table = None

    def boot(self, io_ms):
        """Inicializacion: CPU real (tabla) + I/O modelada (conexiones)."""
        # Parte de CPU: construir la tabla de configuracion. Trabajo de verdad.
        table = [0] * 256
        h = 2166136261
        for i in range(INIT_TABLE_ROWS):
            h = (h ^ i) * 16777619 & 0xFFFFFFFF
            table[h & 0xFF] = h
        # Parte de I/O: abrir el pool, resolver DNS, negociar TLS. Esperar a la
        # red no quema CPU, asi que va como sleep — y fijo, para que los siete
        # stacks sean comparables.
        time.sleep(io_ms / 1000.0)
        self.table = table
        self.ready_at = now_ms()
        self.ready = True

    def gap_ms(self):
        if self.ready_at is None:
            return round(now_ms() - self.live_at, 2)
        return round(self.ready_at - self.live_at, 2)


def now_ms():
    return time.monotonic() * 1000.0


def work(iters):
    """Trabajo por peticion: lazo entero puro, sin sleep, sin I/O.

    Es identico en los siete stacks. Lo que cambia entre ellos es lo que el
    runtime hace con el mismo codigo repetido mil veces — que es exactamente
    lo que este caso mide.
    """
    h = 2166136261
    for i in range(iters):
        h = (h ^ i) * 16777619 & 0xFFFFFFFF
    return h


_fleet_lock = threading.Lock()
_fleet = []          # instancias del run en curso
_warm_pool = []      # pool tibio: ya inicializado y ya ejercitado
_metrics = {}


def initial_metrics():
    def slot():
        return {
            "runs": 0,
            "served": 0,
            "rejected_cold_start": 0,
            "cold_starts": 0,
            "max_ready_at_ms": 0.0,
        }

    return {"cold": slot(), "warmed": slot()}


_metrics = initial_metrics()


def percentile(values, pct):
    if not values:
        return 0.0
    sv = sorted(values)
    idx = max(0, min(len(sv) - 1, int((pct / 100.0) * len(sv) + 0.999999) - 1))
    return round(float(sv[idx]), 3)


# ---------------------------------------------------------------------------
# El pool tibio: instancias ya inicializadas Y ya ejercitadas
# ---------------------------------------------------------------------------

def build_warm_pool(instances, io_ms, prime, work_iters):
    """Levanta el pool antes de que llegue el trafico, y lo ejercita.

    Las dos mitades importan. Inicializar deja la instancia LISTA. Ejercitarla
    deja al RUNTIME listo — en los stacks que tienen JIT, esa segunda mitad es
    la que aplana la curva. En Python no cambia nada, y eso tambien se mide.
    """
    global _warm_pool
    t0 = now_ms()
    pool = [Instance(f"warm-{i}") for i in range(instances)]
    threads = [threading.Thread(target=inst.boot, args=(io_ms,)) for inst in pool]
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    init_ms = now_ms() - t0

    # Ejercitar: cruzar el umbral de calentamiento del runtime, si lo tiene.
    for _ in range(prime):
        work(work_iters)
    for inst in pool:
        inst.served += prime // max(1, instances)

    with _fleet_lock:
        _warm_pool = pool
    return {
        "warm_pool_size": len(pool),
        "init_ms": round(init_ms, 2),
        "prime_requests": prime,
        "warmup_duration_ms": round(now_ms() - t0, 2),
    }


# ---------------------------------------------------------------------------
# El balanceador: la diferencia entre mirar /health y mirar /ready
# ---------------------------------------------------------------------------

def pick(fleet, by_readiness, counter):
    """Round-robin. `by_readiness=False` es el balanceador ingenuo: enruta a
    cualquier instancia VIVA, aunque no este LISTA. Ahi nacen los 503."""
    n = len(fleet)
    for k in range(n):
        inst = fleet[(counter + k) % n]
        if by_readiness:
            if inst.ready:
                return inst
        else:
            if inst.live:
                return inst
    return None


def client(idx, clients, requests, fleet, by_readiness, pace_ms, work_iters, out, gate):
    gate.wait()
    served = rejected = 0
    mine = requests // clients + (1 if idx < requests % clients else 0)
    for k in range(mine):
        inst = pick(fleet, by_readiness, idx + k)
        t0 = now_ms()
        if inst is None or not inst.ready:
            # El proceso esta vivo, el healthcheck da verde, y la peticion se
            # cae igual. No hay alerta que dispare: nada esta "caido".
            rejected += 1
        else:
            work(work_iters)
            inst.served += 1
            out.append(now_ms() - t0)
            served += 1
        if pace_ms:
            time.sleep(pace_ms / 1000.0)
    return served, rejected


def run_scenario(variant, requests, instances, clients, io_ms, pace_ms, work_iters, prime):
    global _fleet
    warm_info = {}

    if variant == "cold":
        # El autoescalador reacciona tarde: las instancias arrancan CON el
        # trafico encima, no antes.
        fleet = [Instance(f"cold-{i}") for i in range(instances)]
        with _fleet_lock:
            _fleet = fleet
        boots = [threading.Thread(target=inst.boot, args=(io_ms,)) for inst in fleet]
        for t in boots:
            t.start()
        by_readiness = False   # el balanceador ingenuo mira /health
        cold_starts = instances
    else:
        with _fleet_lock:
            pool = list(_warm_pool)
        if len(pool) < instances:
            warm_info = build_warm_pool(instances, io_ms, prime, work_iters)
            with _fleet_lock:
                pool = list(_warm_pool)
        fleet = pool[:instances]
        with _fleet_lock:
            _fleet = fleet
        boots = []
        by_readiness = True    # el balanceador correcto mira /ready
        cold_starts = 0

    latencies = []
    lat_lock = threading.Lock()

    class Collector(list):
        def append(self, v):
            with lat_lock:
                list.append(self, v)

    ordered = Collector()
    results = [None] * clients
    gate = threading.Barrier(clients)

    def runner(i):
        results[i] = client(i, clients, requests, fleet, by_readiness, pace_ms, work_iters, ordered, gate)

    t0 = now_ms()
    threads = [threading.Thread(target=runner, args=(i,)) for i in range(clients)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    for t in boots:
        t.join()
    wall = now_ms() - t0

    served = sum(r[0] for r in results if r)
    rejected = sum(r[1] for r in results if r)
    latencies = list(ordered)

    first_100 = latencies[:100]
    after_1000 = latencies[1000:]
    if not after_1000:
        after_1000 = latencies[-100:]
    p99_first = percentile(first_100, 99)
    p99_after = percentile(after_1000, 99)

    ready_at = max((i.gap_ms() for i in fleet), default=0.0)

    with _fleet_lock:
        slot = _metrics[variant]
        slot["runs"] += 1
        slot["served"] += served
        slot["rejected_cold_start"] += rejected
        slot["cold_starts"] += cold_starts
        slot["max_ready_at_ms"] = max(slot["max_ready_at_ms"], ready_at)
        warm_size = len(_warm_pool)

    payload = {
        "variant": variant,
        "instances": instances,
        "requests": requests,
        "clients": clients,
        "lb_routes_by": "liveness (/health)" if not by_readiness else "readiness (/ready)",
        "cold_start_count": cold_starts,
        "warm_pool_size": warm_size,
        "ready_at_ms": round(ready_at, 2),
        "health_vs_ready_gap_ms": round(ready_at, 2) if cold_starts else 0.0,
        "first_response_ms": round(latencies[0], 3) if latencies else 0.0,
        "p99_first_100_ms": p99_first,
        "p99_after_1000_ms": p99_after,
        "warmup_speedup_x": round(p99_first / p99_after, 2) if p99_after > 0 else 1.0,
        "p50_ms": percentile(latencies, 50),
        "served": served,
        "rejected_cold_start": rejected,
        "availability_pct": round(served / max(1, served + rejected) * 100, 2),
        "work_iters": work_iters,
        "io_ms": io_ms,
        "pace_ms": pace_ms,
        "wall_ms": round(wall, 2),
    }
    if warm_info:
        payload["warm_pool_built_now"] = warm_info
    payload["note"] = (
        "El proceso esta vivo desde el milisegundo cero y /health lo confirma, pero la instancia no sirve nada "
        "hasta que termina de inicializar. El balanceador que enruta por liveness manda trafico a ese hueco: "
        "los 503 salen de una instancia que ninguna alerta considera caida."
        if variant == "cold"
        else "El pool ya estaba inicializado y ya ejercitado, y el balanceador enruta por readiness. Ninguna "
        "peticion cae en una instancia a medio levantar: 0 rechazos y la latencia parte donde la otra variante "
        "recien termina."
    )
    payload["python_note"] = (
        "Python no tiene JIT: p99_first_100_ms y p99_after_1000_ms salen casi iguales, asi que warmup_speedup_x "
        "ronda 1.0. No hay calentamiento porque no hay nada que calentar — el costo de Python esta en el import, "
        "no en la centesima peticion."
    )
    return payload


def ready_state():
    with _fleet_lock:
        fleet = list(_fleet)
        warm = len(_warm_pool)
    instances = [
        {
            "id": i.id,
            "live": i.live,
            "ready": i.ready,
            "ready_at_ms": i.gap_ms(),
            "requests_served": i.served,
        }
        for i in fleet
    ]
    all_ready = all(i["ready"] for i in instances) if instances else False
    return {
        "ready": all_ready,
        "instances": instances,
        "warm_pool_size": warm,
        "note": "`/health` responde 200 apenas el proceso arranca. `/ready` responde 200 recien cuando la "
                "instancia puede servir. Si el balanceador mira la primera en vez de la segunda, el hueco entre "
                "las dos es tiempo de caida que nadie registra como caida.",
    }


def diagnostics():
    with _fleet_lock:
        variants = {}
        for name in ("cold", "warmed"):
            s = _metrics[name]
            variants[name] = {
                "runs": s["runs"],
                "served": s["served"],
                "rejected_cold_start": s["rejected_cold_start"],
                "cold_starts": s["cold_starts"],
                "max_ready_at_ms": round(s["max_ready_at_ms"], 2),
            }
    return {
        "stack": APP_STACK,
        "case": CASE_NAME,
        "variants": variants,
        "fleet": ready_state(),
        "fidelity": {
            "medido": "La curva de calentamiento. El trabajo por peticion es un lazo entero puro sin sleep, "
                      "identico en los 7 stacks; p99_first_100_ms vs p99_after_1000_ms es lo que ese runtime hace "
                      "de verdad.",
            "modelado": "La parte de I/O de la inicializacion (abrir pool, DNS, TLS) es un sleep de io_ms: esperar "
                        "a la red no quema CPU, y fijarlo es lo que hace comparables a los 7 stacks.",
            "real": "La parte de CPU de la inicializacion construye una tabla de 20.000 entradas. Eso si es "
                    "trabajo, y su costo depende del runtime.",
        },
        "interpretation": {
            "cold": "rejected_cold_start > 0 con el proceso vivo todo el tiempo. health_vs_ready_gap_ms es la "
                    "ventana exacta en la que el balanceador mando trafico a una instancia que no podia servirlo.",
            "warmed": "rejected_cold_start = 0. El pool ya estaba, y el balanceador enruta por readiness.",
            "python_note": "warmup_speedup_x cerca de 1.0 es la firma de un runtime sin JIT. En Java, .NET y Node "
                           "ese numero es visiblemente mayor que 1: el mismo codigo se vuelve mas rapido solo por "
                           "repetirse.",
        },
    }


def clamp_int(v, lo, hi):
    return max(lo, min(hi, v))


def query_int(q, key, default):
    vals = q.get(key, [])
    if not vals:
        return default
    try:
        return int(vals[0])
    except ValueError:
        return default


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

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
        global _metrics, _fleet, _warm_pool
        parsed = urlparse(self.path)
        uri = parsed.path or "/"
        q = parse_qs(parsed.query)
        status = 200

        requests = clamp_int(query_int(q, "requests", 2400), 100, 20000)
        instances = clamp_int(query_int(q, "instances", 3), 1, 32)
        clients = clamp_int(query_int(q, "clients", 8), 1, 64)
        io_ms = clamp_int(query_int(q, "io_ms", 150), 0, 5000)
        pace_ms = clamp_int(query_int(q, "pace_ms", 1), 0, 100)
        work_iters = clamp_int(query_int(q, "work_iters", WORK_ITERS), 100, 5000000)
        prime = clamp_int(query_int(q, "prime", 1500), 0, 100000)

        if uri in ("/", "/index"):
            payload = {
                "lab": "Problem-Driven Systems Lab",
                "case": CASE_NAME,
                "stack": APP_STACK,
                "goal": "Mostrar que el hueco entre 'el proceso esta vivo' y 'la instancia puede servir' es tiempo "
                        "de caida real que ningun healthcheck registra como caida.",
                "python_specific": "Python no tiene JIT: la curva de calentamiento es plana. El costo de arranque "
                                   "esta en el import, no en la centesima peticion.",
                "routes": {
                    "/health": "Liveness: responde 200 apenas el proceso arranca.",
                    "/ready": "Readiness: responde 200 recien cuando la instancia puede servir.",
                    "/boot-cold?requests=2400&instances=3": "Instancias frias con el trafico ya encima.",
                    "/boot-warmed?requests=2400&instances=3": "Pool tibio y balanceador que mira readiness.",
                    "/warmup?instances=3&prime=1500": "Construye el pool tibio antes de que llegue el trafico.",
                    "/diagnostics/summary": "Comparativa entre variantes.",
                    "/reset-lab": "Vacia la flota, el pool tibio y las metricas.",
                },
            }
        elif uri == "/health":
            payload = {"status": "ok", "stack": APP_STACK, "case": CASE_NAME,
                       "note": "Liveness. Esto responde 200 aunque la instancia no pueda servir una sola peticion."}
        elif uri == "/ready":
            payload = ready_state()
        elif uri == "/boot-cold":
            payload = run_scenario("cold", requests, instances, clients, io_ms, pace_ms, work_iters, prime)
        elif uri == "/boot-warmed":
            payload = run_scenario("warmed", requests, instances, clients, io_ms, pace_ms, work_iters, prime)
        elif uri == "/warmup":
            payload = build_warm_pool(instances, io_ms, prime, work_iters)
            payload["status"] = "warm"
            payload["note"] = "Inicializar deja la instancia lista. Ejercitarla deja al runtime listo. Las dos " \
                              "mitades hacen falta, y solo la segunda depende del lenguaje."
        elif uri == "/diagnostics/summary":
            payload = diagnostics()
        elif uri == "/reset-lab":
            with _fleet_lock:
                _fleet = []
                _warm_pool = []
                _metrics = initial_metrics()
            payload = {"status": "reset", "message": "Flota, pool tibio y metricas reiniciados."}
        else:
            status = 404
            payload = {"error": "Ruta no encontrada", "path": uri}

        payload["timestamp_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        payload["pid"] = os.getpid()
        self.send_json(status, payload)


class ThreadingHTTPServer(ThreadingMixIn, HTTPServer):
    daemon_threads = True


PORT = int(os.environ.get("PORT", "8080"))
print(f"Servidor Python escuchando en {PORT}")
ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
