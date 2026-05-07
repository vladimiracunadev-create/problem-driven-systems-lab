# 🔭 Caso 03 - Node.js con observabilidad comparada

> Implementacion operativa real del caso 03 en Node.js para comparar logs pobres y telemetria util sin cambiar la historia del problema.

## 🎯 Que resuelve

Modela un checkout con pasos internos y dependencias externas:

- validacion del carrito;
- reserva de inventario;
- autorizacion de pago;
- envio de notificacion.

El mismo flujo se expone en dos modos:

- `checkout-legacy` -> logs pobres, sin correlacion y con muy poca capacidad de diagnostico.
- `checkout-observable` -> logs estructurados, `request_id`, `trace_id`, metricas y trazas locales.

## 💼 Por que importa

Esta variante muestra que el valor de la observabilidad no depende del runtime. Lo importante es el criterio: dejar evidencia accionable que reduzca el tiempo para encontrar la causa raiz.

## 🧱 Servicio

- `app` -> API Node.js 20 con logs legacy y observable, metricas y trazas locales.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## Como consumir (dos opciones)

**Hub Node.js (recomendado, 8300 en `compose.nodejs.yml`):** este caso queda servido en `http://localhost:8300/03/...` junto a los otros 11 casos.

**Modo aislado (823 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## 🔎 Endpoints

```bash
curl http://localhost:8300/03/
curl http://localhost:8300/03/health
curl "http://localhost:8300/03/checkout-legacy?scenario=payment_timeout&customer_id=42&cart_items=3"
curl "http://localhost:8300/03/checkout-observable?scenario=payment_timeout&customer_id=42&cart_items=3"
curl http://localhost:8300/03/logs/legacy?tail=20
curl http://localhost:8300/03/logs/observable?tail=20
curl http://localhost:8300/03/traces?limit=10
curl http://localhost:8300/03/diagnostics/summary
curl http://localhost:8300/03/metrics
curl http://localhost:8300/03/metrics-prometheus
curl http://localhost:8300/03/reset-observability
```

## 🧭 Que observar

- si puedes identificar el paso exacto que fallo;
- si puedes correlacionar eventos de una misma request;
- si tienes latencias por etapa y dependencia;
- si el diagnostico permite pasar de "fallo algo" a "fallo payment.authorize por timeout".

## ⚖️ Nota de honestidad

No reemplaza un stack completo de tracing distribuido, pero si deja una base reproducible para demostrar por que logs pobres alargan el MTTR y que cambia cuando la telemetria es util.