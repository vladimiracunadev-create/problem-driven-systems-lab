"""Caso 15 — Backpressure en colas de mensajes — stack Python 3.12.

Unbounded: `queue.Queue()` sin `maxsize`. El productor nunca espera, la cola
crece hasta donde de la memoria, y el mensaje mas viejo envejece sin limite.
Bounded: `queue.Queue(maxsize=N)` con una politica explicita de que hacer
cuando esta llena.

Primitiva Python distintiva:
    `queue.Queue(maxsize=N)` y la firma de `put()`. Las tres politicas del caso
    NO son tres estructuras distintas: son tres formas de llamar al mismo metodo.

        put(msg)                    -> bloquea: backpressure hacia el productor
        put_nowait(msg)             -> queue.Full: el llamador decide que hacer
        put(msg, timeout=0.05)      -> espera acotada y despues decide

    Es la API mas explicita del laboratorio en este punto: no hay un modo por
    defecto que "haga algo razonable" a tus espaldas. Si no elegis, no hay
    comportamiento — tenes que escribir cual de las tres queres.

La leccion del caso es que **ninguna de las tres es gratis**. Bloquear frena al
productor, descartar pierde datos, y la DLQ solo mueve el problema a otra cola
que alguien tiene que mirar (eso es el caso 20). Una cola sin limite parece la
cuarta opcion y no lo es: es la primera con el freno roto.
"""

from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse
import json
import os
import queue
import threading
import time

APP_STACK = os.environ.get("APP_STACK", "Python 3.12")
CASE_NAME = "15 - Backpressure en colas de mensajes"

POLICIES = ("block", "drop_oldest", "dead_letter")
# Cada mensaje pesa esto en el modelo. Sirve para traducir queue_depth a algo
# que un lector de dashboards reconozca: bytes, no unidades sueltas.
MSG_BYTES = 2048

_lock = threading.Lock()
_dlq = []          # mensajes que no entraron en la cola
_state = {}        # ultimo estado observado


def initial_metrics():
    def slot():
        return {
            "runs": 0,
            "produced": 0,
            "consumed": 0,
            "dropped": 0,
            "dead_lettered": 0,
            "max_queue_depth": 0,
            "max_oldest_age_ms": 0.0,
            "producer_blocked_ms": 0.0,
        }

    return {"unbounded": slot(), "bounded": slot()}


_metrics = initial_metrics()


def now_ms():
    return time.monotonic() * 1000.0


class Msg:
    __slots__ = ("seq", "enqueued_at")

    def __init__(self, seq):
        self.seq = seq
        self.enqueued_at = now_ms()


class Consumer(threading.Thread):
    """Drena la cola a un mensaje cada `consume_ms`. Es el cuello de botella."""

    def __init__(self, q, consume_ms):
        super().__init__(daemon=True)
        self.q = q
        self.consume_ms = consume_ms
        self.consumed = 0
        self.max_depth = 0
        self.max_oldest_age_ms = 0.0
        self._halt = threading.Event()

    def run(self):
        while not self._halt.is_set():
            try:
                msg = self.q.get(timeout=0.05)
            except queue.Empty:
                continue
            # Se mide ANTES de procesar: la edad del mensaje mas viejo en cola
            # es la latencia real que sufre el consumidor final, y en una cola
            # sin limite crece sin techo aunque el throughput se vea sano.
            age = now_ms() - msg.enqueued_at
            with _lock:
                self.max_oldest_age_ms = max(self.max_oldest_age_ms, age)
                self.max_depth = max(self.max_depth, self.q.qsize())
            time.sleep(self.consume_ms / 1000.0)
            self.consumed += 1
            self.q.task_done()

    def stop(self):
        self._halt.set()


def drain(q, consumer, timeout_ms=8000):
    """Espera a que la cola se vacie o venza el plazo."""
    deadline = now_ms() + timeout_ms
    while q.qsize() > 0 and now_ms() < deadline:
        time.sleep(0.005)
    consumer.stop()
    consumer.join(timeout=1.0)


# ---------------------------------------------------------------------------
# Variante unbounded: Queue() sin maxsize
# ---------------------------------------------------------------------------

