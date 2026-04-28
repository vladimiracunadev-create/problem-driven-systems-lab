# ⚡ Caso 01 — Python 3.12 + SQLite + worker embebido

> Implementacion operativa del caso 01 para estudiar latencia bajo carga con evidencia observable en Python stdlib puro.

## 🎯 Que resuelve

Modela una API de reportes con dos variantes:

- `report-legacy`: consulta con patron N+1 sobre tabla transaccional; el servidor acumula round-trips de I/O por cada registro.
- `report-optimized`: lectura sobre tabla resumen refrescada por un worker embebido que corre como `threading.Thread`.

El escenario no se queda en un `sleep`. Mantiene un hilo de fondo real que recalcula el resumen y deja visible la competencia real por recursos sobre SQLite.

## 💼 Por que importa

Este caso sirve para mostrar como se pasa de una API que "parece funcionar" a una implementacion que deja evidencia medible de por que degrada y como se corrige sin adivinar infraestructura. La diferencia es visible en `db_queries` y `db_time_ms` sin necesidad de un profiler externo.

## 🔬 Analisis Tecnico de la Implementacion (Python)

El cuello de botella clasico de N+1 en Python no es exclusivo de ORMs. Ocurre cuando el acceso a datos esta estructurado dentro de bucles sincrónicos sobre una conexion SQLite compartida.

- **Implementacion Falla (`legacy`):** La funcion `report_legacy()` obtiene los pedidos base con una consulta inicial y luego entra en un bucle `for row in orders` donde ejecuta dos consultas dependientes por iteracion: `SELECT` del cliente y `SELECT` de los tres ultimos pedidos. Cada iteracion abre una nueva llamada a `cursor.execute()` sobre el mismo objeto `sqlite3.Connection` protegido por `DB_LOCK` (`threading.RLock`). El resultado es un patron `1 + N + N` de accesos secuenciales: para 20 registros, el worker ejecuta 41 consultas bloqueantes. El GIL de Python no ayuda aqui — el cuello de botella es I/O puro sobre el archivo SQLite, no CPU, por lo que el tiempo total de la request crece linealmente con `limit`.

- **Sanitizacion Algoritmica (`optimized`):** La funcion `report_optimized()` resuelve el mismo conjunto de datos con 2-3 consultas fijas. Extrae todos los IDs de una pasada con `[r["customer_id"] for r in orders]`, construye un placeholder `IN (?,?,?)` con `",".join("?" * len(ids))` y ejecuta un unico `JOIN` que trae clientes y resumen en una sola ida al motor. El ensamblado final ocurre en memoria Python usando un `dict` construido con `{r["customer_id"]: r for r in customers}`, con acceso `O(1)` por clave. El worker de refresco usa el mismo `DB_LOCK` y corre en un `threading.Thread(daemon=True)` para no bloquear el servidor principal.

## 🧱 Servicio

- `app` → API Python 3.12 con rutas legacy y optimized, worker embebido y SQLite autocontenida.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `831`.

## 🔎 Endpoints

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

## 🧭 Que observar

- `db_queries` en `report-legacy` crece como `1 + N + N` con el parametro `limit`;
- `report-optimized` mantiene 2-3 consultas independientemente del `limit`;
- `/batch/status` muestra el estado del worker embebido y su ultima ejecucion;
- `/diagnostics/summary` compara latencias p95 de ambas rutas y el estado del worker;
- diferencia de `db_time_ms` entre ambas rutas en proporcion directa al numero de registros.

## ⚖️ Nota de honestidad

No pretende benchmarkear Python contra otros runtimes ni contra PostgreSQL. Su objetivo es mostrar diagnostico y remediacion real del patron N+1 con evidencia observable. SQLite es suficiente para demostrar el patron de acceso; las diferencias entre motores importan bajo carga concurrente real, no para demostrar el patron.
