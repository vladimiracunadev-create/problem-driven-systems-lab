# Caso 01 — .NET 8

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

**Legacy** — scan lineal (no sargable) + N+1 contra customers:
```csharp
foreach (var o in orders)
    if (o.Region.ToLowerInvariant().StartsWith("n")) scanned.Add(o);
for (int i = 0; i < take; i++) {
    Customer c = LookupCustomerOneByOne(o.CustomerId);   // busqueda lineal
    Thread.SpinWait(1200);                                // costo de roundtrip
}
```

**Optimized** — lookup indexado + batch + cache del worker:
```csharp
var matched = ordersByRegionPrefix.GetValueOrDefault("n", new List<Order>());   // O(1)
for (int i = 0; i < take; i++) {
    if (!batch.ContainsKey(cid)) batch[cid] = customerById[cid];                 // O(1)
}
SleepMicros(700);                                                                // 1 roundtrip
var s = summaryCache[o.CustomerId];                                              // ConcurrentDictionary
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
