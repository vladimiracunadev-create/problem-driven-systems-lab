# ⚡ Caso 01 — Node.js 22 + `node:sqlite` + worker embebido

> Implementacion operativa del caso 01 para estudiar latencia bajo carga con evidencia observable, manteniendo paridad funcional con la version PHP+Postgres y Python+SQLite, pero apoyada en las primitivas naturales de Node: event loop, async/await y el modulo built-in `node:sqlite`.

## 🎯 Que resuelve

Modela una API de reportes de "top customers" con dos variantes:

- `report-legacy`: agregacion con filtro no sargable sobre la tabla transaccional + patron N+1 enriqueciendo cada fila con dos queries dependientes.
- `report-optimized`: lectura sobre `customer_daily_summary` (mantenida por un worker embebido) + un solo batch con window function para los detalles.

El escenario no se queda en un `setTimeout`: el SQL es real contra SQLite embebido, y un worker `setInterval` recalcula el resumen con `DELETE` + `INSERT ... SELECT`, dejando visible la convivencia API/batch sobre el mismo proceso.

## 💼 Por que importa

Este caso muestra como se pasa de una API que "parece funcionar" a una implementacion que deja evidencia medible de por que degrada y como se corrige. La diferencia es visible en `db_queries`, `db_time_ms` **y** en `event_loop_lag_ms` — esta ultima es la senal especifica del runtime que delata el bloqueo agregado del loop por `await` secuencial.

## 🔬 Analisis Tecnico de la Implementacion (Node.js)

El cuello de botella clasico de N+1 en Node se ve agravado por la naturaleza single-thread del runtime: una request lenta no bloquea al kernel, pero cada `await` cede al event loop y el costo de N round-trips se traduce en latencia agregada que degrada al *resto* de las requests concurrentes en el proceso.

- **Implementacion Falla (`legacy`):** `topCustomersLegacy()` ejecuta una agregacion con `CAST(created_at / 86400 AS INTEGER) >= ?` — el `CAST` envuelve la columna e invalida `idx_orders_created_customer`, asi que el motor recorre las 36.000 filas de `orders`. Despues entra en un bucle `for (const row of rows)` con dos queries dependientes por iteracion: lookup de cliente y `recent_orders`. El resultado es `1 + 2N` ejecuciones reales (`db_queries_in_request: 41` con `limit=20`). La medicion `event_loop_lag_ms` (sample entre `setImmediate` y la callback) crece con la presion porque `DatabaseSync` es sincronico: cada query bloquea el loop mientras corre.

- **Sanitizacion Algoritmica (`optimized`):** `topCustomersOptimized()` resuelve el mismo conjunto con **2 queries fijas**. La primera lee `customer_daily_summary` con un rango sargable sobre `order_date`; la segunda trae los ultimos 3 pedidos de todos los clientes de una vez con `ROW_NUMBER() OVER (PARTITION BY customer_id ORDER BY created_at DESC)`. El agrupado posterior es un `Map` O(1), pero sobre un resultado que ya vino resuelto del motor.

- **Worker concurrente:** `startWorker()` usa `setInterval(..., 20000).unref()` y ejecuta `refreshSummaryOnce()`, que hace `DELETE FROM customer_daily_summary` + `INSERT ... SELECT ... GROUP BY` dentro de una transaccion y registra la corrida en `job_runs`. El `unref()` permite que el proceso muera limpio si solo queda el timer.

## 🧱 Servicio

- `app` → API Node.js 22 con rutas legacy y optimized, worker embebido (`setInterval`) y SQLite embebido via `node:sqlite`, sin dependencias externas.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `821` (modo aislado, ver opciones abajo).

## Como consumir (dos opciones)

**Hub Node.js (recomendado, 8300 en `compose.nodejs.yml`):** este caso queda servido en `http://localhost:8300/01/...` junto a los otros 11 casos.

**Modo aislado (821 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## 🔎 Endpoints

```bash
curl http://localhost:8300/01/
curl http://localhost:8300/01/health
curl "http://localhost:8300/01/report-legacy?days=30&limit=20"
curl "http://localhost:8300/01/report-optimized?days=30&limit=20"
curl http://localhost:8300/01/batch/status
curl "http://localhost:8300/01/job-runs?limit=10"
curl http://localhost:8300/01/diagnostics/summary
curl http://localhost:8300/01/metrics
curl http://localhost:8300/01/metrics-prometheus
curl http://localhost:8300/01/reset-metrics
```

## 🧭 Que observar

- `db_queries` en `report-legacy` crece como `1 + N + N` con `limit`;
- `report-optimized` mantiene 2 consultas independientemente del `limit`;
- `/batch/status` muestra el estado del worker embebido y su ultima ejecucion;
- `/diagnostics/summary` compara latencias p95 de ambas rutas y agrega `event_loop_lag_ms`, la senal Node-especifica;
- bajo carga concurrente real, `event_loop_lag_ms` se dispara para `report-legacy` y se mantiene plano para `report-optimized`.

## ⚖️ Nota de honestidad

El SQL es real contra SQLite embebido; lo unico artificial es el round-trip (`ROUNDTRIP_LEGACY_MS` / `ROUNDTRIP_DEFAULT_MS`), que modela el hop de red que un motor remoto tendria y SQLite embebido no. El lab no benchmarkea Node contra otros runtimes: demuestra diagnostico y remediacion del patron N+1 con evidencia observable, agregando la metrica de event loop lag que es propia del runtime.

## Fidelidad

**Substrato real.** Este stack corre SQL contra SQLite embebido via `node:sqlite` (`DatabaseSync`, built-in desde Node 22.5 — sin `npm install`, sin bindings nativos). `db_queries_in_request` cuenta ejecuciones reales contra el motor: 1 agregacion + 2 queries por fila en la ruta legacy, 2 queries totales en la optimizada.

El unico elemento artificial es el round-trip (`ROUNDTRIP_LEGACY_MS` / `ROUNDTRIP_DEFAULT_MS`): SQLite es embebido y no tiene hop de red, asi que esos milisegundos modelan el viaje cliente-servidor de un motor remoto. El trabajo SQL de abajo no se simula.

**Lo que este stack enseña y los otros no:** `DatabaseSync` es **sincronico**. Cada query del N+1 bloquea el event loop mientras corre. Por eso `event_loop_lag_ms` no es decorativo aca — es la señal que delata que el N+1 no penaliza solo a quien lo pide, sino al throughput del proceso entero.

Para ver contencion sobre un recurso externo compartido (pool FPM contra PostgreSQL via socket TCP), ver el stack PHP (`../php/README.md`).
