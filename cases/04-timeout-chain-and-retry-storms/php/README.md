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

## 🔬 Análisis Técnico de la Implementación (PHP)

El diseño de resiliencia requiere manejo de tiempo explícito y algoritmos de retroceso probabilístico para evitar la saturación de recursos síncronos en PHP-FPM.

*   **Punto Crítico (`legacy`):** La política `legacy` utiliza un bucle persistente de reintentos con un `timeout_ms` fijo y elevado, sin tiempo de espera entre intentos (`backoff_base_ms: 0`). Bajo una falla de dependencia, esto provoca que PHP mantenga el descriptor de socket abierto y el proceso bloqueado durante segundos mediante `usleep()` acumulativo, agotando rápidamente el pool de workers disponibles y amplificando la carga hacia el proveedor (tormenta de reintentos).
*   **Resguardo Nativo (`resilient`):** Implementa el algoritmo de **Exponential Backoff con Jitter**. La función `calculateBackoffMs()` utiliza la expresión aritmética `($baseMs * (2 ** max(0, $attempt - 1))) + random_int(15, 45)`, donde `2 ** n` escala el tiempo exponencialmente y `random_int()` añade entropía para desfasar los picos de reintento. Adicionalmente, el **Circuit Breaker** se valida de forma atómica comparando el timestamp actual con la ventana de apertura mediante `strtotime($provider['opened_until']) > time()`, permitiendo que PHP aborte la ejecución *antes* de iniciar el I/O si el sistema se sabe degradado, protegiendo así el Lead Time del usuario.

## 🧱 Servicio

- `app` -> API PHP 8.3 con escenarios de proveedor estable, lento, caído o intermitente.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## Como consumir (dos opciones)

**Hub PHP (recomendado, 8100 en `compose.root.yml`):** este caso queda servido en `http://localhost:8100/04/...` junto a los otros 11 casos.

**Modo aislado (814 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## 🔎 Endpoints

```bash
curl http://localhost:8100/04/
curl http://localhost:8100/04/health
curl "http://localhost:8100/04/quote-legacy?scenario=provider_down&customer_id=42&items=3"
curl "http://localhost:8100/04/quote-resilient?scenario=provider_down&customer_id=42&items=3"
curl http://localhost:8100/04/dependency/state
curl http://localhost:8100/04/incidents?limit=10
curl http://localhost:8100/04/diagnostics/summary
curl http://localhost:8100/04/metrics
curl http://localhost:8100/04/metrics-prometheus
curl http://localhost:8100/04/reset-lab
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
