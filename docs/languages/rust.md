# 🦀 Rust

> **Versión fijada:** `1.83` · **Imagen base:** `rust:1.83-alpine` · **Hub:** `:8700` · **Casos operativos:** 14 / 14

[⬅️ Volver a los perfiles de lenguaje](README.md) · [🗺️ Mapa de stacks](../stack-map.md) · [🔄 Protocolo de actualización](../language-upgrade-protocol.md)

---

## 🪪 Identidad

Rust es un lenguaje compilado y de tipado estático **sin recolector de basura**, que garantiza seguridad de memoria y de concurrencia en tiempo de compilación mediante un sistema de propiedad (*ownership*) verificado por el *borrow checker*. La consigna del proyecto es que un programa que compila no tiene fugas de memoria ni condiciones de carrera en código seguro.

**Para qué se usa en la industria:** sistemas donde el costo de un fallo de memoria es alto o donde no hay presupuesto para un GC — motores de navegador, kernels, sistemas embebidos, bases de datos, herramientas de línea de comandos de alto rendimiento y, cada vez más, servicios de red. Firefox, el kernel de Linux, Dropbox, Discord y buena parte de la infraestructura de Cloudflare tienen componentes en Rust.

**Por qué está en este laboratorio:** porque desplaza errores de runtime a errores de compilación, y eso se puede *demostrar*. En el caso 12 omitir el brazo `None` de un `match` no compila. En el caso 03 el compilador impide que el contexto del request sobreviva al request. Son las dos únicas garantías estructurales del set completo de siete stacks.

---

## ⚙️ Modelo de ejecución

**Threads del sistema operativo, sin recolector de basura, con liberación determinista por `Drop`.**

El laboratorio usa Rust **sin runtime asincrónico**: `std::thread` y un thread por conexión. Es una decisión deliberada, y tiene consecuencias visibles:

| Consecuencia | Dónde se nota |
|---|---|
| **La liberación es determinista y contable** | Un `impl Drop` propio descuenta bytes vivos y cuenta liberaciones. `dropped_total` es contabilidad real del destructor, no una estimación del GC — [caso 05](../../cases/05-memory-pressure-and-resource-leaks/rust/README.md) |
| **No hay cierre que escribir** | La `Connection` se cierra al salir de scope. Ni `try-with-resources`, ni `using`, ni `defer`, ni `finally` — [caso 01](../../cases/01-api-latency-under-load/rust/README.md) |
| **Thread por conexión 1:1 no escala como goroutines** | Es el costo de no usar `tokio`. El caso 11 lo documenta explícitamente en vez de esconderlo — [caso 11](../../cases/11-heavy-reporting-blocks-operations/rust/README.md) |
| **`std` no trae HTTP, JSON ni async** | La capa HTTP del laboratorio está escrita a mano sobre `TcpListener`. Es la contrapartida honesta de una stdlib mínima — transversal |

---

## 🧰 Primitivas que usa el laboratorio

