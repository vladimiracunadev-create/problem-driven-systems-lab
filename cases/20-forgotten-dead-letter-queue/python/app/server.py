"""Caso 20 — La dead letter queue olvidada — stack Python 3.12.

Cierra el arco que abrio el [caso 15](../../15-message-queue-backpressure/): alli
la DLQ **nace**, como la politica de rechazo que salva al productor de bloquearse.
Aca se ve que pasa cuando nadie vuelve a mirarla.

Silencioso: el consumidor falla, manda el mensaje a la DLQ y sigue. Sin
clasificar el error, sin reintentar, sin medir la profundidad, sin alerta. La
cola crece durante meses y **el pipeline se ve perfectamente sano**: throughput
normal, latencia normal, cero errores — porque los errores se fueron a otro lado.

Observado: el error se **clasifica** antes de decidir. Lo transitorio se
reintenta con backoff y casi todo se recupera; lo venenoso va a la DLQ **con su
clase de error y una muestra del payload**; la profundidad y la antiguedad del
mensaje mas viejo se publican; y hay un umbral que dispara alerta.

La distincion que ordena el caso:

    transitorio  — el mismo mensaje funciona en el proximo intento
                   (timeout, 503 del downstream, deadlock de la base)
    venenoso     — el mismo mensaje NUNCA va a funcionar
                   (schema roto, campo desconocido, encoding invalido)

    **Reintentar lo venenoso es quemar CPU. Mandar lo transitorio a la DLQ es
    tirar trabajo que se podia salvar.** El consumidor que no distingue hace las
    dos cosas mal a la vez.

Primitiva Python distintiva — y aqui el stack tiene una virtud y un peligro:

    La jerarquia de excepciones expresa la clasificacion sin ceremonia:

        class ErrorTransitorio(Exception): ...
        class ErrorVenenoso(Exception): ...

        try:  procesar(msg)
        except ErrorTransitorio: reintentar()
        except ErrorVenenoso as e: a_dlq(msg, clase=type(e).__name__)

    **El peligro es `except Exception`.** Un `except Exception` alrededor del
    procesamiento manda a la DLQ no solo los errores de datos sino tambien los
    **bugs del propio consumidor**: un `KeyError` por un typo, un
    `AttributeError` de un refactor a medias. Esos mensajes no son venenosos —
    son correctos, y el codigo esta roto. Terminan en la DLQ indistinguibles del
    resto, y cuando alguien la mira meses despues, la conclusion es «datos
    malos» en vez de «tuvimos un bug tres semanas».

    Rust no tiene esa ambiguedad porque un panic no es un `Result`.
"""

from http.server import BaseHTTPRequestHandler, HTTPServer
from socketserver import ThreadingMixIn
from urllib.parse import parse_qs, urlparse
import json
import os
import threading
import time

APP_STACK = os.environ.get("APP_STACK", "Python 3.12")
CASE_NAME = "20 - La dead letter queue olvidada"

POISON_CLASSES = ("schema_mismatch", "unknown_field", "null_required", "invalid_encoding")


class ErrorTransitorio(Exception):
    """El mismo mensaje funciona en el proximo intento."""


class ErrorVenenoso(Exception):
    """El mismo mensaje NUNCA va a funcionar."""

    def __init__(self, clase):
        super().__init__(clase)
        self.clase = clase


_lock = threading.Lock()
_dlq = []          # [{"id","error_class","attempts","first_seen_ms","sample"}]
_alerts_fired = 0
_metrics = {}


def initial_metrics():
    def slot():
        return {"runs": 0, "consumed": 0, "succeeded": 0, "retried": 0,
                "dead_lettered": 0, "alerts_fired": 0}
    return {"silent": slot(), "observed": slot()}


_metrics = initial_metrics()


def now_ms():
    return time.monotonic() * 1000.0


def reset_all():
    global _alerts_fired
    with _lock:
        _dlq.clear()
        _alerts_fired = 0


def procesar(idx, transient_pct, poison_pct, attempt):
    """Procesa un mensaje. Lanza transitorio o venenoso segun el mensaje.

    El transitorio falla solo en el primer intento: es la definicion de
    transitorio, y es lo que hace que reintentarlo tenga sentido. El venenoso
    falla siempre, por mas veces que se lo intente.
    """
    if (idx * 53) % 101 < poison_pct:
        raise ErrorVenenoso(POISON_CLASSES[idx % len(POISON_CLASSES)])
    if (idx * 37) % 101 < transient_pct and attempt == 0:
        raise ErrorTransitorio("timeout del downstream")
    return True


