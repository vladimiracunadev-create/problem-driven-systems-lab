# 🟢 Caso 17 — Node.js 22

<!-- nav-stack -->
[⬅️ Caso 17](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🟢 Perfil de Node.js](../../../docs/languages/node.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Node del caso 17. Un `ALTER TABLE` bloqueante contra expand-contract, con lectores golpeando la tabla mientras tanto.

## Node no tiene locks — y el caso ocurre igual, de la forma más literal

No hay `RWMutex`, no hay `ReaderWriterLockSlim`, no hay nada que adquirir. Y sin embargo este es el stack donde el problema se ve más crudo:

**el «lock exclusivo» en Node es el event loop.**

Un bucle sincrónico que tarda 400 ms no bloquea una tabla: bloquea el proceso entero. Ningún request se atiende, ningún timer dispara, ningún socket se lee. La migración no compite con los lectores por un recurso — **se los come**.

```js
const shared = new Int32Array(new SharedArrayBuffer(4));
Atomics.wait(shared, 0, 0, ms);   // duerme el hilo entero, sin ceder el turno
```

Esa es la variante bloqueante: la única forma de esperar en Node sin ceder el event loop.

## La consecuencia práctica

**El `await` entre lotes no es una optimización: es el único mecanismo de equidad que existe.**

El lector no tiene deadline que lo salve, porque su propio timeout tampoco puede dispararse mientras el loop esté tomado. En los otros seis stacks, un lector con `tryLock(120ms)` al menos falla rápido y devuelve 503. Acá no falla: **no responde**.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `Atomics.wait` sobre `SharedArrayBuffer` | Bloquear el event loop de verdad, sin ceder el turno. |
| `setTimeout(0)` / `await` | Ceder el turno — el mecanismo de equidad entre lotes. |
| `performance.now()` | Medir cuánto tardó un lector en conseguir su turno. |

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
docker compose -f compose.nodejs.yml up -d --build
curl "http://127.0.0.1:8300/17/migrate-blocking?rows=20000&readers=8"
curl "http://127.0.0.1:8300/17/migrate-expand-contract?rows=20000&readers=8&batch=2000&pause_ms=5"
curl "http://127.0.0.1:8300/17/migration/state"
```

## Lo que ningún stack cambia

`lock_held_ms` es prácticamente el mismo en las dos variantes. **El trabajo no desaparece: se reparte.**

Lo que decide si la aplicación se cae no es el tiempo total sino `longest_single_lock_ms` — y esa es la métrica que casi nunca está en el plan de migración.
