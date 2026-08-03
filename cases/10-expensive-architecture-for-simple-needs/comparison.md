# Caso 10 — Comparativa multi-stack: Arquitectura cara para un problema simple (PHP · Python · Node.js · Java · .NET · Go · Rust)

## El problema que ambos resuelven

La resolución de un feature flag. La variante complex simula una arquitectura multi-capa (event bus → rule engine → ORM hydration → serialización) que introduce overhead real sin añadir valor. La variante right-sized resuelve el mismo caso con un lookup directo O(1).

---

## PHP: json_encode/json_decode loop, object casting, array access O(1)

**Runtime:** PHP-FPM. Cada request ejecuta la lógica de forma sincrona. El overhead de serialización en PHP tiene un costo de CPU real medible.

**El fallo complex en PHP:**
```php
function processComplexFeature(string $feature, int $accounts): mixed {
    // Simula hydration: genera entidades, las serializa, las deserializa
    $entities = [];
    for ($i = 0; $i < $accounts; $i++) {
        $entities[] = ['id' => $i, 'feature' => $feature, 'value' => null];
    }

    // Hops de "coordinación": cada hop serializa y deserializa
    for ($hop = 0; $hop < 4; $hop++) {
        $serialized = json_encode($entities);
        $entities = json_decode($serialized, true);
        // Casting a objeto y de vuelta a array en cada hop
        $entities = array_map(fn($e) => (array)(object)$e, $entities);
    }

    return end($entities)['value'] ?? null;
}
```
`json_encode()` + `json_decode()` en bucle consume CPU proporcional a `count($entities) * $hops`. Para 120 cuentas y 4 hops: 480 ciclos de serialización innecesarios.

**La corrección en PHP — lookup directo:**
```php
private const FEATURE_STORE = [
    'dark_mode'          => ['web' => true,  'mobile' => false, 'default' => false],
    'beta_checkout'      => ['web' => false, 'mobile' => true,  'default' => false],
    'ai_recommendations' => ['web' => true,  'mobile' => true,  'default' => false],
];

function processRightSized(string $feature, string $context): bool {
    return self::FEATURE_STORE[$feature][$context]
        ?? self::FEATURE_STORE[$feature]['default']
        ?? false;   // O(1): acceso a array asociativo PHP
}
```
PHP resuelve el feature flag con un array asociativo en memoria. El acceso por índice en PHP es O(1) — implementado como hash table internamente. Sin serialización, sin bucles, sin overhead.

---

## Python: json.dumps/loads loop, type() dynamic class, dict.get() O(1)

**Runtime:** `ThreadingHTTPServer`. El overhead de serialización en Python también es medible y proporcional a la carga.

**El fallo complex en Python:**
```python
def process_complex_feature(feature: str, accounts: int) -> dict:
    entities = [{"id": i, "feature": feature, "value": None}
                for i in range(accounts)]

    hops_detail = []
    for hop in range(4):   # event_bus, rule_engine, orm_hydrate, serializer
        # Serialización redundante en cada hop
        serialized = json.dumps(entities)
        entities = json.loads(serialized)
        # "Hydración": convierte cada dict a objeto dinámico y de vuelta
        entities = [vars(type("Entity", (), e)()) for e in entities]
        hops_detail.append({"hop": hop, "entities": len(entities)})

    return {"result": entities[-1] if entities else None, "hops": hops_detail}
```
`json.dumps()` + `json.loads()` + `type("Entity", (), e)()` por cada hop. `type()` crea una clase dinámica en cada iteración — overhead del runtime de Python para algo que no aporta valor. Complejidad O(N × hops).

**La corrección en Python — dict.get() O(1):**
```python
FEATURE_STORE: dict = {
    "dark_mode":          {"web": True,  "mobile": False, "default": False},
    "beta_checkout":      {"web": False, "mobile": True,  "default": False},
    "ai_recommendations": {"web": True,  "mobile": True,  "default": False},
}

def process_right_sized(feature: str, context: str) -> bool:
    feature_config = FEATURE_STORE.get(feature, {})
    return feature_config.get(context, feature_config.get("default", False))
```
Dos `.get()` anidados. Los `dict` de Python son hash tables — acceso O(1) garantizado. Sin serialización, sin clases dinámicas, sin bucles. El resultado es el mismo valor booleano que el complex, en microsegundos en lugar de milisegundos.

