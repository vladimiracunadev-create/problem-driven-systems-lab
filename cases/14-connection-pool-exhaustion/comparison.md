# ⚖️ Caso 14 — Comparativa multi-stack: Agotamiento del pool de conexiones (PHP · Python · Node.js · Java · .NET · Go · Rust)

> **TL;DR** — Con un pool de 4 y 24 requests de las que fallan ~7, la variante sin devolución garantizada pierde **4 conexiones** y deja **12 requests colgadas**; el pool termina en `0/4` y nunca se recupera. La variante corregida pierde **0**, termina en `4/4`, y tarda 155 ms en vez de 2 segundos. El número es idéntico en los siete stacks. Lo que cambia es **cuánto trabajo hace falta para no equivocarse**.

<!-- nav -->
`🐘 PHP 8.3` · `🐍 Python 3.12` · `🟢 Node.js 22` · `☕ Java 21` · `🔵 .NET 8` · `🐹 Go 1.23` · `🦀 Rust 1.83`

**Estructura:** 🎯 el problema → 🧪 fidelidad del substrato → una sección por stack → ⚖️ tabla de decisión → 📊 primitiva por stack → 🏁 veredicto y ranking
<!-- /nav -->

## 🎯 El problema que los siete resuelven

Un pool de conexiones con dos defectos que se necesitan mutuamente:

1. **La devolución está solo en el camino feliz.** Cada excepción se lleva una conexión que no vuelve. Nada en los logs lo dice: el pool simplemente se achica.
2. **La adquisición no tiene deadline.** El que llega cuando ya no hay conexiones no falla — se queda. Y mientras se queda ocupa un hilo, una goroutine o una Promise que nadie va a resolver.

El resultado combinado es una indisponibilidad que **no produce errores**. Los requests no terminan, así que no generan muestras de latencia: el p99 no se dispara, desaparece del gráfico.

La métrica del caso es `leaked` = `acquired - released`. Dos contadores.

---

## 🧪 Fidelidad del substrato

| Qué | Es real | Es simulado |
|---|---|---|
| La contención sobre el pool | ✅ Hilos, goroutines o tareas reales compitiendo en 6 stacks | ⚠️ Secuencial en PHP |
| El tiempo de retención | ✅ Una espera real (`sleep` / `Task.Delay` / `setTimeout`) | — |
| Los contadores de fuga | ✅ `acquired` y `released` reales | — |
| La conexión | — | ⚠️ Es un objeto en memoria, no un socket contra una base |
| El deadline de adquisición | ✅ Reloj real | — |

**El `sleep` acá es la decisión fiel, y en el caso 13 era lo contrario.** Una conexión se retiene mientras se espera a la red, no mientras se quema CPU. En el caso 13 un `sleep` habría escondido el punto; acá quemar CPU lo escondería. Misma pregunta —¿qué recurso escasea de verdad?—, respuestas opuestas.

**La asimetría de PHP:** su servidor embebido es de un solo proceso, así que las N requests se recorren en secuencia y el pool vive dentro de una sola llamada HTTP. `leaked` da el mismo número; lo que no es comparable es la espera.

---

## 🐘 PHP: `finally`, y por qué el proceso por request tapa el bug

```php
try {
    runQuery($conn, $queryMs, fails($i, $failRate));
    $counts['completed']++;
} catch (RuntimeException) {
    $counts['failed_query']++;
} finally {
    $pool->release($conn);       // corre en éxito, en excepción y en continue
}
```

`finally` en PHP cubre también el `continue` y el `break` del bloque `try`, no solo el `throw`. Es la misma garantía que Java obtiene con try-with-resources, escrita a mano.

**Lo interesante de PHP es otra cosa.** El proceso por request hace que una conexión fugada se recupere sola: el proceso muere y el sistema operativo reclama el socket. Por eso media industria PHP nunca vio este bug.

Hasta que aparecen las conexiones persistentes. `PDO::ATTR_PERSISTENT` pega la conexión al worker de FPM y el modelo de «el proceso limpia por mí» deja de aplicar. **La versión PHP del agotamiento no es «el pool se vacía»**: es `max_children` de FPM multiplicado por persistentes contra el `max_connections` del motor. Con 50 workers y una persistente cada uno, la base ve 50 conexiones abiertas con 3 req/s de tráfico.

---

## 🐍 Python: `queue.Queue` como pool + `@contextmanager`

```python
@contextmanager
def lease(self, timeout_ms):
    conn = self.acquire(timeout_ms)
    if conn is None:
        raise TimeoutError("pool acquire timeout")
    try:
        yield conn
    finally:
        self.release(conn)
```

