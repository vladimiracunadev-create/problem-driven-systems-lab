# ⚡ Caso 01 — Node.js 20 + datos en memoria + worker embebido

> Implementacion operativa del caso 01 para estudiar latencia bajo carga con evidencia observable, manteniendo paridad funcional con la version PHP+Postgres y Python+SQLite, pero apoyada en las primitivas naturales de Node: event loop, async/await y `Promise.all`.

## 🎯 Que resuelve

Modela una API de reportes de "top customers" con dos variantes:

- `report-legacy`: agregacion sobre datos transaccionales + patron N+1 enriqueciendo cada fila con `await` secuencial.
- `report-optimized`: lectura sobre tabla resumen pre-calculada por un worker embebido + un solo batch en memoria para los detalles.

El escenario no se queda en un `setTimeout`. Mantiene un worker `setInterval` real que recalcula el resumen y deja visible la convivencia API/batch sobre el mismo proceso.

## 💼 Por que importa

Este caso muestra como se pasa de una API que "parece funcionar" a una implementacion que deja evidencia medible de por que degrada y como se corrige. La diferencia es visible en `db_queries`, `db_time_ms` **y** en `event_loop_lag_ms` — esta ultima es la senal especifica del runtime que delata el bloqueo agregado del loop por `await` secuencial.

## 🔬 Analisis Tecnico de la Implementacion (Node.js)

El cuello de botella clasico de N+1 en Node se ve agravado por la naturaleza single-thread del runtime: una request lenta no bloquea al kernel, pero cada `await` cede al event loop y el costo de N round-trips se traduce en latencia agregada que degrada al *resto* de las requests concurrentes en el proceso.

- **Implementacion Falla (`legacy`):** `topCustomersLegacy()` ejecuta una agregacion inicial sobre la lista de orders y luego entra en un bucle `for (const row of aggregated)` donde aplica dos `await` dependientes por iteracion: lookup de cliente y `recent_orders`. Cada await se serializa contra `setTimeout(..., 1.2)` que simula round-trip de I/O. El resultado es un patron `1 + N + N` con latencia que crece linealmente con `limit`. La medicion `event_loop_lag_ms` (sample tomado entre `setImmediate` y la callback) crece con la presion porque las microtasks de cada await se intercalan con otras requests.

- **Sanitizacion Algoritmica (`optimized`):** `topCustomersOptimized()` resuelve el mismo conjunto con dos lecturas fijas. Toma la agregacion del resumen ya pre-calculado por el worker, construye un `Set` de IDs y agrupa los recent orders en una sola pasada O(N) usando `Map`. El detalle se ensambla en memoria con acceso `O(1)` por clave. Sin bucles `await` anidados y sin yield innecesarios al loop.

- **Worker concurrente:** `startWorker()` usa `setInterval(..., 20000).unref()` y ejecuta `refreshSummaryOnce()` que recalcula `summaryByCustomer` (un `Map<customer_id, Map<day, entry>>`). El `unref()` permite que el proceso muera limpio si solo queda el timer. La concurrencia API/worker es real: ambos comparten estructuras en memoria y el observable es la latencia conjunta.

## 🧱 Servicio

- `app` → API Node.js 20 con rutas legacy y optimized, worker embebido (`setInterval`) y datos en memoria autocontenidos.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `821`.

## 🔎 Endpoints

```bash
curl http://localhost:821/
curl http://localhost:821/health
curl "http://localhost:821/report-legacy?days=30&limit=20"
curl "http://localhost:821/report-optimized?days=30&limit=20"
curl http://localhost:821/batch/status
curl "http://localhost:821/job-runs?limit=10"
curl http://localhost:821/diagnostics/summary
curl http://localhost:821/metrics
curl http://localhost:821/metrics-prometheus
curl http://localhost:821/reset-metrics
```

## 🧭 Que observar

- `db_queries` en `report-legacy` crece como `1 + N + N` con `limit`;
- `report-optimized` mantiene 2 consultas independientemente del `limit`;
- `/batch/status` muestra el estado del worker embebido y su ultima ejecucion;
- `/diagnostics/summary` compara latencias p95 de ambas rutas y agrega `event_loop_lag_ms`, la senal Node-especifica;
- bajo carga concurrente real, `event_loop_lag_ms` se dispara para `report-legacy` y se mantiene plano para `report-optimized`.

## ⚖️ Nota de honestidad

Los datos viven en memoria con I/O simulado por `setTimeout` para mantener foco en el patron de acceso (no en el motor de DB). El lab no benchmarkea Node contra otros runtimes; demuestra diagnostico y remediacion del patron N+1 con evidencia observable, agregando la metrica de event loop lag que es propia del runtime.