| Caso | Primitiva central | Por qué esta y no otra |
|---|---|---|
| [01 · API lenta](../../cases/01-api-latency-under-load/rust/README.md) | `rusqlite` (feature `bundled`) + Ownership/`Drop` | SQLite compilado **dentro** del binario: no depende del `libsqlite3` del sistema |
| [02 · N+1](../../cases/02-n-plus-one-and-db-bottlenecks/rust/README.md) | `query_map(...).collect::<Result<Vec<_>>>()` | Materializa las filas **propagando el error de cualquiera de ellas**. Ignorar un fallo parcial es imposible |
| [03 · Observabilidad](../../cases/03-poor-observability-and-useless-logs/rust/README.md) | `struct RequestCtx` prestado por `&` + lifetimes | Una referencia al contexto **no puede almacenarse** en una estructura de vida más larga. La fuga de contexto no compila |
| [04 · Timeouts](../../cases/04-timeout-chain-and-retry-storms/rust/README.md) | `mpsc::channel` + `recv_timeout` | Deadline del lado del llamador. **Limitación documentada:** corta la espera, no el trabajo |
| [05 · Memoria](../../cases/05-memory-pressure-and-resource-leaks/rust/README.md) | `impl Drop for Tracked` + `LazyLock<Mutex<Lru>>` | Destructor propio que hace observable la liberación. `HashMap::new()` no es `const`, de ahí `LazyLock` |
| [06 · Pipeline](../../cases/06-broken-pipeline-and-fragile-delivery/rust/README.md) | `enum DeployOutcome` + `match` exhaustivo | Agregar `Canary` mañana **rompe la compilación** hasta contemplarlo en todos lados |
| [07 · Monolito](../../cases/07-incremental-monolith-modernization/rust/README.md) | `Box<dyn Fn(&Request) -> Response + Send + Sync>` | El compilador verifica la thread-safety **en el punto de registro**, no en el primer request concurrente |
| [08 · Extracción](../../cases/08-critical-module-extraction-without-breaking-operations/rust/README.md) | `mpsc::channel` (multi-producer, single-consumer) | El `Receiver` no implementa `Clone`: **no puede** haber dos consumidores. La restricción está en el tipo |
| [09 · Integración externa](../../cases/09-unstable-external-integration/rust/README.md) | `Mutex<i64>` con decremento condicional | Menos expresivo que el canal de Go, pero el guard libera en **todos** los caminos |
| [10 · Sobre-arquitectura](../../cases/10-expensive-architecture-for-simple-needs/rust/README.md) | `String::with_capacity` + `LazyLock<HashMap>` | Sin realocaciones intermedias; el mapa de solo lectura no necesita lock |
| [11 · Reportes](../../cases/11-heavy-reporting-blocks-operations/rust/README.md) | `Mutex<usize>` + `Condvar` | El que no consigue slot **duerme** hasta ser despertado: espera pasiva, sin busy-wait |
| [12 · Punto único](../../cases/12-single-point-of-knowledge-and-operational-risk/rust/README.md) | `Option<T>` + operador `?` + `match` exhaustivo | Omitir el brazo `None` **no compila**. El `?` propaga la ausencia sin escribir un solo `if` |
| [13 · Cache stampede](../../cases/13-cache-stampede-and-thundering-herd/rust/README.md) | `Arc<Flight>` con `Mutex` + `Condvar` | La `std` no trae `Future` ejecutable; el `Arc` obliga a que el vuelo sobreviva al mapa |
| [14 · Pool de conexiones](../../cases/14-connection-pool-exhaustion/rust/README.md) | `impl Drop` sobre `Lease` | No hay línea que olvidar; fugar exige llamar a `mem::forget` por su nombre |

> 💡 **El patrón que solo se ve mirando la columna entera:** en los casos 03, 06, 07, 08 y 12 la corrección no la impone la disciplina del programador sino el compilador. Son cinco categorías de bug que en los otros seis stacks se evitan *acordándose*.

---

## 📈 Rendimiento: qué mide el laboratorio y cómo reproducirlo

> ⚠️ **Este repositorio no publica benchmarks entre lenguajes.** Lo que se mide es la pendiente dentro de cada stack: legacy contra optimized, mismo runtime, misma máquina.

Rust es el único stack donde la medición de memoria es **contabilidad exacta** en vez de estimación: no hay GC que difiera la liberación, así que `live_bytes` refleja el estado real en el instante de la consulta.

| Señal | De dónde sale | Qué caso la expone |
|---|---|---|
| `live_bytes` · `live_mb` | contador propio en `impl Drop` | 05 |
| `dropped_total` | incremento dentro del destructor | 05 |
| `avg_ms` · `p95_ms` · `p99_ms` | `AtomicI64` + `Mutex<Vec<f64>>` | 01, 02, 10 |
| procesadores lógicos | `thread::available_parallelism()` | 11 |
| `IN_FLIGHT` | `AtomicI64` | 11 |

**Reproducir la medición del caso 05 (liberación determinista):**

```bash
docker compose -f compose.rust.yml up -d --build
curl -s localhost:8700/05/state
for i in $(seq 1 40); do curl -s "localhost:8700/05/batch-legacy?size_kb=64" > /dev/null; done
curl -s localhost:8700/05/state          # live_bytes y retained_count crecen juntos
curl -s localhost:8700/05/reset-lab      # el Drop libera en el acto, no "cuando pase el GC"
curl -s localhost:8700/05/state          # dropped_total refleja exactamente lo liberado
```

**Especificación de rendimiento que este stack verifica y ningún otro puede:** entre el `reset-lab` y la consulta siguiente no hay ventana de incertidumbre. En Java, .NET, Go, Node y Python la memoria se libera *en algún momento* después; acá se libera al salir de scope. Esa diferencia es el argumento completo del caso 05.

---

## 🚧 Límites, problemas sin solución y desafíos

