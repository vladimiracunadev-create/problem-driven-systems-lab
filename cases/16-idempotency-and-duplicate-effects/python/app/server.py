"""Caso 16 — Idempotencia y efectos duplicados — stack Python 3.12.

Unsafe: N reintentos del mismo pago aplican N cargos. El cliente reintento
porque el primer intento dio timeout, no porque quisiera pagar de nuevo.
Idempotent: `Idempotency-Key` persistida + outbox pattern. Un solo cargo, un
solo efecto lateral, y los reintentos reciben la respuesta guardada.

Primitiva Python distintiva:
    `dict.setdefault(key, value)` bajo un `Lock`.

    `setdefault` hace en una sola llamada lo que `if key not in d: d[key] = v`
    hace en dos — y esa diferencia es todo el caso. Con dos operaciones hay una
    ventana entre el chequeo y la escritura por la que se cuelan los reintentos
    concurrentes; con una sola, no la hay.

    El detalle incomodo: **`setdefault` es atomico solo por el GIL**, no por
    contrato. CPython garantiza que un `dict.setdefault` no se interrumpe a la
    mitad, pero eso es una propiedad de la implementacion, no del lenguaje. Por
    eso este codigo igual toma un `Lock` explicito: lo que se quiere expresar es
    "esta operacion es indivisible", y apoyarse en el GIL para eso es escribir
    codigo que depende de un detalle que puede cambiar.

La segunda mitad del caso es el **outbox pattern**. El cargo va a la base y el
email a una cola: dos sistemas distintos, sin transaccion que los abarque. Si el
cargo se aplica y el email falla, se pierde el aviso; si el email sale y el
cargo se revierte, se aviso de algo que no paso. El outbox resuelve eso
escribiendo el efecto en la MISMA transaccion local que el cargo, y dejando que
un worker lo entregue despues.
"""

from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse
import json
import os
import threading
import time

APP_STACK = os.environ.get("APP_STACK", "Python 3.12")
CASE_NAME = "16 - Idempotencia y efectos duplicados"

# Ventana de deduplicacion. Pasada esta, la misma clave se considera una
# operacion nueva — que es lo correcto: una Idempotency-Key no puede vivir para
# siempre o la tabla crece sin techo.
DEDUPE_WINDOW_MS = 24 * 60 * 60 * 1000

_lock = threading.Lock()

# La "base de datos": saldo cobrado por cuenta.
_ledger = {}
# La tabla de idempotencia: key -> {"response", "stored_at_ms"}
_idempotency = {}
# El outbox: efectos que cruzan el boundary, escritos junto al cargo.
_outbox = []
# Los efectos que ya salieron de verdad (emails enviados, eventos publicados).
_delivered = []


def initial_metrics():
    def slot():
        return {
            "runs": 0,
            "attempts": 0,
            "charges_applied": 0,
            "duplicates_prevented": 0,
            "duplicates_applied": 0,
            "idempotency_hits": 0,
            "side_effects_emitted": 0,
            "overcharged_cents": 0,
        }

    return {"unsafe": slot(), "idempotent": slot()}


_metrics = initial_metrics()


def now_ms():
    return time.time() * 1000.0


def mono_ms():
    return time.monotonic() * 1000.0


# ---------------------------------------------------------------------------
# El efecto que cruza el boundary
# ---------------------------------------------------------------------------

def emit_side_effect_direct(key, amount_cents):
    """Publica el efecto DIRECTO, fuera de la transaccion del cargo.

    Es lo que hace la variante unsafe. Si el proceso muere entre el cargo y esta
    linea, el cobro existe y el aviso no. Y si esta linea sale pero el cargo se
    revierte, se aviso de algo que no paso.
    """
    with _lock:
        _delivered.append({
            "key": key,
            "kind": "payment_receipt_email",
            "amount_cents": amount_cents,
            "at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "via": "direct",
        })
        del _delivered[:-200]


