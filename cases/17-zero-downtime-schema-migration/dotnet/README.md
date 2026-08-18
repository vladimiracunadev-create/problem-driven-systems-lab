# 🔵 Caso 17 — .NET 8

<!-- nav-stack -->
[⬅️ Caso 17](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🔵 Perfil de .NET](../../../docs/languages/dotnet.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack .NET del caso 17. Un `ALTER TABLE` bloqueante contra expand-contract, con lectores golpeando la tabla mientras tanto.

## El deadline es un valor de retorno, no una excepción

```csharp
if (rwLock.TryEnterReadLock(120)) Ellipsis   // devuelve false, no lanza
```

Igual que `SemaphoreSlim.WaitAsync` en el [caso 14](../../14-connection-pool-exhaustion/dotnet/README.md): «no pude leer» es un **camino del código**, no un `catch`. Y esa es exactamente la distinción que el handler necesita para devolver 503 en vez de 500.

## El detalle que solo este stack pone a la vista

**`ReaderWriterLockSlim` es `IDisposable`.**

Un read-write lock con recursos nativos que hay que liberar, en un runtime con recolección de basura. Es un recordatorio de que el GC no administra todo, y conecta directo con el caso 14: el `using` no es azúcar sintáctica, es la única garantía de que algo se suelta.

## Y una carencia que hay que decir

`ReaderWriterLockSlim` **no es justo y no tiene modo justo**. La documentación lo dice: favorece a los lectores. Con tráfico de lectura constante, el escritor puede esperar mucho.

Es el problema que Java resuelve con `new ReentrantReadWriteLock(true)` y Python con una bandera explícita. Acá no hay perilla: si hace falta equidad, hay que construirla encima.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `ReaderWriterLockSlim` | El lock. `IDisposable`, y sin modo justo. |
| `TryEnterReadLock(ms)` | El deadline del lector, como valor de retorno. |
| `Barrier` | La largada común de lectores y migración. |

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
docker compose -f compose.dotnet.yml up -d --build
curl "http://127.0.0.1:8500/17/migrate-blocking?rows=20000&readers=8"
curl "http://127.0.0.1:8500/17/migrate-expand-contract?rows=20000&readers=8&batch=2000&pause_ms=5"
curl "http://127.0.0.1:8500/17/migration/state"
```

## Lo que ningún stack cambia

`lock_held_ms` es prácticamente el mismo en las dos variantes. **El trabajo no desaparece: se reparte.**

Lo que decide si la aplicación se cae no es el tiempo total sino `longest_single_lock_ms` — y esa es la métrica que casi nunca está en el plan de migración.
