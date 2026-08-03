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

**Legacy** — filtro no sargable + N+1 real contra el motor:
```java
// LOWER(region) envuelve la columna → idx_orders_region queda inutilizable.
"SELECT id, customer_id, region, amount FROM orders " +
"WHERE LOWER(region) LIKE 'n%' ORDER BY id LIMIT ?"

// ...y una query dependiente por cada fila devuelta.
for (int i = 0; i < ids.size(); i++) {
    try (PreparedStatement ps = db.prepareStatement(
            "SELECT name, tier FROM customers WHERE id = ?")) { ... }
    dbHits++;                                   // db_hits = 1 + N
}
```

**Optimized** — rango sargable + batches `IN(...)` + tabla resumen del worker:
```java
// Mismo predicado, reescrito como rango → recupera el indice.
"SELECT id, customer_id, region, amount FROM orders " +
"WHERE region >= 'n' AND region < 'o' ORDER BY id LIMIT ?"

// Un batch para customers y otro para el resumen. db_hits constante.
"SELECT id, name, tier FROM customers WHERE id IN (?,?,?,…)"
"SELECT customer_id, order_count, total_amount FROM customer_summary WHERE customer_id IN (…)"
```

Que el primero no use el indice y el segundo si no es una afirmacion del README — lo dice el planner:

```text
EXPLAIN QUERY PLAN … WHERE LOWER(region) LIKE 'n%'   →  SCAN orders
EXPLAIN QUERY PLAN … WHERE region >= 'n' AND < 'o'   →  SEARCH orders USING INDEX idx_orders_region
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

## Fidelidad

**Substrato real.** Este stack corre SQL contra SQLite embebido via `sqlite-jdbc` 3.46.1.3 (driver xerial), en archivo bajo `/tmp` y con `journal_mode=WAL`. No hay listas en memoria simulando ser una base: `db_hits` cuenta ejecuciones reales — `1 + N` en la ruta legacy, constante en la optimizada.

**El filtro no sargable lo confirma el planner, no el README:**

```text
LEGACY     WHERE LOWER(region) LIKE 'n%'          →  SCAN orders
OPTIMIZED  WHERE region >= 'n' AND region < 'o'   →  SEARCH orders USING INDEX idx_orders_region
```

Envolver la columna en `LOWER()` invalida `idx_orders_region`. El mismo predicado reescrito como rango lo recupera.

**Por que WAL y una conexion por request:** el worker escribe `customer_summary` mientras las rutas leen. Con WAL los lectores no se bloquean con el escritor — es el equivalente embebido del MVCC que da PostgreSQL en el stack PHP, y es exactamente la propiedad que este caso enseña. `try-with-resources` garantiza el cierre de `Connection` y `PreparedStatement` incluso en el camino de excepcion, sin fugas de conexion.

Para ver contencion sobre un recurso externo compartido (pool FPM contra PostgreSQL via socket TCP), ver el stack PHP (`../php/README.md`).
