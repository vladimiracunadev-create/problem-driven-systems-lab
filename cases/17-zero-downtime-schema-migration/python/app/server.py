"""Caso 17 — Migracion de esquema sin downtime — stack Python 3.12.

Blocking: un `ALTER TABLE` sobre una tabla caliente toma el lock exclusivo
durante toda la migracion. Los lectores esperan, y los que tienen timeout
fallan. La app devuelve 503 mientras dure.

Expand-contract: cuatro fases. La columna nueva se agrega nullable (instantaneo),
un worker la rellena por lotes con pausa entre lotes, un feature flag cambia las
lecturas, y recien despues se borra la vieja. Ningun lector espera mas que un
lote.

Primitiva Python distintiva — y es una ausencia:

    **La biblioteca estandar de Python no tiene un read-write lock.** Hay `Lock`,
    `RLock`, `Semaphore`, `Condition`, `Event` y `Barrier`. No hay `RWLock`.

    Java tiene `ReentrantReadWriteLock`, .NET `ReaderWriterLockSlim`, Go
    `sync.RWMutex`, Rust `RwLock`. Python no, asi que hay que construirlo — y
    construirlo mal es facil: la version ingenua deja al escritor esperando para
    siempre si los lectores nunca se agotan (escritor hambriento).

    El `RWLock` de mas abajo tiene ~30 lineas y una bandera `_writer_waiting`
    que es toda la diferencia entre "funciona" y "el escritor entra alguna vez".
    Escribirlo es el aporte del stack: **la ausencia de la primitiva es lo que
    obliga a entender que hace por dentro**.

El tiempo de migracion es un `sleep`: un `ALTER TABLE` se demora esperando I/O
del motor, no quemando CPU del proceso de la app.
"""

from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse
import json
import os
import threading
import time

APP_STACK = os.environ.get("APP_STACK", "Python 3.12")
CASE_NAME = "17 - Migracion de esquema sin downtime"

READ_TIMEOUT_MS = 120     # lo que un lector aguanta antes de rendirse
PHASES = ("idle", "expand", "backfill", "switch", "contract", "done")


class RWLock:
    """Read-write lock con preferencia de escritor. No existe en la stdlib.

    La version ingenua —solo un contador de lectores— deja al escritor
    esperando para siempre mientras siga entrando trafico de lectura. La
    bandera `_writer_waiting` es lo que impide esa hambruna: en cuanto un
    escritor se anota, los lectores nuevos se forman detras.
    """

    def __init__(self):
        self._cond = threading.Condition()
        self._readers = 0
        self._writer = False
        self._writer_waiting = 0

    def acquire_read(self, timeout_s):
        deadline = time.monotonic() + timeout_s
        with self._cond:
            while self._writer or self._writer_waiting > 0:
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    return False
                if not self._cond.wait(remaining):
                    return False
            self._readers += 1
            return True

    def release_read(self):
        with self._cond:
            self._readers -= 1
            if self._readers == 0:
                self._cond.notify_all()

    def acquire_write(self):
        with self._cond:
            self._writer_waiting += 1
            while self._writer or self._readers > 0:
                self._cond.wait()
            self._writer_waiting -= 1
            self._writer = True

    def release_write(self):
        with self._cond:
            self._writer = False
            self._cond.notify_all()


_lock = RWLock()
_state_lock = threading.Lock()

_table = {"rows": 0, "has_new_column": False, "backfilled": 0, "old_column_dropped": False}
_flag = {"read_from_new_column": False}
_phase = "idle"

_metrics = {}


def initial_metrics():
    def slot():
        return {
            "runs": 0,
            "lock_held_ms": 0.0,
            "readers_served": 0,
            "readers_failed": 0,
            "max_read_wait_ms": 0.0,
            "backfill_batches": 0,
        }

    return {"blocking": slot(), "expand_contract": slot()}


_metrics = initial_metrics()


def now_ms():
    return time.monotonic() * 1000.0


def reset_table(rows):
    global _phase
    with _state_lock:
        _table.update({"rows": rows, "has_new_column": False, "backfilled": 0, "old_column_dropped": False})
        _flag["read_from_new_column"] = False
        _phase = "idle"


def set_phase(p):
    global _phase
    with _state_lock:
        _phase = p


