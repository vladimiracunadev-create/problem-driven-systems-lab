# ☕ Caso 17 — Java 21

<!-- nav-stack -->
[⬅️ Caso 17](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [☕ Perfil de Java](../../../docs/languages/java.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Java del caso 17. Un `ALTER TABLE` bloqueante contra expand-contract, con lectores golpeando la tabla mientras tanto.

## `ReentrantReadWriteLock`, con dos detalles que ningún otro stack tiene juntos

**1. `tryLock(timeout, unit)` del lado del lector.**

```java
boolean got = rwLock.readLock().tryLock(120, TimeUnit.MILLISECONDS);
```

Un lector real no espera para siempre: tiene un deadline y devuelve 503 si no lo alcanza. Es la diferencia entre «la app está lenta» y «la app no responde».

**2. El modo justo, en el constructor.**

```java
new ReentrantReadWriteLock(true)   // ← sin esto, el escritor puede no entrar nunca
```

Por defecto el lock **no** es justo. Con tráfico de lectura constante, el escritor puede quedarse esperando indefinidamente: el `ALTER TABLE` no arranca, y mientras tanto la aplicación funciona perfecto. Es el peor modo de fallar, porque nada se ve roto.

Ese parámetro es exactamente el problema que Python resuelve a mano con una bandera de escritor esperando, y que .NET **no** resuelve — `ReaderWriterLockSlim` favorece a los lectores y no tiene modo justo.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `ReentrantReadWriteLock(true)` | El lock, en modo justo. |
| `readLock().tryLock(timeout, unit)` | El deadline del lector. |
| `CyclicBarrier` | La largada común de lectores y migración. |
| `AtomicLong.accumulateAndGet` | Máximos sin lock. |

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
docker compose -f compose.java.yml up -d --build
curl "http://127.0.0.1:8400/17/migrate-blocking?rows=20000&readers=8"
curl "http://127.0.0.1:8400/17/migrate-expand-contract?rows=20000&readers=8&batch=2000&pause_ms=5"
curl "http://127.0.0.1:8400/17/migration/state"
```

## Lo que ningún stack cambia

`lock_held_ms` es prácticamente el mismo en las dos variantes. **El trabajo no desaparece: se reparte.**

Lo que decide si la aplicación se cae no es el tiempo total sino `longest_single_lock_ms` — y esa es la métrica que casi nunca está en el plan de migración.
