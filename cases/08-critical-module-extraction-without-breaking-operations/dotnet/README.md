# 🔵 Caso 08 — .NET 8

<!-- nav-stack -->
[⬅️ Caso 08](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🔵 Perfil de .NET](../../../docs/languages/dotnet.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack .NET operativo del caso 08. Cutover gradual con proxy de compatibilidad de contrato + event bus thread-safe basado en `event Action`.

## Primitivas .NET nativas

| Primitiva | Rol |
|---|---|
| `Func<PriceRequestOld, PriceRequestNew>` | Proxy de compatibilidad. Traduce contrato viejo `{cost_usd}` ↔ nuevo `{price, currency}` en vuelo. |
| `event Action<string>` + `ImmutableList<Action<string>>` | EventBus thread-safe. Reads paralelos sin lock; writes (subscribe/unsubscribe) generan una copia. Espejo del `EventEmitter` Node y del `CopyOnWriteArrayList` Java. |
| `record PriceRequestOld/New` | Snapshots inmutables de cada contrato. |
| `ConcurrentDictionary<string, bool>` | Progreso de cutover por consumer. |

## Contraste

**Big-bang** — cambio de contrato rompe consumers sensibles:
```csharp
// Nuevo modulo solo entiende {Price, Currency}; consumer manda {CostUsd}
return "contract_violation";   // checkout, partners, backoffice todos rotos
```

**Compatible** — proxy traduce old→new + event bus notifica avance:
```csharp
PriceRequestNew translated = compatProxy(old);   // {CostUsd}→{Price,Currency}
cutoverProgress[consumer] = true;
Emit($"cutover_done:{consumer}");                 // bus notifica suscriptores
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/pricing-bigbang?consumer=checkout&sku=ABC&cost_usd=100` | contract_violation (rompe) |
| `/pricing-compatible?consumer=checkout&sku=ABC&cost_usd=100` | translated payload + cutover_done=true + emite evento |
| `/flows` | cutover_progress por consumer + recent_events (max 50) |
| `/diagnostics/summary` | proxy_hits, contract_tests_passed, bigbang_broken |
| `/reset-lab` | limpia state |

## Hub

```
docker compose -f compose.dotnet.yml up -d --build
curl "http://127.0.0.1:8500/08/pricing-compatible?consumer=checkout&sku=ABC&cost_usd=100"
curl http://127.0.0.1:8500/08/flows
```

## Modo aislado

```
docker compose -f cases/08-critical-module-extraction-without-breaking-operations/dotnet/compose.yml up -d --build
curl http://127.0.0.1:858/health
```

## Por que `ImmutableList<Action<string>>` y no `List<Action<string>>` con lock

Reads del event bus son **frecuentes** (cada emit recorre todos los suscriptores). Writes (add/remove subscriber) son **raros**. `ImmutableList<T>` es exactamente este trade-off: lectores nunca se bloquean ni copian; escritores generan una nueva lista persistente (caro, pero infrecuente). Espejo arquitectonico del `EventEmitter` Node y del `CopyOnWriteArrayList<Consumer>` Java — el mismo problema resuelto con la primitiva idiomatica de cada plataforma.
