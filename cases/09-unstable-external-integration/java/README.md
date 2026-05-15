# Caso 09 — Java 21

Stack Java operativo del caso 09. Adapter endurecido con budget de cuota + snapshot cache + breaker.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `Semaphore` | Budget de cuota: `tryAcquire()` no bloquea — si no hay permits, sirve snapshot. Permits explicitos = cuota explicita. |
| `ConcurrentHashMap<String, String>` | Snapshot cache thread-safe leida cuando el provider falla o esta agotado. |
| `AtomicReference<String>` | Estado del breaker (`closed`/`open`/`half_open`) con CAS implicito. |
| `LongAdder` | Contadores: calls, served_from_cache, budget_denied. |

## Contraste

**Legacy** — cada request golpea al provider sin proteccion:
```java
if (drift) {
    legacyFailures.increment();
    return "{\"status\":\"failed\"}";   // sin fallback
}
```

**Hardened** — budget + cache + breaker:
```java
if (!providerBudget.tryAcquire()) return fromSnapshot(...);    // budget agotado
if (drift) { breaker.set("open"); return fromSnapshot(...); }  // provider failing
String fresh = callProvider(...);                              // success path
snapshotCache.put(sku, fresh);                                  // refresca cache
breaker.set("closed");
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
docker compose -f compose.java.yml up -d --build
# agotar budget (5 calls)
for i in 1 2 3 4 5 6 7; do curl -s "http://127.0.0.1:8400/09/catalog-hardened?sku=widget-A" | head -c 100; echo; done
# proximo call será served_from=snapshot_cache budget_exhausted
curl http://127.0.0.1:8400/09/sync-events
```

## Modo aislado

Puerto `849`.

## Por que `Semaphore` y no contador manual

Un contador `AtomicInteger` con `compareAndSet` funciona pero hay que escribir el loop CAS a mano. `Semaphore.tryAcquire()` es la API que ya implementa "intenta tomar un permit, si no hay, devuelve false sin bloquear". Mas legible, menos bug-prone, y se mapea directo al concepto de cuota.