def enqueue_outbox(key, amount_cents):
    """Escribe el efecto en el outbox, en la MISMA seccion critica que el cargo.

    No lo entrega: solo lo deja anotado. Si el proceso muere aca, el cargo y el
    efecto pendiente sobreviven juntos, porque son la misma escritura.
    """
    _outbox.append({
        "key": key,
        "kind": "payment_receipt_email",
        "amount_cents": amount_cents,
        "at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "status": "pending",
    })
    del _outbox[:-200]


def drain_outbox():
    """El worker que mueve el outbox al destino real. Idempotente por diseño."""
    moved = 0
    with _lock:
        for row in _outbox:
            if row["status"] == "pending":
                row["status"] = "delivered"
                _delivered.append({**row, "via": "outbox"})
                moved += 1
        del _delivered[:-200]
    return moved


# ---------------------------------------------------------------------------
# El cargo
# ---------------------------------------------------------------------------

def apply_charge(account, amount_cents):
    _ledger[account] = _ledger.get(account, 0) + amount_cents
    return _ledger[account]


# ---------------------------------------------------------------------------
# Variante unsafe: check-then-act, sin clave de idempotencia
# ---------------------------------------------------------------------------

def attempt_unsafe(key, account, amount_cents, out, idx, gate):
    gate.wait()
    with _lock:
        apply_charge(account, amount_cents)
    # El efecto sale directo, fuera de cualquier transaccion.
    emit_side_effect_direct(key, amount_cents)
    out[idx] = {"applied": True, "hit": False, "lookup_ms": 0.0}


# ---------------------------------------------------------------------------
# Variante idempotent: setdefault atomico + outbox
# ---------------------------------------------------------------------------

def attempt_idempotent(key, account, amount_cents, out, idx, gate):
    gate.wait()
    t0 = mono_ms()

    with _lock:
        entry = _idempotency.get(key)
        if entry is not None and (now_ms() - entry["stored_at_ms"]) > DEDUPE_WINDOW_MS:
            # Fuera de la ventana: la clave caduco y esto es una operacion nueva.
            _idempotency.pop(key, None)
            entry = None

        if entry is None:
            # setdefault en una sola operacion: no hay ventana entre mirar y
            # escribir. El que gana se lleva el reservado; los demas encuentran
            # la entrada ya puesta.
            placeholder = {"response": None, "stored_at_ms": now_ms()}
            existing = _idempotency.setdefault(key, placeholder)
            leader = existing is placeholder
        else:
            existing = entry
            leader = False

        if leader:
            # El cargo y el efecto pendiente se escriben JUNTOS. Es la mitad del
            # patron que hace que no puedan quedar desincronizados.
            balance = apply_charge(account, amount_cents)
            enqueue_outbox(key, amount_cents)
            existing["response"] = {
                "status": "charged",
                "key": key,
                "account": account,
                "amount_cents": amount_cents,
                "balance_cents": balance,
            }
            lookup_ms = mono_ms() - t0
            out[idx] = {"applied": True, "hit": False, "lookup_ms": lookup_ms}
            return

    # Seguidor: espera a que el lider deje la respuesta y la devuelve tal cual.
    # Un reintento no debe recibir un error ni un cuerpo distinto: tiene que
    # recibir exactamente lo mismo que habria recibido el intento original.
    deadline = mono_ms() + 5000
    while mono_ms() < deadline:
        with _lock:
            if existing.get("response") is not None:
                break
        time.sleep(0.002)

    out[idx] = {"applied": False, "hit": True, "lookup_ms": mono_ms() - t0}


# ---------------------------------------------------------------------------
# Orquestacion
# ---------------------------------------------------------------------------

