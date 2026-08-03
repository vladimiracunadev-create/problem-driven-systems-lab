# Caso 09 — Comparativa multi-stack: Integración externa inestable (PHP · Python · Node.js · Java · .NET · Go · Rust)

> **TL;DR** — el budget baja `5→4→3→2→1→0` y la sexta llamada degrada a snapshot en vez de fallar. Siete formas de escribir un semaforo, y una de ellas no necesita que exista la palabra semaforo.

<!-- nav -->
`🐘 PHP` · `🐍 Python` · `🟢 Node.js` · `☕ Java 21` · `🔵 .NET 8` · `🐹 Go 1.23` · `🦀 Rust 1.83`

**Estructura:** 🎯 el problema → una seccion por stack → ⚖️ tabla de decision → 📊 primitiva por stack → 🏁 veredicto y ranking
<!-- /nav -->


## 🎯 El problema que ambos resuelven

Un consumo de catálogo externo donde el proveedor puede cambiar su esquema, limitar cuota, o enviar datos malformados. La variante legacy acepta todo sin validar. La variante hardened sanitiza SKUs, valida el esquema, garantiza idempotencia y procesa parcialmente los batches con items inválidos.

---

## 🐘 PHP: try/catch Throwable, operador ??, CURLOPT_TIMEOUT, adapter de contrato

**Runtime:** PHP-FPM. Cada request ejecuta la integración completa. No hay estado compartido de idempotencia entre requests — se persiste en disco.

**El fallo legacy en PHP:**
```php
function syncCatalogLegacy(array $items): array {
    foreach ($items as $item) {
        // Acceso directo sin validación — falla si el campo no existe
        $price = $item['price_usd'];           // Undefined key: PHP Warning + null
        $sku   = $item['sku'];                  // Podría ser "SKU 001" (con espacio)
        $this->catalog[$sku] = ['price' => $price];   // Datos corruptos aceptados
    }
}
```
`$item['price_usd']` produce `null` si la clave no existe (PHP 8 emite Warning). El SKU malformado se acepta sin validación. Items duplicados se procesan dos veces.

**La corrección en PHP — adapter defensivo:**
```php
function syncCatalogHardened(array $items): array {
    foreach ($items as $item) {
        // Idempotencia: skip si ya procesado
        $eventId = $item['event_id'] ?? null;
        if ($eventId && isset($this->processedEvents[$eventId])) {
            $this->stats['idempotent_skips']++;
            continue;
        }

        // Adapter: fusión de contratos con ??
        $price = $item['price']     ?? $item['price_usd']
              ?? $item['cost']      ?? null;

        // Validación de SKU con regex
        $sku = $item['sku'] ?? '';
        if (!preg_match('/^[A-Z0-9\-]{4,20}$/', $sku)) {
            $this->stats['rejected_items']++;
            continue;   // Procesamiento parcial: descarta el item, continúa el batch
        }

        if ($price === null) {
            $this->stats['schema_errors']++;
            continue;
        }

        $this->catalog[$sku] = ['price' => $price];
        if ($eventId) $this->processedEvents[$eventId] = true;
    }
}
```
`preg_match()` valida el SKU con regex. `??` fusiona campos del contrato. El procesamiento parcial descarta items inválidos sin fallar el batch completo.

**Timeout en PHP:** `CURLOPT_TIMEOUT` via cURL para la llamada real al proveedor. En la simulación, `usleep()` representa el tiempo de respuesta.

---

## 🐍 Python: re.match, dict.get, set para idempotencia, procesamiento parcial

**Runtime:** `ThreadingHTTPServer`. El estado de idempotencia vive en un `set` de módulo protegido por `threading.Lock`. Persiste entre requests del mismo proceso.

**El fallo legacy en Python:**
```python
def sync_catalog_legacy(items: list) -> dict:
    for item in items:
        price = item["price_usd"]          # KeyError si la clave no existe
        sku   = item["sku"]                # Acepta "SKU 001" sin validar
        catalog[sku] = {"price": price}    # Datos corruptos aceptados
        # Sin idempotencia: items duplicados procesados dos veces
```
`item["price_usd"]` lanza `KeyError` inmediato si la clave no existe — a diferencia de PHP que emite Warning. El batch completo falla.

