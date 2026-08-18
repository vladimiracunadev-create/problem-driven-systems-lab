# 🔵 .NET

> **Versión fijada:** `8.0` (LTS) · **Imagen base:** `mcr.microsoft.com/dotnet/sdk:8.0` · **Hub:** `:8500` · **Casos operativos:** 19 / 19

[⬅️ Volver a los perfiles de lenguaje](README.md) · [🗺️ Mapa de stacks](../stack-map.md) · [🔄 Protocolo de actualización](../language-upgrade-protocol.md)

---

## 🪪 Identidad

.NET es una plataforma multiplataforma y de código abierto que ejecuta C# (entre otros lenguajes) sobre el CLR, un runtime con JIT y recolección de basura generacional. Desde .NET Core la plataforma dejó de ser exclusiva de Windows, y desde .NET 5 hay una sola línea de producto con releases anuales y LTS cada dos años.

**Para qué se usa en la industria:** aplicaciones empresariales, servicios web de alto rendimiento (ASP.NET Core es consistentemente competitivo en los benchmarks públicos de throughput), integraciones corporativas, videojuegos vía Unity y herramientas de escritorio. Es la plataforma por defecto de las organizaciones con inversión previa en el ecosistema Microsoft.

**Por qué está en este laboratorio:** porque es el contraste directo con Java —mismo perfil de problema, mismas garantías, decisiones de diseño distintas— y porque `async/await` está en toda la BCL. Comparar `AsyncLocal<T>` contra `ThreadLocal`, o `CancellationToken` contra `CompletableFuture.orTimeout`, es donde se ve que dos plataformas parecidas resuelven la misma cosa con calidad diferente.

**Nota de precisión sobre el laboratorio:** los doce casos usan `HttpListener` de la BCL, sin ASP.NET y sin paquetes NuGet más allá del proveedor de SQLite. La decisión es deliberada: hace visible qué parte de la solución es de la plataforma y qué parte sería del framework.

---

## ⚙️ Modelo de ejecución

**ThreadPool con `async/await` que libera el hilo durante la espera.**

| Consecuencia | Dónde se nota |
|---|---|
| **`await` no bloquea el thread** | Durante la espera de I/O el hilo vuelve al pool y atiende otro trabajo. Es la diferencia entre esperar y ocupar | transversal |
| **`AsyncLocal<T>` fluye por `await`** | El contexto sobrevive al cambio de thread que hace el pool. Un `ThreadLocal` en Java no lo logra — [caso 03](../../cases/03-poor-observability-and-useless-logs/dotnet/README.md) |
| **`CancellationToken` está en toda la BCL** | La cancelación cooperativa no es un patrón del proyecto: es la convención de la plataforma — [caso 04](../../cases/04-timeout-chain-and-retry-storms/dotnet/README.md) |
| **El pool se puede acotar y observar** | `ThreadPool.SetMaxThreads` y `GetAvailableWorkerThreads` hacen la saturación medible desde adentro — [caso 11](../../cases/11-heavy-reporting-blocks-operations/dotnet/README.md) |

---

## 🧰 Primitivas que usa el laboratorio

