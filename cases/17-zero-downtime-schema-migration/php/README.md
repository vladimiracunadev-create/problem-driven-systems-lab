# 🐘 Caso 17 — PHP 8.3

<!-- nav-stack -->
[⬅️ Caso 17](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐘 Perfil de PHP](../../../docs/languages/php.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack PHP del caso 17. Un `ALTER TABLE` bloqueante contra expand-contract, con lectores golpeando la tabla mientras tanto.

## El único read-write lock del laboratorio que lo provee el sistema operativo

```php
flock($fh, LOCK_SH);   // lock compartido: varios lectores a la vez
flock($fh, LOCK_EX);   // lock exclusivo: uno solo, y sin lectores
flock($fh, LOCK_SH | LOCK_NB);   // el intento con deadline
```

Los otros seis stacks tienen su read-write lock **dentro del proceso**: `ReentrantReadWriteLock`, `ReaderWriterLockSlim`, `sync.RWMutex`, `RwLock`, el que Python se construye, y el event loop de Node. Todos coordinan hilos de un mismo proceso.

El de PHP coordina **procesos distintos**, y es el mismo mecanismo que usan los motores de base de datos por debajo. Es la versión del caso que más se parece a lo que realmente pasa: un `ALTER TABLE` no bloquea hilos de tu aplicación, bloquea a **todos los clientes del motor**, estén donde estén.

Y `LOCK_NB` resuelve de fábrica el problema que Go tiene que armar con una goroutine y Rust con un spin: el intento sin bloqueo que permite darle un plazo al lector.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `flock(LOCK_SH)` | El lock de lectura, compartido entre procesos. |
| `flock(LOCK_EX)` | El lock del escritor. Incompatible con cualquier `LOCK_SH`. |
| `flock(LOCK_SH \| LOCK_NB)` | El intento sin bloqueo, que da el deadline del lector. |
| `finally` | Suelta el lock en todos los caminos de salida. |

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
docker compose -f compose.root.yml up -d --build
curl "http://127.0.0.1:8100/17/migrate-blocking?rows=20000&readers=8"
curl "http://127.0.0.1:8100/17/migrate-expand-contract?rows=20000&readers=8&batch=2000&pause_ms=5"
curl "http://127.0.0.1:8100/17/migration/state"
```

## Nota de fidelidad

El servidor embebido de PHP es de un solo proceso, así que los lectores de este caso se recorren en secuencia dentro de una request. **El lock es real y entre procesos**; lo que no es concurrente es el laboratorio. Bajo PHP-FPM cada lector es otro proceso y `LOCK_SH` los coordina de verdad.

## Dashboard

```bash
docker compose -f cases/17-zero-downtime-schema-migration/php/compose.yml up -d --build
# abrir http://localhost:8117/
```

## Lo que ningún stack cambia

`lock_held_ms` es prácticamente el mismo en las dos variantes. **El trabajo no desaparece: se reparte.**

Lo que decide si la aplicación se cae no es el tiempo total sino `longest_single_lock_ms` — y esa es la métrica que casi nunca está en el plan de migración.