| Límite | Por qué importa | Dónde se ve |
|---|---|---|
| **`recv_timeout` corta la espera, no el trabajo** | Exactamente la misma limitación de `CompletableFuture.orTimeout` en Java. El thread proveedor sigue dormido. `tokio` lo resuelve; `std` no | [caso 04](../../cases/04-timeout-chain-and-retry-storms/rust/README.md) |
| **`std` no trae HTTP, JSON ni runtime asincrónico** | La capa HTTP del lab está escrita a mano. En producción se usaría `axum` + `tokio` + `serde`, tres dependencias que otros stacks tienen en la caja | transversal |
| **`std::thread` es 1:1 con el SO** | Sin `tokio`, mil conexiones son mil threads. Go multiplexa; Rust sin runtime async, no | [caso 11](../../cases/11-heavy-reporting-blocks-operations/rust/README.md) |
| **Curva de aprendizaje del borrow checker** | Es el costo real de las garantías. Un equipo que no lo conoce paga semanas antes de ser productivo | transversal |
| **Tiempos de compilación** | El ciclo editar-compilar-probar es el más lento del laboratorio, con diferencia | transversal |
| **`.unwrap()` sigue estando ahí** | El lenguaje impide ignorar la ausencia, pero no impide convertirla en `panic` de una línea | [caso 12](../../cases/12-single-point-of-knowledge-and-operational-risk/rust/README.md) |

**Desafío abierto del stack en este laboratorio:** el caso 04 es el punto flojo, y no por una mala decisión de implementación sino por una limitación real de `std`. Documentarlo como quinto puesto —en vez de esconderlo detrás de `tokio`— es lo que hace comparable el caso. Si una versión futura de `std` incorporara cancelación cooperativa, **esa limitación dejaría de ser cierta y el caso 04 tendría que reescribirse**. Está anotado como disparador explícito en el [protocolo de actualización](../language-upgrade-protocol.md).

---

## 🏆 Dónde gana y dónde pierde en el laboratorio

Agregado de los veredictos de las 13 comparativas que rankean: **7 primeros puestos, media 2.0** — más oros que ningún otro stack.

- 🥇 **Gana en 02, 03, 05, 06, 07, 12 y 14** — en el 14 por lo que el lenguaje **impide**: `impl Drop` hace que fugar una conexión no se pueda escribir por descuido — todos los casos donde el sistema de tipos o el `Drop` determinista convierten un error de runtime en un error de compilación.
- 🥈 **Segundo en 08 y 09** — pierde el primer puesto contra Go por expresividad, no por corrección.
- 🥉 **Tercero en 01 y 11** — el thread-per-connection 1:1 le cuesta el podio.
- **4º en 13** — hay que construir el single-flight entero con `Condvar`: el compilador protege del use-after-remove, pero no regala la primitiva.
- **5º en 04** — la limitación de `recv_timeout`, documentada arriba.

**Lectura honesta:** Rust tiene el mayor rango del laboratorio. Es primero siete veces y quinto una vez. Go promedia mejor porque nunca baja del tercer puesto; Rust brilla donde el compilador aporta y queda al descubierto donde `std` no llega.

---

## 🔄 Ciclo de versiones

| | |
|---|---|
| **Versión fijada hoy** | `1.83` (`rust:1.83-alpine`) |
| **Cadencia upstream** | Una release menor cada 6 semanas |
| **Política de soporte** | Solo la última versión estable; el compromiso es de estabilidad hacia atrás por *edición*, no soporte de versiones viejas |
| **Producto en endoflife.date** | `rust` |

**Qué revisar en el próximo salto:**

1. **🚨 Cancelación en `std`** — si `std` incorpora algo equivalente a un timeout cancelable o a un semáforo, **las limitaciones documentadas en los casos 04 y 09 dejan de ser ciertas**. Es el disparador de revisión más importante de este stack: no es un bump, es una reescritura de la narrativa del caso.
2. **`LazyLock`** — estabilizado en 1.80 y usado en los casos 05, 07, 08 y 10. Cualquier cambio de API los toca a todos.
3. **Nueva edición (2024 en adelante)** — cambia reglas del lenguaje, no solo la versión del compilador. Requiere revisar los siete casos con código `unsafe`-adyacente o con inferencia sensible.
4. **`async` en `std`** — si llegara a estabilizarse un runtime mínimo, la decisión de "sin runtime asincrónico" pasaría de honesta a anticuada.

El detalle del procedimiento está en [docs/language-upgrade-protocol.md](../language-upgrade-protocol.md).

---

## 🚀 Levantar el stack

```bash
docker compose -f compose.rust.yml up -d --build
```

Los 14 casos quedan servidos en `http://localhost:8700/NN/`. La primera compilación es notablemente más lenta que la de los otros seis stacks — es esperable, no es un fallo del build.
