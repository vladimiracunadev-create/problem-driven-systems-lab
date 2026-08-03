# Caso 04 — Comparativa multi-stack: Timeout chain y retry storms (PHP · Python · Node.js · Java · .NET · Go · Rust)

> **TL;DR** — legacy tarda ~4 s y falla; resilient corta a 300 ms y, con el breaker abierto, a ~0 ms. La diferencia real entre stacks no es el reloj: es **si el trabajo remoto se abandona o sigue ocupando el recurso**.

<!-- nav -->
`🐘 PHP` · `🐍 Python` · `🟢 Node.js` · `☕ Java 21` · `🔵 .NET 8` · `🐹 Go 1.23` · `🦀 Rust 1.83`

**Estructura:** 🎯 el problema → una seccion por stack → ⚖️ tabla de decision → 📊 primitiva por stack → 🏁 veredicto y ranking
<!-- /nav -->


## 🎯 El problema que ambos resuelven

Una API de cotización que depende de un proveedor externo inestable. La variante legacy reintenta agresivamente sin límites, amplificando la carga. La variante resilient usa timeout corto, backoff exponencial con jitter, circuit breaker y fallback cacheado.

---

## 🐘 PHP: socket bloqueante, usleep, circuit breaker con strtotime

**Runtime:** PHP-FPM. Cada worker tiene su propio proceso. Un timeout que bloquea un proceso FPM lo deja inaccesible para otras requests durante toda la espera.

**El fallo legacy en PHP:**
```php
for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    // timeout_ms=360, backoff_base_ms=0 — sin espera entre intentos
    $result = $this->simulateProviderCall($scenario, $timeoutMs);
    if ($result['success']) break;
    // Sin backoff: siguiente intento inmediato
}
```
Para `provider_down`: 4 intentos × 360ms = 1440ms mínimo por request. El proceso FPM queda bloqueado durante ese tiempo. Bajo carga concurrente, el pool de FPM se agota y las nuevas requests esperan en cola.

**La corrección en PHP:**
```php
private function calculateBackoffMs(int $baseMs, int $attempt): int {
    return (int)(($baseMs * (2 ** max(0, $attempt - 1))) + random_int(15, 45));
}

// Circuit breaker: evaluación antes del I/O
if (isset($provider['opened_until']) &&
    strtotime($provider['opened_until']) > time()) {
    return $this->buildFallbackResponse($provider);
}
```
`2 ** n` escala exponencialmente. `random_int(15, 45)` añade jitter para desfasar picos. `strtotime()` convierte el timestamp ISO8601 a Unix para comparación. Si el circuito está abierto, retorna el fallback **antes** de iniciar cualquier I/O.

**Estado del circuit breaker en PHP:** persiste en `/tmp/pdsl-case04-php/dependency_state.json`. PHP no tiene estado compartido entre procesos FPM, así que el estado se persiste en disco y se lee al inicio de cada request.

---

## 🐍 Python: time.sleep, random.randint, threading.Lock, circuit breaker con time.time

**Runtime:** `ThreadingHTTPServer`. Los hilos comparten estado en memoria. `time.sleep()` libera el GIL, permitiendo que otros hilos progresen durante la espera.

**El fallo legacy en Python:**
```python
for attempt in range(max_attempts):  # max_attempts=4, timeout_ms=360
    result = simulate_provider_call(scenario, timeout_ms)
    if result["success"]:
        break
    # backoff_base_ms=0: siguiente intento inmediato
```
Para `provider_down`: 4 × 0.36s = 1.44s por request. `time.sleep()` libera el GIL durante la espera, pero el hilo sigue ocupado y no puede atender otras requests.

**La corrección en Python:**
```python
def calculate_backoff_ms(base_ms: int, attempt: int) -> int:
    return int(base_ms * (2 ** max(0, attempt - 1)) + random.randint(15, 45))

# Circuit breaker: evaluación con time.time() antes del I/O
if provider.get("opened_until") and time.time() < provider["opened_until"]:
    return build_fallback_response(provider)
```
Misma fórmula de backoff que PHP. La diferencia está en la comparación del circuit breaker: PHP usa `strtotime()` para convertir ISO8601 a Unix; Python almacena directamente el timestamp Unix (`time.time()`) en el JSON y lo compara directamente. Más simple, sin conversión.

**Estado del circuit breaker en Python:** persiste en `/tmp/pdsl-case04-python/dependency_state.json`. A diferencia de PHP, los hilos podrían leer el estado en memoria sin tocar disco, pero la persistencia en JSON garantiza que el estado sobreviva reinicios del servidor.