# ---------------------------------------------------------------------------
# Los lectores: trafico normal que corre mientras la migracion pasa
# ---------------------------------------------------------------------------

def reader(idx, out, gate, stop_at):
    gate.wait()
    served = failed = 0
    waits = []
    while now_ms() < stop_at:
        t0 = now_ms()
        # Un lector real tiene timeout. Si el lock no se suelta a tiempo, no
        # espera para siempre: falla y devuelve 503 al usuario.
        if _lock.acquire_read(READ_TIMEOUT_MS / 1000.0):
            waits.append(now_ms() - t0)
            try:
                _ = _table["rows"]
            finally:
                _lock.release_read()
            served += 1
        else:
            waits.append(now_ms() - t0)
            failed += 1
        time.sleep(0.002)
    out[idx] = {"served": served, "failed": failed, "waits": waits}


def percentile(values, pct):
    if not values:
        return 0.0
    sv = sorted(values)
    idx = max(0, min(len(sv) - 1, int((pct / 100.0) * len(sv) + 0.999999) - 1))
    return round(float(sv[idx]), 2)


# ---------------------------------------------------------------------------
# Variante blocking: un solo ALTER TABLE con el lock tomado todo el tiempo
# ---------------------------------------------------------------------------

def migrate_blocking(rows, ms_per_1k):
    reset_table(rows)
    set_phase("expand")
    duration_ms = rows / 1000.0 * ms_per_1k

    t0 = now_ms()
    # El lock exclusivo se toma UNA vez y se suelta cuando termina todo. Es lo
    # que hace un ALTER TABLE sobre un motor sin DDL online.
    _lock.acquire_write()
    try:
        time.sleep(duration_ms / 1000.0)
        with _state_lock:
            _table["has_new_column"] = True
            _table["backfilled"] = rows
            _table["old_column_dropped"] = True
            _flag["read_from_new_column"] = True
    finally:
        _lock.release_write()
    held = now_ms() - t0
    set_phase("done")

    return held, 1


# ---------------------------------------------------------------------------
# Variante expand-contract: cuatro fases, lotes cortos
# ---------------------------------------------------------------------------

def migrate_expand_contract(rows, ms_per_1k, batch_size, pause_ms):
    reset_table(rows)
    total_ms = rows / 1000.0 * ms_per_1k
    held_total = 0.0
    batches = 0

    # 1. EXPAND — agregar la columna nullable. Es metadata: instantaneo, y el
    # lock se toma por microsegundos.
    set_phase("expand")
    t0 = now_ms()
    _lock.acquire_write()
    try:
        with _state_lock:
            _table["has_new_column"] = True
    finally:
        _lock.release_write()
    held_total += now_ms() - t0

    # 2. BACKFILL — rellenar por lotes, soltando el lock entre cada uno. Un
    # lector espera como mucho lo que dura UN lote, no la migracion entera.
    set_phase("backfill")
    done = 0
    per_batch_ms = total_ms * (batch_size / max(1, rows))
    while done < rows:
        chunk = min(batch_size, rows - done)
        t0 = now_ms()
        _lock.acquire_write()
        try:
            time.sleep(per_batch_ms / 1000.0)
            with _state_lock:
                _table["backfilled"] += chunk
        finally:
            _lock.release_write()
        held_total += now_ms() - t0
        done += chunk
        batches += 1
        # La pausa entre lotes es lo que le devuelve el motor a la aplicacion.
        # Sin ella, el backfill es un ALTER TABLE largo escrito en pedazos.
        time.sleep(pause_ms / 1000.0)

    # 3. SWITCH — el feature flag cambia las lecturas a la columna nueva. No
    # toca datos: es una decision de la aplicacion, reversible en un segundo.
    set_phase("switch")
    with _state_lock:
        _flag["read_from_new_column"] = True

    # 4. CONTRACT — recien ahora se borra la vieja, en una migracion posterior.
    set_phase("contract")
    t0 = now_ms()
    _lock.acquire_write()
    try:
        with _state_lock:
            _table["old_column_dropped"] = True
    finally:
        _lock.release_write()
    held_total += now_ms() - t0
    set_phase("done")

    return held_total, batches


# ---------------------------------------------------------------------------
# Orquestacion: la migracion corre mientras N lectores golpean la tabla
# ---------------------------------------------------------------------------

