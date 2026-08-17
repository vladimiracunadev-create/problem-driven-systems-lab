# 🐍 Caso 13 — Python 3.12

<!-- nav-stack -->
[⬅️ Caso 13](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐍 Perfil de Python](../../../docs/languages/python.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Python del caso 13. Ráfaga de N hilos sobre una clave que acaba de expirar, sin coordinación y con single-flight.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `threading.Event` | El «espera a que alguien más termine». Un dict de vuelos en curso guarda un Event por clave; el líder lo crea, los seguidores hacen `wait()`. |
| `threading.Lock` | Protege el dict de vuelos. El registro del líder ocurre **bajo lock y antes** de tocar el origen. |
| `threading.Barrier` | Solo para el laboratorio: sincroniza la largada de los N llamadores. Ver la nota de abajo. |

## Contraste

**Naive** — cada llamador que ve el miss recalcula:
```python
_, state = cache_lookup(key)
if state != "fresh":
    value = origin_compute(key, rounds)   # N veces
    cache_store(key, value)
```

**Single-flight** — un Event por clave en vuelo:
```python
with _inflight_lock:
    flight = _inflight.get(key)
    leader = flight is None
    if leader:
        flight = {"event": threading.Event(), "value": None}
        _inflight[key] = flight          # publicar ANTES de calcular

if leader:
    _, recheck = cache_lookup(key)       # double check dentro del vuelo
    if recheck != "fresh":
        cache_store(key, origin_compute(key, rounds))
    flight["event"].set()
else:
    flight["event"].wait(timeout=30)     # no recalcula: espera
```

## Por qué hay una barrera de dos fases

Sin ella el resultado dependería del GIL, no del código. Con `cost` chico, el primer hilo termina su digest completo (~4 ms) dentro de su propio quantum, escribe la cache, y los otros quince ya encuentran el valor fresco: `origin_computations` daría 1 y la variante naive **parecería correcta**. Un falso verde que depende de `sys.setswitchinterval`.

La barrera no infla el número: reproduce lo que pasa de verdad. Cuando una clave caliente expira, los N requests **ya estaban en vuelo** y todos leyeron la cache antes de que ninguno alcanzara a escribirla.

## Rutas

| Ruta | Qué muestra |
|---|---|
| `/health` | liveness |
| `/cache-naive?key=report-alpha&concurrency=16&cost=40` | `origin_computations` = `concurrency`: el origen recibe la ráfaga entera |
| `/cache-singleflight?key=report-alpha&concurrency=16&cost=40` | `origin_computations` = 1, `coalesced_waiters` = `concurrency - 1` |
| `/cache/state` | edad, soft TTL, hard TTL y jitter aplicado por clave |
| `/diagnostics/summary` | acumulado por variante y `origin_total_computations` |
| `/reset-lab` | vacía cache y contadores |

**Parámetros:** `key` (clave a golpear), `concurrency` (1–128 llamadores simultáneos), `cost` (1–400 rondas de trabajo del origen; cada ronda son 2.000 iteraciones de CPU real).

## Hub

```bash
docker compose -f compose.python.yml up -d --build
curl "http://127.0.0.1:8200/13/cache-naive?key=k&concurrency=16&cost=40"
curl "http://127.0.0.1:8200/13/reset-lab"
curl "http://127.0.0.1:8200/13/cache-singleflight?key=k&concurrency=16&cost=40"
```

## Sobre el GIL y `stampede_depth`

`stampede_depth` mide cuántos hilos coincidieron dentro del camino de recómputo. En Python ese número suele quedar por debajo de `concurrency` aunque `origin_computations` dé exactamente `concurrency`: el GIL hace que algunos hilos terminen antes de que otros entren.

Las dos cosas son ciertas y miden cosas distintas. **`origin_computations` es la métrica del caso** — el trabajo total que el origen tuvo que hacer. `stampede_depth` es la del runtime.
