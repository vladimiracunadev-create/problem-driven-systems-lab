# 📊 Caso 11 - PHP 8.3 con reporting legacy vs aislado

> Implementación operativa del caso 11 para contrastar reporting pesado sobre el primario contra una ruta que protege la operación.

## 🎯 Qué resuelve

Modela la competencia entre reporting y operación:

- `report-legacy` ejecuta carga analítica sobre el mismo núcleo transaccional;
- `report-isolated` empuja presión a cola, replica o snapshot;
- `order-write` deja ver cómo la operación siente esa diferencia.

## 💻 Interfaz Visual Nativa

Al abrir la ruta raíz en tu navegador (`Accept: text/html`), este caso inyecta automáticamente un **Dashboard visual interactivo** renderizado en Vanilla JS/CSS. Esto permite observar las métricas y efectos simulados en tiempo real sin perder la capacidad de responder a consultas JSON de CLI o Postman.

## 💼 Por qué importa

Este caso deja visible un problema muy real: el reporte puede “funcionar” y aun así romper negocio si sube locks, degrada escrituras y deja sin aire a la operación.

## 🧱 Servicio

- `app` -> API PHP 8.3 con estado persistido de carga en primario, presión de locks, lag de replica y cola de reporting.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## 🔎 Endpoints

```bash
curl http://localhost:8111/
curl http://localhost:8111/health
curl "http://localhost:8111/report-legacy?scenario=end_of_month&rows=600000"
curl "http://localhost:8111/report-isolated?scenario=end_of_month&rows=600000"
curl "http://localhost:8111/order-write?orders=25"
curl http://localhost:8111/reporting/state
curl http://localhost:8111/activity?limit=10
curl http://localhost:8111/diagnostics/summary
curl http://localhost:8111/metrics
curl http://localhost:8111/metrics-prometheus
curl http://localhost:8111/reset-lab
```

## 🧪 Escenarios útiles

- `end_of_month` -> deja visible el clásico choque entre cierre financiero y operación.
- `finance_audit` -> muestra necesidad de consistencia sin bloquear todo.
- `ad_hoc_export` -> expone reportes no planificados pidiendo recursos en mal momento.
- `mixed_peak` -> combina alta operación con reporting pesado.

## 🧭 Qué observar

- si suben `primary_load` y `lock_pressure` tras cada reporte;
- cuánto se degrada `order-write` después de un export pesado;
- cómo cambia `replica_lag_s` y `queue_depth` en la ruta aislada;
- cuándo el sistema pasa de `healthy` a `warning` o `critical`.

## ⚖️ Nota de honestidad

No sustituye una plataforma real con replicas, warehouse o jobs distribuidos. Sí reproduce la decisión operacional clave: aislar cargas analíticas para no romper el camino transaccional.
