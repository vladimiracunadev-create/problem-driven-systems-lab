# ☕ Java

> **Versión fijada:** `21` (LTS) · **Imagen base:** `eclipse-temurin:21-jdk-alpine` · **Hub:** `:8400` · **Casos operativos:** 13 / 13

[⬅️ Volver a los perfiles de lenguaje](README.md) · [🗺️ Mapa de stacks](../stack-map.md) · [🔄 Protocolo de actualización](../language-upgrade-protocol.md)

---

## 🪪 Identidad

Java es un lenguaje de tipado estático que compila a bytecode y corre sobre la JVM, una máquina virtual con compilación JIT y recolección de basura generacional. Su rasgo definitorio no es el lenguaje sino la plataforma: treinta años de compatibilidad hacia atrás, un ecosistema de bibliotecas maduro y un runtime que optimiza el código *mientras corre*.

**Para qué se usa en la industria:** sistemas empresariales de larga vida, banca, seguros, telecomunicaciones, plataformas de datos (Hadoop, Kafka, Elasticsearch, Spark) y Android. Cuando un sistema tiene que seguir funcionando y siendo mantenible dentro de quince años, Java es la apuesta conservadora — y suele ser la correcta.

**Por qué está en este laboratorio:** porque tiene el catálogo de primitivas concurrentes más rico del set. `java.util.concurrent` no es una biblioteca externa: es la respuesta canónica a la mayoría de los problemas de este laboratorio, y en el caso 11 —donde el problema **es** el pool de threads— eso deja de ser ceremonia y pasa a ser la herramienta exacta.

**Nota de precisión sobre el laboratorio:** los doce casos corren con `javac Main.java` + `java Main`, sin Maven, sin Gradle y sin frameworks. `HttpServer` viene en el JDK. La decisión es deliberada: hace visible qué parte de la solución es *del lenguaje* y qué parte sería del framework.

---

## ⚙️ Modelo de ejecución

**Threads del sistema operativo con paralelismo real, sobre una JVM con JIT y GC generacional.**

| Consecuencia | Dónde se nota |
|---|---|
| **Paralelismo real sin GIL** | Dos threads ejecutan bytecode simultáneamente en dos núcleos. Python no puede; Java sí — [caso 11](../../cases/11-heavy-reporting-blocks-operations/java/README.md) |
| **El pool es explícito y observable** | `getActiveCount()` y `getQueue().size()` reportan saturación desde adentro, sin agente. Es el `event_loop_lag` del mundo JVM — [caso 11](../../cases/11-heavy-reporting-blocks-operations/java/README.md) |
| **El thread se reutiliza entre requests** | Un `ThreadLocal` sin limpiar arrastra el correlation ID al request siguiente. El caso 03 documenta ese riesgo en vez de esconderlo — [caso 03](../../cases/03-poor-observability-and-useless-logs/java/README.md) |
| **El JIT calienta** | Las primeras requests son más lentas que las siguientes. Cualquier medición debe descartar el arranque | transversal |

---

## 🧰 Primitivas que usa el laboratorio