---

## Node.js: JSON.stringify/parse en bucle vs acceso O(1) directo

**Runtime:** Node.js 22 single-thread. El overhead de la sobrearquitectura se materializa como CPU real sobre el event loop — y esa medicion es lo que hace al caso accionable en Node.

**El fallo complex en Node:**
```javascript
let entities = Array.from({ length: Math.min(8000, Math.max(100, accounts * 15)) }, () => ({
  id: 100 + Math.floor(Math.random() * 900),
}));
if (mode === 'complex') {
  for (let hop = 0; hop < servicesTouched; hop++) {
    const json = JSON.stringify(entities);
    entities = JSON.parse(json);                                       // serializacion entre hops
    entities = entities.map((e) => Object.assign(Object.create(null), e));  // hidratacion
  }
  if (scenario === 'seasonal_peak') {
    throw new Error('Gateway Timeout: demasiados hops serializando bajo pico estacional.');
  }
}
```
`JSON.stringify`/`parse` y `map` consumen CPU del event loop entero — no hay GIL en Node, pero hay un solo thread principal, asi que el costo se ve directamente en latencia para otras requests.

**La correccion right-sized:**
```javascript
let entities = Array.from(...);
const _ = entities[0]?.id;   // O(1)
```

---

## Java 21: CPU real medido en `StringBuilder` loops vs `HashMap.get` O(1)

**Runtime:** JVM con JIT — el JIT optimiza `HashMap.get` agresivamente; el camino complex con N hops de `StringBuilder` no se puede optimizar porque cada hop construye objetos nuevos.

**El fallo legacy en Java:**
```java
for (int h = 0; h < hops; h++) {
    StringBuilder hop = new StringBuilder(2048);
    for (int i = 0; i < 200; i++) hop.append((char) ('A' + (i % 26)));
    payload.append(hop);    // construccion + traversal por hop, alocacion real
}
if (hops > 20) return /* internal_timeout */;
```

**La correccion en Java:**
```java
Long value = directStore.get(key);   // O(1), 0 hops, 0 alocaciones
return /* 1 service touched, cost_usd_month=3, lead_time=1 */;
```

**Por que importa que sea CPU real, no `Thread.sleep`:** un caso simulado con `sleep` no muestra contencion sobre el thread pool. CPU real consume threads del pool y crea backpressure observable via `ThreadPoolExecutor.getActiveCount()`. El lab no inventa el costo — lo demuestra.

---

## .NET 8: Dictionary O(1) vs N hops JsonSerializer

**Runtime:** .NET 8 sobre `HttpListener`. CLR `ThreadPool`. El "complex" cobra CPU real con `JsonSerializer.Serialize`/`Deserialize` por hop — no `Task.Delay()`.

**El fallo complex en C#:**
```csharp
var payload = new Dictionary<string, object> { ["key"] = key, ["value"] = 0 };
for (int h = 0; h < hops; h++) {
    var blob = JsonSerializer.Serialize(payload);     // alocacion real
    payload = JsonSerializer.Deserialize<Dictionary<string, object>>(blob)!;
    payload["last_hop"] = h;
}
if (hops > 20) return /* internal_timeout */;
```
A partir de blobs grandes, las alocaciones pasan al LOH (Large Object Heap, >85 KB) y disparan colecciones Gen2 — costo doble: CPU del serializer + presion del GC.

**La correccion en C#:**
```csharp
long value = directStore.TryGetValue(key, out var v) ? v : 0;   // O(1), 0 hops
return /* 1 service touched, cost_usd_month=3, lead_time=1 */;
```

