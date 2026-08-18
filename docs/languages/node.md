# 🟢 Node.js

> **Versión fijada:** `22` (LTS) · **Imagen base:** `node:22-alpine` · **Hub:** `:8300` · **Casos operativos:** 16 / 16

[⬅️ Volver a los perfiles de lenguaje](README.md) · [🗺️ Mapa de stacks](../stack-map.md) · [🔄 Protocolo de actualización](../language-upgrade-protocol.md)

---

## 🪪 Identidad

Node.js es un runtime de JavaScript construido sobre V8 (el motor de Chrome) con un modelo de I/O no bloqueante y orientado a eventos. Su propuesta original —un solo hilo que nunca espera— resultó ser una respuesta excelente al problema dominante de los servicios web: la mayoría del tiempo de una request se va esperando a la red o al disco, no calculando.

**Para qué se usa en la industria:** APIs y BFF, servicios de tiempo real (websockets, streaming), herramientas de build del ecosistema frontend, funciones serverless y prototipado rápido. Es la opción por defecto cuando el equipo ya escribe JavaScript en el navegador y no quiere pagar el costo de dos lenguajes.

**Por qué está en este laboratorio:** porque su restricción —un solo hilo— convierte en visible lo que en otros stacks queda escondido. Si una operación bloquea, no degrada: **para todo**. El `event_loop_lag_ms` que el caso 01 expone no tiene equivalente en PHP ni en Python, y es la señal más honesta de saturación de todo el repositorio.

---

## ⚙️ Modelo de ejecución

**Event loop de un solo hilo, con I/O asincrónico delegado al pool de libuv.**

| Consecuencia | Dónde se nota |
|---|---|
| **Una operación bloqueante detiene el proceso entero** | `node:sqlite` expone `DatabaseSync`, que es **síncrono**: cada query del N+1 bloquea a todos los demás clientes. Es el peor lugar posible para ese bug, y por eso el caso lo elige — [caso 01](../../cases/01-api-latency-under-load/node/README.md) |
| **El lag del event loop es medible** | `monitorEventLoopDelay` reporta cuánto se retrasó el loop. Ningún otro runtime del lab tiene una señal tan directa de "estoy saturado" — [caso 01](../../cases/01-api-latency-under-load/node/README.md) |
| **La cancelación es un objeto de primera clase** | `AbortSignal` se pasa a `fetch`, a una promesa propia o a un `EventTarget`. El deadline queda desacoplado de la biblioteca HTTP — [caso 04](../../cases/04-timeout-chain-and-retry-storms/node/README.md) |
| **El CPU-bound necesita otro hilo** | `worker_threads` es la única salida real para no bloquear el loop — [caso 11](../../cases/11-heavy-reporting-blocks-operations/node/README.md) |

---

## 🧰 Primitivas que usa el laboratorio

