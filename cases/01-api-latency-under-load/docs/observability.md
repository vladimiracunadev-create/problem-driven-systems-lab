# Observabilidad del caso 1

## Capas disponibles
### 1. Métrica local de aplicación
- `/metrics`
- `/metrics-prometheus`
- `/diagnostics/summary`

Estas rutas permiten responder rápido:
- cuántos requests pasaron,
- cuánto tardaron,
- cuánto tiempo pasaron en DB,
- cuántas queries ejecutaron,
- cómo se comparan legacy y optimized,
- cómo está el worker.

### 2. Prometheus
Prometheus scrapea:
- la app (`/metrics-prometheus`)
- PostgreSQL exporter

URL:
- `http://localhost:9091`

### 3. Grafana
Se provisiona con datasource Prometheus y un dashboard inicial del caso.

URL:
- `http://localhost:3001`
- usuario: `admin`
- password: `admin`

### 4. PostgreSQL exporter
Expone métricas estándar del motor para ver conexiones, actividad y tamaño.

URL:
- `http://localhost:9187/metrics`

## Endpoints útiles de aplicación
```bash
curl http://localhost:811/metrics
curl http://localhost:811/metrics-prometheus
curl http://localhost:811/diagnostics/summary
curl http://localhost:811/job-runs?limit=10
curl http://localhost:811/batch/status
```

## Qué mirar primero
1. `avg_ms`, `p95_ms`, `p99_ms`
2. `avg_db_time_ms` y `p95_db_time_ms`
3. `avg_db_queries` y `p95_db_queries`
4. estado y duración del worker
5. si `report-optimized` realmente baja el costo por request

## Qué no promete esta observabilidad
- tracing distribuido completo
- profiling profundo por línea de código
- APM enterprise
- dashboards perfectos para todos los stacks

Sí deja una base honesta y útil para demostrar diagnóstico, comparación y mejora medible.