---

## 🟢 Node.js: `AbortController` como timeout primitivo cooperativo

**Runtime:** Node.js 22 con event loop. La diferencia mas importante con PHP y Python: el timeout no se implementa como "wall clock que pasa y abandono el resultado", sino como **cancelacion cooperativa de la operacion en curso**.

**El timeout como primitiva nativa:**
```javascript
const callWithTimeout = async (scenario, attempt, timeoutMs) => {
  const ac = new AbortController();
  const t = setTimeout(() => ac.abort(), timeoutMs);
  try {
    const { latencyMs, success } = await simulateProviderCall(scenario, attempt, ac.signal);
    clearTimeout(t);
    return { elapsedMs: ..., success, timedOut: false, latencyMs };
  } catch (_e) {
    clearTimeout(t);
    return { elapsedMs: ..., success: false, timedOut: true, latencyMs: timeoutMs };
  }
};
```
`AbortController.abort()` dispara el `signal.aborted` y la promise pendiente la rechaza inmediatamente. La operacion subyacente (en codigo real, un `fetch(url, { signal })`) recibe la senal y cancela el request HTTP — libera el socket. Esto es radicalmente distinto a `time.sleep(timeout_ms)` en Python: Python espera el timeout completo aun si el resultado ya esta en camino; Node tira la operacion realmente.

**Backoff con jitter:**
```javascript
const backoffForAttempt = (policy, attempt) => {
  if (policy.backoff_base_ms === 0) return 0;
  const jitter = 15 + Math.random() * 30;
  return policy.backoff_base_ms * Math.pow(2, Math.max(0, attempt - 1)) + jitter;
};
```
Misma formula que PHP/Python. La diferencia: `await sleep(wait)` cede al loop pero no bloquea otros handlers — el proceso sigue atendiendo otras requests durante el backoff.

**Estado del circuit breaker:** persiste en `/tmp/pdsl-case04-node/dependency_state.json`. Como Node es single-thread, no requiere lock — cada lectura/escritura del JSON es atomica desde el punto de vista de los handlers async, mientras no se intercale `await` entre lectura y escritura.

---

## ☕ Java 21: `CompletableFuture.orTimeout()` + `AtomicReference<BreakerState>` con CAS

**Runtime:** JVM con thread pool. `CompletableFuture` ejecuta el call al provider en otro thread y puede completarse exceptionally por timeout sin requerir cooperacion del callee (a diferencia de `AbortSignal` Node que necesita que el handler chequee la senal).

**Primitiva de timeout:** `CompletableFuture.orTimeout(Duration)` (JDK 9+) marca el future con `TimeoutException` si no completa en el plazo. El `supplyAsync` task sigue corriendo en background hasta que termine — el handler ya retorno con fallback. Para HTTP real con `HttpClient.send()` la API es `HttpRequest.newBuilder().timeout(Duration.ofMs(300))`.

**El fallo legacy en Java:**
```java
for (int attempt = 1; attempt <= 5; attempt++) {
    legacyRetries.increment();
    try { return callProvider(fail, 800); }
    catch (Exception e) { /* sin backoff, sin breaker */ }
}
```
5 reintentos secuenciales × 800ms = 4 segundos bloqueando un thread del pool. Bajo carga concurrente con M requests → 5M roundtrips al provider con `fail=on`. Retry storm clasico.

**La correccion en Java:**
```java
BreakerState st = breaker.get();
if ("open".equals(st.state) && cooldownNotElapsed(st)) {
    return fallback(lastFallbackPrice.get());   // sin tocar al provider
}
CompletableFuture<Long> fut = CompletableFuture
    .supplyAsync(() -> callProviderUnchecked(fail, 800))
    .orTimeout(300, TimeUnit.MILLISECONDS);
```
Tras 3 fallos consecutivos `breaker.set(new BreakerState("open", fails, now()))` — el siguiente request lee el `AtomicReference`, ve `open`, devuelve fallback en microsegundos. `AtomicReference.set()` es atomico (CAS-backed); no hay lock global.

**Por que `record BreakerState`:** Inmutable. Cada transicion es una nueva instancia. Evita race conditions de "leyo state pero failCount era stale" — capturas el estado completo en una sola lectura del `AtomicReference`.

---

## 🔵 .NET 8: CancellationToken cooperativo + Interlocked CAS sobre el breaker

**Runtime:** .NET 8 sobre `HttpListener`. CLR `ThreadPool` despachando handlers async. Las primitivas idiomaticas son `Task` + `CancellationToken` para deadlines y `Interlocked.CompareExchange` para transiciones de estado sin lock.

