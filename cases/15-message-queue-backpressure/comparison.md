# ⚖️ Caso 15 — Comparativa multi-stack: Backpressure en colas de mensajes (PHP · Python · Node.js · Java · .NET · Go · Rust)

> **TL;DR** — Con 120 mensajes y un consumidor 3× más lento, la cola sin límite acumula **los 120** y el mensaje más viejo espera ~280 ms. La misma carga con capacidad 32 acota la profundidad y baja esa espera a ~80 ms. Pero acotar **obliga a elegir**: frenar al productor (218 ms de espera), descartar 88 mensajes, o mandar 88 a la DLQ. Los siete stacks llegan al mismo resultado. Lo que cambia es **cuánto ayuda el lenguaje a no equivocarse**.

<!-- nav -->
`🐘 PHP 8.3` · `🐍 Python 3.12` · `🟢 Node.js 22` · `☕ Java 21` · `🔵 .NET 8` · `🐹 Go 1.23` · `🦀 Rust 1.83`

**Estructura:** 🎯 el problema → 🧪 fidelidad del substrato → una sección por stack → ⚖️ tabla de decisión → 📊 primitiva por stack → 🏁 veredicto y ranking
<!-- /nav -->

## 🎯 El problema que los siete resuelven

El productor va más rápido que el consumidor. La cola absorbe la diferencia.

Sin límite, eso se ve **bien en todas las métricas que la gente mira**: throughput alto, cero errores, cero descartes, productor que nunca espera. Las dos que lo delatan casi nunca están en el dashboard — la profundidad de la cola y la edad del mensaje más viejo.

Con límite hay que decidir qué pasa cuando se llena, y las tres opciones cuestan algo:

| Política | Qué paga |
|---|---|
| `block` | latencia: la lentitud viaja aguas arriba hasta el cliente |
| `drop_oldest` | datos: se pierden mensajes, en silencio salvo que se cuenten |
| `dead_letter` | deuda operativa: alguien tiene que mirar esa cola |

**No existe una cuarta opción gratis.** La cola sin límite parece serlo porque el costo llega después y de golpe.

---

## 🧪 Fidelidad del substrato

| Qué | Es real | Es simulado |
|---|---|---|
| La concurrencia productor/consumidor | ✅ Hilos, goroutines o tareas reales en 6 stacks | ⚠️ Un solo bucle en PHP |
| El tiempo de consumo | ✅ Una espera real | — |
| Profundidad, edad del más viejo, descartes | ✅ Medidos sobre la estructura real de cada runtime | — |
| El broker | — | ⚠️ La cola vive dentro del proceso; no hay Kafka ni SQS detrás |
| El tamaño en memoria | — | ⚠️ `queue_bytes_peak` = profundidad × 2 KB, un modelo para traducir unidades a algo legible |

**El `sleep` acá es la decisión fiel**, igual que en el [caso 14](../14-connection-pool-exhaustion/README.md) y al revés que en el [13](../13-cache-stampede-and-thundering-herd/README.md): un consumidor se demora esperando I/O, no quemando CPU.

**La asimetría de PHP:** su servidor embebido es de un solo proceso, así que el productor y el consumidor son pasos del mismo bucle — cada 3 mensajes producidos se drena 1. Profundidad, edad y pérdida son comparables; `producer_blocked_ms` no lo es.

---

## 🐘 PHP: el stack que no tiene la primitiva, y por eso enseña otra cosa

PHP **no tiene cola en proceso**. No hay `queue.Queue`, ni `chan`, ni `BlockingQueue`, ni `Channel`. Un array dentro de una request desaparece cuando la request termina.

Consecuencia: en PHP el backpressure **no vive en el lenguaje, vive en el transporte**.

| Política | Dónde vive en PHP |
|---|---|
| bloquear | `listen.backlog` de FPM y el accept queue del kernel |
| descartar | `pm.max_children` agotado: FPM devuelve 502 y el request se pierde |
| dead letter | la DLQ del broker real, porque la cola de verdad nunca estuvo en PHP |

Y ahí está lo que este stack enseña mejor que ninguno, justamente por no tener la primitiva: **el backpressure no es una propiedad de la cola, es una propiedad del sistema entero**. Si el freno no está en tu proceso, está en el kernel, en el broker o en el balanceador — pero está en algún lado.

---

## 🐍 Python: la política se elige en la firma de `put()`

```python
q.put(msg)                    # bloquea: backpressure hacia el productor
q.put_nowait(msg)             # levanta queue.Full: el llamador decide
q.put(msg, timeout=0.05)      # espera acotada y después decide
```

Las tres políticas no son tres estructuras: son tres formas de llamar al mismo método. Es la API más explícita del laboratorio en este punto — **no hay un modo por defecto que «haga algo razonable» a tus espaldas**.

