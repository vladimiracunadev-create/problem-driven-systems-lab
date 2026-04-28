# Caso 01 — Python: API lenta bajo carga (latencia y N+1)

Implementacion Python del caso **API lenta bajo carga por cuellos de botella reales**.

Logica funcional identica al stack PHP: expone las mismas rutas, implementa el mismo patron N+1 vs tabla resumen, incluye un worker que refresca el agregado y reporta historial de ejecuciones.

## Equivalencia funcional con PHP

| Aspecto | PHP | Python |
|---|---|---|
| Rutas HTTP | `/report-legacy`, `/report-optimized`, `/batch/status`, `/job-runs`, `/diagnostics/summary`, `/metrics`, `/metrics-prometheus`, `/reset-metrics` | Identicas |
| Patron N+1 (legacy) | SELECT orders → bucle: SELECT customer + 3 recent orders | Identico sobre SQLite |
| Tabla resumen (optimized) | JOIN customer_daily_summary | Identico sobre SQLite |
| Worker | Proceso separado (`worker.php`, container propio) | Hilo embebido (`threading.Thread`) |
| Base de datos | PostgreSQL 16 (container externo) | **SQLite embebida** (ver razon abajo) |
| Observabilidad | Prometheus + Grafana + postgres-exporter | Endpoint `/metrics-prometheus` (sin stack externo) |
| Puerto | 811 | 831 |

## Por que SQLite en Python y no PostgreSQL

SQLite es la opcion correcta para la variante Python de este caso por tres razones:

1. **Portabilidad real**: la variante Python arranca con un solo `docker compose up`, sin dependencias de contenedores externos. Permite ejecutar el caso en cualquier maquina sin configurar red, credenciales ni volumen.
2. **El patron es el problema, no el motor**: el costo de N+1 es visible con SQLite de la misma forma que con PostgreSQL. El caso enseña el patron de acceso, no las diferencias entre motores.
3. **Stdlib pura**: Python tiene `sqlite3` en la libreria estandar. Agregar `psycopg2` o `asyncpg` introduciria una dependencia externa y romperia el principio de autocontencion del laboratorio.

La variante PHP mantiene PostgreSQL porque PHP no tiene acceso embebido a un motor relacional de produccion y el stack completo (exporter + Prometheus + Grafana) es parte del valor diferencial de esa version.

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `831`.

## Endpoints

```bash
curl http://localhost:831/
curl http://localhost:831/health
curl "http://localhost:831/report-legacy?days=30&limit=20"
curl "http://localhost:831/report-optimized?days=30&limit=20"
curl http://localhost:831/batch/status
curl "http://localhost:831/job-runs?limit=10"
curl http://localhost:831/diagnostics/summary
curl http://localhost:831/metrics
curl http://localhost:831/metrics-prometheus
curl http://localhost:831/reset-metrics
```

## Que observar

- `db_queries` en `report-legacy` crece linealmente con `limit` (N+1: 1 + N + N queries).
- `report-optimized` mantiene 2-3 queries independientemente del `limit`.
- `/batch/status` muestra el estado del worker embebido y su ultima ejecucion.
- `/diagnostics/summary` compara latencias p95 de ambas rutas y el estado del worker.
- `/metrics-prometheus` expone metricas scrapeables por Prometheus si se integra externamente.

## Worker embebido vs proceso separado

El worker en Python corre como `threading.Thread` dentro del proceso del servidor. La diferencia con PHP (proceso separado) es de aislamiento, no de logica:

- Mismo intervalo de refresco (20 segundos)
- Mismo scope de lookback (45 dias)
- Misma tabla `customer_daily_summary` y misma logica de agregacion
- Mismo historial en `job_runs`

La decision de embeber el worker como hilo en lugar de un contenedor separado es correcta para Python: evita la complejidad operacional de coordinar dos procesos cuando el objetivo es demostrar el patron de acceso a datos, no la arquitectura de workers.
