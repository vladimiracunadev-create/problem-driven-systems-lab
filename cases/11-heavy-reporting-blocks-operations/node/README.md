# Reportes pesados que bloquean la operacion — Node.js

> Implementacion operativa del caso 11 con paridad al stack PHP. Medicion del impacto sobre la operacion via `perf_hooks.monitorEventLoopDelay()` — la primitiva nativa de Node para detectar bloqueos del loop principal.

## Que resuelve

Compara dos formas de correr un reporte pesado:

- `report-legacy`: ejecuta el volcado en el path critico, bloqueando el event loop con CPU sincronico. La operacion concurrente (`/order-write`) lo paga: `event_loop_lag_ms_p99` sube y la latencia de escritura se degrada.
- `report-isolated`: programa el reporte fuera del path critico via `setImmediate` y lo manda a una "cola" virtual con replica/snapshot, dejando el primary libre.

Escenarios: `end_of_month`, `finance_audit`, `ad_hoc_export`, `mixed_peak`.

## Primitivas Node-especificas

- `monitorEventLoopDelay({ resolution: 10 })`: histograma del lag del event loop, expuesto como `p50/p99/max` en `/metrics` y Prometheus. Mide el impacto real, no estimado.
- En `report-legacy`, un `while (Date.now() < end) {}` simula CPU pesado bloqueante — equivalente a un long-running query sin yield. El histograma lo muestra crudo.
- En `report-isolated`, `await new Promise((r) => setImmediate(r))` cede el control al event loop antes de actualizar la cola, manteniendo `/order-write` responsivo.

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `8211`.

## Endpoints

```bash
curl http://localhost:8211/
curl http://localhost:8211/health
curl "http://localhost:8211/report-legacy?scenario=end_of_month&rows=600000"
curl "http://localhost:8211/report-isolated?scenario=end_of_month&rows=600000"
curl "http://localhost:8211/order-write?orders=25"
curl "http://localhost:8211/activity?limit=10"
curl http://localhost:8211/diagnostics/summary
curl http://localhost:8211/metrics
curl http://localhost:8211/metrics-prometheus
curl http://localhost:8211/reset-lab
```

## Que observar

- Tras correr `report-legacy` repetidamente, `event_loop_lag_ms_p99` y `event_loop_lag_ms_max` suben drasticamente;
- `/order-write` concurrente registra `write_latency_ms` mas alto durante la presion legacy;
- `report-isolated` mantiene `event_loop_lag_ms_p99` cercano al baseline y libera al primary;
- bajo `mixed_peak` con `rows` alto, legacy puede llegar a `pressure_level: critical` y devolver 503 — el sistema se autoprotege.
