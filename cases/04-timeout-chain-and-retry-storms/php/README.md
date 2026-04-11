# ⏱️ Caso 04 - PHP 8.3 resiliente vs legacy

> Implementación operativa del caso 04 para contrastar retries agresivos contra una variante que contiene la falla.

## 🎯 Qué resuelve

Modela una API de cotización que depende de un proveedor externo de carriers:

- `quote-legacy` repite timeouts varias veces y amplifica la carga saliente;
- `quote-resilient` usa timeout corto, backoff, circuit breaker y fallback cacheado.

## 💻 Interfaz Visual Nativa

Al abrir la ruta raíz en tu navegador (`Accept: text/html`), este caso inyecta automáticamente un **Dashboard visual interactivo** renderizado en Vanilla JS/CSS. Esto permite observar las métricas y efectos simulados en tiempo real sin perder la capacidad de responder a consultas JSON de CLI o Postman.

## 💼 Por qué importa

Este caso deja visible un patrón muy real: una dependencia lenta no solo agrega latencia, también puede degradar al servicio llamador cuando los retries no tienen límites sanos.

## 🧱 Servicio

- `app` -> API PHP 8.3 con escenarios de proveedor estable, lento, caído o intermitente.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## 🔎 Endpoints

```bash
curl http://localhost:814/
curl http://localhost:814/health
curl "http://localhost:814/quote-legacy?scenario=provider_down&customer_id=42&items=3"
curl "http://localhost:814/quote-resilient?scenario=provider_down&customer_id=42&items=3"
curl http://localhost:814/dependency/state
curl http://localhost:814/incidents?limit=10
curl http://localhost:814/diagnostics/summary
curl http://localhost:814/metrics
curl http://localhost:814/metrics-prometheus
curl http://localhost:814/reset-lab
```

## 🧪 Escenarios útiles

- `provider_down` -> ideal para ver tormenta de retries y fallback.
- `flaky_provider` -> muestra retry útil versus retry agresivo.
- `burst_then_recover` -> permite ver recuperación parcial con distinto costo.
- `slow_provider` -> enfatiza la necesidad de deadlines explícitos.

## 🧭 Qué observar

- cuántos intentos y retries hace cada modo;
- si el circuito se abre y evita seguir golpeando la dependencia;
- cuándo aparece respuesta degradada con fallback en vez de cascada de fallas;
- cómo cambia la latencia total entre `legacy` y `resilient`.

## ⚖️ Nota de honestidad

No reemplaza una integración real ni una malla de servicios. Sí reproduce el comportamiento operativo que importa aquí: timeouts, retries, circuit breaker, fallback y el costo de una mala postura de resiliencia.
