# 🔵 Caso 01 — .NET 8

<!-- nav-stack -->
[⬅️ Caso 01](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🔵 Perfil de .NET](../../../docs/languages/dotnet.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack .NET operativo del caso 01. Mismo problema que PHP/Python/Node/Java: N+1 + filtro no sargable bajo carga + worker concurrente. Primitivas BCL distintas.

## Primitivas .NET nativas

| Primitiva | Rol |
|---|---|
| `ConcurrentDictionary<int, CustomerSummary>` | Summary cache lock-free leida por `/report-optimized`. El worker la actualiza, los handlers la leen — sin contencion. |
| `Task.Delay` + `CancellationToken` | Worker `report-refresh-dotnet` con tick cooperativo cancelable en SIGTERM. |
| `Interlocked.Increment` | Contadores `requests` por ruta sin lock — equivalente del `LongAdder` Java. |
| `record` types | `Customer`, `Order`, `JobRun` inmutables con `with`-expressions, sin boilerplate. |
| `HttpListener` (BCL) | Sin ASP.NET, sin paquetes externos. `dotnet build` + `dotnet run`. |

## Contraste

**Legacy** — filtro no sargable + N+1 real contra el motor:
```csharp
// LOWER(region) envuelve la columna → idx_orders_region queda inutilizable.
cmd.CommandText = "SELECT id, customer_id, region, amount FROM orders " +
                  "WHERE LOWER(region) LIKE 'n%' ORDER BY id LIMIT $limit";

// ...y una query dependiente por cada fila devuelta.
for (int i = 0; i < rows.Count; i++) {
    using var cmd = db.CreateCommand();
    cmd.CommandText = "SELECT name, tier FROM customers WHERE id = $id";
    dbHits++;                                   // db_hits = 1 + N
}
```

**Optimized** — rango sargable + batches `IN(...)` + tabla resumen del worker:
```csharp
// Mismo predicado, reescrito como rango → recupera el indice.
"SELECT id, customer_id, region, amount FROM orders " +
"WHERE region >= 'n' AND region < 'o' ORDER BY id LIMIT $limit"

// Un batch para customers y otro para el resumen. db_hits constante.
$"SELECT id, name, tier FROM customers WHERE id IN ({placeholders})"
$"SELECT customer_id, order_count, total_amount FROM customer_summary WHERE customer_id IN ({placeholders})"
```

Que el primero no use el indice y el segundo si no es una afirmacion del README — lo dice el planner:

```text
EXPLAIN QUERY PLAN … WHERE LOWER(region) LIKE 'n%'   →  SCAN orders
EXPLAIN QUERY PLAN … WHERE region >= 'n' AND < 'o'   →  SEARCH orders USING INDEX idx_orders_region
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/report-legacy?limit=20` | N+1 + filtro no sargable, `db_hits` crece linealmente |
| `/report-optimized?limit=20` | 1 lookup indexado + 1 batch + O(1) en summary cache |
| `/batch/status` | ultimo heartbeat del worker |
| `/job-runs` | historial de corridas (max 30) |
| `/diagnostics/summary` | contraste legacy vs optimized en una vista |
| `/metrics` | avg/p95/p99 por ruta |
| `/reset-lab` | reinicia contadores e historico |

## Hub (recomendado)

```
docker compose -f compose.dotnet.yml up -d --build
curl http://127.0.0.1:8500/01/health
curl "http://127.0.0.1:8500/01/report-optimized?limit=10"
```

## Modo aislado

```
docker compose -f cases/01-api-latency-under-load/dotnet/compose.yml up -d --build
curl http://127.0.0.1:851/health
```

## Diferencias de runtime vs los otros stacks

- **vs PHP-FPM**: PHP crea proceso por request, no comparte estado en memoria. La summary cache en .NET vive en el heap del proceso unico — accesible por todos los handlers sin reconexion.
- **vs Python**: Python tiene GIL que serializa bytecode. El CLR ejecuta handlers en paralelo real sobre el `ThreadPool` (limite por nucleos, no por GIL).
- **vs Node event loop**: Node es single-thread cooperativo. .NET usa `ThreadPool` con worker threads; `summaryCache` se lee concurrentemente sin yield y sin lock — eso es lo que `ConcurrentDictionary` garantiza.
- **vs Java**: paridad funcional 1:1; `ConcurrentDictionary` cumple el rol de `ConcurrentHashMap`, `Interlocked` el de `LongAdder`, `Task.Delay`+`CancellationToken` el de `ScheduledExecutorService`.

## Fidelidad

**Substrato real.** Este stack corre SQL contra SQLite embebido via `Microsoft.Data.Sqlite` 8.0.10 (paquete oficial de Microsoft, ADO.NET-style), en archivo bajo el temp del sistema y con `journal_mode=WAL`. No hay listas en memoria simulando ser una base: `db_hits` cuenta ejecuciones reales — `1 + N` en la ruta legacy, constante en la optimizada.

**El filtro no sargable lo confirma el planner, no el README:**

```text
LEGACY     WHERE LOWER(region) LIKE 'n%'          →  SCAN orders
OPTIMIZED  WHERE region >= 'n' AND region < 'o'   →  SEARCH orders USING INDEX idx_orders_region
```

Envolver la columna en `LOWER()` invalida `idx_orders_region`. El mismo predicado reescrito como rango lo recupera.

**Por que WAL y una conexion por unidad de trabajo:** el worker escribe `customer_summary` mientras las rutas leen. Con WAL los lectores no se bloquean con el escritor — es el equivalente embebido del MVCC que da PostgreSQL en el stack PHP, y es exactamente la propiedad que este caso enseña. `using` / `IDisposable` garantiza el cierre de `SqliteConnection` y `SqliteCommand` incluso en el camino de excepcion, sin fugas de conexion.

Este stack es el espejo exacto del Java: mismo esquema, mismas queries, mismos resultados fila por fila. Para ver contencion sobre un recurso externo compartido (pool FPM contra PostgreSQL via socket TCP), ver el stack PHP (`../php/README.md`).