El reverso está una línea más arriba: `queue.Queue()` sin `maxsize` es la versión sin límite, y se escribe con **menos** caracteres que la acotada.

Dato contraintuitivo: este es de los pocos casos donde el GIL no molesta. El tiempo de consumo es un `sleep`, y `sleep` libera el GIL, así que la contención es real.

---

## 🟢 Node.js: el único donde el backpressure es parte del protocolo

```js
const ok = writable.write(chunk);
if (!ok) await once(writable, 'drain');   // el freno
```

`write()` devuelve `false` cuando el buffer pasó el `highWaterMark`. **No es un error ni un rechazo**: el chunk se aceptó igual. Es una señal de cortesía — «seguí si querés, pero estoy acumulando».

Ningún otro stack tiene esto integrado en su API de escritura. En los demás, el backpressure es algo que uno construye eligiendo qué método llamar.

**Y por eso mismo, la trampa:** ignorar ese `false` compila, pasa los tests y funciona en desarrollo. El único síntoma es que el buffer crece hasta el OOM. La variante `unbounded` de este caso hace exactamente eso — ignora el retorno y pone el `highWaterMark` en infinito, que es lo que pasa cuando alguien «arregla» un warning subiendo el umbral en vez de respetarlo.

---

## ☕ Java: le puso nombre a cada forma de rechazar

```java
q.put(msg);                        // bloquea
q.offer(msg);                      // devuelve false
q.offer(msg, timeout, unit);       // espera acotada
```

Es la misma taxonomía de las `RejectedExecutionHandler` de `ThreadPoolExecutor` — `AbortPolicy`, `DiscardOldestPolicy`, `CallerRunsPolicy`. **Java tiene un nombre para cada forma de rechazar**, y eso obliga a nombrar la decisión en el código.

**El contraste incómodo está una interfaz más arriba.** `ConcurrentLinkedQueue` implementa la misma `Queue` que `ArrayBlockingQueue` y no tiene capacidad. Cambiar una por otra es una línea que compila sin advertencias, pasa todos los tests —porque con poca carga el comportamiento es idéntico— y **saca el freno del sistema entero**.

Por eso la variante `unbounded` de este caso usa exactamente esa clase: es el error real, no uno inventado.

---

## 🔵 .NET: la política es un enum del constructor

```csharp
new BoundedChannelOptions(capacity) {
    FullMode = BoundedChannelFullMode.Wait | DropOldest | DropWrite
}
```

En Python, Java o Go la política se elige **en cada sitio de envío** — y por lo tanto se puede elegir distinto en dos lugares del mismo sistema sin que nada lo note. Acá la decisión se toma una vez, al construir el canal, y todos los productores la heredan.

Segundo detalle que ningún otro stack tiene: **el canal avisa cuando descarta**.

```csharp
Channel.CreateBounded<Msg>(options, dropped => Interlocked.Increment(ref droppedCount));
```

No hay que inferir la pérdida de un contador propio. El descarte silencioso —la peor variante de este caso— es difícil de escribir en .NET.

El reverso: `Channel.CreateUnbounded<T>()` es igual de fácil, sin parámetros y sin advertencias.

---

## 🐹 Go: no existe el canal con buffer infinito

```go
q := make(chan msg, capacity)   // el número está escrito, siempre
```

O el canal es sin buffer, o la capacidad está en la línea. **Go no ofrece la versión sin límite** — por eso la variante `unbounded` de este caso hay que construirla a mano con una slice y un mutex, con más código que la correcta.

En Java o Python la cola sin techo es lo que sale por defecto. Acá hay que escribirla a propósito.

Las tres políticas son tres formas del mismo envío (`q <- msg`, `select` con `default`, `select` con `time.After`), y es la **misma primitiva** de los casos 04, 08, 09 y 14. Cinco problemas distintos, un concepto.

---

## 🦀 Rust: el límite está en el tipo

```rust
let (tx, rx) = mpsc::channel::<Msg>();          // Sender<T>     — sin capacidad
let (tx, rx) = mpsc::sync_channel::<Msg>(32);   // SyncSender<T> — acotado
```

Son **tipos distintos con métodos distintos**. No se puede olvidar el límite de un canal acotado ni pedirle backpressure a uno sin límite: el compilador no deja escribir la confusión.

Es el contraste directo con Java, donde las dos colas comparten interfaz y se intercambian en una línea.

Y hay un segundo detalle que ningún otro stack tiene:

```rust
Err(TrySendError::Full(msg)) => dlq.push(msg),   // el mensaje vuelve
```

`TrySendError::Full(T)` devuelve **la propiedad del valor rechazado**. En Go o Java el mensaje descartado sigue en tu mano por convención; acá el tipo garantiza que llegó entero y que hay que decidir explícitamente qué hacer con él. Es exactamente lo que una DLQ necesita, sin clonar por las dudas antes de intentar el envío.

---

