# Caso 01 — Java 21

Stack Java operativo del caso 01. Mismo problema que PHP/Python/Node: N+1 + filtro no sargable bajo carga + worker concurrente. Primitivas Java distintas.

## Primitivas nativas que aporta este stack

| Primitiva | Rol en el caso |
|---|---|
| `ConcurrentHashMap` | Cache de summary leida por `/report-optimized` sin lock. El worker actualiza, los handlers leen — sin contencion. |
| `LongAdder` | Contador `requests` por ruta. Mejor throughput que `synchronized` bajo carga concurrente real. |
| `ScheduledExecutorService` | Worker `report-refresh-java` corriendo cada 5s. Shutdown limpio en SIGTERM via shutdown hook. |
| `record` types | `Customer`, `Order`, `JobRun` inmutables sin boilerplate. |
| `HttpServer` (JDK built-in) | Sin frameworks externos, sin Maven. `javac Main.java` + `java Main`. |

## El contraste que esta linea de codigo expone

**Legacy** — scan lineal (no sargable) + N+1 contra customers:
```java
for (Order o : orders) if (lowerRegion(o.region).startsWith("n")) scanned.add(o);
for (int i = 0; i < take; i++) {
    Customer c = lookupCustomerOneByOne(o.customerId);  // busqueda lineal
    sleepMicros(1200);                                   // costo de roundtrip
}
```

**Optimized** — lookup indexado + batch + cache del worker:
```java
List<Order> matched = ordersByRegionPrefix.getOrDefault("n", List.of());   // O(1)
for (int i = 0; i < take; i++) {
    if (!batch.containsKey(cid)) batch.put(cid, customerById.get(cid));    // O(1)
}
sleepMicros(700);                                                           // 1 roundtrip
CustomerSummary s = summaryCache.get(o.customerId);                         // ConcurrentHashMap
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/report-legacy?limit=20` | N+1 + filtro no sargable, db_hits crece linealmente |
| `/report-optimized?limit=20` | 1 lookup indexado + 1 batch + O(1) en summary cache |
| `/batch/status` | ultimo heartbeat del worker |
| `/job-runs` | historial de corridas (max 30) |
| `/diagnostics/summary` | contraste legacy vs optimized en una vista |
| `/metrics` | avg/p95/p99 por ruta |
| `/reset-lab` | reinicia contadores e historico |

## Modo hub (recomendado)

```
docker compose -f compose.java.yml up -d --build
curl http://127.0.0.1:8400/01/health
curl "http://127.0.0.1:8400/01/report-optimized?limit=10"
```

## Modo aislado

```
docker compose -f cases/01-api-latency-under-load/java/compose.yml up -d --build
curl http://127.0.0.1:841/health
```

## Diferencias de runtime vs los otros stacks

- **vs PHP-FPM**: PHP crea proceso por request, no comparte estado en memoria. La cache de summary en Java vive en el heap del proceso unico — accesible por todos los handlers sin reconexion.
- **vs Python**: Python tiene GIL que serializa bytecode. JVM ejecuta handlers en paralelo real (limite por nucleos, no por GIL).
- **vs Node event loop**: Node es single-thread cooperativo. Java usa thread-per-request; `summaryCache` se lee concurrentemente sin yield y sin lock — eso es lo que `ConcurrentHashMap` garantiza.