def run_unbounded(messages, consume_ms):
    q = queue.Queue()          # <- sin maxsize: el freno no existe
    consumer = Consumer(q, consume_ms)
    consumer.start()

    started = now_ms()
    produced = 0
    peak = 0
    for seq in range(messages):
        # put() sobre una Queue sin maxsize NUNCA bloquea. El productor no
        # tiene forma de enterarse de que el consumidor no da abasto.
        q.put(Msg(seq))
        produced += 1
        peak = max(peak, q.qsize())

    depth_at_end = q.qsize()
    drain(q, consumer)
    wall_ms = now_ms() - started

    return {
        "variant": "unbounded",
        "policy": None,
        "capacity": None,
        "produced": produced,
        "consumed": consumer.consumed,
        "dropped": 0,
        "dead_lettered": 0,
        "queue_depth_peak": peak,
        "queue_depth_at_end_of_production": depth_at_end,
        "queue_bytes_peak": peak * MSG_BYTES,
        "oldest_msg_age_ms_peak": round(consumer.max_oldest_age_ms, 2),
        "producer_blocked_ms": 0.0,
        "backpressure_signals": 0,
        "wall_ms": round(wall_ms, 2),
        "throughput_msg_s": round(produced / (wall_ms / 1000.0), 2) if wall_ms > 0 else 0.0,
        "note": "Queue() sin maxsize: el productor nunca espera y la cola crece hasta donde de la memoria. "
                "El throughput se ve sano mientras la latencia del mensaje mas viejo sube sin techo.",
    }


# ---------------------------------------------------------------------------
# Variante bounded: Queue(maxsize=N) + politica explicita
# ---------------------------------------------------------------------------

