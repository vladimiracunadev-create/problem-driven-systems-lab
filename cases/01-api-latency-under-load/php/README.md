# Caso 01 — PHP 8 + PostgreSQL + worker concurrente

Esta implementación es la versión **real** del caso 1.

## Qué resuelve
Modela una API de reportes con dos variantes:

- `report-legacy`: consulta defectuosa sobre tabla transaccional + N+1
- `report-optimized`: lectura sobre tabla resumen refrescada por worker

Además levanta un proceso crítico concurrente (`worker`) que recalcula el resumen periódicamente.

## Servicios
- `app` → API PHP 8
- `db` → PostgreSQL 16 con datos semilla
- `worker` → refresco periódico de resumen y heartbeat operacional
- `postgres-exporter` → métricas del motor PostgreSQL
- `prometheus` → scraping de la app y la base
- `grafana` → dashboard inicial del caso

## Arranque
```bash
docker compose -f compose.yml up -d --build
```

## Endpoints
```bash
curl http://localhost:811/
curl http://localhost:811/health
curl "http://localhost:811/report-legacy?days=30&limit=20"
curl "http://localhost:811/report-optimized?days=30&limit=20"
curl http://localhost:811/batch/status
curl http://localhost:811/job-runs?limit=10
curl http://localhost:811/diagnostics/summary
curl http://localhost:811/metrics
curl http://localhost:811/metrics-prometheus
```

## Observabilidad
- Prometheus: `http://localhost:9091`
- Grafana: `http://localhost:3001` (`admin` / `admin`)
- PostgreSQL exporter: `http://localhost:9187/metrics`

## Benchmark sugerido
### Manual
```bash
curl http://localhost:811/reset-metrics
make case-load CASE=01-api-latency-under-load TARGET_URL="http://php-app:8080/report-legacy?days=30&limit=20" REQUESTS=60 CONCURRENCY=8
curl http://localhost:811/metrics | jq
curl http://localhost:811/diagnostics/summary | jq

curl http://localhost:811/reset-metrics
make case-load CASE=01-api-latency-under-load TARGET_URL="http://php-app:8080/report-optimized?days=30&limit=20" REQUESTS=60 CONCURRENCY=8
curl http://localhost:811/metrics | jq
curl http://localhost:811/diagnostics/summary | jq
```

### Automatizado
```bash
bash ../shared/benchmark/run-benchmark.sh
```

## Qué observar
- diferencia de latencia entre legacy y optimized,
- diferencia de `avg_db_queries` y `avg_db_time_ms`,
- duración de los refresh del worker,
- efecto del proceso concurrente sobre las lecturas,
- si la mejora es real o solo aparente.

## Perfil de recursos
El compose usa un perfil deliberadamente acotado para hacer visible la degradación sin requerir infraestructura grande:
- app: ~1 CPU / 512 MB
- db: ~1.5 CPU / 1 GB
- worker: ~0.75 CPU / 256 MB

No pretende ser réplica exacta de producción; sí busca aproximar presión relativa de recursos de forma honesta y reproducible.
