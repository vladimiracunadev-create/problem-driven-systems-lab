# Caso 04 — Java 21

Stack Java operativo del caso 04. Contraste entre retry storm (5 reintentos sin backoff) vs circuit breaker con timeout cooperativo + fallback cacheado.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `CompletableFuture.orTimeout(Duration)` | Deadline cooperativo a nivel future. Si el provider no responde en 300ms, se cancela. |
| `AtomicReference<BreakerState>` | Transiciones `closed → open → half_open` sin lock. CAS implicito en `set()`. |
| `record BreakerState(state, failCount, openedAt)` | Snapshot inmutable del estado del breaker. |
| `LongAdder` | Contadores de `legacy_retries`, `resilient_short_circuits`, `resilient_fallbacks`. |

## Contraste

**Legacy** — retry storm sin breaker, sin backoff:
```java
for (int attempt = 1; attempt <= 5; attempt++) {
    legacyRetries.increment();
    try { return callProvider(fail, 800); }
    catch (Exception e) { /* sin backoff */ }
}
```

**Resilient** — short-circuit cuando breaker abierto + future con timeout + fallback:
```java
BreakerState st = breaker.get();
if ("open".equals(st.state) && cooldownNotElapsed(st)) {
    return fallback(lastFallbackPrice.get());  // sin tocar al provider
}
CompletableFuture<Long> fut = CompletableFuture
    .supplyAsync(() -> callProviderUnchecked(fail, 800))
    .orTimeout(300, TimeUnit.MILLISECONDS);
```

Tras 3 fallos consecutivos el breaker pasa a `open` durante 5s.

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/quote-legacy?fail=on` | 5 reintentos secuenciales hasta agotarse |
| `/quote-resilient?fail=on` | timeout 300ms + breaker; tras 3 fallos pasa a fallback inmediato |
| `/dependency/state` | estado actual del breaker + cooldown restante |
| `/diagnostics/summary` | totales por variante |
| `/reset-lab` | limpia contadores y cierra el breaker |

## Hub

```
docker compose -f compose.java.yml up -d --build
# generar 3 fallos para abrir el breaker
for i in 1 2 3; do curl -s "http://127.0.0.1:8400/04/quote-resilient?fail=on"; done
curl http://127.0.0.1:8400/04/dependency/state
# proximo call sera short_circuited sin tocar al provider
curl "http://127.0.0.1:8400/04/quote-resilient?fail=on"
```

## Por que CompletableFuture y no Thread.interrupt()

`CompletableFuture.orTimeout()` es cooperativo y limpio: el future entra en estado `completedExceptionally(TimeoutException)` y el chain de continuations recibe el error. `Thread.interrupt()` sobre un `Thread.sleep()` funciona, pero no se propaga a I/O bloqueante real (sockets, FileChannel). Para HTTP real usariamos `HttpClient` con `Duration` timeout — misma idea.
