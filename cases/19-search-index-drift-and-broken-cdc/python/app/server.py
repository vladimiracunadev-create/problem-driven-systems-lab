"""Caso 19 — Deriva del indice de busqueda y CDC roto — stack Python 3.12.

Dual-write: la aplicacion escribe en la base y despues en el indice de busqueda.
Cuando la segunda escritura falla —y falla, porque son dos sistemas distintos sin
transaccion comun— **nadie se entera**. La busqueda sigue devolviendo resultados,
solo que los devuelve mal: le faltan documentos, le sobran borrados, y los que
tiene estan viejos.

Outbox + checkpoint + reconciliacion: la escritura a la base y el registro del
cambio ocurren juntos; un consumidor aplica los cambios al indice y **solo avanza
el checkpoint cuando la aplicacion se confirma**; y un barrido periodico compara
los dos lados y repara lo que quedo torcido.

Las tres formas de deriva, que no son la misma cosa:

    missing  — esta en la base, no en el indice     → la busqueda no lo encuentra
    stale    — esta en los dos, con version vieja   → la busqueda lo encuentra mal
    orphan   — esta en el indice, borrado en la base → la busqueda devuelve fantasmas

Primitiva Python distintiva:

    **El algebra de conjuntos de la stdlib.** La deriva de tres caras se expresa
    en tres lineas que se leen como su propia definicion:

        missing = db_ids - index_ids
        orphan  = index_ids - db_ids
        stale   = {i for i in db_ids & index_ids if db[i].v != index[i].v}

    Ningun otro stack del laboratorio la escribe tan corto. Go no tiene tipo
    conjunto y hay que recorrer a mano; Java y .NET lo tienen pero mas verboso.

    La contracara, y hay que decirla: **un `except:` desnudo se traga la falla
    del indice sin dejar rastro**, y es exactamente la forma en que este bug
    llega a produccion. Python hace el diagnostico facil y el bug tambien.
"""

from http.server import BaseHTTPRequestHandler, HTTPServer
from socketserver import ThreadingMixIn
from urllib.parse import parse_qs, urlparse
import json
import os
import threading
import time

APP_STACK = os.environ.get("APP_STACK", "Python 3.12")
CASE_NAME = "19 - Deriva del indice de busqueda y CDC roto"

TERMS = ("alfa", "beta", "gamma", "delta", "epsilon", "zeta", "eta", "theta")

_lock = threading.Lock()
_db = {}          # id -> {"version": n, "term": t, "deleted": bool, "updated_ms": t}
_index = {}       # id -> {"version": n, "term": t}
_outbox = []      # [{"seq": n, "id": i, "version": v, "term": t, "deleted": b, "at_ms": t}]
_checkpoint = 0
_seq = 0
_silent_failures = 0
_metrics = {}


def initial_metrics():
    def slot():
        return {"runs": 0, "writes": 0, "silent_failures": 0, "drift_count": 0,
                "repaired": 0, "outbox_retried": 0}
    return {"drifted": slot(), "reconciled": slot()}


_metrics = initial_metrics()


def now_ms():
    return time.monotonic() * 1000.0


def reset_all():
    global _checkpoint, _seq, _silent_failures
    with _lock:
        _db.clear()
        _index.clear()
        _outbox.clear()
        _checkpoint = 0
        _seq = 0
        _silent_failures = 0


def index_write_fails(idx, fail_rate):
    """El indice rechaza una fraccion de las escrituras.

    El modulo 101 —primo— importa: con modulo 100, las dos escrituras del mismo
    documento (i y i+keyspace) caen en el mismo residuo y siempre corren la misma
    suerte, asi que nunca se produce deriva `stale`. Con 101 se separan.
    """
    return (idx * 37) % 101 < fail_rate


# ---------------------------------------------------------------------------
# Variante dual-write: escribir en la base y despues en el indice, y rezar
# ---------------------------------------------------------------------------