| Caso | Primitiva central | Por qué esta y no otra |
|---|---|---|
| [01 · API lenta](../../cases/01-api-latency-under-load/dotnet/README.md) | `ConcurrentDictionary` + `Task.Delay` con `CancellationToken` | Cache lock-free; worker con tick cooperativo cancelable en SIGTERM |
| [02 · N+1](../../cases/02-n-plus-one-and-db-bottlenecks/dotnet/README.md) | `SqliteCommand` + `using` sobre `SqliteDataReader` | Cleanup garantizado por `IDisposable` |
| [03 · Observabilidad](../../cases/03-poor-observability-and-useless-logs/dotnet/README.md) | `AsyncLocal<RequestContext>` + `System.Text.Json` | Fluye por `await` sin propagación manual. JSON estructurado en la BCL |
| [04 · Timeouts](../../cases/04-timeout-chain-and-retry-storms/dotnet/README.md) | `CancellationTokenSource(TimeSpan)` + `Interlocked.CompareExchange` | Deadline cooperativo; CAS **explícito** en las transiciones del breaker |
| [05 · Memoria](../../cases/05-memory-pressure-and-resource-leaks/dotnet/README.md) | `Dictionary` + `LinkedList` (LRU a mano) + `WorkingSet64` | La BCL no trae LRU. `Process.WorkingSet64` da el RSS real del proceso |
| [06 · Pipeline](../../cases/06-broken-pipeline-and-fragile-delivery/dotnet/README.md) | `record` + `with`-expressions | El rollback es una expresión, no una mutación |
| [07 · Monolito](../../cases/07-incremental-monolith-modernization/dotnet/README.md) | `ConcurrentDictionary<string, Func<Request,Response>>` | La firma del delegate **es** el contrato |
| [08 · Extracción](../../cases/08-critical-module-extraction-without-breaking-operations/dotnet/README.md) | `event Action<string>` + `ImmutableList` | Lecturas paralelas sin lock. **Limitación:** los subscribers son síncronos |
| [09 · Integración externa](../../cases/09-unstable-external-integration/dotnet/README.md) | `SemaphoreSlim` con `Wait(0)` | No bloquea: si no hay permits, sirve el snapshot |
| [10 · Sobre-arquitectura](../../cases/10-expensive-architecture-for-simple-needs/dotnet/README.md) | `Dictionary.TryGetValue` + `Stopwatch` | El "right-sized", con medición directa del CPU por request |
| [11 · Reportes](../../cases/11-heavy-reporting-blocks-operations/dotnet/README.md) | `ThreadPool.SetMaxThreads(4)` + scheduler dedicado | **Modelo canónico del problema**, junto con Java |
| [12 · Punto único](../../cases/12-single-point-of-knowledge-and-operational-risk/dotnet/README.md) | `?.` null-conditional + `??` null-coalescing | Con nullable reference types el compilador avisa. **Limitación:** avisa, no obliga |
| [13 · Cache stampede](../../cases/13-cache-stampede-and-thundering-herd/dotnet/README.md) | `Lazy<Task<T>>` con `ExecutionAndPublication` | `GetOrAdd` **no** garantiza fábrica única; la garantía la aporta `Lazy` |
| [14 · Pool de conexiones](../../cases/14-connection-pool-exhaustion/dotnet/README.md) | `SemaphoreSlim.WaitAsync` + `using var` | El timeout es un valor de retorno, no una excepción; y lo correcto ocupa menos líneas |
| [15 · Backpressure](../../cases/15-message-queue-backpressure/dotnet/README.md) | `BoundedChannelFullMode` | Único stack donde la política es un enum del constructor, no una elección por envío |
| [16 · Idempotencia](../../cases/16-idempotency-and-duplicate-effects/dotnet/README.md) | `ConcurrentDictionary.TryAdd` | Sí es atómico, a diferencia de `GetOrAdd` con fábrica del caso 13 |
| [17 · Migración sin downtime](../../cases/17-zero-downtime-schema-migration/dotnet/README.md) | `ReaderWriterLockSlim` + `TryEnterReadLock(ms)` | Deadline como valor de retorno; `IDisposable` y sin modo justo |
| [18 · Arranque en frío](../../cases/18-cold-start-and-autoscale-lag/dotnet/README.md) | `PublishReadyToRun` · `TieredPGO` · `PublishAot` | Tiene la curva (2,3x) y **la respuesta en la caja**: tres líneas del `.csproj` |
| [19 · Deriva del índice](../../cases/19-search-index-drift-and-broken-cdc/dotnet/README.md) | `Except` / `Join` tipados | Las tres caras como una sola forma; la pereza de LINQ es la trampa |

> 💡 **El patrón que solo se ve mirando la columna entera:** .NET usa `Interlocked.CompareExchange` donde Java usa `AtomicReference`. El CAS explícito hace visible que la transición del breaker es una operación atómica de comparar-y-cambiar; en Java el `set()` lo esconde. Es la misma corrección con distinta cantidad de verdad a la vista.

---

## 📈 Rendimiento: qué mide el laboratorio y cómo reproducirlo

> ⚠️ **Este repositorio no publica benchmarks entre lenguajes.** Se mide la pendiente dentro de cada stack. En .NET, igual que en Java, **hay que descartar el arranque**: el JIT necesita tráfico antes de estabilizarse.

| Señal | De dónde sale | Qué caso la expone |
|---|---|---|
| `avg_ms` · `p95_ms` · `p99_ms` | `Interlocked.Increment` + muestras en memoria | 01, 02, 10 |
| RSS del proceso | `Process.GetCurrentProcess().WorkingSet64` | 05 |
| memoria gestionada | `GC.GetTotalMemory(forceFullCollection: true)` | 05 |
| worker threads disponibles | `ThreadPool.GetAvailableWorkerThreads` | 11 |
| CPU por request | `Stopwatch` / `Environment.TickCount64` | 10 |

**Reproducir la medición del caso 11 (saturación de pool):**

```bash
docker compose -f compose.dotnet.yml up -d --build
curl -s localhost:8500/11/activity                       # pool en reposo
for i in $(seq 1 8); do curl -s "localhost:8500/11/report-legacy?rows=200000" & done; wait
curl -s localhost:8500/11/activity                       # worker threads disponibles cayendo
curl -s "localhost:8500/11/order-write"                  # degraded: true
curl -s "localhost:8500/11/report-isolated?rows=200000"  # scheduler dedicado
curl -s "localhost:8500/11/order-write"                  # el pool principal quedo libre
```

**Especificación de rendimiento que este stack verifica con precisión:** `GetMaxThreads` menos `GetAvailableWorkerThreads` da los threads ocupados **en el instante de la consulta**, sin agente ni profiler. Junto con Java, es el par que mejor expone el problema del caso 11 desde adentro del proceso.