def run_migration(variant, rows, readers, ms_per_1k, batch_size, pause_ms):
    out = [None] * readers
    gate = threading.Barrier(readers + 1)
    # Los lectores corren durante toda la migracion mas un margen.
    budget_ms = rows / 1000.0 * ms_per_1k + (rows / max(1, batch_size)) * pause_ms + 400
    stop_at = now_ms() + budget_ms

    threads = [threading.Thread(target=reader, args=(i, out, gate, stop_at)) for i in range(readers)]
    for t in threads:
        t.start()

    started = now_ms()
    gate.wait()   # largada comun: lectores y migracion arrancan juntos
    if variant == "blocking":
        held, batches = migrate_blocking(rows, ms_per_1k)
    else:
        held, batches = migrate_expand_contract(rows, ms_per_1k, batch_size, pause_ms)
    migration_ms = now_ms() - started

    for t in threads:
        t.join()
    wall_ms = now_ms() - started

    results = [r for r in out if r]
    served = sum(r["served"] for r in results)
    failed = sum(r["failed"] for r in results)
    all_waits = [w for r in results for w in r["waits"]]

    with _state_lock:
        slot = _metrics[variant]
        slot["runs"] += 1
        slot["lock_held_ms"] += held
        slot["readers_served"] += served
        slot["readers_failed"] += failed
        slot["max_read_wait_ms"] = max(slot["max_read_wait_ms"], max(all_waits) if all_waits else 0.0)
        slot["backfill_batches"] += batches
        phase = _phase
        backfilled = _table["backfilled"]

    return {
        "variant": variant,
        "rows_total": rows,
        "readers": readers,
        "phase": phase,
        "lock_held_ms": round(held, 2),
        "longest_single_lock_ms": round(held if variant == "blocking" else held / max(1, batches), 2),
        "readers_served": served,
        "readers_failed": failed,
        "availability_pct": round(served / max(1, served + failed) * 100, 2),
        "p99_read_wait_ms": percentile(all_waits, 99),
        "max_read_wait_ms": round(max(all_waits), 2) if all_waits else 0.0,
        "read_timeout_ms": READ_TIMEOUT_MS,
        "backfill_batches": batches,
        "backfill_progress_pct": round(backfilled / max(1, rows) * 100, 2),
        "migration_ms": round(migration_ms, 2),
        "wall_ms": round(wall_ms, 2),
        "note": (
            "Un solo lock exclusivo tomado durante toda la migracion: los lectores esperan lo que dure, y los que "
            "tienen timeout fallan. Es el ALTER TABLE que devuelve 503 durante veinte minutos."
            if variant == "blocking"
            else "Expand, backfill por lotes con pausa, switch por feature flag y contract. El lock se toma y se "
            "suelta en cada lote, asi que ningun lector espera mas que un lote."
        ),
    }


def migration_state():
    with _state_lock:
        return {
            "phase": _phase,
            "phases": list(PHASES),
            "rows_total": _table["rows"],
            "has_new_column": _table["has_new_column"],
            "backfilled": _table["backfilled"],
            "backfill_progress_pct": round(_table["backfilled"] / max(1, _table["rows"]) * 100, 2),
            "old_column_dropped": _table["old_column_dropped"],
            "read_from_new_column": _flag["read_from_new_column"],
            "read_timeout_ms": READ_TIMEOUT_MS,
            "note": "El feature flag `read_from_new_column` es lo unico reversible en un segundo. Por eso el switch "
                    "va antes del contract, y no al reves.",
        }


def backfill_step(batch_size, ms_per_1k):
    """Un lote suelto, para ver el efecto de a uno."""
    with _state_lock:
        rows = _table["rows"]
        done = _table["backfilled"]
        has_col = _table["has_new_column"]
    if not has_col:
        return {"status": "skipped", "reason": "la columna nueva todavia no existe: falta la fase expand"}
    if done >= rows:
        return {"status": "complete", "backfilled": done, "rows_total": rows}

    chunk = min(batch_size, rows - done)
    t0 = now_ms()
    _lock.acquire_write()
    try:
        time.sleep(rows / 1000.0 * ms_per_1k * (chunk / max(1, rows)) / 1000.0)
        with _state_lock:
            _table["backfilled"] += chunk
            done = _table["backfilled"]
    finally:
        _lock.release_write()

    return {
        "status": "batch_done",
        "batch_size": chunk,
        "lock_held_ms": round(now_ms() - t0, 2),
        "backfilled": done,
        "rows_total": rows,
        "backfill_progress_pct": round(done / max(1, rows) * 100, 2),
    }


