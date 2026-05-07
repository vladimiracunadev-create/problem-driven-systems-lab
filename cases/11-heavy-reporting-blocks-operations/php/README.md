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

## 🔬 Análisis Técnico de la Implementación (PHP)

El choque entre procesos de reporte y operaciones vitales se produce mediante la colisión física de recursos en el sistema de archivos, simulando el comportamiento de bloqueos de tabla en una base de datos.

*   **Bloqueo Físico de Storage (`legacy`):** La API de reportes solicita un bloqueo exclusivo al kernel mediante la función **`flock($fp, LOCK_EX)`**. Mientras PHP ejecuta un I/O largo (simulado con `usleep()`), el descriptor de archivo queda retenido. Cualquier petición concurrente en la ruta operacional (`order-write`) intenta obtener el bloqueo mediante **`flock($fp, LOCK_EX | LOCK_NB)`**. Al fallar la adquisición inmediata del lock no bloqueante, el script despacha un **`503 Service Unavailable`**, demostrando cómo una tarea analítica de larga duración puede estrangular el flujo transaccional de ventas debido a una mala gestión de concurrencia.
*   **Aislamiento y Concurrencia (`isolated`):** PHP evita el bloqueo del hilo transaccional delegando la carga. La versión aislada utiliza un buffer de persistencia asíncrono (simulado) y omite el requerimiento de bloqueo exclusivo sobre el recurso primario. Esto permite que el FPM procese escrituras en milisegundos sin toparse con el estado ocupado del descriptor de archivo, garantizando que el *Availability* de la tienda no se vea comprometido por el procesamiento de bultos analíticos.

## 🧱 Servicio

- `app` -> API PHP 8.3 con estado persistido de carga en primario, presión de locks, lag de replica y cola de reporting.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## Como consumir (dos opciones)

**Hub PHP (recomendado, 8100 en `compose.root.yml`):** este caso queda servido en `http://localhost:8100/11/...` junto a los otros 11 casos.

**Modo aislado (8111 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## 🔎 Endpoints

```bash
curl http://localhost:8100/11/
curl http://localhost:8100/11/health
curl "http://localhost:8100/11/report-legacy?scenario=end_of_month&rows=600000"
curl "http://localhost:8100/11/report-isolated?scenario=end_of_month&rows=600000"
curl "http://localhost:8100/11/order-write?orders=25"
curl http://localhost:8100/11/reporting/state
curl http://localhost:8100/11/activity?limit=10
curl http://localhost:8100/11/diagnostics/summary
curl http://localhost:8100/11/metrics
curl http://localhost:8100/11/metrics-prometheus
curl http://localhost:8100/11/reset-lab
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