def run_drifted(writes, fail_rate, delete_pct):
    global _silent_failures
    reset_all()
    keyspace = max(1, writes // 2)   # la mitad son actualizaciones: generan stale
    silent = 0

    for i in range(writes):
        doc_id = f"doc-{i % keyspace}"
        term = TERMS[i % len(TERMS)]
        deleting = (i * 53) % 101 < delete_pct

        with _lock:
            prev = _db.get(doc_id)
            version = (prev["version"] + 1) if prev else 1
            _db[doc_id] = {"version": version, "term": term, "deleted": deleting,
                           "updated_ms": now_ms()}

        # La segunda escritura. No hay transaccion que la ate a la primera, y
        # cuando falla el codigo sigue como si nada: es el bug entero.
        if index_write_fails(i, fail_rate):
            silent += 1
            continue
        with _lock:
            if deleting:
                _index.pop(doc_id, None)
            else:
                _index[doc_id] = {"version": version, "term": term}

    with _lock:
        _silent_failures = silent
    return silent


# ---------------------------------------------------------------------------
# Variante outbox + checkpoint + reconciliacion
# ---------------------------------------------------------------------------

def run_reconciled(writes, fail_rate, delete_pct):
    """Tres mecanismos, y hacen falta los tres.

    1. **Outbox**: el cambio se registra junto con la escritura a la base. Si el
       indice esta caido, el cambio no se pierde: queda anotado.
    2. **Checkpoint**: el consumidor solo lo avanza cuando la aplicacion al
       indice se confirmo. Una falla deja el cambio pendiente, no perdido.
    3. **Reconciliacion**: un barrido compara los dos lados. Es la red de
       seguridad para lo que los dos primeros no cubren — un indice restaurado
       de un backup viejo, una reindexacion parcial, un borrado manual.
    """
    global _seq, _checkpoint, _silent_failures
    reset_all()
    keyspace = max(1, writes // 2)
    silent = 0

    for i in range(writes):
        doc_id = f"doc-{i % keyspace}"
        term = TERMS[i % len(TERMS)]
        deleting = (i * 53) % 101 < delete_pct

        with _lock:
            prev = _db.get(doc_id)
            version = (prev["version"] + 1) if prev else 1
            _db[doc_id] = {"version": version, "term": term, "deleted": deleting,
                           "updated_ms": now_ms()}
            # El cambio se anota JUNTO con la escritura, bajo el mismo lock.
            _seq += 1
            _outbox.append({"seq": _seq, "id": doc_id, "version": version,
                            "term": term, "deleted": deleting, "at_ms": now_ms()})

    # El consumidor. Aplica en orden y solo avanza el checkpoint si confirmo.
    retried = drain_outbox(fail_rate)
    with _lock:
        silent = 0
        _silent_failures = 0
    return silent, retried


def drain_outbox(fail_rate, max_retries=5):
    """Aplica los cambios pendientes al indice, en orden, reintentando.

    Dos reglas, y las dos importan:

    - **Se aplica en orden.** Saltearse un cambio para seguir con el siguiente
      dejaria una version vieja pisando a una nueva.
    - **El checkpoint avanza solo con la confirmacion.** Si un cambio no entra
      despues de `max_retries`, el consumidor se frena ahi: el cambio queda
      pendiente, no perdido. Eso es lo que el dual-write no puede hacer.
    """
    global _checkpoint
    retried = 0
    with _lock:
        pending = [e for e in _outbox if e["seq"] > _checkpoint]
    for entry in pending:
        applied = False
        for attempt in range(max_retries):
            if index_write_fails(entry["seq"] * (attempt + 1) + attempt, fail_rate):
                retried += 1
                continue
            with _lock:
                if entry["deleted"]:
                    _index.pop(entry["id"], None)
                else:
                    _index[entry["id"]] = {"version": entry["version"], "term": entry["term"]}
            applied = True
            break
        if not applied:
            break   # el checkpoint se frena: el cambio queda pendiente
        with _lock:
            _checkpoint = entry["seq"]
    return retried


# ---------------------------------------------------------------------------
# La deriva de tres caras, en el algebra de conjuntos de la stdlib
# ---------------------------------------------------------------------------

def compute_drift():
    with _lock:
        db_live = {k: v for k, v in _db.items() if not v["deleted"]}
        db_ids = set(db_live)
        index_ids = set(_index)

        missing = db_ids - index_ids
        orphan = index_ids - db_ids
        stale = {i for i in db_ids & index_ids if _index[i]["version"] != db_live[i]["version"]}

        oldest = 0.0
        now = now_ms()
        for i in missing | stale:
            age = now - db_live[i]["updated_ms"]
            oldest = max(oldest, age)

        return {
            "db_count": len(db_live),
            "index_count": len(_index),
            "missing": len(missing),
            "stale": len(stale),
            "orphan": len(orphan),
            "drift_count": len(missing) + len(stale) + len(orphan),
            "drift_age_ms": round(oldest, 2),
            "missing_ids": sorted(missing)[:8],
            "orphan_ids": sorted(orphan)[:8],
            "last_checkpoint": _checkpoint,
            "outbox_pending": sum(1 for e in _outbox if e["seq"] > _checkpoint),
        }


def reconcile():
    """El barrido: compara los dos lados y repara. La red de seguridad."""
    t0 = now_ms()
    before = compute_drift()
    with _lock:
        db_live = {k: v for k, v in _db.items() if not v["deleted"]}
        for i, doc in db_live.items():
            cur = _index.get(i)
            if cur is None or cur["version"] != doc["version"]:
                _index[i] = {"version": doc["version"], "term": doc["term"]}
        for i in list(_index):
            if i not in db_live:
                del _index[i]
    after = compute_drift()
    return {
        "reconcile_duration_ms": round(now_ms() - t0, 2),
        "drift_before": before["drift_count"],
        "drift_after": after["drift_count"],
        "repaired": before["drift_count"] - after["drift_count"],
        "detail_before": {k: before[k] for k in ("missing", "stale", "orphan")},
        "state": after,
        "note": "El barrido es la red de seguridad de lo que el outbox no cubre: un indice restaurado de un "
                "backup viejo, una reindexacion parcial, un borrado manual. Sin el, el outbox garantiza que "
                "ningun cambio NUEVO se pierda — pero no arregla los que ya se perdieron.",
    }


# ---------------------------------------------------------------------------
# Las consultas: medir la deriva desde donde la ve el usuario
# ---------------------------------------------------------------------------

def run_queries(queries):
    """Recall y precision reales, corriendo busquedas contra los dos lados."""
    with _lock:
        db_live = {k: v for k, v in _db.items() if not v["deleted"]}
        idx = dict(_index)

    hits = expected = returned = correct = 0
    for q in range(queries):
        term = TERMS[q % len(TERMS)]
        esperados = {i for i, d in db_live.items() if d["term"] == term}
        devueltos = {i for i, d in idx.items() if d["term"] == term}
        expected += len(esperados)
        returned += len(devueltos)
        hits += len(esperados & devueltos)
        correct += len(devueltos & esperados)

    return {
        "queries": queries,
        "search_recall_pct": round(hits / max(1, expected) * 100, 2),
        "search_precision_pct": round(correct / max(1, returned) * 100, 2),
        "note": "Recall bajo = la busqueda no encuentra lo que existe. Precision baja = devuelve lo que ya no "
                "existe o con datos viejos. Las dos se ven como 'la busqueda anda rara', no como un error.",
    }


def run_scenario(variant, writes, fail_rate, delete_pct, queries):
    t0 = now_ms()
    retried = 0
    if variant == "drifted":
        silent = run_drifted(writes, fail_rate, delete_pct)
    else:
        silent, retried = run_reconciled(writes, fail_rate, delete_pct)
        rec = reconcile()
        retried += 0

    drift = compute_drift()
    q = run_queries(queries)

    with _lock:
        slot = _metrics[variant]
        slot["runs"] += 1
        slot["writes"] += writes
        slot["silent_failures"] += silent
        slot["drift_count"] += drift["drift_count"]
        slot["outbox_retried"] += retried

    payload = {
        "variant": variant,
        "writes": writes,
        "fail_rate_pct": fail_rate,
        "delete_pct": delete_pct,
        "silent_failures": silent,
        "outbox_retried": retried,
    }
    payload.update(drift)
    payload.update(q)
    payload["wall_ms"] = round(now_ms() - t0, 2)
    payload["note"] = (
        "La escritura al indice fallo y el codigo siguio como si nada. La base y el indice no comparten "
        "transaccion, asi que la unica forma de enterarse es mirando — y nadie mira, porque la busqueda "
        "sigue respondiendo 200."
        if variant == "drifted"
        else "El outbox garantiza que ningun cambio nuevo se pierda, el checkpoint impide saltear uno, y el "
             "barrido repara lo que los dos primeros no cubren. Deriva final: cero."
    )
    payload["python_note"] = (
        "La deriva de tres caras se escribe en tres lineas de algebra de conjuntos: db_ids - index_ids, "
        "index_ids - db_ids, y la interseccion filtrada por version. Ningun otro stack del lab la escribe tan "
        "corto — y ninguno hace tan facil tragarse la falla original con un except desnudo."
    )
    return payload


def index_state():
    d = compute_drift()
    d["stack"] = APP_STACK
    d["note"] = ("`missing` no se encuentra, `stale` se encuentra mal y `orphan` es un fantasma. Las tres se ven "
                 "igual desde afuera —«la busqueda anda rara»— y se arreglan distinto.")
    return d


def diagnostics():
    with _lock:
        variants = {k: dict(v) for k, v in _metrics.items()}
    return {
        "stack": APP_STACK,
        "case": CASE_NAME,
        "variants": variants,
        "index": index_state(),
        "fidelity": {
            "real": "El diff de tres caras, el outbox con orden y checkpoint, y el barrido de reconciliacion son "
                    "codigo de verdad, con la primitiva idiomatica de cada runtime.",
            "modelado": "El indice de busqueda es un diccionario en memoria, no Elasticsearch. La falla de "
                        "escritura es deterministica (multiplicador primo sobre el indice) para que el escenario "
                        "sea reproducible.",
            "honesto": "Lo que importa del caso no es el motor de busqueda: es que la base y el indice son dos "
                       "sistemas sin transaccion comun. Eso es igual de cierto con un dict que con Elasticsearch.",
        },
        "interpretation": {
            "drifted": "drift_count > 0 y recall por debajo de 100 con el servicio respondiendo 200 a todo. "
                       "silent_failures cuenta las escrituras que nadie miro.",
            "reconciled": "drift_count = 0, recall y precision en 100. El outbox no dejo perder ningun cambio y "
                          "el barrido reparo lo que quedaba.",
            "python_note": "El algebra de conjuntos hace el diagnostico de tres lineas. El except desnudo hace el "
                           "bug de una.",
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

        writes = clamp_int(query_int(q, "writes", 2000), 10, 200000)
        fail_rate = clamp_int(query_int(q, "fail_rate", 8), 0, 100)
        delete_pct = clamp_int(query_int(q, "delete_pct", 5), 0, 50)
        queries = clamp_int(query_int(q, "queries", 200), 1, 5000)

        if uri in ("/", "/index"):
            payload = {
                "lab": "Problem-Driven Systems Lab",
                "case": CASE_NAME,
                "stack": APP_STACK,
                "goal": "Mostrar que una busqueda que responde 200 puede estar respondiendo mal, y que la unica "
                        "forma de saberlo es comparar los dos lados a proposito.",
                "python_specific": "La deriva de tres caras en tres lineas de algebra de conjuntos — y el except "
                                   "desnudo que produce el bug en una.",
                "routes": {
                    "/health": "Estado basico del servicio.",
                    "/search-drifted?writes=2000&fail_rate=8": "Dual-write: el indice se desincroniza en silencio.",
                    "/search-reconciled?writes=2000&fail_rate=8": "Outbox + checkpoint + barrido: deriva cero.",
                    "/reconcile": "Un barrido suelto, para ver que encuentra y que repara.",
                    "/index/state": "Las tres caras de la deriva y la antiguedad del cambio mas viejo sin aplicar.",
                    "/diagnostics/summary": "Comparativa entre variantes.",
                    "/reset-lab": "Vacia la base, el indice, el outbox y las metricas.",
                },
            }
        elif uri == "/health":
            payload = {"status": "ok", "stack": APP_STACK, "case": CASE_NAME}
        elif uri == "/search-drifted":
            payload = run_scenario("drifted", writes, fail_rate, delete_pct, queries)
        elif uri == "/search-reconciled":
            payload = run_scenario("reconciled", writes, fail_rate, delete_pct, queries)
        elif uri == "/reconcile":
            payload = reconcile()
        elif uri == "/index/state":
            payload = index_state()
        elif uri == "/diagnostics/summary":
            payload = diagnostics()
        elif uri == "/reset-lab":
            reset_all()
            with _lock:
                _metrics = initial_metrics()
            payload = {"status": "reset", "message": "Base, indice, outbox y metricas reiniciados."}
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
