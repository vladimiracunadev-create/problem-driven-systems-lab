# ⚖️ Caso 13 — Comparativa multi-stack: Cache stampede y thundering herd (PHP · Python · Node.js · Java · .NET · Go · Rust)

> **TL;DR** — Con 16 llamadores sobre una clave recién expirada, la variante naive manda **16 recálculos** al origen en los siete stacks. La variante corregida manda **1**, y los otros 15 se cuelgan del mismo resultado. El número es idéntico en los siete; lo que cambia es cuánto código hace falta para conseguirlo.

<!-- nav -->
`🐘 PHP 8.3` · `🐍 Python 3.12` · `🟢 Node.js 22` · `☕ Java 21` · `🔵 .NET 8` · `🐹 Go 1.23` · `🦀 Rust 1.83`

**Estructura:** 🎯 el problema → 🧪 fidelidad del substrato → una sección por stack → ⚖️ tabla de decisión → 📊 primitiva por stack → 🏁 veredicto y ranking
<!-- /nav -->

## 🎯 El problema que los siete resuelven

Una clave de cache caliente expira. En ese instante, N requests que la estaban usando encuentran el hueco. Ninguno sabe que los otros existen, así que **todos** van al origen a recalcular exactamente el mismo valor.

El fallo tiene tres partes independientes, y hacen falta las tres para que ocurra:

1. Nada coordina a los N llamadores (**sin single-flight**).
2. Las claves cargadas juntas expiran juntas (**TTL fijo sin jitter**).
3. No existe el estado «viejo pero servible» (**sin soft TTL**).

La métrica del caso es `origin_computations`: cuántas veces se ejecuta el trabajo caro. No es la latencia.

---

## 🧪 Fidelidad del substrato

| Qué | Es real | Es simulado |
|---|---|---|
| El trabajo del origen | ✅ CPU real: un digest iterativo de `cost × 2000` iteraciones | — |
| La concurrencia de los llamadores | ✅ Hilos, goroutines o tareas reales en 6 stacks | ⚠️ Secuencial en PHP (ver abajo) |
| La cache | ✅ Estructura en memoria del proceso (archivo JSON en PHP) | — |
| El origen | — | ⚠️ No hay base de datos detrás: el «origen» es CPU, no una consulta |
| Las TTL | ✅ Reloj real, jitter real | — |

**Las dos asimetrías, dichas de frente:**

- **PHP recorre los N llamadores en secuencia.** Su servidor embebido es de un solo proceso. La primitiva que demuestra —lock de almacenamiento más double check— es exactamente la que hace falta bajo PHP-FPM con N procesos reales, y `origin_computations` da el mismo número en los dos modelos. Lo que no es comparable es `wall_ms`.
- **El origen es CPU, no una base de datos.** Un `sleep` habría sido más fácil de escribir y no habría probado nada: lo que duele en una estampida real es que el origen **hace** el trabajo N veces, no que espera N veces.

---

## 🐘 PHP: `flock()` + double-checked locking

PHP no tiene heap compartido entre requests. Cada petición arranca un proceso limpio, corre y muere. El `Map` de Node y el `ConcurrentHashMap` de Java **no existen** acá: cualquier estructura en memoria se evapora al terminar la request.

Consecuencia: en PHP el single-flight no puede vivir en el proceso, tiene que vivir en el almacenamiento.

```php
// 1. leer sin lock
[, $state] = cacheLookup($key);
if ($state === 'fresh') { return; }

// 2. lock exclusivo entre procesos
flock($lock, LOCK_EX);

// 3. VOLVER a leer — otro proceso pudo llenarla mientras esperábamos
[, $recheck] = cacheLookup($key);
if ($recheck === 'fresh') { $waiters++; }
else { computeOrigin($key, $rounds); }

// 4. soltar
flock($lock, LOCK_UN);
```

El paso 3 es el que se omite y es medio patrón. Un lock sin double check no evita la estampida: la ordena en fila.

---

## 🐍 Python: dict de vuelos + `threading.Event`

```python
with _inflight_lock:
    flight = _inflight.get(key)
    leader = flight is None
    if leader:
        flight = {"event": threading.Event(), "value": None}
        _inflight[key] = flight          # publicar ANTES de calcular

if leader:
    _, recheck = cache_lookup(key)       # double check
    if recheck != "fresh":
        cache_store(key, origin_compute(key, rounds))
    flight["event"].set()
else:
    flight["event"].wait(timeout=30)
```

`Event` es literalmente el «espera a que alguien más termine» que el patrón necesita. No hace falta librería.