# ---------------------------------------------------------------------------
# Variante silenciosa: cualquier fallo va a la DLQ, y nadie vuelve
# ---------------------------------------------------------------------------

def consume_silent(messages, transient_pct, poison_pct, sample_size):
    reset_all()
    consumed = succeeded = dead = 0
    t0 = now_ms()

    for i in range(messages):
        consumed += 1
        try:
            procesar(i, transient_pct, poison_pct, 0)
            succeeded += 1
        except Exception:
            # El bug entero, en tres lineas. No clasifica, no reintenta, no
            # guarda por que fallo. El mensaje se va a la DLQ y el consumidor
            # sigue con el siguiente como si nada hubiera pasado.
            with _lock:
                _dlq.append({"id": f"msg-{i}", "error_class": "unclassified",
                             "attempts": 1, "first_seen_ms": now_ms(), "sample": None})
            dead += 1

    return {"consumed": consumed, "succeeded": succeeded, "retried": 0,
            "dead_lettered": dead, "alerts_fired": 0, "sampled": 0,
            "wall_ms": round(now_ms() - t0, 2)}


# ---------------------------------------------------------------------------
# Variante observada: clasificar, reintentar, medir, alertar
# ---------------------------------------------------------------------------

def consume_observed(messages, transient_pct, poison_pct, max_retries, alert_threshold, sample_size):
    global _alerts_fired
    reset_all()
    consumed = succeeded = retried = dead = sampled = 0
    t0 = now_ms()

    for i in range(messages):
        consumed += 1
        for attempt in range(max_retries + 1):
            try:
                procesar(i, transient_pct, poison_pct, attempt)
                succeeded += 1
                break
            except ErrorTransitorio:
                # Transitorio: el proximo intento tiene otra suerte. Mandarlo a
                # la DLQ seria tirar trabajo que se podia salvar.
                retried += 1
                if attempt == max_retries:
                    with _lock:
                        _dlq.append({"id": f"msg-{i}", "error_class": "transient_exhausted",
                                     "attempts": attempt + 1, "first_seen_ms": now_ms(),
                                     "sample": None})
                    dead += 1
                continue
            except ErrorVenenoso as e:
                # Venenoso: reintentarlo es quemar CPU. Va a la DLQ ya mismo,
                # con su clase de error y —para los primeros— una muestra del
                # payload, que es lo que despues permite depurarlo.
                with _lock:
                    muestra = None
                    if sampled < sample_size:
                        muestra = {"idx": i, "payload": f"{{\"id\": {i}, \"campo\": \"...\"}}"}
                    _dlq.append({"id": f"msg-{i}", "error_class": e.clase,
                                 "attempts": attempt + 1, "first_seen_ms": now_ms(),
                                 "sample": muestra})
                    if muestra:
                        sampled += 1
                dead += 1
                break

    alerts = 0
    with _lock:
        if len(_dlq) > alert_threshold:
            _alerts_fired += 1
            alerts = 1

    return {"consumed": consumed, "succeeded": succeeded, "retried": retried,
            "dead_lettered": dead, "alerts_fired": alerts, "sampled": sampled,
            "wall_ms": round(now_ms() - t0, 2)}


# ---------------------------------------------------------------------------
# La DLQ como cola observable, no como agujero
# ---------------------------------------------------------------------------

def dlq_stats(alert_threshold):
    with _lock:
        por_clase = {}
        for m in _dlq:
            por_clase[m["error_class"]] = por_clase.get(m["error_class"], 0) + 1
        oldest = 0.0
        if _dlq:
            now = now_ms()
            oldest = max(now - m["first_seen_ms"] for m in _dlq)
        muestras = [m["sample"] for m in _dlq if m["sample"]][:5]
        depth = len(_dlq)
        alertas = _alerts_fired

    return {
        "dlq_depth": depth,
        "dlq_oldest_msg_age_ms": round(oldest, 2),
        "by_error_class": dict(sorted(por_clase.items())),
        "alert_threshold": alert_threshold,
        "over_threshold": depth > alert_threshold,
        "alerts_fired": alertas,
        "samples": muestras,
        "note": "Una DLQ sin profundidad publicada, sin antiguedad del mensaje mas viejo y sin desglose por clase "
                "de error no es una cola: es un agujero. `by_error_class` es lo que convierte «hay 4.000 mensajes» "
                "en «hay un bug de schema y tres timeouts».",
    }