**El fallo legacy en C#:**
```csharp
for (int attempt = 1; attempt <= 5; attempt++) {
    Interlocked.Increment(ref legacyRetries);
    try { return CallProvider(fail, 800); }
    catch { /* sin backoff, sin breaker, sin fallback */ }
}
```
Cinco intentos secuenciales sin proteccion. Tres requests concurrentes = 15 intentos contra un provider ya caido.

**La correccion en C#:**
```csharp
var st = Volatile.Read(ref breakerState);   // snapshot atomico
if (st.State == "open" && CooldownNotElapsed(st)) {
    Interlocked.Increment(ref shortCircuits);
    return Fallback(lastFallbackPrice);   // sin tocar al provider
}

using var cts = new CancellationTokenSource(TimeSpan.FromMilliseconds(300));
try {
    var result = await Task.Run(() => CallProvider(fail, 800), cts.Token);
    Interlocked.CompareExchange(ref breakerState, new BreakerState("closed", 0, default), st);
    return result;
} catch (OperationCanceledException) {
    var failed = new BreakerState("open", st.FailCount + 1, DateTime.UtcNow);
    Interlocked.CompareExchange(ref breakerState, failed, st);   // CAS — si otro thread ya cambio, reintenta
    return Fallback(lastFallbackPrice);
}
```
`CancellationToken` cancela el `Task` cooperativamente; el `Interlocked.CompareExchange` reemplaza el `AtomicReference.compareAndSet` Java.

**Notas idiomaticas vs los otros stacks:**
- `CancellationToken` es el equivalente exacto del `AbortSignal` Node y del `CompletableFuture.orTimeout` Java. Las tres APIs cancelan cooperativamente sin matar threads.
- `Interlocked.CompareExchange<T>` reemplaza el `AtomicReference.compareAndSet` Java o el patron CAS manual.
- `record BreakerState(string State, int FailCount, DateTime OpenedAt)` con `with`-expressions hace explicito que cada transicion es una nueva instancia — mismo patron de Java.
- A diferencia de PHP/Python, `await` no bloquea el thread durante el backoff — el `ThreadPool` puede atender otras requests, como Node.

---

---

## 🐹 Go 1.23: `context.WithTimeout` — el unico stack donde el deadline cancela de verdad

**La primitiva:** el deadline no es un reloj para el llamador, es una señal que **viaja hacia abajo**. El proveedor la observa:

```go
func callProvider(ctx context.Context, fail bool) (int64, error) {
    select {
    case <-time.After(providerLatency):   // 800 ms
        ...
    case <-ctx.Done():                     // vence a los 300 ms → retorna YA
        return 0, ctx.Err()
    }
}
```

**Por que importa mas de lo que parece:** `CompletableFuture.orTimeout(300ms)` en Java completa el future excepcionalmente a los 300 ms, **pero el thread que hacia el `Thread.sleep(800)` sigue ahi hasta terminar**. El llamador cree que corto; el recurso sigue ocupado. Bajo retry storm, esa diferencia decide si el pool se agota o no.

En Go el trabajo se abandona de verdad y la goroutine se libera. No es azucar sintactico sobre el mismo comportamiento: es cancelacion propagada por la cadena de llamadas.

**El precio es disciplina:** si una funcion ignora su `ctx`, la cancelacion no ocurre. Go no la impone — la hace posible y la deja visible en la firma.

---

## 🦀 Rust 1.83: `mpsc::recv_timeout` — y la limitacion que este stack no puede ocultar

**La primitiva:** se lanza el trabajo en un thread y el llamador espera con limite.

```rust
let (tx, rx) = mpsc::channel();
thread::spawn(move || { let _ = tx.send(call_provider_blocking(fail)); });
match rx.recv_timeout(Duration::from_millis(deadline_ms)) { ... }
```

**Lo que hay que decir claro:** `recv_timeout` corta **la espera**, no **el trabajo**. El thread lanzado sigue durmiendo sus 800 ms hasta terminar. Es exactamente la misma limitacion de `orTimeout()` en Java — y **peor que lo que logra Go**.

La razon es estructural: `std` de Rust no tiene runtime asincronico ni cancelacion cooperativa. Eso vive en `tokio`, donde `tokio::time::timeout` sobre un future si abandona el trabajo pendiente. Mantener el caso con cero dependencias tiene este costo concreto.

**Lo que Rust si aporta:** el `MutexGuard` del breaker libera al salir de scope, en todos los caminos de retorno. En Go, un `mu.Lock()` cuyo `defer mu.Unlock()` falta en una rama de error es un deadlock silencioso que compila y pasa los tests. Esa categoria de bug no existe aca porque no hay unlock que escribir.