**La corrección en Python — adapter con re y set:**
```python
import re

_SKU_PATTERN = re.compile(r"^[A-Z0-9\-]{4,20}$")
_processed_event_ids: set = set()   # Estado de módulo: persiste entre requests

def sanitize_sku(sku: str) -> str | None:
    """Retorna el SKU si es válido, None si debe rechazarse."""
    clean = sku.strip().upper()
    return clean if _SKU_PATTERN.match(clean) else None

def sync_catalog_hardened(items: list) -> dict:
    for item in items:
        # Idempotencia con set: O(1) lookup
        event_id = item.get("event_id")
        if event_id and event_id in _processed_event_ids:
            stats["idempotent_skips"] += 1
            continue

        # Adapter: .get() con fallback encadenado
        price = (item.get("price") or item.get("price_usd")
                 or item.get("cost"))
        if price is None:
            stats["schema_errors"] += 1
            continue   # Procesamiento parcial

        sku = sanitize_sku(item.get("sku", ""))
        if sku is None:
            stats["rejected_items"] += 1
            continue   # Procesamiento parcial

        catalog[sku] = {"price": price}
        if event_id:
            _processed_event_ids.add(event_id)
```
`re.compile()` precompila el patrón para reutilización. `set` de módulo para idempotencia: `in` es O(1). `item.get()` nunca lanza `KeyError`.

**Diferencia de idempotencia entre PHP y Python:**
- PHP: persiste `processedEvents` en disco (JSON) — sobrevive reinicios del proceso FPM
- Python: `_processed_event_ids` es un `set` de módulo — sobrevive entre requests del mismo proceso, se pierde si el servidor se reinicia. Para producción real ambos necesitarían Redis o similar.

---

## 🟢 Node.js: AbortSignal.timeout + circuit breaker en memoria

**Runtime:** Node.js 22. El proveedor externo se consume como Promise. La novedad Node es **`AbortSignal.timeout(ms)`**, primitiva ECMAScript estandarizada (Node 18+) que marca el deadline del llamado sin atornillar `setTimeout` manualmente.

**El llamado al proveedor con deadline nativo:**
```javascript
const callProvider = async (mode, scenario, sku) => {
  const timeoutMs = mode === 'hardened' ? 250 : 1500;
  const signal = AbortSignal.timeout(timeoutMs);
  const fakeLatency = ['rate_limited', 'maintenance_window'].includes(scenario) ? 4000 : 50;
  await new Promise((resolve, reject) => {
    const t = setTimeout(resolve, fakeLatency);
    signal.addEventListener('abort', () => {
      clearTimeout(t);
      reject(new Error(`AbortSignal.timeout: provider call exceeded ${timeoutMs}ms`));
    }, { once: true });
  });
  // ... fetch real seria: await fetch(url, { signal })
};
```
En produccion seria `await fetch(url, { signal })` — el `fetch` global de Node respeta `AbortSignal` y aborta automaticamente. El cleanup del socket es responsabilidad del runtime, no del codigo.

**Circuit breaker en memoria de modulo:**
```javascript
const breakerState = {
  status: 'closed',           // closed | open | half_open
  failureCount: 0,
  threshold: 3,
  cooldownMs: 5000,
  reopenAt: null,
};

const breakerHit = (success) => {
  const now = Date.now();
  if (breakerState.status === 'open' && now >= breakerState.reopenAt) {
    breakerState.status = 'half_open';
  }
  if (success) {
    breakerState.failureCount = 0;
    breakerState.status = 'closed';
  } else {
    breakerState.failureCount += 1;
    if (breakerState.failureCount >= breakerState.threshold) {
      breakerState.status = 'open';
      breakerState.reopenAt = now + breakerState.cooldownMs;
    }
  }
};
```
Sin biblioteca externa, sin estado en disco. Funciona porque Node es single-process long-running — exactamente como el `set` de Python para idempotencia.

**Adapter en Node:**
```javascript
const normalized = { ...raw };
if (normalized.price_usd === undefined) {
  normalized.price_usd = normalized.cost ?? 0;   // ??: igual a PHP, no a Python
}
```

---

## ☕ Java 21: `Semaphore` budget + `ConcurrentHashMap` snapshot cache + `AtomicReference` breaker

**Runtime:** JVM con thread pool. Cada request compite por permits del budget; lecturas del cache son lock-free; el breaker se transiciona con CAS.

**El fallo legacy en Java:**
```java
if (drift) {
    legacyFailures.increment();
    return "{\"status\":\"failed\"}";   // sin cache, sin breaker
}
```

