# Caso 11 — Java 21

Stack Java operativo del caso 11. Saturacion del pool principal vs aislamiento por ExecutorService dedicado.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `ThreadPoolExecutor` (acotado a 4 threads) | Pool principal — capacidad finita realista para mostrar saturacion. |
| `ThreadPoolExecutor.getActiveCount() / getQueue().size()` | Telemetria directa del pool, sin agente — `event_loop_lag` del mundo Java. |
| `ExecutorService` (`reportingPool`) | Pool dedicado para trabajo CPU-bound de reporting, separado del main. |
| `CompletableFuture.supplyAsync(task, executor)` | Submission explicita al pool correcto. |

## Contraste

**Legacy** — reporting bloquea threads del pool principal:
```java
// /report-legacy corre SINCRONO en el thread del HttpServer (mainPool)
for (int i = 0; i < rows; i++) checksum += (i * 13L) % 7;
// → mainPool.getActiveCount sube; /order-write queda esperando turno
```

**Isolated** — reporting sale a pool dedicado:
```java
CompletableFuture.supplyAsync(() -> {
    for (int i = 0; i < rows; i++) checksum += (i * 13L) % 7;
    return checksum;
}, reportingPool)            // pool separado, mainPool intacto
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/report-legacy?rows=200000` | corre en mainPool, satura el pool — main_pool_active sube |
| `/report-isolated?rows=200000` | corre en reportingPool, main_pool_active estable |
| `/order-write` | escribe 20ms; `degraded=true` si el pool principal esta saturado |
| `/activity` | snapshot live: active, queue, pool_size, max |
| `/diagnostics/summary` | calls + comportamiento por variante |

## Hub

```
docker compose -f compose.java.yml up -d --build
# saturar con reports legacy
for i in 1 2 3 4 5; do curl -s "http://127.0.0.1:8400/11/report-legacy?rows=1000000" > /dev/null & done
# medir order-write
curl "http://127.0.0.1:8400/11/order-write"   # → degraded:true
# reset y misma carga aislada
curl http://127.0.0.1:8400/11/reset-lab
for i in 1 2 3 4 5; do curl -s "http://127.0.0.1:8400/11/report-isolated?rows=1000000" > /dev/null & done
curl "http://127.0.0.1:8400/11/order-write"   # → degraded:false
```

## Modo aislado (recomendado para este caso)

Puerto `8411`. Aislamiento sin contaminacion de otros casos del hub.

## Senal Java-especifica

Node tiene `monitorEventLoopDelay()` que mide lag del loop. Java no tiene event loop — el equivalente es **saturacion del thread pool**: `ThreadPoolExecutor.getActiveCount()` y `getQueue().size()` son la senal nativa. El lab los expone via `/activity` para diagnostico directo, sin agente.
