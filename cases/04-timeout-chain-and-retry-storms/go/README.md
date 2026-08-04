# 🐹 Caso 04 — Go 1.23

<!-- nav-stack -->
[⬅️ Caso 04](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐹 Perfil de Go](../../../docs/languages/go.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Go operativo del caso 04. Reintentos sin control vs deadline cooperativo + circuit breaker + fallback.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `context.WithTimeout` | Deadline que **viaja hacia abajo** como señal de cancelacion, no solo un reloj para el llamador. |
| `select` + `<-ctx.Done()` | El proveedor abandona el trabajo apenas vence el deadline. |
| `sync.Mutex` sobre el estado del breaker | La transicion closed→open es una decision compuesta, no un swap atomico. |
| `errors.Is(err, context.DeadlineExceeded)` | Distingue timeout de error del proveedor sin comparar strings. |

## Contraste

**Legacy** — 5 reintentos, sin backoff, sin deadline propio, sin breaker:
```go
for attempt := 1; attempt <= 5; attempt++ {
    quote, err := callProvider(ctx, fail)   // 800 ms cada uno
    if err == nil { return ok }
}                                            // ~4 s de recurso ocupado antes de rendirse
```

**Resilient** — corta en 300 ms y abre el breaker a los 3 fallos:
```go
ctx, cancel := context.WithTimeout(parent, 300*time.Millisecond)
defer cancel()
quote, err := callProvider(ctx, fail)
```

Y el proveedor respeta la señal:
```go
select {
case <-time.After(providerLatency):  // 800 ms
    ...
case <-ctx.Done():                    // vence a los 300 ms → retorna YA
    return 0, ctx.Err()
}
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/quote-legacy?fail=on` | 5 intentos, ~4 s, `status: failed` |
| `/quote-resilient?fail=on` | corta en ~300 ms con `cause: timeout`; tras 3 fallos, `short_circuited` en ~0 ms |
| `/dependency/state` | estado del breaker, `cooldown_left_ms` |
| `/diagnostics/summary` | retries, fallbacks y short circuits acumulados |
| `/reset-lab` | limpia contadores y cierra el breaker |

## Hub

```
docker compose -f compose.go.yml up -d --build
curl "http://127.0.0.1:8600/04/quote-resilient?fail=on"
curl http://127.0.0.1:8600/04/dependency/state
```

## Por que este caso es el mas fuerte de Go

`CompletableFuture.orTimeout(300ms)` en Java completa el future excepcionalmente a los 300 ms — pero **el thread que estaba haciendo el `Thread.sleep(800)` sigue ahi hasta terminar**. El llamador cree que corto; el recurso sigue ocupado. Bajo retry storm, esa diferencia es la que decide si el pool se agota.

En Go el deadline es una señal que el callee observa. Al vencer, `callProvider` retorna de inmediato y la goroutine se libera. No es azucar sintactico sobre el mismo comportamiento: es cancelacion real, propagada por la cadena de llamadas.

El precio es disciplina: si una funcion ignora su `ctx`, la cancelacion no ocurre. Go no la impone — la hace posible y la deja visible en la firma.
