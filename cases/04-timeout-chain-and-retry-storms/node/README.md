# 🔁 Caso 04 — Node.js 20 con AbortController y circuit breaker

> Implementacion operativa del caso 04 para estudiar cadenas de timeouts y tormentas de reintentos con evidencia observable, manteniendo paridad funcional con la version Python y aprovechando primitivas nativas del runtime: `AbortController`, `AbortSignal` y `setTimeout` cooperativo.

## 🎯 Que resuelve

Modela un endpoint de quote contra un proveedor inestable. Dos politicas:

- `quote-legacy`: 4 reintentos, sin backoff, sin circuit breaker, sin fallback. Cuando el proveedor degrada, multiplica la presion sobre el dependiente (retry storm clasico).
- `quote-resilient`: 2 intentos con backoff exponencial + jitter, **timeout via AbortController**, circuit breaker (umbral 2 fallas, ventana 30s) y fallback a quote cacheado.

Escenarios soportados: `ok`, `slow_provider`, `flaky_provider`, `provider_down`, `burst_then_recover`.

## 💼 Por que importa

Un proveedor lento + politica de reintentos sin freno es la receta clasica del retry storm: la propia politica pretende mejorar la disponibilidad y termina derrumbando al proveedor (y al cliente). El caso muestra como pasar de "tu sistema es la causa de su propio incidente" a "tu sistema absorbe la falla y sigue util".

## 🔬 Analisis Tecnico de la Implementacion (Node.js)

- **AbortController como timeout primitivo:** `callWithTimeout()` crea un `AbortController` y registra `setTimeout(() => ac.abort(), timeoutMs)`. La llamada simulada al proveedor es awaiteable y cancelable: si el timer dispara, `signal.abort()` rechaza la promise sin esperar la respuesta tardia. Esto es radicalmente distinto a `time.sleep()` en Python: el Node tira la operacion realmente, libera el slot del event loop y permite que el resto del proceso siga atendiendo.

- **Implementacion Falla (`legacy`):** politica `{timeout_ms: 360, max_attempts: 4, backoff_base_ms: 0, use_circuit_breaker: false, allow_fallback: false}`. Bajo `slow_provider`, cada intento de 640-730ms es cancelado por AbortController a los 360ms. Total: 4 cancelaciones consecutivas sin esperar entre intentos. La metrica `attempts_total` y `timeouts_total` por modo deja visible la presion. Sin fallback, el cliente termina con HTTP 503.

- **Sanitizacion (`resilient`):** politica `{timeout_ms: 220, max_attempts: 2, backoff_base_ms: 80, use_circuit_breaker: true, allow_fallback: true}`. Backoff exponencial entre intentos `80 * 2^(attempt-1) + jitter(15-45)ms`. Si tras los 2 intentos el contador `consecutive_failures >= 2`, el breaker se abre por 30s. Mientras esta abierto, las requests entran en short-circuit y devuelven el `fallback_quote` cacheado (HTTP 200 con `source: "fallback"`).

- **Concurrencia y estado:** Node es single-thread, asi que el estado del breaker (`opened_until`, `consecutive_failures`) no necesita locks. La persistencia en `tmp/dependency_state.json` permite que multiples requests vean el mismo estado del breaker sin race conditions del runtime.

## 🧱 Servicio

- `app` → API Node.js 20 con politicas legacy/resilient, breaker en JSON local y telemetria por modo.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `824`.

## 🔎 Endpoints

```bash
curl http://localhost:824/
curl http://localhost:824/health
curl "http://localhost:824/quote-legacy?scenario=slow_provider&customer_id=42&items=3"
curl "http://localhost:824/quote-resilient?scenario=slow_provider&customer_id=42&items=3"
curl http://localhost:824/dependency/state
curl "http://localhost:824/incidents?limit=10"
curl http://localhost:824/diagnostics/summary
curl http://localhost:824/metrics
curl http://localhost:824/metrics-prometheus
curl http://localhost:824/reset-lab
```

## 🧭 Que observar

- bajo `slow_provider`, `quote-legacy` acumula 4 timeouts y devuelve 503; `quote-resilient` activa breaker tras 2 fallas y empieza a servir fallback;
- `dependency_circuit_open` en Prometheus pasa a `1` cuando el breaker se abre y vuelve a `0` cuando expira;
- `app_flow_avg_attempts{mode="legacy"}` se mantiene cerca de 4; `mode="resilient"` se mantiene en 1-2 por la combinacion CB + fallback;
- `recent_incidents` permite reconstruir incidentes con suficiente contexto para postmortem.

## ⚖️ Nota de honestidad

El proveedor es simulado y los escenarios son determinismo controlado por `Math.random()` con rangos definidos. El laboratorio no reemplaza un test contra dependencia real bajo carga, pero demuestra el patron y deja la primitiva `AbortController` como referencia explicita — la misma que se usa con `fetch` en codigo de produccion.
