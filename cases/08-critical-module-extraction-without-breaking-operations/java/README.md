# Caso 08 — Java 21

Stack Java operativo del caso 08. Cutover gradual con proxy de compatibilidad de contrato + event bus thread-safe.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `Function<PriceRequestOld, PriceRequestNew>` | Proxy de compatibilidad. Traduce contrato viejo `{cost_usd}` ↔ nuevo `{price, currency}` en vuelo. |
| `CopyOnWriteArrayList<Consumer<String>>` | EventBus thread-safe — equivalente al `EventEmitter` de Node, sin libreria externa. Reads paralelos sin lock; writes raros copian el array. |
| `record PriceRequestOld/New` | Snapshots inmutables de cada contrato. |
| `ConcurrentHashMap<String, Boolean>` | Progreso de cutover por consumer. |

## Contraste

**Big-bang** — cambio de contrato rompe consumers sensibles:
```java
// Nuevo modulo solo entiende {price, currency}; consumer manda {cost_usd}
return "contract_violation";   // checkout, partners, backoffice todos rotos
```

**Compatible** — proxy traduce old→new + event bus notifica avance:
```java
PriceRequestNew translated = compatProxy.apply(old);   // {cost_usd}→{price,currency}
cutoverProgress.put(consumer, true);
emit("cutover_done:" + consumer);                       // bus notifica suscriptores
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
docker compose -f compose.java.yml up -d --build
curl "http://127.0.0.1:8400/08/pricing-compatible?consumer=checkout&sku=ABC&cost_usd=100"
curl http://127.0.0.1:8400/08/flows
```

## Modo aislado

Puerto `848`.

## Por que `CopyOnWriteArrayList` y no `synchronized List`

Reads del event bus son **frecuentes** (cada emit recorre todos los suscriptores). Writes (add/remove subscriber) son **raros**. `CopyOnWriteArrayList` es exactamente este trade-off: lectores no se bloquean nunca; escritores copian todo el array (caro, pero infrecuente). Espejo arquitectonico del `EventEmitter` Node sin dependencias.
