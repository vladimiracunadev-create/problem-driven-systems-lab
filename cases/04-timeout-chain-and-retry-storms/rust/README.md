# 🦀 Caso 04 — Rust 1.83

<!-- nav-stack -->
[⬅️ Caso 04](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🦀 Perfil de Rust](../../../docs/languages/rust.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Rust operativo del caso 04. Reintentos sin control vs deadline + circuit breaker + fallback.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `mpsc::channel` + `recv_timeout` | Deadline **del lado del llamador**: corta la espera a los 300 ms. |
| `Mutex<BreakerState>` | Estado del breaker. El guard libera al salir de scope: no hay unlock que olvidar. |
| `AtomicI64` | Contadores de retries, fallbacks y short circuits. |

## Contraste

**Legacy** — 5 reintentos, sin backoff, sin deadline, sin breaker:
```rust
for attempt in 1..=LEGACY_MAX_ATTEMPTS {
    if let Ok(quote) = call_provider_blocking(fail) {   // 800 ms cada uno
        return ok;
    }
}                                                       // ~4 s antes de rendirse
```

**Resilient** — corta a los 300 ms y abre el breaker a los 3 fallos:
```rust
fn call_with_deadline(fail: bool, deadline_ms: u64) -> Result<i64, &'static str> {
    let (tx, rx) = mpsc::channel();
    thread::spawn(move || { let _ = tx.send(call_provider_blocking(fail)); });
    match rx.recv_timeout(Duration::from_millis(deadline_ms)) {
        Ok(inner) => inner,
        Err(_) => Err("timeout"),
    }
}
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/quote-legacy?fail=on` | 5 intentos, ~4 s, `status: failed` |
| `/quote-resilient?fail=on` | corta en ~300 ms con `cause: timeout`; tras 3 fallos, `short_circuited` en ~0 ms |
| `/dependency/state` | estado del breaker y `cooldown_left_ms` |
| `/diagnostics/summary` | retries, fallbacks y short circuits acumulados |
| `/reset-lab` | limpia contadores y cierra el breaker |

## Hub

```
docker compose -f compose.rust.yml up -d --build
curl "http://127.0.0.1:8700/04/quote-resilient?fail=on"
curl http://127.0.0.1:8700/04/dependency/state
```

## La limitacion que este stack NO puede ocultar

`recv_timeout` corta **la espera**, no **el trabajo**. El thread lanzado sigue durmiendo sus 800 ms hasta terminar; el llamador ya devolvio el fallback, pero el recurso sigue ocupado.

Es exactamente la misma limitacion que tiene `CompletableFuture.orTimeout()` en Java — y es **peor que lo que logra Go**, donde `context.WithTimeout` propaga la cancelacion al callee y este abandona de verdad con un `select` sobre `ctx.Done()`.

La razon es estructural: `std` de Rust no tiene runtime asincronico ni cancelacion cooperativa. Eso vive en `tokio`, donde `tokio::time::timeout` sobre un future si abandona el trabajo pendiente. Mantener el caso con cero dependencias tiene este costo concreto, y esconderlo seria justamente el tipo de afirmacion que este laboratorio evita.

**Lo que Rust si aporta aca:** el `MutexGuard` del breaker libera al salir de scope, siempre. En Go, un `mu.Lock()` sin su `defer mu.Unlock()` en algun camino de error es un deadlock silencioso que compila. Esa categoria de bug no existe en este codigo porque no hay unlock que escribir.