def dlq_drain(limit, transient_pct, poison_pct, max_retries):
    """Replay desde la DLQ. Lo que se recupera vuelve; lo venenoso sigue ahi.

    El drenaje es la mitad que casi nunca se construye. Una DLQ que solo recibe
    es un cementerio; una DLQ de la que se puede volver es un buffer.
    """
    t0 = now_ms()
    with _lock:
        lote = _dlq[:limit]
        resto = _dlq[limit:]

    ok = fallo = 0
    quedan = []
    for m in lote:
        idx = int(m["id"].split("-")[1])
        recuperado = False
        for attempt in range(1, max_retries + 1):
            try:
                procesar(idx, transient_pct, poison_pct, attempt)
                recuperado = True
                break
            except ErrorTransitorio:
                continue
            except ErrorVenenoso:
                break
        if recuperado:
            ok += 1
        else:
            fallo += 1
            m["attempts"] += max_retries
            quedan.append(m)

    with _lock:
        _dlq[:] = quedan + resto

    return {
        "drain_limit": limit,
        "drained_ok": ok,
        "drain_failed": fallo,
        "recovered_pct": round(ok * 100 / max(1, ok + fallo), 2),
        "drain_duration_ms": round(now_ms() - t0, 2),
        "dlq_depth_after": len(quedan) + len(resto),
        "note": "Lo que se recupera en el replay es exactamente lo que nunca deberia haber estado aca: errores "
                "transitorios que un reintento habria resuelto. Lo que sigue fallando es veneno de verdad, y "
                "necesita un cambio de codigo o de datos — no otro reintento.",
    }


def run_scenario(variant, messages, transient_pct, poison_pct, max_retries, alert_threshold, sample_size):
    if variant == "silent":
        r = consume_silent(messages, transient_pct, poison_pct, sample_size)
    else:
        r = consume_observed(messages, transient_pct, poison_pct, max_retries, alert_threshold, sample_size)

    stats = dlq_stats(alert_threshold)

    with _lock:
        slot = _metrics[variant]
        slot["runs"] += 1
        for k in ("consumed", "succeeded", "retried", "dead_lettered", "alerts_fired"):
            slot[k] += r[k]

    payload = {"variant": variant, "messages": messages, "transient_pct": transient_pct,
               "poison_pct": poison_pct, "max_retries": max_retries if variant == "observed" else 0}
    payload.update(r)
    payload.update({k: stats[k] for k in ("dlq_depth", "dlq_oldest_msg_age_ms", "by_error_class",
                                          "alert_threshold", "over_threshold")})
    payload["dead_letter_rate_pct"] = round(r["dead_lettered"] * 100 / max(1, r["consumed"]), 2)
    payload["note"] = (
        "El consumidor no clasifico nada: transitorio y venenoso fueron al mismo lugar, sin reintentar y sin "
        "registrar por que. El pipeline se ve sano —throughput normal, cero errores— porque los errores se "
        "fueron a otro lado. Y nadie va a volver."
        if variant == "silent"
        else "Lo transitorio se reintento y casi todo se recupero; solo el veneno llego a la DLQ, con su clase de "
             "error y una muestra del payload. La profundidad esta publicada y el umbral disparo alerta."
    )
    payload["python_note"] = (
        "La jerarquia de excepciones expresa la clasificacion sin ceremonia. El peligro es `except Exception`: "
        "manda a la DLQ no solo los errores de datos sino los bugs del propio consumidor —un KeyError por un typo, "
        "un AttributeError de un refactor— y esos mensajes no son venenosos: son correctos, y el codigo esta roto."
    )
    return payload


