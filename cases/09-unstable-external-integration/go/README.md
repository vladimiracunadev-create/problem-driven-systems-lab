# 🐹 Caso 09 — Go 1.23

<!-- nav-stack -->
[⬅️ Caso 09](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐹 Perfil de Go](../../../docs/languages/go.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Go operativo del caso 09. Provider inestable sin red de contencion vs budget de cuota + snapshot cache + breaker.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `chan struct{}` bufferizado | **Semaforo de cuota.** `struct{}` ocupa cero bytes: el canal es puro conteo. |
| `select` con `default` | El `tryAcquire()` no bloqueante, sin API extra. |
| `sync.RWMutex` | Snapshot cache con lecturas concurrentes. |
| `atomic.Value` | Estado del breaker sin lock. |

## Contraste

**Legacy** — cada request pega al provider; un drift de esquema es un fallo al usuario:
```go
if isDrift(scenario) {
    return map[string]any{"status": "failed", ...}   // sin cache, sin budget
}
```

**Hardened** — primero el budget, despues el provider, y snapshot si algo falla:
```go
if !tryAcquireBudget() {
    return fromSnapshot(sku, "budget_exhausted", ...)
}
if isDrift(scenario) {
    breakerState.Store("open")
    return fromSnapshot(sku, "provider_failing", ...)
}
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/catalog-legacy?sku=widget-A&scenario=drift` | `status: failed` |
| `/catalog-hardened?sku=widget-A&scenario=drift` | `served_from_cache`, breaker `open` |
| `/catalog-hardened?...&scenario=ok` (×6) | las 5 primeras van al provider; la 6ª cae a cache por budget agotado |
| `/sync-events` | breaker, `budget_remaining`, tamaño del snapshot cache |
| `/diagnostics/summary` | llamadas, hits de cache y denegaciones por budget |
| `/reset-lab` | rellena el budget y cierra el breaker |

## Hub

```
docker compose -f compose.go.yml up -d --build
for i in $(seq 1 6); do curl -s "http://127.0.0.1:8600/09/catalog-hardened?sku=widget-A&scenario=ok" | head -c 120; echo; done
curl http://127.0.0.1:8600/09/sync-events
```

## Un canal bufferizado ES un semaforo

Java usa `Semaphore(5)` — una clase del paquete `java.util.concurrent`. Go no tiene semaforo en la stdlib, y no le hace falta:

```go
budget := make(chan struct{}, 5)   // 5 permisos

select {
case <-budget:   // adquirir
default:         // sin permisos → degradar a cache, sin bloquear
}
```

Dos detalles que no son cosmeticos:

- `struct{}` tiene **tamaño cero**. El canal no guarda datos, solo cuenta. El costo de memoria del semaforo es el del buffer de slots, nada mas.
- El `select` con `default` da el `tryAcquire()` no bloqueante sin aprender otra API. Es la **misma primitiva** que ya se uso para el timeout del caso 04 y para el bus del caso 08.

Ese es el argumento de fondo de la concurrencia en Go: canal + `select` cubren semaforo, cola, timeout, cancelacion, fan-in y pipeline. En Java cada uno de esos es una clase distinta con su propio contrato — `Semaphore`, `BlockingQueue`, `CompletableFuture`, `CountDownLatch`. Menos abstracciones que aprender; a cambio, mas codigo explicito por cada una.
