# Caso 04 — Comparativa multi-stack: Timeout chain y retry storms (PHP · Python · Node.js · Java)

## El problema que ambos resuelven

Una API de cotización que depende de un proveedor externo inestable. La variante legacy reintenta agresivamente sin límites, amplificando la carga. La variante resilient usa timeout corto, backoff exponencial con jitter, circuit breaker y fallback cacheado.

---

## PHP: socket bloqueante, usleep, circuit breaker con strtotime

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

## Python: time.sleep, random.randint, threading.Lock, circuit breaker con time.time

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

## Node.js: `AbortController` como timeout primitivo cooperativo

**Runtime:** Node.js 20 con event loop. La diferencia mas importante con PHP y Python: el timeout no se implementa como "wall clock que pasa y abandono el resultado", sino como **cancelacion cooperativa de la operacion en curso**.

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

## Java 21: `CompletableFuture.orTimeout()` + `AtomicReference<BreakerState>` con CAS

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

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Node.js | Razon |
|---|---|---|---|---|
| Espera (sleep) | `usleep($us)` bloquea el proceso | `time.sleep(s)` bloquea el thread | `await sleep(ms)` cede al loop | Solo Node permite atender otras requests durante el backoff. |
| Timeout | Wall-clock que abandona el resultado | Wall-clock que abandona el resultado | `AbortController` cancela cooperativamente | Solo Node libera realmente el recurso subyacente. |
| Jitter | `random_int(15, 45)` | `random.randint(15, 45)` | `15 + Math.random() * 30` | Misma distribucion uniforme. |
| Estado compartido | Disco (procesos FPM aislados) | Disco + opcionalmente en memoria | Disco (sin lock por single-thread) | Cada runtime resuelve segun su modelo de concurrencia. |
| Fallback quote | JSON en disco | JSON en disco | JSON en disco | Identico — el fallback sobrevive reinicios. |

**El algoritmo que los tres implementan es idéntico:** exponential backoff con jitter, circuit breaker con ventana fija, fallback al ultimo valor conocido. La diferencia practica entre Node y los otros dos es la primitiva de timeout: `AbortController` es la misma que se usa con `fetch` en codigo de produccion, asi que el laboratorio no introduce un patron sintetico — usa el mismo que veria un developer en su trabajo diario.