def diagnostics():
    with _state_lock:
        variants = {}
        for name in ("blocking", "expand_contract"):
            s = _metrics[name]
            variants[name] = {
                "runs": s["runs"],
                "lock_held_ms": round(s["lock_held_ms"], 2),
                "readers_served": s["readers_served"],
                "readers_failed": s["readers_failed"],
                "max_read_wait_ms": round(s["max_read_wait_ms"], 2),
                "backfill_batches": s["backfill_batches"],
            }
    return {
        "stack": APP_STACK,
        "case": CASE_NAME,
        "variants": variants,
        "migration": migration_state(),
        "interpretation": {
            "blocking": "readers_failed > 0 y max_read_wait_ms = la duracion entera de la migracion: la app estuvo "
                        "caida todo ese tiempo aunque el proceso siguiera vivo.",
            "expand_contract": "readers_failed = 0 y max_read_wait_ms = lo que dura UN lote. El trabajo total es el "
                               "mismo; lo que cambia es como se reparte.",
            "python_note": "La stdlib de Python no tiene read-write lock: hay que escribirlo. La bandera "
                           "_writer_waiting es la diferencia entre que el escritor entre alguna vez y que muera de "
                           "hambre esperando a que se acaben los lectores.",
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

        rows = clamp_int(query_int(q, "rows", 20000), 1000, 500000)
        readers = clamp_int(query_int(q, "readers", 8), 1, 64)
        ms_per_1k = clamp_int(query_int(q, "ms_per_1k", 20), 1, 200)
        batch_size = clamp_int(query_int(q, "batch", 2000), 100, 100000)
        pause_ms = clamp_int(query_int(q, "pause_ms", 5), 0, 200)

        if uri in ("/", "/index"):
            payload = {
                "lab": "Problem-Driven Systems Lab",
                "case": CASE_NAME,
                "stack": APP_STACK,
                "goal": "Mostrar que el trabajo total de una migracion es el mismo; lo que cambia es si se cobra "
                        "todo junto con la app caida o repartido en lotes que nadie nota.",
                "python_specific": "La stdlib no tiene read-write lock: este caso lo construye en ~30 lineas, con la "
                                   "bandera de escritor esperando que evita la hambruna.",
                "routes": {
                    "/health": "Estado basico del servicio.",
                    "/migrate-blocking?rows=20000&readers=8": "ALTER TABLE con el lock tomado todo el tiempo.",
                    "/migrate-expand-contract?rows=20000&readers=8&batch=2000&pause_ms=5": "Cuatro fases, lotes cortos.",
                    "/migration/state": "Fase actual, progreso del backfill y estado del feature flag.",
                    "/backfill?batch=2000": "Un lote suelto, para ver el efecto de a uno.",
                    "/diagnostics/summary": "Comparativa entre variantes.",
                    "/reset-lab": "Vuelve la tabla al esquema viejo.",
                },
            }
        elif uri == "/health":
            payload = {"status": "ok", "stack": APP_STACK, "case": CASE_NAME}
        elif uri == "/migrate-blocking":
            payload = run_migration("blocking", rows, readers, ms_per_1k, batch_size, pause_ms)
        elif uri == "/migrate-expand-contract":
            payload = run_migration("expand_contract", rows, readers, ms_per_1k, batch_size, pause_ms)
        elif uri == "/migration/state":
            payload = migration_state()
        elif uri == "/backfill":
            payload = backfill_step(batch_size, ms_per_1k)
        elif uri == "/diagnostics/summary":
            payload = diagnostics()
        elif uri == "/reset-lab":
            reset_table(rows)
            with _state_lock:
                _metrics = initial_metrics()
            payload = {"status": "reset", "message": "Tabla, fase y metricas reiniciadas."}
        else:
            status = 404
            payload = {"error": "Ruta no encontrada", "path": uri}

        payload["timestamp_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        payload["pid"] = os.getpid()
        self.send_json(status, payload)


reset_table(20000)
PORT = int(os.environ.get("PORT", "8080"))
print(f"Servidor Python escuchando en {PORT}")
HTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