**La correccion en Java:**
```java
Semaphore providerBudget = new Semaphore(BUDGET_PER_WINDOW);
if (!providerBudget.tryAcquire()) return fromSnapshot(...);   // budget agotado
if (drift) { breaker.set("open"); return fromSnapshot(...); }
// success: refresca cache, breaker.set("closed")
snapshotCache.put(sku, fresh);
```

**Por que `Semaphore.tryAcquire()` y no contador con CAS:** Un `AtomicInteger` + loop CAS funciona pero hay que escribirlo. `Semaphore.tryAcquire()` es la API que ya implementa "intenta tomar un permit, si no hay, devuelve false sin bloquear". Mas legible y mapea directo al concepto de cuota.

---

## 🔵 .NET 8: SemaphoreSlim como budget + Interlocked.CompareExchange sobre el breaker

**Runtime:** .NET 8 sobre `HttpListener`. CLR `ThreadPool` despachando handlers async. Budget de cuota, cache snapshot y breaker, todos en memoria del proceso.

**El fallo legacy en C#:**
```csharp
if (drift) {
    Interlocked.Increment(ref legacyFailures);
    return "{\"status\":\"failed\"}";   // sin fallback, sin cache
}
```
Cada request golpea al provider sin proteccion. Provider caido → cascada de fallos.

**La correccion en C#:**
```csharp
private static readonly SemaphoreSlim providerBudget = new(BUDGET_PER_WINDOW);
private static readonly ConcurrentDictionary<string,string> snapshotCache = new();
private static string breakerState = "closed";

if (!providerBudget.Wait(0)) return FromSnapshot(sku);       // budget agotado
if (drift) {
    Interlocked.Exchange(ref breakerState, "open");
    return FromSnapshot(sku);
}
string fresh = CallProvider(sku);
snapshotCache[sku] = fresh;                                   // refresca cache
Interlocked.Exchange(ref breakerState, "closed");
```

**Notas idiomaticas vs los otros stacks:**
- `SemaphoreSlim.Wait(0)` es 1:1 con el `Semaphore.tryAcquire()` Java — devuelve `false` si no hay permits, sin bloquear.
- `Interlocked.Exchange<T>(ref state, newValue)` reemplaza el `AtomicReference.set()` Java.
- `Interlocked.CompareExchange` es CAS explicito si la transicion debe ser condicional ("solo abrir si esta closed").
- `ConcurrentDictionary<K,V>` reemplaza el `ConcurrentHashMap<K,V>` Java.
- `CancellationToken` (con `CancellationTokenSource.CreateLinkedTokenSource(...)`) es el equivalente del `AbortSignal` Node — `HttpClient.SendAsync(req, ct)` lo respeta nativamente para timeout del provider.
- `MemoryCache` de `Microsoft.Extensions.Caching.Memory` es opcion mas pesada con TTL automatico — para este caso, `ConcurrentDictionary` plano es suficiente.

---

---

## 🐹 Go 1.23: un canal bufferizado **es** el semaforo

Java usa `Semaphore(5)`, una clase de `java.util.concurrent`. Go no tiene semaforo en la stdlib y no le hace falta:

```go
var providerBudget = make(chan struct{}, budgetPerWindow)   // 5 permisos

select {
case <-providerBudget:   // adquirir
default:                 // sin permisos → degradar a cache, sin bloquear
}
```

Dos detalles que no son cosmeticos:

- `struct{}` tiene **tamaño cero**. El canal no guarda datos, solo cuenta.
- El `select` con `default` da el `tryAcquire()` no bloqueante sin aprender otra API — es la **misma primitiva** del timeout del caso 04 y del bus del caso 08.

Ese es el argumento de fondo de la concurrencia en Go: canal + `select` cubren semaforo, cola, timeout, cancelacion, fan-in y pipeline. En Java cada uno es una clase distinta con su propio contrato.

---

## 🦀 Rust 1.83: menos expresivo, pero sin unlock que olvidar

`std` de Rust tampoco tiene semaforo. Aca el budget es mas prosaico: un `Mutex<i64>` que se decrementa si hay permisos.

```rust
let mut permits = PROVIDER_BUDGET.lock().unwrap();
if *permits <= 0 {
    return false;      // el MutexGuard se libera AQUI, automaticamente
}
*permits -= 1;
true                   // y aca tambien
```