**Notas idiomaticas vs los otros stacks:**
- `Dictionary<K,V>.TryGetValue` reemplaza `HashMap.get()` Java o `STORE[key]` Node.
- `JsonSerializer` (System.Text.Json) reemplaza `JSON.stringify`/`parse` Node o `StringBuilder` loops Java — el costo cualitativo es el mismo.
- `Stopwatch` o `Environment.TickCount64` reemplazan `System.nanoTime()` Java.
- A diferencia de Node, el costo no se ve directamente en latencia de otras requests (CLR usa pool real); pero saturarlo si afecta `ThreadPool.GetAvailableWorkerThreads`, lo que el caso 11 explota.

---

---

## Go 1.23 y Rust 1.83: los dos mas rapidos, y por que eso no importa aca

El costo de este caso es CPU puro: construir y recorrer buffers.

- **Go** usa `strings.Builder`, que garantiza cero copias al convertir a string (reinterpreta el buffer interno). El `toString()` de Java copia el array.
- **Rust** usa `String::with_capacity` + `push_str`, sin asignaciones intermedias ocultas y sin GC que despues recoja la basura generada.

Es previsible que los numeros absolutos de ambos salgan entre los mas bajos de los siete stacks. **Y por eso mismo vale repetir lo que el caso dice en todos los lenguajes: comparar `elapsed_ms` entre stacks aca no dice nada util.**

Lo comparable es la **forma de la curva dentro de cada stack**: lineal en `hops` para la variante compleja, constante para la right-sized. Esa pendiente es identica en los siete lenguajes, porque la sobrearquitectura no es un problema de runtime sino de diseño. Un lenguaje rapido no arregla ocho saltos de red que no hacian falta — solo hace que tarden menos en no hacer falta.

**Evidencia de que el trabajo nominal es el mismo:** con `hops=8`, tanto Go como Rust devuelven `payload_bytes: 1719`. Byte por byte, construyen el mismo payload. Lo unico que cambia es cuanto cuesta hacerlo.

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Node.js | Razon |
|---|---|---|---|---|
| Overhead simulado | `json_encode/decode` + `(array)(object)` | `json.dumps/loads` + `type()` | `JSON.stringify/parse` + `Object.assign` | Tres formas, mismo costo cualitativo. |
| Lookup O(1) | Array asociativo PHP `$store[$key]` | Dict Python `FEATURE_STORE.get(key)` | Objeto `STORE[key]` o `Map.get(key)` | Tres hash tables. |
| Modelo de concurrencia | Multi-proceso (FPM) | Multi-thread con GIL | Single-thread + event loop | Solo Node sufre el costo en latencia visible inmediatamente. |
| Sintoma observable | Memoria/CPU del proceso | Memoria/CPU del proceso | Latencia subiendo en otras rutas concurrentes | El single-thread de Node hace el costo mas visible. |
| Constante | `const FEATURE_STORE` (clase) | Modulo-level `dict` | `const STORE = ...` o `Object.freeze(...)` | Tres formas de declarar inmutable-ish. |

**El principio que los tres demuestran es idéntico:** la complejidad debe ser proporcional al problema. Lo distintivo de Node: como el event loop es **un solo thread**, el costo de la sobrearquitectura se ve directamente en latencia degradada para otras peticiones concurrentes — el caso 11 lo explora en profundidad con `monitorEventLoopDelay()`.

---

## Primitiva central por stack

> Los siete stacks resuelven el mismo problema. Lo que cambia es la primitiva y donde duele.

| Stack | Primitiva central en este caso |
|---|---|
| PHP | `array` asociativo O(1) |
| Python | `dict` O(1) |
| Node.js | `Map` + `JSON.stringify` por hop |
| Java 21 | `HashMap` vs N hops con `StringBuilder` |
| .NET 8 | `Dictionary` vs `JsonSerializer` con presion LOH |
| Go 1.23 | `map` vs `strings.Builder` (cero copias al convertir a string) |
| Rust 1.83 | `HashMap` vs `String::with_capacity` (sin GC que recoja despues) |