`queue.Queue(maxsize=N)` **es** el pool: cada elemento es una conexión libre, `get(timeout=...)` es la adquisición con deadline y `put()` la devolución. La biblioteca estándar trae la estructura; lo que hay que aportar es la disciplina, y el `finally` de un generador decorado la aporta en todos los caminos de salida.

**Dato contraintuitivo:** este es de los pocos casos donde el GIL no molesta. El trabajo que retiene la conexión es un `sleep`, y `sleep` libera el GIL — así que los 24 hilos esperan de verdad en paralelo y la contención es real.

---

## 🟢 Node.js: el que espera es una Promise, y ese es el problema

En Java o Go, un hilo bloqueado esperando una conexión sigue siendo un objeto que un thread dump muestra. En Node **no hay hilo**: el que espera es una `Promise` que nadie va a resolver nunca.

No aparece en ningún stack trace. No consume CPU. No dispara ninguna alarma. El request simplemente no responde. Es un leak de memoria y un request perdido a la vez, y el proceso se ve sano desde afuera.

```js
const signal = AbortSignal.timeout(timeoutMs);
signal.addEventListener('abort', onAbort, { once: true });
```

`AbortSignal.timeout()` no es un lujo acá: **es la única forma de que la espera tenga un final observable**. Y no necesita `clearTimeout` manual — el runtime libera el temporizador al abortar o al recolectar la señal.

---

## ☕ Java: el compilador escribe el `finally`

```java
try (Lease l = lease) {
    runQuery(l.conn, queryMs, fails(idx, failRate));
    return new Outcome("completed", waitMs);
} catch (RuntimeException e) {
    return new Outcome("failed_query", waitMs);
}
```

try-with-resources **no depende de que el programador se acuerde**: el compilador genera el `finally` que llama a `close()`, para todos los caminos de salida, incluida una excepción lanzada dentro del propio bloque. La única forma de fugar con try-with-resources es no usarlo.

`ArrayBlockingQueue` es la estructura sobre la que están construidos HikariCP y compañía, y `poll(timeout, unit)` es la otra mitad. Sin él, `take()` espera para siempre: un hilo del pool HTTP que en un thread dump aparece como `WAITING (parking)` y no dice por qué.

---

## 🔵 .NET: el timeout es un valor, no una excepción

```csharp
if (!await _permits.WaitAsync(timeoutMs)) return null;
```

`WaitAsync` con timeout devuelve `false` en vez de lanzar. Eso hace que **«no había conexión» y «la conexión falló» sean dos caminos distintos en el código** — exactamente la distinción que el llamador necesita para decidir si reintenta o se rinde. En Python el timeout llega como `queue.Empty` y hay que capturarlo para no confundirlo con un error de la query.

Y el segundo detalle, que es único en el laboratorio:

```csharp
using var held = lease;        // sin bloque anidado
```

`using var` no necesita anidar, así que **el código correcto queda más corto que el incorrecto**. Es el único stack del lab donde hacer lo correcto ahorra líneas.

---

## 🐹 Go: el canal bufferizado **es** el pool

```go
type pool struct {
    free chan *conn      // lleva las conexiones Y limita cuántas hay en vuelo
}

select {
case c := <-p.free:  return c, nil
case <-timer.C:      return nil, errNoConn
}
```

Una sola estructura hace de contenedor y de límite. Y el `select` con temporizador es la **misma primitiva** que el caso 04 usa para cancelación, el 08 para el bus de eventos y el 09 para la cuota: cuatro problemas distintos, un concepto.

**Pero acá Go tiene un límite honesto.** `defer p.release(c)` es una línea que hay que acordarse de escribir. Un `return` temprano antes del `defer` fuga la conexión y **compila igual**. Es exactamente la puerta que Rust cierra.

---

## 🦀 Rust: el leak hay que pedirlo por su nombre

```rust
impl Drop for Lease {
    fn drop(&mut self) {
        if let Some(conn) = self.conn.take() {
            self.pool.give_back(conn);
        }
    }
}
```

El `Drop` devuelve la conexión cuando el `Lease` sale de alcance — en el return feliz, en el temprano, y también mientras un `panic` desenrolla la pila. No hay línea que olvidar.

Por eso la variante leaky de este caso **tuvo que escribirse a propósito**:

```rust
std::mem::forget(lease);   // se queda con el valor y NO corre su Drop
```

Es la única forma de fugar un recurso en Rust seguro. Y no es `unsafe`: no puede corromper memoria, solo perderla. Rust considera que perder memoria es seguro; lo que impide es usarla después de liberarla.