---

## 🚧 Límites, problemas sin solución y desafíos

| Límite | Por qué importa | Dónde se ve |
|---|---|---|
| **El aviso de nulabilidad es un warning** | Nullable reference types marcan el riesgo, pero el operador `!` lo silencia y compila igual | [caso 12](../../cases/12-single-point-of-knowledge-and-operational-risk/comparison.md) |
| **El ecosistema fabrica N+1 solo** | Entity Framework con lazy loading genera el bug del caso 02 sin que nadie lo escriba | [caso 02](../../cases/02-n-plus-one-and-db-bottlenecks/comparison.md) |
| **`CancellationToken` es cooperativo** | Si el callee no lo respeta, el deadline no hace nada. Mismo límite que `context.Context` en Go | [caso 04](../../cases/04-timeout-chain-and-retry-storms/dotnet/README.md) |
| **Sin LRU en la BCL** | `Dictionary` + `LinkedList` a mano. Java lo resuelve con una línea | [caso 05](../../cases/05-memory-pressure-and-resource-leaks/dotnet/README.md) |
| **Subscribers síncronos en el EventBus** | `event` del CLR notifica en línea: un subscriber lento frena al publicador | [caso 08](../../cases/08-critical-module-extraction-without-breaking-operations/dotnet/README.md) |
| **Huella del SDK en la imagen** | La imagen del laboratorio es la del SDK, no la del runtime. Es lo correcto para un lab reproducible y no lo que se llevaría a producción | transversal |

**Desafío abierto del stack en este laboratorio:** el caso 12 muestra el techo de los nullable reference types. El compilador *avisa* de la ausencia, pero un `!` la silencia. Es exactamente la diferencia con Rust, donde omitir el brazo `None` no compila — y explica por qué .NET queda cuarto ahí pese a tener la herramienta.

---

## 🏆 Dónde gana y dónde pierde en el laboratorio

Agregado de los veredictos de las 18 comparativas que rankean: **1 primer puesto, media 3.3**.

- 🥇 **Gana en 11** — junto con Java: cuando el problema es el pool, tener pool explícito es la herramienta exacta.
- 🥈 **Segundo en 01 y 06** — `record` types con `with`-expressions modelan el rollback mejor que casi cualquier otro stack.
- 🥉 **Tercero en 04, 07, 09, 14, 15, 17, 18 y 19** — en el 19 porque `Except` y `Join` expresan las tres caras de la deriva como consultas tipadas, la forma más legible del set (con la pereza de LINQ como trampa) — en el 18 porque es el único stack con la respuesta a su propio problema **en la caja**: `PublishReadyToRun`, `TieredPGO` y `PublishAot` son tres líneas del `.csproj`
- **4º en 16** — `TryAdd` es correcto, pero convive con `GetOrAdd`, que parece equivalente y no lo es. — `CancellationToken` y `SemaphoreSlim` son claros y directos.
- **5º en 13** — `GetOrAdd` no garantiza fábrica única y el envoltorio `Lazy` que sí lo hace no es obvio.
- **6º en 02** — mismo motivo que Java: el ORM del ecosistema fabrica el bug.

**Lectura honesta:** dos décimas detrás de Java. La ventaja concreta de .NET en este laboratorio es el caso 03 —`AsyncLocal` fluye por `await`, que es más de lo que logra un `ThreadLocal` reutilizado— y la de Java es el caso 05, con la única LRU built-in del set.

---

## 🔄 Ciclo de versiones

| | |
|---|---|
| **Versión fijada hoy** | `8.0` LTS (`mcr.microsoft.com/dotnet/sdk:8.0`) |
| **Cadencia upstream** | Una release por año en noviembre; LTS en las pares |
| **Política de soporte** | LTS: 3 años. .NET 8 llega hasta noviembre de 2026 |
| **Producto en endoflife.date** | `dotnet` |

**Qué revisar en el próximo salto:**

1. **Fecha de EOL de .NET 8 (noviembre 2026)** — es la fecha de caducidad más cercana de todo el laboratorio. `language-drift.yml` la marcará en rojo cuando llegue.
2. **Cambios en las primitivas BCL de los casos 04, 09 y 11** — `CancellationTokenSource`, `SemaphoreSlim` y la API de `ThreadPool` son el corazón de esos tres casos.
3. **Mejoras en el análisis de nulabilidad** — si el compilador endureciera el aviso a error, el veredicto del caso 12 cambiaría.
4. **Nuevas colecciones en la BCL** — una LRU oficial dejaría obsoleta la construcción manual del caso 05.

El detalle del procedimiento está en [docs/language-upgrade-protocol.md](../language-upgrade-protocol.md).

---

## 🚀 Levantar el stack

```bash
docker compose -f compose.dotnet.yml up -d --build
```

Los 19 casos quedan servidos en `http://localhost:8500/NN/`. Cada caso trae además su propio `compose.yml` para correrlo aislado.