## ⚖️ Tabla de decisión

| Pregunta | Respuesta |
|---|---|
| ¿La cola sin límite es más rápida? | En throughput sí, y por eso engaña. En latencia del mensaje real es **3 o 4 veces peor**, con la misma cantidad de trabajo hecho. |
| ¿Agrandar la capacidad arregla? | Difiere el problema. Con productor sostenidamente más rápido, cualquier capacidad se llena; solo cambia cuánto tarda. |
| ¿Cuál política elegir? | Depende de qué se puede sacrificar. Pagos: `block`. Telemetría: `drop_oldest`. Importante pero no urgente: `dead_letter` **con dueño**. |
| ¿`drop_oldest` sin contador? | Es peor que la cola sin límite: pierde datos **y** es invisible. `messages_dropped_total` tiene que existir aunque valga cero. |
| ¿La DLQ resuelve el problema? | No: lo muda. Convierte un problema de capacidad en uno de operación — y si nadie la mira, es el [caso 20](../20-forgotten-dead-letter-queue/README.md). |
| ¿Y escalar consumidores? | Mueve el cuello de botella al recurso que comparten: la base, el pool ([caso 14](../14-connection-pool-exhaustion/README.md)) o el servicio externo. |

---

## 📊 Primitiva central por stack

| Stack | Cola acotada | Versión sin límite | Cómo se elige la política |
|---|---|---|---|
| 🐘 PHP | array con chequeo manual | array (por defecto) | a mano; en producción vive en FPM y el broker |
| 🐍 Python | `queue.Queue(maxsize=N)` | `queue.Queue()` | en la firma de `put()` |
| 🟢 Node.js | `Writable` con `highWaterMark` | ignorar el `false` de `write()` | respetando o no el protocolo |
| ☕ Java | `ArrayBlockingQueue(N)` | `ConcurrentLinkedQueue` **(misma interfaz)** | `put` / `offer` / `offer(timeout)` |
| 🔵 .NET | `Channel.CreateBounded` | `Channel.CreateUnbounded()` | **enum del constructor** |
| 🐹 Go | `make(chan T, N)` | **no existe** — hay que construirla | `select` con o sin `default` |
| 🦀 Rust | `sync_channel(N)` → `SyncSender` | `channel()` → `Sender` **(otro tipo)** | `send` / `try_send` |

---

## 🏁 Veredicto

> Mide **fit con el problema**, no calidad del lenguaje. El criterio acá: qué tanto ayuda el lenguaje a que la cola sin límite y el descarte silencioso sean difíciles de escribir.

| Puesto | Stack | Por qué |
|---|---|---|
| 🥇 | **Go 1.23** | La capacidad es parte de la construcción del canal: **no existe el buffer infinito**. La versión con el bug hay que escribirla a mano y sale más larga que la correcta. Y las tres políticas reutilizan el `select` que ya se aprendió en otros cuatro casos. |
| 🥈 | **Rust 1.83** | El límite está en el tipo, así que la confusión no compila. `TrySendError::Full(T)` devolviendo el mensaje rechazado es la mejor primitiva del set para una DLQ. Pierde el oro porque `mpsc::channel()` sin límite está a un nombre de distancia. |
| 🥉 | **.NET 8** | La política como enum del constructor se decide una vez para todo el sistema, y el callback de descarte hace que la pérdida silenciosa sea difícil de escribir. `CreateUnbounded()` sin advertencias le cuesta el podio más alto. |
| 4º | **Python 3.12** | `put` / `put_nowait` / `put(timeout=)` es la API más explícita para elegir política. Baja porque `Queue()` sin `maxsize` se escribe con menos caracteres que la acotada. |
| 5º | **Java 21** | Le puso nombre a cada forma de rechazar, lo cual es valioso. Pero `ConcurrentLinkedQueue` comparte interfaz con `ArrayBlockingQueue`, así que sacar el freno del sistema es un cambio de una línea que compila y pasa los tests. |
| 6º | **Node.js 22** | Único stack donde el backpressure es parte del protocolo del runtime — y único donde **ignorarlo compila, pasa los tests y funciona en desarrollo**. La señal existe; nada obliga a mirarla. |
| 7º | **PHP 8.3** | No tiene cola en proceso, así que el caso se demuestra en un solo bucle. A cambio enseña lo que ningún otro: que el freno, si no está en tu proceso, está en el kernel o en el broker — y hay que saber cuál. |

**Lectura honesta:** los siete llegan al mismo resultado. La diferencia no está en lo que pasa cuando el código está bien, sino en **qué tan fácil es que esté mal** — y en este caso «mal» tiene dos formas: la cola sin techo y el descarte que nadie cuenta. Si este caso te deja con la conclusión «debería migrar a Go», lo leíste al revés. La conclusión es «debería graficar la profundidad de mi cola y la edad de su mensaje más viejo».
