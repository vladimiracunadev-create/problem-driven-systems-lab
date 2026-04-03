# 🔭 Caso 03 - Python con observabilidad comparada

> Implementacion operativa real del caso 03 en Python para comparar logs pobres y telemetria util sobre el mismo flujo funcional.

## 🎯 Que resuelve

Modela un checkout con pasos internos y dependencias externas:

- validacion del carrito;
- reserva de inventario;
- autorizacion de pago;
- envio de notificacion.

El mismo flujo se expone en dos modos:

- `checkout-legacy` -> logs pobres, sin correlacion y con poca capacidad de respuesta operacional.
- `checkout-observable` -> logs estructurados, `request_id`, `trace_id`, metricas y trazas locales.

## 💼 Por que importa

Sirve para demostrar que una mejora de observabilidad es portable como criterio operativo: si puedes explicar mejor el incidente, puedes responder mejor sin depender del lenguaje de turno.

## 🧱 Servicio

- `app` -> API Python 3.12 con logs legacy y observable, metricas y trazas locales.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## 🔎 Endpoints

```bash
curl http://localhost:833/
curl http://localhost:833/health
curl "http://localhost:833/checkout-legacy?scenario=payment_timeout&customer_id=42&cart_items=3"
curl "http://localhost:833/checkout-observable?scenario=payment_timeout&customer_id=42&cart_items=3"
curl http://localhost:833/logs/legacy?tail=20
curl http://localhost:833/logs/observable?tail=20
curl http://localhost:833/traces?limit=10
curl http://localhost:833/diagnostics/summary
curl http://localhost:833/metrics
curl http://localhost:833/metrics-prometheus
curl http://localhost:833/reset-observability
```

## 🧭 Que observar

- si puedes identificar el paso exacto que fallo;
- si puedes correlacionar eventos de una misma request;
- si tienes latencias por etapa y dependencia;
- si el diagnostico permite pasar de "fallo algo" a "fallo payment.authorize por timeout".

## ⚖️ Nota de honestidad

No reemplaza un stack completo de tracing distribuido, pero si deja una base reproducible para demostrar por que logs pobres alargan el MTTR y que cambia cuando la telemetria es util.