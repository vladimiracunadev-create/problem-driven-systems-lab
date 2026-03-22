# Benchmark antes/después del caso 1

## Objetivo
Comparar de forma reproducible la ruta defectuosa (`/report-legacy`) contra la ruta corregida (`/report-optimized`) usando el mismo hardware acotado, la misma base y el mismo proceso crítico concurrente.

## Qué debe quedar demostrado
- La versión legacy usa más queries por request.
- La versión legacy pasa más tiempo en DB.
- La versión legacy debería mostrar peor `avg`, `p95` y `p99` cuando el worker está refrescando el resumen.
- La versión optimizada debería convivir mejor con la presión operacional.

## Preparación
Levanta el caso real:

```bash
make case-up CASE=01-api-latency-under-load STACK=php
```

Observabilidad disponible:
- API: `http://localhost:811`
- Prometheus: `http://localhost:9091`
- Grafana: `http://localhost:3001` (admin/admin)
- Postgres exporter: `http://localhost:9187/metrics`

## Ruta rápida manual
```bash
curl http://localhost:811/reset-metrics
make case-load CASE=01-api-latency-under-load TARGET_URL="http://php-app:8080/report-legacy?days=30&limit=20" REQUESTS=60 CONCURRENCY=8
curl http://localhost:811/metrics | jq
curl http://localhost:811/diagnostics/summary | jq
```

Luego repite con:

```bash
make case-load CASE=01-api-latency-under-load TARGET_URL="http://php-app:8080/report-optimized?days=30&limit=20" REQUESTS=60 CONCURRENCY=8
```

## Ruta automatizada
```bash
bash cases/01-api-latency-under-load/shared/benchmark/run-benchmark.sh
```

Artefactos generados:
- `legacy-loadtest.json`
- `legacy-metrics.json`
- `legacy-diagnostics.json`
- `legacy-worker.json`
- `optimized-loadtest.json`
- `optimized-metrics.json`
- `optimized-diagnostics.json`
- `optimized-worker.json`

## Cómo interpretar
### Señales de mejora real
- `p95_ms` y `p99_ms` bajan de forma clara.
- `avg_db_queries` baja.
- `avg_db_time_ms` baja.
- El worker mantiene heartbeat sano y duraciones razonables.
- El exporter de PostgreSQL no muestra saturación sostenida en conexiones o actividad anómala de BGWriter/checkpoints para esta escala del laboratorio.

### Señales de que el problema sigue vivo
- Legacy y optimized quedan casi iguales.
- `avg_db_queries` sigue alto en optimized.
- El worker tarda demasiado y su duración se dispara bajo carga.
- El beneficio se logra solo a costa de ocultar el problema, no de rediseñarlo.

## Notas de honestidad
Este benchmark no pretende reemplazar una prueba de producción real. Sí pretende dejar evidencia reproducible, con recursos acotados, de un patrón muy común: reportes que leen desde tablas transaccionales y se degradan cuando conviven con procesos críticos.
