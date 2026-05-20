# Caso 11 — .NET 8

Stack .NET operativo del caso 11. Saturacion del `ThreadPool` principal vs aislamiento por `Thread` dedicado (o `ConcurrentExclusiveSchedulerPair`).

## Primitivas .NET nativas

| Primitiva | Rol |
|---|---|
| `ThreadPool.GetMaxThreads` / `ThreadPool.GetAvailableWorkerThreads` | Telemetria directa del pool, sin agente — `event_loop_lag` del mundo .NET. |
| `ThreadPool.SetMaxThreads(4, ...)` | Cap acotado para mostrar saturacion realista. |
| `Thread` dedicado o `ConcurrentExclusiveSchedulerPair.ExclusiveScheduler` | Aislamiento del trabajo CPU-bound de reporting, separado del pool principal. |
| `Task.Factory.StartNew(task, ..., scheduler)` | Submission explicita al scheduler correcto. |

## Contraste

**Legacy** — reporting bloquea threads del pool principal:
```csharp
// /report-legacy corre SINCRONO en el thread del HttpListener (mainPool)
long checksum = 0;
for (int i = 0; i < rows; i++) checksum += (i * 13L) % 7;
// → ThreadPool.GetAvailableWorkerThreads cae; /order-write queda esperando turno
```

**Isolated** — reporting sale a pool dedicado:
```csharp
private static readonly ConcurrentExclusiveSchedulerPair reportingPair = new();

await Task.Factory.StartNew(() => {
    long checksum = 0;
    for (int i = 0; i < rows; i++) checksum += (i * 13L) % 7;
    return checksum;
}, CancellationToken.None, TaskCreationOptions.LongRunning, reportingPair.ExclusiveScheduler);
// pool principal intacto
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/report-legacy?rows=200000` | corre en mainPool, satura el pool — `available_workers` cae |
| `/report-isolated?rows=200000` | corre en reportingPair, `available_workers` estable |
| `/order-write` | escribe 20ms; `degraded=true` si el pool principal esta saturado |
| `/activity` | snapshot live: available_workers, busy_workers, max |
| `/diagnostics/summary` | calls + comportamiento por variante |

## Hub

```
docker compose -f compose.dotnet.yml up -d --build
# saturar con reports legacy
for i in 1 2 3 4 5; do curl -s "http://127.0.0.1:8500/11/report-legacy?rows=1000000" > /dev/null & done
# medir order-write
curl "http://127.0.0.1:8500/11/order-write"   # → degraded:true
# reset y misma carga aislada
curl http://127.0.0.1:8500/11/reset-lab
for i in 1 2 3 4 5; do curl -s "http://127.0.0.1:8500/11/report-isolated?rows=1000000" > /dev/null & done
curl "http://127.0.0.1:8500/11/order-write"   # → degraded:false
```

## Modo aislado (recomendado para este caso)

```
docker compose -f cases/11-heavy-reporting-blocks-operations/dotnet/compose.yml up -d --build
curl http://127.0.0.1:8511/health
```

Aislamiento sin contaminacion de otros casos del hub.

## Senal .NET-especifica

Node tiene `monitorEventLoopDelay()` que mide lag del loop. Java tiene `ThreadPoolExecutor.getActiveCount()`. .NET tiene **`ThreadPool.GetAvailableWorkerThreads`**: muestra cuantos worker threads quedan disponibles antes de que el pool deba crear mas (con su penalty asociado). El lab lo expone via `/activity` para diagnostico directo, sin agente. Equivalente conceptual del `ThreadPoolExecutor.getActiveCount()` de Java; sin event loop como Node, el equivalente es justamente el thread pool del CLR.