**El ranking honesto de este caso:** Go > Rust(`std`) ≈ Java > el resto. Es el unico caso del lab donde Rust queda por detras de Go en la primitiva central, y esta escrito asi a proposito.

## ⚖️ Diferencias de decision, no de correccion

> Los siete stacks implementan el **mismo algoritmo**. Esta tabla contrasta como lo expresa cada uno.

| Aspecto | PHP | Python | Node.js | Java | .NET | Go | Rust | Razon |
|---|---|---|---|---|---|---|---|---|
| Espera / backoff | `usleep` bloquea el proceso | `time.sleep` bloquea el thread | `await sleep` cede el loop | `Thread.sleep` | `await Task.Delay` | `time.After` en `select` | `thread::sleep` | Solo Node, .NET y Go liberan capacidad durante la espera. |
| ¿El deadline cancela el trabajo? | no | no | **si — `AbortController`** | no — `orTimeout` deja el thread dormido | **si — `CancellationToken` cooperativo** | **si — `ctx.Done()` observado por el callee** | **no** — `recv_timeout` corta la espera, el thread sigue | Esta fila es el caso entero: creer que cortaste y seguir ocupando el recurso. |
| Estado del breaker | disco (procesos aislados) | disco / memoria | memoria (single-thread) | `AtomicReference` + CAS | `Interlocked.CompareExchange` | `sync.Mutex` | `Mutex` con guard automatico | En Rust no hay unlock que olvidar en la rama de error. |
| Coste de un retry storm | satura el pool FPM | satura threads | degrada el loop entero | satura el pool | satura el pool | satura el scheduler | satura threads del SO | Todos degradan; cambia el recurso que se agota primero. |

**El algoritmo que los siete stacks implementan es idéntico** (y estos tres, en detalle): exponential backoff con jitter, circuit breaker con ventana fija, fallback al ultimo valor conocido. La diferencia practica entre Node y los otros dos es la primitiva de timeout: `AbortController` es la misma que se usa con `fetch` en codigo de produccion, asi que el laboratorio no introduce un patron sintetico — usa el mismo que veria un developer en su trabajo diario.

---

## 📊 Primitiva central por stack

> Los siete stacks resuelven el mismo problema. Lo que cambia es la primitiva y donde duele.

| Stack | Primitiva central en este caso |
|---|---|
| PHP | reintentos con `sleep()`; sin cancelacion real |
| Python | `signal`/timeouts de socket |
| Node.js | `AbortController` + `AbortSignal.timeout` |
| Java 21 | `CompletableFuture.orTimeout` — corta la espera, no el trabajo |
| .NET 8 | `CancellationTokenSource` con timeout |
| Go 1.23 | **`context.WithTimeout` — el callee observa `ctx.Done()` y abandona de verdad** |
| Rust 1.83 | `mpsc::recv_timeout` — corta la espera, no el trabajo (como Java; `tokio` lo resolveria) |

---

## 🏁 Veredicto: que stack resuelve mejor **este** problema

> ⚠️ **Ranking de fit, no de calidad de lenguaje.** Mide que tan directamente las primitivas nativas del runtime expresan la solucion de *este* caso concreto. El orden cambia — a veces se invierte — de un caso a otro: leer varios rankings juntos dice mas que cualquiera por separado.

| | Stack | Por que |
|---|---|---|
| 🥇 | **Go 1.23** | `context.WithTimeout` propaga la cancelacion y el callee la observa con `select`. **El unico stack donde el trabajo remoto se abandona de verdad.** |
| 🥈 | **Node.js 22** | `AbortController` cancela cooperativamente y es la misma primitiva que se usa con `fetch` en produccion. |
| 🥉 | **.NET 8** | `CancellationToken` es cooperativo y esta en toda la BCL; requiere que el callee lo respete. |
| 4º | **Java 21** | `orTimeout` completa el future a tiempo, **pero el thread sigue dormido**. Cree que corto; el recurso sigue tomado. |
| 5º | **Rust 1.83** | `mpsc::recv_timeout` tiene exactamente la misma limitacion que Java. `tokio` lo resuelve; `std` no. |
| 6º | **Python 3.12 / PHP 8.3** | Wall-clock que abandona el resultado sin liberar nada. |

**Lectura honesta:** **Es el unico caso del lab donde Rust queda por detras de Go**, y esta escrito asi a proposito: `std` no tiene runtime asincronico. Un ranking que pusiera a Rust primero por reputacion seria una mentira comoda.