> En seis stacks el leak es lo que pasa si te distraes. En Rust hay que pedirlo por su nombre, y el nombre es grepeable.

---

## ⚖️ Tabla de decisión

| Pregunta | Respuesta |
|---|---|
| ¿Agrandar el pool arregla la fuga? | No. Retrasa el agotamiento. Con fuga, cualquier tamaño se vacía; solo cambia cuántas horas tarda. |
| ¿Basta con el `finally`? | Evita la fuga, no la saturación legítima. Sin deadline, un pico de tráfico sigue colgando requests. |
| ¿Basta con el timeout? | Limita el daño, no evita la fuga. El pool se sigue vaciando; ahora los que llegan tarde reciben 503 en vez de colgarse. |
| ¿Cómo se distingue pool ocupado de pool vacío? | Por `leaked`. `available == 0` con `leaked == 0` es saturación; con `leaked > 0` es fuga. La misma métrica de «disponibles» los muestra igual. |
| ¿Por qué el p99 desaparece en vez de dispararse? | Porque los requests no terminan, y un request que no termina no produce muestra. **Una latencia que desaparece del gráfico es peor noticia que una latencia alta.** |
| ¿Y si el pool está bien dimensionado? | `pool_size = throughput × tiempo_de_servicio + buffer`. Un pool de 100 para 5 req/s con queries de 20 ms son 99 conexiones ociosas que la base sostiene igual. |

---

## 📊 Primitiva central por stack

| Stack | Pool | Deadline de adquisición | Garantía de devolución |
|---|---|---|---|
| 🐘 PHP | array en el proceso | — (un solo proceso) | `finally` |
| 🐍 Python | `queue.Queue(maxsize=N)` | `get(timeout=...)` | `@contextmanager` |
| 🟢 Node.js | array + cola de waiters | `AbortSignal.timeout()` | `finally` en `async` |
| ☕ Java | `ArrayBlockingQueue` | `poll(timeout, unit)` | **try-with-resources** (lo genera el compilador) |
| 🔵 .NET | `SemaphoreSlim` + `ConcurrentBag` | `WaitAsync(timeout)` → `false` | **`using var`** (lo genera el compilador) |
| 🐹 Go | `chan *conn` bufferizado | `select` + `time.NewTimer` | `defer` (hay que escribirlo) |
| 🦀 Rust | `Mutex<Vec<Conn>>` + `Condvar` | `wait_timeout` | **`impl Drop`** (no hay línea que olvidar) |

---

## 🏁 Veredicto

> Mide **fit con el problema**, no calidad del lenguaje. Acá el criterio es directo: qué tan difícil es escribir la versión con fuga.

| Puesto | Stack | Por qué |
|---|---|---|
| 🥇 | **Rust 1.83** | El único stack donde la fuga **no se puede escribir por descuido**. `impl Drop` cubre el return feliz, el temprano y el desenrollado por panic. Fugar exige llamar a `mem::forget` por su nombre. |
| 🥈 | **Java 21** | try-with-resources genera el `finally` en el compilador, para todos los caminos. Se puede fugar, pero solo no usándolo — y eso se ve en la review. |
| 🥉 | **.NET 8** | La misma garantía que Java más dos ventajas de forma: `using var` sin anidar hace que lo correcto sea más corto, y `WaitAsync` devuelve `false` en vez de lanzar, separando «no había» de «falló». |
| 4º | **Python 3.12** | `queue.Queue` + `@contextmanager` es limpio y el `with` se lee bien, pero la garantía depende de que alguien escriba el context manager y de que todos lo usen. |
| 5º | **Go 1.23** | El canal como pool es la expresión más económica del laboratorio, y el `select` reutiliza el concepto de otros cuatro casos. Pierde el podio por una línea: `defer` hay que acordarse de escribirlo, y olvidarlo compila. |
| 6º | **Node.js 22** | `AbortSignal.timeout` y `finally` alcanzan, pero el modo de falla es el peor del set: sin deadline el que espera es una Promise invisible que no aparece en ningún stack trace ni consume CPU. |
| 7º | **PHP 8.3** | Sin concurrencia dentro del proceso, el caso se demuestra en secuencia. Y su versión real del problema es otra — persistentes de FPM contra `max_connections` — que este código documenta pero no ejecuta. |

**Lectura honesta:** los siete llegan al mismo resultado con la primitiva correcta. La diferencia no está en lo que pasa cuando el código está bien, sino en **qué tan fácil es que esté mal**. Ese es el eje real de este caso, y es el único del laboratorio donde Rust gana por lo que el lenguaje *impide* y no por lo que expresa.