| Caso | Primitiva central | Por qué esta y no otra |
|---|---|---|
| [01 · API lenta](../../cases/01-api-latency-under-load/node/README.md) | `node:sqlite` (`DatabaseSync`) + `monitorEventLoopDelay` | SQLite en la stdlib, sin `npm install`. El lag del loop es la señal propia del stack |
| [02 · N+1](../../cases/02-n-plus-one-and-db-bottlenecks/node/README.md) | `node:sqlite` con `db.prepare()` | Statement preparado; el N+1 se vuelve visible por el conteo de `db_hits` |
| [03 · Observabilidad](../../cases/03-poor-observability-and-useless-logs/node/README.md) | `AsyncLocalStorage` | Contexto que sobrevive a los saltos asincrónicos. **Limitación:** nada impide filtrarlo |
| [04 · Timeouts](../../cases/04-timeout-chain-and-retry-storms/node/README.md) | `AbortController` + `AbortSignal.timeout(ms)` | Deadline sin atornillar timers a mano; la cancelación se propaga al runtime |
| [05 · Memoria](../../cases/05-memory-pressure-and-resource-leaks/node/README.md) | `process.memoryUsage()` | Distingue heap de V8 de RSS del proceso — más de lo que ofrecen PHP y Python |
| [06 · Pipeline](../../cases/06-broken-pipeline-and-fragile-delivery/node/README.md) | Objeto en memoria, single-thread | Sin lock: el modelo de un solo hilo **es** la sección crítica |
| [07 · Monolito](../../cases/07-incremental-monolith-modernization/node/README.md) | `Map<consumer, handler>` | Tabla de routing mutable en runtime, legible y directa |
| [08 · Extracción](../../cases/08-critical-module-extraction-without-breaking-operations/node/README.md) | `EventEmitter` + `Proxy` como ACL | Lo más idiomático del set para un bus de eventos. **Limitación:** los subscribers son síncronos |
| [09 · Integración externa](../../cases/09-unstable-external-integration/node/README.md) | `AbortSignal.timeout(250)` + breaker de módulo | El mismo signal sirve para `fetch` y para una promesa propia |
| [10 · Sobre-arquitectura](../../cases/10-expensive-architecture-for-simple-needs/node/README.md) | `Map` + `JSON.stringify` por hop | El costo de cada salto queda cobrado en CPU real |
| [11 · Reportes](../../cases/11-heavy-reporting-blocks-operations/node/README.md) | `worker_threads` | La única forma de sacar el CPU del loop sin frenar el proceso |
| [12 · Punto único](../../cases/12-single-point-of-knowledge-and-operational-risk/node/README.md) | optional chaining `?.` | Cómodo. **Limitación:** propaga `undefined` en silencio hasta que explota tres capas más arriba |
| [13 · Cache stampede](../../cases/13-cache-stampede-and-thundering-herd/node/README.md) | `Map<key, Promise>` | La Promise ya es el single-flight. Tres líneas — y el orden del `set` es toda la garantía |
| [14 · Pool de conexiones](../../cases/14-connection-pool-exhaustion/node/README.md) | `AbortSignal.timeout` + `finally` | Sin deadline, el que espera es una Promise invisible que no responde nunca |
| [15 · Backpressure](../../cases/15-message-queue-backpressure/node/README.md) | `Writable` con `highWaterMark` | El backpressure es parte del protocolo del runtime — e ignorarlo compila |
| [16 · Idempotencia](../../cases/16-idempotency-and-duplicate-effects/node/README.md) | `Map.has()` + `set()` | Atómico por el modelo de un hilo — y por eso deja de ser correcto con dos procesos |

> 💡 **El patrón que solo se ve mirando la columna entera:** Node es el stack donde más soluciones dependen de la disciplina y menos del lenguaje. `AsyncLocalStorage` funciona pero nada impide filtrarlo; `?.` propaga `undefined` sin avisar; el bus de eventos notifica en línea. A cambio, tiene la mejor primitiva de cancelación del set.

---

## 📈 Rendimiento: qué mide el laboratorio y cómo reproducirlo

> ⚠️ **Este repositorio no publica benchmarks entre lenguajes.** Se mide la pendiente dentro de cada stack: legacy contra optimized, mismo runtime, misma máquina.

Node tiene la señal de saturación más directa del laboratorio:

| Señal | De dónde sale | Qué caso la expone |
|---|---|---|
| `event_loop_lag_ms` | `perf_hooks.monitorEventLoopDelay` | 01, 11 |
| `heapUsed` · `rss` | `process.memoryUsage()` | 05 |
| `avg_ms` · `p95_ms` · `p99_ms` | muestras en memoria | 01, 02, 10 |
| `db_hits` por request | contador alrededor de `node:sqlite` | 01, 02 |

**Reproducir la medición del caso 01 (bloqueo del event loop):**

```bash
docker compose -f compose.nodejs.yml up -d --build
curl -s localhost:8300/01/metrics                          # event_loop_lag_ms en reposo
for i in $(seq 1 20); do curl -s "localhost:8300/01/report-legacy?limit=50" & done; wait
curl -s localhost:8300/01/metrics                          # el lag sube: el N+1 sincronico bloquea el loop
for i in $(seq 1 20); do curl -s "localhost:8300/01/report-optimized?limit=50" & done; wait
curl -s localhost:8300/01/metrics                          # db_hits constante, el lag vuelve a la linea base
```

**Especificación de rendimiento que este stack verifica y ningún otro puede:** en Java o Go un N+1 lento degrada *esa* request. En Node bloquea el proceso completo, y `event_loop_lag_ms` lo cuantifica en milisegundos. Es la demostración más limpia del repositorio de por qué el modelo de ejecución importa.

---

## 🚧 Límites, problemas sin solución y desafíos