def diagnostics(alert_threshold):
    with _lock:
        variants = {k: dict(v) for k, v in _metrics.items()}
    return {
        "stack": APP_STACK,
        "case": CASE_NAME,
        "variants": variants,
        "dlq": dlq_stats(alert_threshold),
        "arco_con_el_caso_15": "En el caso 15 la DLQ NACE: es la politica de rechazo que salva al productor de "
                               "bloquearse cuando la cola se llena. Aca se ve que pasa cuando nadie vuelve a "
                               "mirarla. Los dos casos son el mismo mecanismo en dos momentos distintos.",
        "fidelity": {
            "real": "La clasificacion de errores, el reintento con presupuesto acotado, el desglose por clase, el "
                    "muestreo de payloads y el replay desde la DLQ son codigo de verdad.",
            "modelado": "La DLQ es una lista en memoria, no SQS ni RabbitMQ. La clase de error de cada mensaje es "
                        "deterministica para que el escenario sea reproducible.",
            "honesto": "Lo que define el caso no es el broker: es que un mensaje que falla tiene que ir a algun "
                       "lado, y que ese lado necesita profundidad, antiguedad, clasificacion y una salida.",
        },
        "interpretation": {
            "silent": "dead_letter_rate_pct alto, by_error_class con una sola entrada («unclassified») y "
                      "alerts_fired en cero. El pipeline se ve sano.",
            "observed": "dead_letter_rate_pct bajo —solo el veneno—, by_error_class desglosado y la alerta "
                        "disparada. Lo transitorio se recupero sin llegar a la DLQ.",
            "python_note": "El `except Exception` es lo que convierte «datos malos» en la explicacion por defecto "
                           "de una DLQ que en realidad tiene un bug adentro.",
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
        global _metrics
        parsed = urlparse(self.path)
        uri = parsed.path or "/"
        q = parse_qs(parsed.query)
        status = 200

        messages = clamp_int(query_int(q, "messages", 3000), 10, 200000)
        transient_pct = clamp_int(query_int(q, "transient_pct", 12), 0, 100)
        poison_pct = clamp_int(query_int(q, "poison_pct", 4), 0, 100)
        max_retries = clamp_int(query_int(q, "max_retries", 3), 0, 20)
        alert_threshold = clamp_int(query_int(q, "alert_threshold", 50), 0, 100000)
        sample_size = clamp_int(query_int(q, "sample_size", 20), 0, 1000)
        limit = clamp_int(query_int(q, "limit", 500), 1, 200000)

        if uri in ("/", "/index"):
            payload = {
                "lab": "Problem-Driven Systems Lab",
                "case": CASE_NAME,
                "stack": APP_STACK,
                "goal": "Mostrar que un pipeline con throughput normal y cero errores puede estar perdiendo el 16% "
                        "de los mensajes, porque los errores se fueron a un lugar que nadie mira.",
                "arco": "Cierra el arco del caso 15, donde la DLQ nace como politica de rechazo.",
                "python_specific": "La jerarquia de excepciones clasifica sin ceremonia — y el `except Exception` "
                                   "manda los bugs del consumidor a la DLQ junto con los datos malos.",
                "routes": {
                    "/health": "Estado basico del servicio.",
                    "/consume-silent?messages=3000": "Cualquier fallo a la DLQ, sin clasificar ni reintentar.",
                    "/consume-observed?messages=3000": "Clasificar, reintentar lo transitorio, alertar.",
                    "/dlq/stats": "Profundidad, antiguedad del mas viejo y desglose por clase de error.",
                    "/dlq/drain?limit=500": "Replay desde la DLQ: que se recupera y que sigue siendo veneno.",
                    "/diagnostics/summary": "Comparativa entre variantes.",
                    "/reset-lab": "Vacia la DLQ y las metricas.",
                },
            }
        elif uri == "/health":
            payload = {"status": "ok", "stack": APP_STACK, "case": CASE_NAME}
        elif uri == "/consume-silent":
            payload = run_scenario("silent", messages, transient_pct, poison_pct, max_retries,
                                   alert_threshold, sample_size)
        elif uri == "/consume-observed":
            payload = run_scenario("observed", messages, transient_pct, poison_pct, max_retries,
                                   alert_threshold, sample_size)
        elif uri == "/dlq/stats":
            payload = dlq_stats(alert_threshold)
        elif uri == "/dlq/drain":
            payload = dlq_drain(limit, transient_pct, poison_pct, max_retries)
        elif uri == "/diagnostics/summary":
            payload = diagnostics(alert_threshold)
        elif uri == "/reset-lab":
            reset_all()
            with _lock:
                _metrics = initial_metrics()
            payload = {"status": "reset", "message": "DLQ y metricas reiniciadas."}
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