| Caso | Primitiva central | Por qué esta y no otra |
|---|---|---|
| [01 · API lenta](../../cases/01-api-latency-under-load/java/README.md) | `ConcurrentHashMap` + `ScheduledExecutorService` + `LongAdder` | Cache leída sin lock; worker con shutdown limpio en SIGTERM. `LongAdder` supera a `synchronized` bajo contención |
| [02 · N+1](../../cases/02-n-plus-one-and-db-bottlenecks/java/README.md) | `PreparedStatement` + `try-with-resources` | Cleanup garantizado incluso bajo excepción; plan cacheado por `sqlite-jdbc` |
| [03 · Observabilidad](../../cases/03-poor-observability-and-useless-logs/java/README.md) | `ThreadLocal<RequestContext>` | Equivalente disponible hoy de `ScopedValue`, que en JDK 21 sigue en preview |
| [04 · Timeouts](../../cases/04-timeout-chain-and-retry-storms/java/README.md) | `CompletableFuture.orTimeout(Duration)` | Deadline a nivel future. **Limitación documentada:** completa el future a tiempo, pero el thread sigue dormido |
| [05 · Memoria](../../cases/05-memory-pressure-and-resource-leaks/java/README.md) | `LinkedHashMap` con `removeEldestEntry` | **La única LRU built-in del set completo.** En Go, Rust y .NET hay que construirla a mano |
| [06 · Pipeline](../../cases/06-broken-pipeline-and-fragile-delivery/java/README.md) | `record EnvState` + `ConcurrentHashMap` | Snapshots inmutables por ambiente, sin boilerplate de getters |
| [07 · Monolito](../../cases/07-incremental-monolith-modernization/java/README.md) | `ConcurrentHashMap<String, Function<Request,Response>>` | La firma del handler **es** el contrato; registrar un módulo es una línea |
| [08 · Extracción](../../cases/08-critical-module-extraction-without-breaking-operations/java/README.md) | `CopyOnWriteArrayList<Consumer<String>>` | EventBus thread-safe sin biblioteca externa. **Limitación:** los subscribers son síncronos |
| [09 · Integración externa](../../cases/09-unstable-external-integration/java/README.md) | `Semaphore` + `AtomicReference` | `tryAcquire()` no bloquea. Permits explícitos = cuota explícita |
| [10 · Sobre-arquitectura](../../cases/10-expensive-architecture-for-simple-needs/java/README.md) | `HashMap.get` + `System.nanoTime()` | El "right-sized" del caso, con medición directa del CPU por request |
| [11 · Reportes](../../cases/11-heavy-reporting-blocks-operations/java/README.md) | `ThreadPoolExecutor` acotado + pool de reporting separado | **El modelo canónico del problema.** Dos pools, telemetría directa de ambos |
| [12 · Punto único](../../cases/12-single-point-of-knowledge-and-operational-risk/java/README.md) | `Optional<T>` + `map`/`flatMap`/`orElse` | Runbook codificado. **Limitación:** `.get()` sin `isPresent()` compila igual |
| [13 · Cache stampede](../../cases/13-cache-stampede-and-thundering-herd/java/README.md) | `ConcurrentHashMap.computeIfAbsent` | **Atómico por clave**: mirar si existe y crearlo son una sola operación indivisible |

> 💡 **El patrón que solo se ve mirando la columna entera:** Java tiene una clase distinta para cada problema de concurrencia — `Semaphore`, `CompletableFuture`, `ConcurrentHashMap`, `CopyOnWriteArrayList`, `ThreadPoolExecutor`, `AtomicReference`, `LongAdder`. Es lo opuesto a Go, donde canal + `select` cubre casi todo. Más superficie que aprender; también más precisión cuando se conoce.

---

## 📈 Rendimiento: qué mide el laboratorio y cómo reproducirlo

> ⚠️ **Este repositorio no publica benchmarks entre lenguajes.** Se mide la pendiente dentro de cada stack: legacy contra optimized, mismo runtime, misma máquina. En Java, además, **hay que descartar el arranque**: el JIT necesita tráfico antes de estabilizarse.

| Señal | De dónde sale | Qué caso la expone |
|---|---|---|
| `avg_ms` · `p95_ms` · `p99_ms` | `LongAdder` + muestras en memoria | 01, 02, 10 |
| heap usado / total / máximo | `Runtime.getRuntime().totalMemory()`, `freeMemory()`, `maxMemory()` | 05 |
| threads activos del pool | `ThreadPoolExecutor.getActiveCount()` | 11 |
| profundidad de cola | `getQueue().size()` | 11 |
| `db_hits` por request | contador propio alrededor de JDBC | 01, 02 |

**Reproducir la medición del caso 11 (saturación de pool):**

```bash
docker compose -f compose.java.yml up -d --build
curl -s localhost:8400/11/activity                     # pool en reposo
for i in $(seq 1 8); do curl -s "localhost:8400/11/report-legacy?rows=200000" & done; wait
curl -s localhost:8400/11/activity                     # activeCount saturado, queueSize creciendo
curl -s "localhost:8400/11/order-write"                # degraded: true — el trafico normal lo paga
curl -s "localhost:8400/11/report-isolated?rows=200000"  # corre en el pool dedicado
curl -s "localhost:8400/11/order-write"                # el pool principal quedo libre
```

**Especificación de rendimiento que este stack verifica mejor que ningún otro:** el pool acotado a 4 threads hace que la saturación sea *predecible y observable*. En Go no hay pool que saturar; en Node el bloqueo es del proceso entero; en Java se ve exactamente cuántos threads están ocupados y cuántas tareas esperan. Por eso el caso 11 es el único donde Java gana el primer puesto.

---

## 🚧 Límites, problemas sin solución y desafíos

