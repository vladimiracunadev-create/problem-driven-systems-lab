# ⚡ Caso 01 - PHP 8 + PostgreSQL + worker concurrente

> Implementacion operativa real del caso 01 para estudiar latencia bajo carga con evidencia observable.

## 🎯 Que resuelve

Modela una API de reportes con dos variantes:

- `report-legacy`: consulta defectuosa sobre tabla transaccional con demasiada carga sobre DB.
- `report-optimized`: lectura sobre tabla resumen refrescada por un worker concurrente.

El escenario no se queda en un `sleep`. Mantiene un proceso de fondo que recalcula el resumen y deja visible la competencia real por recursos.

## 💻 Interfaz Visual Nativa

Al abrir la ruta raíz en tu navegador (`Accept: text/html`), este caso inyecta automáticamente un **Dashboard visual interactivo** renderizado en Vanilla JS/CSS. Esto permite observar las métricas y efectos simulados en tiempo real sin perder la capacidad de responder a consultas JSON de CLI o Postman.

## 💼 Por que importa

Este caso sirve para mostrar como se pasa de una API que "parece funcionar" a una implementacion que deja evidencia medible de por que degrada y como se corrige sin adivinar infraestructura.

## 🔬 Análisis Técnico de la Implementación (PHP)

A nivel de código dentro del ecosistema PHP, este caso expone el riesgo del acoplamiento entre la latencia de la base de datos y la capacidad de despacho del runtime:

*   **Implementación `legacy`:** Depende de iteraciones sobre un `fetchAll()` mediante ciclos `foreach`, donde internamente la función `timedQuery()` levanta consultas `SELECT` por cada usuario usando `PDOStatement->fetch()`. Esto bloquea el hilo de PHP induciendo una alta latencia de entrada/salida (*I/O bound*) mientras el recurso relacional es severamente penalizado.
*   **Aproximación `optimized`:** Se libera a PHP del procesamiento iterativo de grafos reemplazándolo con una sola consulta hacia una tabla `customer_daily_summary` enlazada por `JOIN`. PHP se enfoca solo en resolver un arreglo nativo con el resultado consolidado del motor SQL o procesar una subconsulta `IN (...)` generada dinámicamente (`placeholderList(count($ids))`). Esto drásticamente aumenta el _throughput_ asíncrono y los *QPS* (Queries Per Second) reales soportables por la API sin inflar la memoria o el CPU del Worker FPM.

## 🧱 Servicios

- `app` -> API PHP 8 con rutas legacy y optimized.
- `db` -> PostgreSQL 16 con datos semilla.
- `worker` -> refresco periodico de tabla resumen y heartbeat operacional.
- `postgres-exporter` -> metricas del motor PostgreSQL.
- `prometheus` -> scraping de la app y la base.
- `grafana` -> dashboard inicial del caso.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## 🔎 Endpoints

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

## 📈 Observabilidad

- Prometheus: `http://localhost:9091`
- Grafana: `http://localhost:3001` (`admin` / `admin`)
- PostgreSQL exporter: `http://localhost:9187/metrics`

## 🧪 Benchmark sugerido

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

## 🧭 Que observar

- diferencia de latencia entre legacy y optimized;
- diferencia de `avg_db_queries` y `avg_db_time_ms`;
- duracion de los refresh del worker;
- efecto del proceso concurrente sobre las lecturas;
- si la mejora es real o solo aparente.

## ⚖️ Nota de honestidad

No pretende benchmarkear PHP contra otros runtimes. Su objetivo es mostrar diagnostico y remediacion real de un problema de latencia bajo carga, con observabilidad suficiente para sostener la conclusion.