**El detalle del GIL:** sin una barrera de largada, el primer hilo termina su digest completo dentro de su propio quantum y los otros quince encuentran el valor fresco. `origin_computations` daría 1 y la variante naive **parecería correcta** — un falso verde que depende de `sys.setswitchinterval`. La barrera reproduce lo que pasa de verdad: cuando la clave expira, los N requests ya estaban en vuelo.

---

## 🟢 Node.js: `Map<key, Promise>`

La versión más corta del patrón en todo el lab:

```js
const flight = computeOriginIfNeeded(key, rounds);
inflight.set(key, flight);          // ← el orden importa
try { didCompute = await flight; } finally { inflight.delete(key); }
```

Una Promise ya es «un resultado que todavía no está, al que cualquiera puede suscribirse». No hace falta lock ni Event.

Y por eso mismo es la más fácil de escribir mal: si el `Map.set` ocurre **después** del primer `await`, la ventana entre ambos deja pasar la estampida entera. La garantía la pone quien escribe el código, no el runtime.

---

## ☕ Java: `computeIfAbsent` atómico + `CompletableFuture`

```java
CompletableFuture<Boolean> flight = inflight.computeIfAbsent(key, k -> {
    leader[0] = true;
    return CompletableFuture.supplyAsync(() -> {
        if ("fresh".equals(cacheState(k))) return false;   // double check
        computeOrigin(k, rounds);
        return true;
    }, originPool).whenComplete((v, err) -> inflight.remove(k));
});
```

`computeIfAbsent` mantiene el bin de la clave bloqueado mientras corre la función de mapeo: **mirar si existe y crearlo son una sola operación indivisible**. No hay ventana check-then-act que ordenar a mano.

La sutileza que el código respeta: la función de mapeo no debe bloquear, o el bin queda tomado mientras el origen trabaja. Por eso adentro solo se crea el Future y el trabajo caro corre en otro executor.

---

## 🔵 .NET: `Lazy<Task<T>>` porque `GetOrAdd` no alcanza

Acá está el matiz más interesante de la comparativa. **`ConcurrentDictionary.GetOrAdd` no garantiza que la fábrica corra una sola vez** — la documentación lo dice explícitamente. Si varios hilos entran a la vez, la fábrica puede ejecutarse N veces y solo una instancia gana el puesto.

Para una cache de valores eso es desperdicio. Para un single-flight es el bug entero, y el código *parece* correcto.

```csharp
var mine = new Lazy<Task<bool>>(
    () => Task.Run(() => { /* double check + origen */ }),
    LazyThreadSafetyMode.ExecutionAndPublication);

var flight   = Inflight.GetOrAdd(key, mine);
var isLeader = ReferenceEquals(flight, mine);
```

Aunque `GetOrAdd` construya varios `Lazy`, solo el que quedó en el diccionario recibe `.Value` — y `Lazy` sí garantiza ejecución única.

**Java y .NET al lado:** misma estructura de datos aparente, garantía distinta. En Java la trae el mapa; en .NET hay que traerla uno.

---

## 🐹 Go: `singleflight` escrito a mano en 25 líneas

Go tiene la respuesta oficial en `golang.org/x/sync/singleflight`, pero es un módulo externo y este lab compila sin red. No hace falta:

```go
func do(key string, fn func() bool) (bool, bool) {
    flightMu.Lock()
    if c, ok := flights[key]; ok {
        flightMu.Unlock()      // soltar ANTES de esperar
        c.wg.Wait()
        return c.did, false
    }
    c := new(call)
    c.wg.Add(1)
    flights[key] = c
    flightMu.Unlock()

    c.did = fn()
    c.wg.Done()

    flightMu.Lock()
    delete(flights, key)
    flightMu.Unlock()
    return c.did, true
}
```

La pieza es `sync.WaitGroup` usada al revés: en vez de «el coordinador espera a los trabajadores», el **líder** hace `Add(1)` y los seguidores `Wait()`. Un WaitGroup es un contador con espera, y eso es exactamente un single-flight con una sola operación pendiente.

---

## 🦀 Rust: `Condvar` porque la `std` no trae futuros ejecutables

Node, Java y .NET apoyan el patrón en un objeto «resultado futuro» que el runtime ya trae. La `std` de Rust no tiene ninguno: sin un runtime externo como tokio no hay `Future` ejecutable. Lo que sí trae es la pieza de más abajo, la misma que los otros esconden adentro:

