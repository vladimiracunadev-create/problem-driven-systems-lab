# 🔵 Caso 04 — .NET 8

<!-- nav-stack -->
[⬅️ Caso 04](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🔵 Perfil de .NET](../../../docs/languages/dotnet.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack .NET operativo del caso 04. Contraste entre retry storm (5 reintentos sin backoff) vs circuit breaker con timeout cooperativo + fallback cacheado.

## Primitivas .NET nativas

| Primitiva | Rol |
|---|---|
| `CancellationTokenSource(TimeSpan)` | Deadline cooperativo a nivel `Task`. Si el provider no responde en 300ms, se cancela. |
| `Interlocked.CompareExchange` | Transiciones `closed → open → half_open` del breaker sin lock. CAS explicito sobre el estado. |
| `record BreakerState(string State, int FailCount, DateTime OpenedAt)` | Snapshot inmutable del estado del breaker. |
| `Interlocked.Increment` | Contadores de `legacy_retries`, `resilient_short_circuits`, `resilient_fallbacks`. |

## Contraste

**Legacy** — retry storm sin breaker, sin backoff:
```csharp
for (int attempt = 1; attempt <= 5; attempt++) {
    Interlocked.Increment(ref legacyRetries);
    try { return CallProvider(fail, 800); }
    catch { /* sin backoff */ }
}
```

**Resilient** — short-circuit cuando breaker abierto + task con timeout + fallback:
```csharp
var st = breaker;   // snapshot
if (st.State == "open" && CooldownNotElapsed(st)) {
    return Fallback(lastFallbackPrice);   // sin tocar al provider
}
using var cts = new CancellationTokenSource(TimeSpan.FromMilliseconds(300));
var task = Task.Run(() => CallProvider(fail, 800), cts.Token);
```

Tras 3 fallos consecutivos el breaker pasa a `open` durante 5s (CAS via `Interlocked.CompareExchange`).

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
docker compose -f compose.dotnet.yml up -d --build
# generar 3 fallos para abrir el breaker
for i in 1 2 3; do curl -s "http://127.0.0.1:8500/04/quote-resilient?fail=on"; done
curl http://127.0.0.1:8500/04/dependency/state
# proximo call sera short_circuited sin tocar al provider
curl "http://127.0.0.1:8500/04/quote-resilient?fail=on"
```

## Modo aislado

```
docker compose -f cases/04-timeout-chain-and-retry-storms/dotnet/compose.yml up -d --build
curl http://127.0.0.1:854/health
```

## Por que `CancellationToken` y no `Thread.Abort`

`Thread.Abort` esta deprecado (y nunca fue seguro). `CancellationToken` es la API cooperativa idiomatica de .NET: cada `await` chequea el token, y un `OperationCanceledException` se propaga limpio por la cadena `await`. Para HTTP real usariamos `HttpClient.SendAsync(req, cts.Token)` — misma idea. Es exactamente el equivalente del `CompletableFuture.orTimeout` de Java.
