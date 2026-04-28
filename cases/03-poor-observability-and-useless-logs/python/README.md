# Caso 03 — Python: Observabilidad deficiente y logs inutiles

Implementacion Python del caso **Observabilidad deficiente y logs inutiles**.

Logica funcional identica al stack PHP: mismo flujo de checkout con los mismos 4 pasos, mismos escenarios de fallo, mismos logs legacy vs estructurados, mismas rutas y misma telemetria local.

## Equivalencia funcional con PHP

| Aspecto | PHP | Python |
|---|---|---|
| Rutas HTTP | `/checkout-legacy`, `/checkout-observable`, `/logs/legacy`, `/logs/observable`, `/traces`, `/diagnostics/summary`, `/metrics`, `/metrics-prometheus`, `/reset-observability` | Identicas |
| Flujo de checkout | 4 pasos: cart.validate, inventory.reserve, payment.authorize, notification.dispatch | Identico |
| Escenarios | ok, inventory_conflict (503), payment_timeout (504), notification_down (502) | Identicos |
| Logging legacy | Lineas de texto sin estructura ni correlacion | Identico |
| Logging observable | JSON estructurado con request_id, trace_id, step, dependency, elapsed_ms | Identico |
| Telemetria | Trazas locales, conteo de exitos/fallos por modo y escenario | Identica |
| Puerto | 813 | 833 |

Este es el unico caso de los 12 donde PHP y Python son funcionalmente equivalentes sin diferencias de infraestructura: ninguno necesita base de datos ni worker externo.

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `833`.

## Endpoints

```bash
curl http://localhost:833/
curl http://localhost:833/health
curl "http://localhost:833/checkout-legacy?scenario=payment_timeout&customer_id=42&cart_items=3"
curl "http://localhost:833/checkout-observable?scenario=payment_timeout&customer_id=42&cart_items=3"
curl "http://localhost:833/logs/legacy?tail=20"
curl "http://localhost:833/logs/observable?tail=20"
curl "http://localhost:833/traces?limit=10"
curl http://localhost:833/diagnostics/summary
curl http://localhost:833/metrics
curl http://localhost:833/metrics-prometheus
curl http://localhost:833/reset-observability
```

## Escenarios disponibles

| Scenario | Paso que falla | HTTP Status |
|---|---|---|
| `ok` | Ninguno | 200 |
| `inventory_conflict` | inventory.reserve | 503 |
| `payment_timeout` | payment.authorize | 504 |
| `notification_down` | notification.dispatch | 502 |

## Que observar

Con `checkout-legacy`:
- Los logs dicen "checkout failed" pero no identifican el paso exacto.
- No hay correlacion entre eventos de una misma request.
- Es imposible saber cuanto tardo cada dependencia.

Con `checkout-observable`:
- `request_id` y `trace_id` aparecen en todos los eventos del mismo flujo.
- El paso fallido y la dependencia involucrada son visibles en el log.
- Las trazas en `/traces` muestran la latencia de cada paso.
- `/diagnostics/summary` cuantifica la capacidad de diagnostico de cada modo.