```rust
// seguidor
let guard = flight.result.lock().unwrap();
let done  = flight.ready.wait_while(guard, |r| r.is_none()).unwrap();

// líder
*flight.result.lock().unwrap() = Some(did_compute);
flight.ready.notify_all();
```

**Lo que el compilador aporta y ningún otro stack tiene:** el `Arc<Flight>` es obligatorio. En Go o Java uno puede quedarse con un puntero a una entrada que otro hilo ya borró del mapa y el código compila; acá no hay forma de expresar eso.

`wait_while` en vez de `wait` tampoco es cosmético: protege del *spurious wakeup*. Con `wait` a secas el seguidor podría leer un `None` y seguir de largo.

---

## ⚖️ Tabla de decisión

| Pregunta | Respuesta |
|---|---|
| ¿Basta con el jitter del TTL? | No. Reduce la probabilidad de coincidencia, no la elimina. Es la mitad barata del arreglo, no el arreglo. |
| ¿Basta con un lock? | No, si no tiene double check adentro. Sin él, el origen recibe las mismas N consultas en fila. |
| ¿El single-flight en memoria alcanza? | Solo dentro del proceso. Con 20 réplicas quedan 20 recálculos en vez de 2000: mejor, pero no 1. Para llegar a 1 hace falta un lock distribuido. |
| ¿Cuándo conviene servir stale? | Cuando el dueño del dato acepta la ventana. Es una decisión de producto, no de ingeniería. |
| ¿Se puede medir con el hit rate? | **No.** Un sistema con 99,9% de hit rate puede caerse por el 0,1%: lo que importa no es la proporción de aciertos sino cuántos fallos coinciden en el tiempo. |

---

## 📊 Primitiva central por stack

| Stack | Primitiva | Garantía de ejecución única |
|---|---|---|
| 🐘 PHP | `flock(LOCK_EX)` + double check | Del sistema de archivos, entre procesos |
| 🐍 Python | dict de vuelos + `threading.Event` | Del `Lock` que protege el dict |
| 🟢 Node.js | `Map<key, Promise>` | Del orden que escribe el autor (`set` antes del `await`) |
| ☕ Java | `ConcurrentHashMap.computeIfAbsent` | **Del mapa**: atómica por clave |
| 🔵 .NET | `Lazy<Task<T>>` en `ConcurrentDictionary` | **Del `Lazy`**, no del diccionario |
| 🐹 Go | `sync.WaitGroup` + map bajo `Mutex` | Del mutex que protege el registro |
| 🦀 Rust | `Arc<Flight>` con `Mutex` + `Condvar` | Del mutex, y el `Arc` la hace segura de por vida |

---

## 🏁 Veredicto

> Mide **fit con el problema**, no calidad del lenguaje. El último puesto no dice que el stack sea peor: dice cuánto código hay que escribir para expresar este patrón concreto.

| Puesto | Stack | Por qué |
|---|---|---|
| 🥇 | **Go 1.23** | El patrón canónico cabe en 25 líneas de stdlib. `WaitGroup` usado al revés es la expresión más económica del lab: un contador con espera **es** un single-flight. |
| 🥈 | **Java 21** | `computeIfAbsent` elimina la ventana check-then-act por contrato del mapa. Es el único stack donde la garantía no depende de que el autor ordene bien las líneas. |
| 🥉 | **Node.js 22** | Tres líneas y no hace falta ninguna primitiva de sincronización: la Promise ya es el patrón. Pierde el podio porque la garantía la pone el orden que escriba el autor. |
| 4º | **Rust 1.83** | Hay que construir la primitiva entera con `Condvar`, pero a cambio el compilador impide el use-after-remove que en los otros seis es responsabilidad del programador. |
| 5º | **.NET 8** | La trampa de `GetOrAdd` es una lección valiosa, pero es una lección sobre la biblioteca, no sobre el problema. El envoltorio `Lazy` no es obvio y hay que saberlo de antes. |
| 6º | **Python 3.12** | `Event` expresa bien la espera, pero el GIL vuelve el fenómeno difícil de observar: sin una barrera explícita el caso da un falso verde. |
| 7º | **PHP 8.3** | Sin heap compartido, el single-flight tiene que salir del proceso. Es la respuesta más costosa — y, a la vez, la que enseña la lección más transferible: **el double check dentro del lock**, que los otros seis pueden esconder y PHP no. |

**Lectura honesta:** el resultado del caso es el mismo en los siete. 16 recálculos sin coordinación, 1 con ella. Si este caso te deja con la conclusión «debería migrar a Go», lo leíste al revés. La conclusión es «debería agregar el double check adentro del lock que ya tengo».