def run_attempts(variant, key, account, amount_cents, attempts):
    worker = attempt_unsafe if variant == "unsafe" else attempt_idempotent
    out = [None] * attempts
    # Barrera de largada: los reintentos de un cliente con timeout llegan casi
    # juntos, no en fila. Sin esto el caso dependeria del scheduler.
    gate = threading.Barrier(attempts)
    threads = [
        threading.Thread(target=worker, args=(key, account, amount_cents, out, i, gate))
        for i in range(attempts)
    ]
    started = mono_ms()
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    wall_ms = mono_ms() - started

    results = [r for r in out if r]
    applied = sum(1 for r in results if r["applied"])
    hits = sum(1 for r in results if r["hit"])
    lookups = [r["lookup_ms"] for r in results if r["lookup_ms"] > 0]

    delivered = drain_outbox() if variant == "idempotent" else 0

    with _lock:
        balance = _ledger.get(account, 0)
        pending = sum(1 for r in _outbox if r["status"] == "pending")
        delivered_total = len(_delivered)
        effects = delivered_total

    overcharged = max(0, (applied - 1)) * amount_cents

    with _lock:
        s = _metrics[variant]
        s["runs"] += 1
        s["attempts"] += attempts
        s["charges_applied"] += applied
        s["duplicates_prevented"] += hits
        s["duplicates_applied"] += max(0, applied - 1)
        s["idempotency_hits"] += hits
        s["side_effects_emitted"] += (attempts if variant == "unsafe" else delivered)
        s["overcharged_cents"] += overcharged

    return {
        "variant": variant,
        "key": key,
        "account": account,
        "attempts": attempts,
        "amount_cents": amount_cents,
        "charges_applied": applied,
        "duplicates_prevented": hits,
        "duplicates_applied": max(0, applied - 1),
        "idempotency_hits": hits,
        "balance_cents": balance,
        "overcharged_cents": overcharged,
        "side_effects_emitted": attempts if variant == "unsafe" else delivered,
        "side_effect_transport": "directo, fuera de la transaccion" if variant == "unsafe" else "outbox, en la misma escritura que el cargo",
        "outbox_pending": pending,
        "outbox_delivered": delivered_total,
        "lookup_overhead_ms": round(sum(lookups) / len(lookups), 3) if lookups else 0.0,
        "dedupe_window_ms": DEDUPE_WINDOW_MS,
        "wall_ms": round(wall_ms, 2),
        "note": (
            "Sin clave de idempotencia: cada reintento aplica su propio cargo y publica su propio efecto. "
            "El cliente reintento por un timeout, no porque quisiera pagar de nuevo."
            if variant == "unsafe"
            else "setdefault atomico sobre la tabla de idempotencia + outbox en la misma escritura que el cargo: "
            "un cobro, un efecto, y los reintentos reciben la respuesta guardada."
        ),
    }


def idempotency_state():
    with _lock:
        entries = {
            k: {
                "age_ms": round(now_ms() - v["stored_at_ms"], 2),
                "expired": (now_ms() - v["stored_at_ms"]) > DEDUPE_WINDOW_MS,
                "has_response": v["response"] is not None,
            }
            for k, v in _idempotency.items()
        }
        ledger = dict(_ledger)
    return {
        "keys": entries,
        "key_count": len(entries),
        "ledger_cents": ledger,
        "dedupe_window_ms": DEDUPE_WINDOW_MS,
        "note": "La tabla de idempotencia necesita ventana y limpieza: una clave que vive para siempre es una tabla "
                "que crece para siempre.",
    }


def outbox_view(limit):
    with _lock:
        rows = list(reversed(_outbox))[:limit]
        pending = sum(1 for r in _outbox if r["status"] == "pending")
        delivered = list(reversed(_delivered))[:limit]
        delivered_total = len(_delivered)
    return {
        "outbox_pending": pending,
        "outbox_total": len(rows),
        "delivered_total": delivered_total,
        "limit": limit,
        "outbox": rows,
        "delivered": delivered,
        "note": "El outbox se escribe en la misma transaccion que el cargo. El worker que lo drena puede reintentar "
                "sin miedo: entregar dos veces el mismo row es visible y corregible, perder el efecto no.",
    }


