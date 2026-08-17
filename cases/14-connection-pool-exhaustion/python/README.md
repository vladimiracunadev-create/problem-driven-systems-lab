# 🐍 Caso 14 — Python 3.12

<!-- nav-stack -->
[⬅️ Caso 14](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐍 Perfil de Python](../../../docs/languages/python.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Python del caso 14. Un pool que se achica en silencio contra uno con devolución garantizada.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `queue.Queue(maxsize=N)` | **El pool.** No es una cola de mensajes: cada elemento es una conexión libre. |
| `Queue.get(timeout=...)` | La adquisición con deadline. Levanta `queue.Empty` al vencer. |
| `@contextmanager` | La devolución garantizada: el `finally` de un generador decorado corre en todos los caminos de salida. |
| `time.sleep` | El tiempo de retención de la conexión. |

## `queue.Queue` como pool

La biblioteca estándar ya trae la estructura. Lo que hay que aportar es la **disciplina de devolverla**, y eso se expresa con un context manager:

```python
@contextmanager
def lease(self, timeout_ms):
    conn = self.acquire(timeout_ms)
    if conn is None:
        raise TimeoutError("pool acquire timeout")
    try:
        yield conn
    finally:
        self.release(conn)     # corre en return, en excepción y en break
```

Es la versión Python de `try-with-resources`. La diferencia de forma con Java es que acá el recurso y su liberación viven en el mismo generador, en vez de en un método `close()` separado.

## Contraste

**Leaky** — sin `try/finally`, la excepción se lleva la conexión:
```python
conn = pool.acquire(LEAKY_WATCHDOG_MS)
try:
    run_query(conn, query_ms, fails(idx, fail_rate))
except RuntimeError:
    return                     # ← la conexión no vuelve
pool.release(conn)
```

**Managed** — `with` cubre todos los caminos:
```python
with pool.lease(ACQUIRE_TIMEOUT_MS) as conn:
    ...                        # la conexión vuelve pase lo que pase
```

## Rutas

| Ruta | Qué muestra |
|---|---|
| `/health` | liveness |
| `/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25` | `leaked` > 0 y `hung` creciente: el pool se vacía y no vuelve |
| `/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25` | `leaked` = 0 y `pool_available_after` = `pool_size` |
| `/pool/state` | tamaño, disponibles, adquiridas, devueltas y fugadas |
| `/diagnostics/summary` | acumulado por variante + ley de Little |
| `/reset-lab` | reconstruye el pool y limpia contadores |

**Parámetros:** `requests` (1–200 llamadores), `pool` (1–64 conexiones), `query_ms` (1–500, cuánto retiene cada query), `fail_rate` (0–100 %, porcentaje de queries que lanzan).

## Hub

```bash
docker compose -f compose.python.yml up -d --build
curl "http://127.0.0.1:8200/14/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25"
curl "http://127.0.0.1:8200/14/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25"
curl "http://127.0.0.1:8200/14/pool/state"
```

## Por qué acá el trabajo sí es un `sleep`

En el [caso 13](../../13-cache-stampede-and-thundering-herd/README.md) un `sleep` habría escondido el punto: lo que duele en una estampida es que el origen **hace** el trabajo N veces, así que hubo que quemar CPU de verdad.

Acá es al revés. Una conexión se retiene mientras se **espera a la red**, no mientras se calcula. Dormir es el modelo fiel del tiempo de retención; quemar CPU mediría otra cosa y además competiría con los propios hilos del laboratorio.

La misma decisión, tomada en sentidos opuestos, por la misma razón: modelar el recurso que realmente escasea.

## Sobre el GIL

Este caso es de los pocos donde el GIL **no molesta**: el trabajo que retiene la conexión es un `sleep`, y `sleep` libera el GIL. Los 24 hilos esperan de verdad en paralelo, así que la contención sobre el pool es real y no un artefacto del intérprete.
