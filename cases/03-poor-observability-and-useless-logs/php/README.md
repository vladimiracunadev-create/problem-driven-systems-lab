# 🔭 Caso 03 - PHP 8 con observabilidad comparada

> Implementacion operativa real del caso 03 para contrastar logs pobres contra telemetria util en un mismo flujo funcional.

## 🎯 Que resuelve

Modela un checkout con pasos internos y dependencias externas:

- validacion del carrito;
- reserva de inventario;
- autorizacion de pago;
- envio de notificacion.

El mismo flujo se expone en dos modos:

- `checkout-legacy` -> logs pobres, sin correlacion y con poco contexto.
- `checkout-observable` -> logs estructurados, correlation IDs, metricas y trazas utiles.

## 💼 Por que importa

La mejora no es estetica. Este caso muestra por que la observabilidad reduce MTTR: transforma un incidente vago en una falla diagnosticable con evidencia accionable.

## 🧱 Servicio

- `app` -> API PHP 8.3 con logs legacy y observable, metricas y trazas locales.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## 🔎 Endpoints

```bash
curl http://localhost:813/
curl http://localhost:813/health
curl "http://localhost:813/checkout-legacy?scenario=payment_timeout&customer_id=42&cart_items=3"
curl "http://localhost:813/checkout-observable?scenario=payment_timeout&customer_id=42&cart_items=3"
curl http://localhost:813/logs/legacy?tail=20
curl http://localhost:813/logs/observable?tail=20
curl http://localhost:813/traces?limit=10
curl http://localhost:813/diagnostics/summary
curl http://localhost:813/metrics
curl http://localhost:813/metrics-prometheus
curl http://localhost:813/reset-observability
```

## 🧭 Que observar

- si puedes identificar el paso exacto que fallo;
- si puedes correlacionar eventos de una misma request;
- si tienes latencias por etapa y dependencia;
- si el diagnostico permite pasar de "fallo algo" a "fallo payment.authorize por timeout".

## ⚖️ Nota de honestidad

No sustituye un stack completo de tracing distribuido. Si deja una base reproducible para demostrar por que logs pobres alargan el MTTR y que cambia cuando la telemetria es util.