def diagnostics():
    with _lock:
        variants = {k: dict(v) for k, v in _metrics.items()}
        pending = sum(1 for r in _outbox if r["status"] == "pending")
        delivered = len(_delivered)
    return {
        "stack": APP_STACK,
        "case": CASE_NAME,
        "variants": variants,
        "outbox_pending": pending,
        "outbox_delivered": delivered,
        "interpretation": {
            "unsafe": "charges_applied = attempts: cada reintento cobro de nuevo. overcharged_cents es plata real que "
                      "el negocio tiene que devolver.",
            "idempotent": "charges_applied = 1 y duplicates_prevented = attempts - 1, sin importar cuantas veces "
                          "reintente el cliente.",
            "python_note": "dict.setdefault hace en una operacion lo que `if k not in d` hace en dos, y esa ventana es "
                           "todo el bug. El Lock explicito esta igual: apoyarse en la atomicidad del GIL es depender de "
                           "un detalle de CPython, no de un contrato del lenguaje.",
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

        key = (q.get("key", ["order-4711"])[0] or "order-4711")[:60]
        account = (q.get("account", ["acct-1"])[0] or "acct-1")[:40]
        attempts = clamp_int(query_int(q, "attempts", 5), 1, 64)
        amount = clamp_int(query_int(q, "amount", 2500), 1, 10_000_000)
        limit = clamp_int(query_int(q, "limit", 20), 1, 200)

        if uri in ("/", "/index"):
            payload = {
                "lab": "Problem-Driven Systems Lab",
                "case": CASE_NAME,
                "stack": APP_STACK,
                "goal": "Mostrar que un reintento por timeout se convierte en un segundo cobro salvo que el servidor "
                        "sepa distinguir 'es la primera vez que veo esto' de 'ya procese esto'.",
                "python_specific": "dict.setdefault bajo Lock: una sola operacion en vez de check-then-act, y el Lock "
                                   "explicito porque la atomicidad del GIL es un detalle de CPython, no un contrato.",
                "routes": {
                    "/health": "Estado basico del servicio.",
                    "/charge-unsafe?key=order-4711&attempts=5&amount=2500": "N reintentos, N cargos.",
                    "/charge-idempotent?key=order-4711&attempts=5&amount=2500": "N reintentos, un cargo y un efecto.",
                    "/idempotency/state": "Claves guardadas, edad, ventana de dedupe y saldo por cuenta.",
                    "/outbox?limit=20": "Efectos pendientes y entregados.",
                    "/diagnostics/summary": "Comparativa entre variantes.",
                    "/reset-lab": "Vacia ledger, claves y outbox.",
                },
            }
        elif uri == "/health":
            payload = {"status": "ok", "stack": APP_STACK, "case": CASE_NAME}
        elif uri == "/charge-unsafe":
            payload = run_attempts("unsafe", key, account, amount, attempts)
        elif uri == "/charge-idempotent":
            payload = run_attempts("idempotent", key, account, amount, attempts)
        elif uri == "/idempotency/state":
            payload = idempotency_state()
        elif uri == "/outbox":
            payload = outbox_view(limit)
        elif uri == "/diagnostics/summary":
            payload = diagnostics()
        elif uri == "/reset-lab":
            with _lock:
                _ledger.clear()
                _idempotency.clear()
                _outbox.clear()
                _delivered.clear()
                _metrics = initial_metrics()
            payload = {"status": "reset", "message": "Ledger, claves de idempotencia y outbox reiniciados."}
        else:
            status = 404
            payload = {"error": "Ruta no encontrada", "path": uri}

        payload["timestamp_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        payload["pid"] = os.getpid()
        self.send_json(status, payload)


PORT = int(os.environ.get("PORT", "8080"))
print(f"Servidor Python escuchando en {PORT}")
HTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