def run_bounded(messages, capacity, policy, consume_ms):
    q = queue.Queue(maxsize=capacity)
    consumer = Consumer(q, consume_ms)
    consumer.start()

    started = now_ms()
    produced = dropped = dead = signals = 0
    blocked_ms = 0.0
    peak = 0

    for seq in range(messages):
        msg = Msg(seq)
        if policy == "block":
            # La cola llena ES la señal de backpressure. El productor se frena
            # solo, sin protocolo extra: es el mecanismo, no un efecto lateral.
            t0 = now_ms()
            q.put(msg)
            waited = now_ms() - t0
            if waited > 0.5:
                signals += 1
                blocked_ms += waited
            produced += 1
        else:
            try:
                q.put_nowait(msg)
                produced += 1
            except queue.Full:
                signals += 1
                if policy == "drop_oldest":
                    # Se sacrifica el mas viejo para que entre el mas nuevo.
                    # Tiene sentido para telemetria, no para pagos.
                    try:
                        q.get_nowait()
                        dropped += 1
                        q.put_nowait(msg)
                        produced += 1
                    except (queue.Empty, queue.Full):
                        dropped += 1
                else:  # dead_letter
                    with _lock:
                        _dlq.append({
                            "seq": msg.seq,
                            "reason": "queue_full",
                            "at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                        })
                        del _dlq[:-200]
                    dead += 1
        peak = max(peak, q.qsize())

    depth_at_end = q.qsize()
    drain(q, consumer)
    wall_ms = now_ms() - started

    notes = {
        "block": "put() bloqueante: la cola llena ES la señal de backpressure. Nada se pierde, pero el productor "
                 "se frena — y esa lentitud viaja aguas arriba hasta el cliente.",
        "drop_oldest": "put_nowait() + descarte del mas viejo: el productor nunca se frena, pero se pierden datos "
                       "en silencio. Aceptable para telemetria, inaceptable para pagos.",
        "dead_letter": "put_nowait() + DLQ: no se frena ni se pierde, pero el problema se muda a otra cola que "
                       "alguien tiene que mirar. Si nadie la mira, es el caso 20.",
    }

    return {
        "variant": "bounded",
        "policy": policy,
        "capacity": capacity,
        "produced": produced,
        "consumed": consumer.consumed,
        "dropped": dropped,
        "dead_lettered": dead,
        "queue_depth_peak": peak,
        "queue_depth_at_end_of_production": depth_at_end,
        "queue_bytes_peak": peak * MSG_BYTES,
        "oldest_msg_age_ms_peak": round(consumer.max_oldest_age_ms, 2),
        "producer_blocked_ms": round(blocked_ms, 2),
        "backpressure_signals": signals,
        "wall_ms": round(wall_ms, 2),
        "throughput_msg_s": round(produced / (wall_ms / 1000.0), 2) if wall_ms > 0 else 0.0,
        "note": notes[policy],
    }


def record(variant, result):
    with _lock:
        s = _metrics[variant]
        s["runs"] += 1
        s["produced"] += result["produced"]
        s["consumed"] += result["consumed"]
        s["dropped"] += result["dropped"]
        s["dead_lettered"] += result["dead_lettered"]
        s["max_queue_depth"] = max(s["max_queue_depth"], result["queue_depth_peak"])
        s["max_oldest_age_ms"] = max(s["max_oldest_age_ms"], result["oldest_msg_age_ms_peak"])
        s["producer_blocked_ms"] += result["producer_blocked_ms"]
        _state.clear()
        _state.update({
            "last_variant": variant,
            "last_policy": result["policy"],
            "capacity": result["capacity"],
            "queue_depth_peak": result["queue_depth_peak"],
            "queue_bytes_peak": result["queue_bytes_peak"],
            "oldest_msg_age_ms_peak": result["oldest_msg_age_ms_peak"],
        })


def queue_state():
    with _lock:
        st = dict(_state)
        dlq_depth = len(_dlq)
    st.update({
        "dlq_depth": dlq_depth,
        "msg_bytes": MSG_BYTES,
        "policies": list(POLICIES),
        "note": "queue_depth_peak x msg_bytes es lo que la cola llego a ocupar. Sin maxsize, ese numero no tiene techo.",
    })
    return st


def dlq_view(limit):
    with _lock:
        items = list(reversed(_dlq))[:limit]
        depth = len(_dlq)
    return {
        "dlq_depth": depth,
        "limit": limit,
        "messages": items,
        "note": "La DLQ no resuelve el backpressure: lo muda. El caso 20 trata que pasa cuando nadie la mira.",
    }


def diagnostics():
    with _lock:
        variants = {k: dict(v) for k, v in _metrics.items()}
        for v in variants.values():
            v["max_oldest_age_ms"] = round(v["max_oldest_age_ms"], 2)
            v["producer_blocked_ms"] = round(v["producer_blocked_ms"], 2)
        dlq_depth = len(_dlq)
    return {
        "stack": APP_STACK,
        "case": CASE_NAME,
        "variants": variants,
        "dlq_depth": dlq_depth,
        "interpretation": {
            "unbounded": "producer_blocked_ms = 0 y dropped = 0 se ven bien hasta que se mira queue_depth_peak y "
                         "oldest_msg_age_ms_peak: la cola absorbio todo y el mensaje mas viejo espero por todos.",
            "bounded": "Las tres politicas pagan algo distinto: block paga latencia del productor, drop_oldest paga "
                       "datos, dead_letter paga deuda operativa. No hay una cuarta opcion gratis.",
            "python_note": "queue.Queue(maxsize=N) obliga a elegir: put() bloquea, put_nowait() levanta Full, "
                           "put(timeout=) espera acotado. No hay un default que decida por vos.",
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
        q = parse_qs(parsed.query)
        status = 200

        messages = clamp_int(query_int(q, "messages", 120), 1, 2000)
        capacity = clamp_int(query_int(q, "capacity", 32), 1, 1000)
        consume_ms = clamp_int(query_int(q, "consume_ms", 2), 0, 100)
        policy = q.get("policy", ["block"])[0] or "block"
        if policy not in POLICIES:
            policy = "block"
        limit = clamp_int(query_int(q, "limit", 20), 1, 200)

        if uri in ("/", "/index"):
            payload = {
                "lab": "Problem-Driven Systems Lab",
                "case": CASE_NAME,
                "stack": APP_STACK,
                "goal": "Mostrar que una cola sin limite no es la opcion sin costo: es la opcion con el freno roto.",
                "python_specific": "queue.Queue(maxsize=N) obliga a elegir politica en la firma de put(): bloquear, "
                                   "levantar Full, o esperar acotado.",
                "routes": {
                    "/health": "Estado basico del servicio.",
                    "/produce-unbounded?messages=120&consume_ms=2": "Cola sin limite.",
                    "/produce-bounded?messages=120&capacity=32&policy=block&consume_ms=2": "Cola acotada con backpressure al productor.",
                    "/produce-bounded?messages=120&capacity=32&policy=drop_oldest": "Cola acotada, se descarta el mas viejo.",
                    "/produce-bounded?messages=120&capacity=32&policy=dead_letter": "Cola acotada, lo que no entra va a la DLQ.",
                    "/queue/state": "Profundidad pico, bytes y edad del mensaje mas viejo.",
                    "/dlq?limit=20": "Contenido de la dead letter queue.",
                    "/diagnostics/summary": "Comparativa entre variantes y politicas.",
                    "/reset-lab": "Limpia DLQ y contadores.",
                },
                "allowed_policies": list(POLICIES),
            }
        elif uri == "/health":
            payload = {"status": "ok", "stack": APP_STACK, "case": CASE_NAME}
        elif uri == "/produce-unbounded":
            payload = run_unbounded(messages, consume_ms)
            record("unbounded", payload)
        elif uri == "/produce-bounded":
            payload = run_bounded(messages, capacity, policy, consume_ms)
            record("bounded", payload)
        elif uri == "/queue/state":
            payload = queue_state()
        elif uri == "/dlq":
            payload = dlq_view(limit)
        elif uri == "/diagnostics/summary":
            payload = diagnostics()
        elif uri == "/reset-lab":
            with _lock:
                _dlq.clear()
                _metrics = initial_metrics()
                _state.clear()
            payload = {"status": "reset", "message": "DLQ y metricas reiniciadas."}
        else:
            status = 404
            payload = {"error": "Ruta no encontrada", "path": uri}

        payload["timestamp_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        payload["pid"] = os.getpid()
        self.send_json(status, payload)


PORT = int(os.environ.get("PORT", "8080"))
print(f"Servidor Python escuchando en {PORT}")
HTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