En expresividad, Go gana. Pero el guard libera al salir de scope **en todos los caminos de retorno**. En Go, un `mu.Lock()` cuyo `defer mu.Unlock()` falta en una rama de error es un deadlock silencioso que compila y pasa los tests felices. Esa categoria de bug no existe en este codigo, porque no hay unlock que escribir ni que olvidar.

**Verificado en ambos:** `budget_remaining` baja 4→3→2→1→0 y la sexta llamada devuelve `served_from_cache`.

## ⚖️ Diferencias de decision, no de correccion

> Los siete stacks implementan el **mismo algoritmo**. Esta tabla contrasta como lo expresa cada uno.

| Aspecto | PHP | Python | Node.js | Java | .NET | Go | Rust | Razon |
|---|---|---|---|---|---|---|---|---|
| Semaforo de cuota | contador en disco | `threading.Semaphore` | contador en memoria | `Semaphore` | `SemaphoreSlim` | **`chan struct{}` bufferizado** | `Mutex<i64>` | Go no necesita que exista el tipo: un canal *es* el semaforo, con `struct{}` de tamaño cero. |
| `tryAcquire` no bloqueante | `if` | `acquire(blocking=False)` | `if` | `tryAcquire()` | `Wait(0)` | **`select` + `default`** | `if *permits <= 0` | En Go es la misma primitiva del timeout del caso 04 y del bus del 08. |
| ¿Se puede olvidar el release? | si | si | n/a | si | si | **si — `defer` olvidado = deadlock que compila** | **no — el guard libera al salir de scope** | Unica categoria de bug que Rust elimina y Go no. |
| Breaker | archivo de estado | variable + lock | variable | `AtomicReference` | `Interlocked.CompareExchange` | `atomic.Value` | `Mutex<&str>` | Todos correctos; cambia la ceremonia. |

**Lo distintivo de Node:** `AbortSignal.timeout` desacopla el deadline de la libreria HTTP. El mismo signal se puede pasar a `fetch`, a una promesa custom, o a un `EventTarget` — la cancelacion se propaga al runtime sin que el codigo tenga que limpiar timers manualmente. PHP y Python lo hacen via parametros de `cURL`/`requests`, atando deadline a la libreria.

---

## 📊 Primitiva central por stack

> Los siete stacks resuelven el mismo problema. Lo que cambia es la primitiva y donde duele.

| Stack | Primitiva central en este caso |
|---|---|
| PHP | cache en disco + contador |
| Python | `threading.Semaphore` |
| Node.js | contador + `Map` snapshot |
| Java 21 | `Semaphore` + `AtomicReference` breaker |
| .NET 8 | `SemaphoreSlim` + `Interlocked.CompareExchange` |
| Go 1.23 | **`chan struct{}` bufferizado ES el semaforo** (`struct{}` = tamaño cero) |
| Rust 1.83 | `Mutex<i64>`; menos expresivo, pero **el guard libera en todos los caminos** |

---

## 🏁 Veredicto: que stack resuelve mejor **este** problema

> ⚠️ **Ranking de fit, no de calidad de lenguaje.** Mide que tan directamente las primitivas nativas del runtime expresan la solucion de *este* caso concreto. El orden cambia — a veces se invierte — de un caso a otro.

| | Stack | Por que |
|---|---|---|
| 🥇 | **Go 1.23** | `chan struct{}` bufferizado **es** el semaforo, con `struct{}` de tamaño cero. Una primitiva —canal + `select`— cubre semaforo, timeout, cola y cancelacion en todo el lab. |
| 🥈 | **Rust 1.83** | Menos expresivo (`Mutex<i64>` con decremento condicional), pero el guard libera en **todos** los caminos: no hay unlock que olvidar. |
| 🥉 | **Java 21 / .NET 8** | `Semaphore` / `SemaphoreSlim` son claros y directos; cada primitiva de concurrencia es una clase distinta que hay que conocer. |
| 4º | **Python 3.12** | `threading.Semaphore` de stdlib, correcto. |
| 6º | **Node.js 22 / PHP 8.3** | Contador en memoria o en disco. Funciona en su modelo de concurrencia; no hay primitiva que lo respalde. |

**Lectura honesta:** Go gana por economia conceptual y Rust por seguridad. Si tuvieras que elegir uno para un equipo grande, el argumento de Rust —el deadlock por `defer` olvidado no existe— pesa mas de lo que sugiere el segundo puesto.