| Límite | Por qué importa | Dónde se ve |
|---|---|---|
| **`orTimeout` no interrumpe el trabajo** | Completa el future a tiempo, pero el thread proveedor sigue dormido. *Parece* que cortó, y no cortó | [caso 04](../../cases/04-timeout-chain-and-retry-storms/java/README.md) |
| **`ThreadLocal` se filtra entre requests** | Un thread reutilizado sin limpiar arrastra el correlation ID. `ScopedValue` lo resuelve, pero en 21 es preview | [caso 03](../../cases/03-poor-observability-and-useless-logs/java/README.md) |
| **`Optional.get()` sin `isPresent()` compila** | El tipo expresa la ausencia; el compilador no la exige. En Rust, omitir el brazo `None` no compila | [caso 12](../../cases/12-single-point-of-knowledge-and-operational-risk/comparison.md) |
| **El ecosistema fabrica N+1 solo** | Hibernate y JPA con lazy loading generan el bug del caso 02 sin que nadie lo escriba. Por eso Java queda 6º ahí | [caso 02](../../cases/02-n-plus-one-and-db-bottlenecks/comparison.md) |
| **Arranque lento y huella de memoria alta** | La JVM tarda en calentar y reserva heap con generosidad. Relevante en funciones serverless o contenedores efímeros | transversal |
| **Subscribers síncronos en el EventBus** | `CopyOnWriteArrayList` es thread-safe pero notifica en línea: un subscriber lento frena al publicador | [caso 08](../../cases/08-critical-module-extraction-without-breaking-operations/java/README.md) |

**Desafío abierto del stack en este laboratorio:** el caso 03 está escrito sobre `ThreadLocal` porque `ScopedValue` sigue en preview en JDK 21. Cuando salga de preview, el caso pasa a enseñar la forma vieja de hacer las cosas. Es el disparador de revisión más concreto de todo el repositorio y está anotado explícitamente en `scripts/language_drift.py`.

---

## 🏆 Dónde gana y dónde pierde en el laboratorio

Agregado de los veredictos de las 12 comparativas que rankean: **1 primer puesto, media 3.2**.

- 🥇 **Gana en 11** — cuando el problema *es* el pool de threads, tener pool explícito y observable es la herramienta exacta.
- 🥈 **Segundo en 01, 06 y 13** — paralelismo real, `record` types inmutables y el `computeIfAbsent` atómico que elimina la ventana check-then-act.
- 🥉 **Tercero en 05, 07, 09 y 12** — sólido, con las limitaciones documentadas arriba.
- **6º en 02** — no por la API (JDBC es correcto), sino porque es uno de los dos ecosistemas donde el N+1 **nace solo**.

**Lectura honesta:** Java y .NET quedan a dos décimas y ganan exactamente el mismo caso. Son casi intercambiables en este laboratorio, y las diferencias reales aparecen en los detalles: `AsyncLocal` de .NET fluye por `await` mejor que `ThreadLocal`; `LinkedHashMap` de Java es la única LRU built-in del set.

---

## 🔄 Ciclo de versiones

| | |
|---|---|
| **Versión fijada hoy** | `21` LTS (`eclipse-temurin:21-jdk-alpine`) |
| **Cadencia upstream** | Una release cada 6 meses; una LTS cada 2 años |
| **Política de soporte** | Temurin da soporte extendido a las LTS (21 hasta al menos 2029) |
| **Producto en endoflife.date** | `eclipse-temurin` |

**Qué revisar en el próximo salto:**

1. **🚨 `ScopedValue` fuera de preview (JDK 25)** — el `ThreadLocal` del [caso 03](../../cases/03-poor-observability-and-useless-logs/java/README.md) pasa de "alternativa razonable" a "lo que ya no se hace". Hay que reescribir el caso y su `comparison.md`, no solo cambiar el `FROM`.
2. **Virtual threads (Loom)** — estables desde 21. El [caso 11](../../cases/11-heavy-reporting-blocks-operations/java/README.md) está construido sobre pools acotados de threads de plataforma; con virtual threads el argumento cambia de fondo. Vale la pena evaluar si el caso debería mostrar **ambos** modelos.
3. **Cambios en el GC por defecto** — afectan la lectura del [caso 05](../../cases/05-memory-pressure-and-resource-leaks/java/README.md).
4. **Pattern matching y `sealed` types** — si maduran, el [caso 06](../../cases/06-broken-pipeline-and-fragile-delivery/java/README.md) podría acercarse a la exhaustividad que hoy solo tiene Rust.

El detalle del procedimiento está en [docs/language-upgrade-protocol.md](../language-upgrade-protocol.md).

---

## 🚀 Levantar el stack

```bash
docker compose -f compose.java.yml up -d --build
```

Los 13 casos quedan servidos en `http://localhost:8400/NN/`. Cada caso trae además su propio `compose.yml` para correrlo aislado.
