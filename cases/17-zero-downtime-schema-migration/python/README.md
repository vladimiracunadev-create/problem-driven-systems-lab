# 🐍 Caso 17 — Python 3.12

<!-- nav-stack -->
[⬅️ Caso 17](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐍 Perfil de Python](../../../docs/languages/python.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Python del caso 17. Un `ALTER TABLE` bloqueante contra expand-contract, con lectores golpeando la tabla mientras tanto.

## La primitiva distintiva es una ausencia

**La biblioteca estándar de Python no tiene read-write lock.** Hay `Lock`, `RLock`, `Semaphore`, `Condition`, `Event` y `Barrier`. No hay `RWLock`.

Java tiene `ReentrantReadWriteLock`, .NET `ReaderWriterLockSlim`, Go `sync.RWMutex`, Rust `RwLock`. Python no, así que hay que construirlo:

```python
def acquire_read(self, timeout_s):
    with self._cond:
        while self._writer or self._writer_waiting > 0:   # ← la bandera
            ...
        self._readers += 1
```

## Y construirlo mal es fácil

La versión ingenua —solo un contador de lectores— deja al escritor esperando **para siempre** mientras siga entrando tráfico de lectura. En una migración eso significa que el `ALTER TABLE` nunca arranca, y la aplicación funciona perfecto: el peor modo de fallar, porque nada se ve roto.

La bandera `_writer_waiting` es toda la diferencia. En cuanto un escritor se anota, los lectores nuevos se forman detrás.

Es exactamente el mismo problema que Java resuelve con `new ReentrantReadWriteLock(true)` —el flag de equidad— y que .NET **no** resuelve, porque `ReaderWriterLockSlim` favorece a los lectores y no tiene modo justo. **La ausencia de la primitiva es lo que obliga a entender qué hace por dentro.**

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `threading.Condition` | La base sobre la que se construye el RWLock. |
| `Condition.wait(timeout)` | El deadline del lector, que duerme en vez de hacer spin. |
| `threading.Barrier` | La largada común de los lectores y la migración. |

## Las cuatro fases, y por qué ese orden

1. **Expand** — agregar la columna nullable. Es metadata: instantáneo.
2. **Backfill** — rellenar por lotes, soltando el lock entre cada uno.
3. **Switch** — un feature flag cambia lecturas y escrituras a la columna nueva.
4. **Contract** — recién ahora, en un despliegue posterior, se borra la vieja.

**El switch va antes del contract** porque el flag es lo único reversible en un segundo. Si se borra la columna vieja primero, volver atrás requiere otra migración — y a esa altura ya no hay a dónde volver.

## Rutas

| Ruta | Qué muestra |
|---|---|
| `/health` | liveness |
| `/migrate-blocking?rows=20000&readers=8` | `readers_failed` > 0 y `longest_single_lock_ms` = la migración entera |
| `/migrate-expand-contract?rows=20000&readers=8&batch=2000&pause_ms=5` | `readers_failed` = 0 y el lock más largo = un lote |
| `/migration/state` | fase actual, progreso del backfill y estado del feature flag |
| `/backfill?batch=2000` | un lote suelto, para ver el efecto de a uno |
| `/diagnostics/summary` | acumulado por variante |
| `/reset-lab` | vuelve la tabla al esquema viejo |

**Parámetros:** `rows` (1k–500k), `readers` (1–64 lectores concurrentes), `ms_per_1k` (costo de migrar mil filas), `batch` (tamaño de lote), `pause_ms` (pausa entre lotes).

## Hub

```bash
docker compose -f compose.python.yml up -d --build
curl "http://127.0.0.1:8200/17/migrate-blocking?rows=20000&readers=8"
curl "http://127.0.0.1:8200/17/migrate-expand-contract?rows=20000&readers=8&batch=2000&pause_ms=5"
curl "http://127.0.0.1:8200/17/migration/state"
```

## Lo que ningún stack cambia

`lock_held_ms` es prácticamente el mismo en las dos variantes. **El trabajo no desaparece: se reparte.**

Lo que decide si la aplicación se cae no es el tiempo total sino `longest_single_lock_ms` — y esa es la métrica que casi nunca está en el plan de migración.