| Límite | Por qué importa | Dónde se ve |
|---|---|---|
| **Una operación síncrona bloquea todo** | `DatabaseSync` de `node:sqlite` es síncrono. El N+1 no degrada: para el servidor entero | [caso 01](../../cases/01-api-latency-under-load/comparison.md) |
| **Sin paralelismo real sin `worker_threads`** | Todo el CPU-bound compite por el mismo hilo. Sacarlo afuera implica serializar mensajes entre workers | [caso 11](../../cases/11-heavy-reporting-blocks-operations/node/README.md) |
| **`AsyncLocalStorage` no impide la fuga** | Funciona, pero nada en el lenguaje evita almacenar el contexto donde no corresponde | [caso 03](../../cases/03-poor-observability-and-useless-logs/comparison.md) |
| **`?.` esconde el error hasta tres capas después** | El `undefined` viaja en silencio y explota lejos del origen. Es lo contrario de `Option<T>` | [caso 12](../../cases/12-single-point-of-knowledge-and-operational-risk/comparison.md) |
| **Sin tipos en runtime** | Nada valida la firma del handler al registrarlo; el error llega con el request | [caso 07](../../cases/07-incremental-monolith-modernization/comparison.md) |
| **`node:sqlite` sigue siendo experimental en 22** | Requiere el flag `--experimental-sqlite`. La API puede cambiar entre versiones menores | casos 01 y 02 |

**Desafío abierto del stack en este laboratorio:** los casos 01 y 02 dependen de `node:sqlite`, que en Node 22 sigue detrás de `--experimental-sqlite`. Es la dependencia más frágil del repositorio: una API experimental puede cambiar sin ceremonia. Está anotada como disparador explícito en `scripts/language_drift.py`.

---

## 🏆 Dónde gana y dónde pierde en el laboratorio

Agregado de los veredictos de las 15 comparativas que rankean: **0 primeros puestos, media 4.7**.

- 🥈 **Segundo en 04** — `AbortController` es la mejor primitiva de cancelación del set después de `context.Context`, y la única que se pasa igual a `fetch` que a una promesa propia.
- 🥉 **Tercero en 08 y 13** — `EventEmitter` + `Proxy` para el bus de eventos; `Map<key, Promise>` como el single-flight más corto del lab.
- **6º en 01, 03, 09, 14, 15 y 16** — en el 16 con el matiz más incómodo del lab: el código correcto es el más corto de los siete y deja de ser correcto al escalar a dos procesos, sin ningún aviso. — en el 15 con un matiz: es el único stack donde el backpressure es parte del protocolo del runtime, y también el único donde ignorarlo compila y pasa los tests. — el modelo de un solo hilo y la falta de respaldo del lenguaje le cuestan tres casos.

**Lectura honesta:** Node no gana ningún caso, y el laboratorio no lo maquilla. Lo que sí hace es ganar el argumento del caso 01 *por el lado contrario*: es el peor stack posible para un N+1 síncrono, y precisamente por eso es donde el problema se ve con más claridad. Un stack puede ser valioso para enseñar sin ser el que mejor resuelve.

---

## 🔄 Ciclo de versiones

| | |
|---|---|
| **Versión fijada hoy** | `22` LTS (`node:22-alpine`) |
| **Cadencia upstream** | Una mayor cada 6 meses; las pares pasan a LTS en octubre |
| **Política de soporte** | LTS: 30 meses de mantenimiento |
| **Producto en endoflife.date** | `nodejs` |

**Qué revisar en el próximo salto:**

1. **🚨 `node:sqlite` fuera de experimental (Node 24+)** — el flag `--experimental-sqlite` de los [casos 01](../../cases/01-api-latency-under-load/node/README.md) y [02](../../cases/02-n-plus-one-and-db-bottlenecks/node/README.md) sobraría, y la API podría haber cambiado. Hay que revisar el código, no solo el `Dockerfile`.
2. **Cambios en `AbortSignal`** — es la primitiva central de los casos 04 y 09.
3. **Evolución de `worker_threads`** — el argumento del caso 11 depende de que sacar el CPU del loop siga siendo la única salida.
4. **Cambios en el GC de V8** — afectan la lectura de `process.memoryUsage()` en el caso 05.

El detalle del procedimiento está en [docs/language-upgrade-protocol.md](../language-upgrade-protocol.md).

---

## 🚀 Levantar el stack

```bash
docker compose -f compose.nodejs.yml up -d --build
```

Los 16 casos quedan servidos en `http://localhost:8300/NN/`. Cada caso trae además su propio `compose.yml` para correrlo aislado — útil en los casos 01 y 11, donde la medición del event loop necesita el runtime sin ruido de los otros once casos.
