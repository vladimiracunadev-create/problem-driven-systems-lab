# 🔭 Caso 03 — Python 3.12 con observabilidad comparada

> Implementacion operativa del caso 03 para contrastar logs pobres contra telemetria util en un mismo flujo funcional.

## 🎯 Que resuelve

Modela un checkout con pasos internos y dependencias externas:

- validacion del carrito;
- reserva de inventario;
- autorizacion de pago;
- envio de notificacion.

El mismo flujo se expone en dos modos:

- `checkout-legacy` → logs en texto plano sin estructura ni correlacion.
- `checkout-observable` → logs JSON estructurados con correlation IDs, metricas y trazas utiles.

## 💼 Por que importa

La mejora no es estetica. Este caso muestra por que la observabilidad reduce MTTR: transforma un incidente vago en una falla diagnosticable con evidencia accionable. La diferencia entre "fallo algo" y "fallo `payment.authorize` por timeout en la request `req-a3f2`" es la diferencia entre minutos y horas de investigacion.

## 🔬 Analisis Tecnico de la Implementacion (Python)

La telemetria efectiva en Python no requiere librerias externas. Se implementa con `secrets`, `json` y excepciones estructuradas de la stdlib.

- **Logs Opacos (`legacy`):** La funcion `run_legacy_checkout()` acumula mensajes de texto con concatenacion de strings: `f"checkout processing customer={customer_id}"`. Este enfoque destruye la cardinalidad al generar strings no parseables algoritmicamente. Cuando ocurre un fallo, el `except Exception as e` captura unicamente `str(e)`, perdiendo el contexto de en que paso, en que dependencia y con que latencia parcial ocurrio el error. El log resultante no permite correlacionar eventos de una misma request ni cruzar informacion entre requests paralelas.

- **Logs Estructurados y Trazabilidad (`observable`):** Implementa una arquitectura de **Correlation IDs** generados con `secrets.token_hex(4)`, produciendo un `request_id` y un `trace_id` con entropia criptografica. El flujo usa una clase de excepcion personalizada `WorkflowFailure` que captura `step`, `dependency`, `http_status`, `request_id`, `trace_id` y `events` en el momento exacto del fallo. Cada evento del flujo se emite con `json.dumps({"request_id": ..., "trace_id": ..., "step": ..., "elapsed_ms": ...})`, produciendo lineas de log consultables por cualquier motor de busqueda. Al unir eventos por `trace_id`, es posible reconstruir la traza completa de una request independientemente del paralelismo del servidor (`ThreadingHTTPServer`).

## 🧱 Servicio

- `app` → API Python 3.12 con logs legacy y observable, metricas y trazas locales en JSON.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `833` (modo aislado, ver opciones abajo).

## Como consumir (dos opciones)

**Hub Python (recomendado, 8200 en `compose.python.yml`):** este caso queda servido en `http://localhost:8200/03/...` junto a los otros 11 casos.

**Modo aislado (833 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## 🔎 Endpoints

```bash
curl http://localhost:8200/03/
curl http://localhost:8200/03/health
curl "http://localhost:8200/03/checkout-legacy?scenario=payment_timeout&customer_id=42&cart_items=3"
curl "http://localhost:8200/03/checkout-observable?scenario=payment_timeout&customer_id=42&cart_items=3"
curl "http://localhost:8200/03/logs/legacy?tail=20"
curl "http://localhost:8200/03/logs/observable?tail=20"
curl "http://localhost:8200/03/traces?limit=10"
curl http://localhost:8200/03/diagnostics/summary
curl http://localhost:8200/03/metrics
curl http://localhost:8200/03/metrics-prometheus
curl http://localhost:8200/03/reset-observability
```

## 🧪 Escenarios utiles

- `payment_timeout` → paso que falla visible en observable, invisible en legacy.
- `inventory_conflict` → muestra correlacion entre reserva fallida y log estructurado.
- `notification_down` → fallo suave que legacy ignora y observable captura con `http_status: 502`.

## 🧭 Que observar

- si puedes identificar el paso exacto que fallo en cada modo;
- si puedes correlacionar eventos de una misma request usando `request_id`;
- si tienes latencias por etapa y dependencia en los logs de observable;
- si el diagnostico permite pasar de "fallo algo" a "fallo `payment.authorize` por timeout".

## ⚖️ Nota de honestidad

No sustituye un stack completo de tracing distribuido (Jaeger, OpenTelemetry). Si deja una base reproducible para demostrar por que logs pobres alargan el MTTR y que cambia cuando la telemetria es util.
