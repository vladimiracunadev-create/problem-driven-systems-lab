# 🌐 Caso 09 - PHP 8.3 con adapter y cache defensiva

> Implementación operativa del caso 09 para contrastar una integración externa directa contra una variante endurecida.

## 🎯 Qué resuelve

Modela un consumo de catálogo externo donde el proveedor puede:

- cambiar esquema sin aviso;
- limitar cuota;
- responder con payload parcial;
- entrar en mantenimiento.

La variante `catalog-hardened` agrega adapter, validación y snapshot cacheado.

## 💻 Interfaz Visual Nativa

Al abrir la ruta raíz en tu navegador (`Accept: text/html`), este caso inyecta automáticamente un **Dashboard visual interactivo** renderizado en Vanilla JS/CSS. Esto permite observar las métricas y efectos simulados en tiempo real sin perder la capacidad de responder a consultas JSON de CLI o Postman.

## 💼 Por qué importa

Este caso deja visible que la resiliencia frente a terceros no depende solo del timeout. También importa la estabilidad del contrato, la cuota y la posibilidad de operar con información ya conocida.

## 🧱 Servicio

- `app` -> API PHP 8.3 con proveedor externo simulado, quota budget, adapter de contrato y cache local.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## 🔎 Endpoints

```bash
curl http://localhost:819/
curl http://localhost:819/health
curl "http://localhost:819/catalog-legacy?scenario=rate_limited&sku=SKU-100"
curl "http://localhost:819/catalog-hardened?scenario=rate_limited&sku=SKU-100"
curl http://localhost:819/integration/state
curl http://localhost:819/sync-events?limit=10
curl http://localhost:819/diagnostics/summary
curl http://localhost:819/metrics
curl http://localhost:819/metrics-prometheus
curl http://localhost:819/reset-lab
```

## 🧪 Escenarios útiles

- `schema_drift` -> muestra normalización de contrato versus ruptura directa.
- `rate_limited` -> deja visible el valor del ahorro de cuota y el cache.
- `partial_payload` -> contrasta validación y degradación segura.
- `maintenance_window` -> muestra continuidad con snapshot local.

## 🧭 Qué observar

- si el flujo puede seguir con cache cuando el tercero no está disponible;
- cuándo aparecen nuevos schema mappings en el adapter;
- cómo cambia el budget restante de cuota;
- si la integración directa dispara eventos de cuarentena y errores más rápido.

## ⚖️ Nota de honestidad

No reemplaza una integración real con colas, DLQ ni proveedores de terceros. Sí reproduce las decisiones operativas que importan aquí: adapter, contrato defensivo, cache y amortiguación frente a cambios externos.
