# 🔭 Caso 03 - Node.js con observabilidad comparada

Esta variante ya no es una base minima: implementa el mismo flujo conceptual del caso PHP con dos modos de ejecucion.

- `checkout-legacy` -> logs pobres, sin correlacion y con muy poca capacidad de diagnostico
- `checkout-observable` -> logs estructurados, `request_id`, `trace_id`, metricas y trazas locales

## ✅ Que resuelve

Modela un checkout con pasos internos y dependencias externas:

- validacion del carrito;
- reserva de inventario;
- autorizacion de pago;
- envio de notificacion.

Cuando algo falla, el modo legacy deja evidencia insuficiente. El modo observable deja informacion accionable para responder rapido que paso, donde y con que impacto.

## 🧰 Servicio

- `app` -> API Node.js 20 con logs legacy y observable, metricas y trazas locales

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## 🔎 Endpoints

```bash
curl http://localhost:823/
curl http://localhost:823/health
curl "http://localhost:823/checkout-legacy?scenario=payment_timeout&customer_id=42&cart_items=3"
curl "http://localhost:823/checkout-observable?scenario=payment_timeout&customer_id=42&cart_items=3"
curl http://localhost:823/logs/legacy?tail=20
curl http://localhost:823/logs/observable?tail=20
curl http://localhost:823/traces?limit=10
curl http://localhost:823/diagnostics/summary
curl http://localhost:823/metrics
curl http://localhost:823/metrics-prometheus
curl http://localhost:823/reset-observability
```

## 🧭 Que observar

- si puedes identificar el paso exacto que fallo;
- si puedes correlacionar eventos de una misma request;
- si tienes latencias por etapa y dependencia;
- si el diagnostico permite pasar de "fallo algo" a "fallo payment.authorize por timeout".

## ⚖️ Nota de honestidad

No reemplaza un stack completo de tracing distribuido, pero si deja una base reproducible para demostrar por que logs pobres alargan el MTTR y que cambia cuando la telemetria es util.
