# ⏱️ Caso 04 — Python 3.12 resiliente vs legacy

> Implementacion operativa del caso 04 para contrastar retries agresivos contra una variante que contiene la falla.

## 🎯 Que resuelve

Modela una API de cotizacion que depende de un proveedor externo de carriers:

- `quote-legacy` repite timeouts varias veces y amplifica la carga saliente;
- `quote-resilient` usa timeout corto, backoff exponencial, circuit breaker y fallback cacheado.

## 💼 Por que importa

Este caso deja visible un patron muy real: una dependencia lenta no solo agrega latencia, tambien puede degradar al servicio llamador cuando los retries no tienen limites sanos. El efecto cascada es la regla, no la excepcion.

## 🔬 Analisis Tecnico de la Implementacion (Python)

El control de tiempo en Python sobre I/O simulado con `time.sleep()` expone la diferencia entre una politica de reintentos agresiva y una con backoff y circuit breaker.

- **Punto Critico (`legacy`):** La funcion `call_provider_legacy()` ejecuta un bucle `for attempt in range(max_attempts)` con `max_attempts=4` y `timeout_ms=360`. En cada iteracion llama a `time.sleep(timeout_ms / 1000)` sin espera entre intentos (`backoff_base_ms=0`). Bajo un escenario `provider_down`, el proceso queda bloqueado durante `4 * 0.36 = 1.44` segundos minimos por request, multiplicando la presion sobre el proveedor sin ninguna posibilidad de recuperacion. El `ThreadingHTTPServer` mantiene un hilo ocupado por ese tiempo completo, agotando el pool de workers bajo carga concurrente.

- **Resguardo Nativo (`resilient`):** Implementa **Exponential Backoff con Jitter** en la funcion `calculate_backoff_ms()`: `base_ms * (2 ** max(0, attempt - 1)) + random.randint(15, 45)`. El jitter aleatorio desfasa los picos de reintento cuando multiples clientes fallan simultaneamente. El **Circuit Breaker** se evalua comparando `time.time()` con `provider["opened_until"]` antes de iniciar cualquier I/O: si el circuito esta abierto, la funcion retorna inmediatamente con el `fallback_quote` cacheado del ultimo exito, eliminando completamente la latencia de espera. El estado del circuito (`consecutive_failures`, `opened_until`, `fallback_quote`) se persiste en JSON bajo `tempfile.gettempdir()` para sobrevivir reinicios del servidor.

## 🧱 Servicio

- `app` → API Python 3.12 con escenarios de proveedor estable, lento, caido o intermitente.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `834`.

## 🔎 Endpoints

```bash
curl http://localhost:834/
curl http://localhost:834/health
curl "http://localhost:834/quote-legacy?scenario=provider_down&customer_id=42&items=3"
curl "http://localhost:834/quote-resilient?scenario=provider_down&customer_id=42&items=3"
curl http://localhost:834/dependency/state
curl "http://localhost:834/incidents?limit=10"
curl http://localhost:834/diagnostics/summary
curl http://localhost:834/metrics
curl http://localhost:834/metrics-prometheus
curl http://localhost:834/reset-lab
```

## 🧪 Escenarios utiles

- `provider_down` → ideal para ver tormenta de retries en legacy y fallback en resilient.
- `flaky_provider` → muestra retry util versus retry agresivo.
- `burst_then_recover` → permite ver recuperacion parcial con distinto costo de backoff.
- `slow_provider` → enfatiza la necesidad de deadlines explicitos en el timeout.

## 🧭 Que observar

- cuantos intentos y retries hace cada modo por request;
- si el circuito se abre y evita seguir golpeando la dependencia;
- cuando aparece respuesta degradada con fallback en vez de cascada de fallas;
- como cambia la latencia total entre `legacy` y `resilient` en el mismo escenario.

## ⚖️ Nota de honestidad

No reemplaza una integracion real ni una malla de servicios. Si reproduce el comportamiento operativo que importa aqui: timeouts, retries, circuit breaker, fallback y el costo de una mala postura de resiliencia.
