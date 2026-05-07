# 📊 Caso 11 — Python 3.12 con reporting legacy vs aislado

> Implementacion operativa del caso 11 para contrastar reporting pesado sobre el primario contra una ruta que protege la operacion.

## 🎯 Que resuelve

Modela la competencia entre reporting y operacion:

- `report-legacy` ejecuta carga analitica sobre el mismo lock compartido con las escrituras;
- `report-isolated` usa un lock separado y no interfiere con el flujo transaccional;
- `order-write` deja ver como la operacion siente esa diferencia.

## 💼 Por que importa

Este caso deja visible un problema muy real: el reporte puede "funcionar" y aun asi romper negocio si sube locks, degrada escrituras y deja sin aire a la operacion. El choque entre OLAP y OLTP no es solo un problema de bases de datos: ocurre en cualquier sistema que comparta recursos entre analitica y escrituras.

## 🔬 Analisis Tecnico de la Implementacion (Python)

La colision entre reportes y operaciones se produce mediante la competencia fisica por `threading.Lock`, simulando el comportamiento de bloqueos de tabla en una base de datos.

- **Bloqueo Compartido (`legacy`):** La funcion `run_legacy_report()` adquiere `SHARED_LOCK.acquire(blocking=True)` al inicio y lo mantiene durante toda la duracion del reporte simulado con `time.sleep(report_duration)`. Mientras el lock esta retenido, cualquier peticion concurrente a `order-write` intenta adquirirlo con `SHARED_LOCK.acquire(blocking=False)`: al fallar la adquisicion inmediata, el endpoint devuelve HTTP 503 con `lock_contention: true` y registra el `blocked_ms`. Este mecanismo reproduce el comportamiento de un `SELECT ... FOR UPDATE` o un `flock(LOCK_EX | LOCK_NB)` sobre un recurso compartido: una tarea analitica larga estrangula el flujo transaccional de ventas.

- **Aislamiento y Concurrencia (`isolated`):** La ruta `report-isolated` usa `REPORTING_LOCK`, un `threading.Lock` separado y distinto del `SHARED_LOCK` que usan las escrituras. Las peticiones a `order-write` nunca intentan adquirir `REPORTING_LOCK`; operan unicamente sobre `OPERATIONAL_LOCK`, que el reporte aislado nunca toca. Esto garantiza que el FPM de escrituras procese pedidos en milisegundos sin toparse con el estado ocupado del reporte, independientemente de cuanto tarde la carga analitica.

## 🧱 Servicio

- `app` → API Python 3.12 con estado persistido de contention, presion de locks y cola de reporting.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `841`.

## Como consumir (dos opciones)

**Hub Python (recomendado, 8200 en `compose.python.yml`):** este caso queda servido en `http://localhost:8200/11/...` junto a los otros 11 casos.

**Modo aislado (8311 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## 🔎 Endpoints

```bash
curl http://localhost:8200/11/
curl http://localhost:8200/11/health
curl "http://localhost:8200/11/report-legacy?rows=500&period_days=30"
curl "http://localhost:8200/11/report-isolated?rows=500&period_days=30"
curl "http://localhost:8200/11/order-write?order_id=ORD-001&mode=legacy"
curl "http://localhost:8200/11/order-write?order_id=ORD-001&mode=isolated"
curl http://localhost:8200/11/reporting/state
curl "http://localhost:8200/11/activity?limit=20"
curl http://localhost:8200/11/diagnostics/summary
curl http://localhost:8200/11/metrics
curl http://localhost:8200/11/metrics-prometheus
curl http://localhost:8200/11/reset-lab
```

## 🧪 Escenarios utiles

- Llamar `report-legacy` y en paralelo `order-write?mode=legacy`: la escritura devuelve `lock_contention: true`.
- Llamar `report-isolated` y en paralelo `order-write?mode=isolated`: las escrituras nunca ven contention.
- `rows=600000` → exagera la duracion del reporte para hacer la contention mas visible.

## 🧭 Que observar

- si suben `lock_contentions` y `blocked_writes` tras cada reporte legacy;
- cuanto se degrada `order-write` en terminos de `blocked_ms` despues de un reporte pesado;
- si `write_blocking_rate` es cero en el modo isolated en `/diagnostics/summary`;
- cuando el sistema pasa de `healthy` a `warning` o `critical` en `/reporting/state`.

## ⚖️ Nota de honestidad

No sustituye una plataforma real con replicas, warehouse o jobs distribuidos. Si reproduce la decision operacional clave: aislar cargas analiticas para no romper el camino transaccional, con evidencia observable en terminos de contention y latencia.
