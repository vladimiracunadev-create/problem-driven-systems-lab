# 🔵 Caso 09 — .NET 8

<!-- nav-stack -->
[⬅️ Caso 09](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🔵 Perfil de .NET](../../../docs/languages/dotnet.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack .NET operativo del caso 09. Adapter endurecido con budget de cuota + snapshot cache + breaker.

## Primitivas .NET nativas

| Primitiva | Rol |
|---|---|
| `SemaphoreSlim` | Budget de cuota: `Wait(0)` no bloquea — si no hay permits, sirve snapshot. Permits explicitos = cuota explicita. |
| `MemoryCache` (Microsoft.Extensions.Caching.Memory) o `ConcurrentDictionary<string,string>` | Snapshot cache thread-safe leida cuando el provider falla o esta agotado. |
| `Interlocked.CompareExchange` | Estado del breaker (`closed`/`open`/`half_open`) con CAS explicito. |
| `Interlocked.Increment` | Contadores: calls, served_from_cache, budget_denied. |

## Contraste

**Legacy** — cada request golpea al provider sin proteccion:
```csharp
if (drift) {
    Interlocked.Increment(ref legacyFailures);
    return "{\"status\":\"failed\"}";   // sin fallback
}
```

**Hardened** — budget + cache + breaker:
```csharp
if (!providerBudget.Wait(0)) return FromSnapshot(sku);    // budget agotado
if (drift) { TripBreaker("open"); return FromSnapshot(sku); }  // provider failing
string fresh = CallProvider(sku);                          // success path
snapshotCache[sku] = fresh;                                 // refresca cache
TripBreaker("closed");
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/catalog-legacy?sku=widget-A&scenario=drift` | status=failed sin cache |
| `/catalog-hardened?sku=widget-A&scenario=drift` | served_from=snapshot_cache + breaker:open |
| `/catalog-hardened?sku=widget-A&scenario=ok` | served_from=provider + refresca cache |
| `/sync-events` | breaker state + budget_remaining + cache_size |
| `/diagnostics/summary` | contadores por variante |
| `/reset-lab` | restaura budget + cierra breaker |

## Hub

```
docker compose -f compose.dotnet.yml up -d --build
# agotar budget (5 calls)
for i in 1 2 3 4 5 6 7; do curl -s "http://127.0.0.1:8500/09/catalog-hardened?sku=widget-A" | head -c 100; echo; done
# proximo call será served_from=snapshot_cache budget_exhausted
curl http://127.0.0.1:8500/09/sync-events
```

## Modo aislado

```
docker compose -f cases/09-unstable-external-integration/dotnet/compose.yml up -d --build
curl http://127.0.0.1:859/health
```

## Por que `SemaphoreSlim.Wait(0)` y no contador manual

Un contador `int` con `Interlocked.CompareExchange` funciona pero hay que escribir el loop CAS a mano. `SemaphoreSlim.Wait(0)` es la API que ya implementa "intenta tomar un permit, si no hay, devuelve `false` sin bloquear". Mas legible, menos bug-prone, y se mapea directo al concepto de cuota. Es exactamente el equivalente del `Semaphore.tryAcquire()` de Java.
