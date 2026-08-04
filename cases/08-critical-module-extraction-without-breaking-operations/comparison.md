# ⚖️ Caso 08 — Comparativa multi-stack: Extracción de módulo crítico sin romper la operación (PHP · Python · Node.js · Java · .NET · Go · Rust)

> **TL;DR** — big-bang devuelve `contract_violation`; el proxy traduce `{cost_usd}` a `{price, currency}` y el consumer no se entera. Lo que separa a los stacks es **si el bus de eventos corre en el thread del request o desacoplado**.

<!-- nav -->
`🐘 PHP` · `🐍 Python` · `🟢 Node.js` · `☕ Java 21` · `🔵 .NET 8` · `🐹 Go 1.23` · `🦀 Rust 1.83`

**Estructura:** 🎯 el problema → una seccion por stack → ⚖️ tabla de decision → 📊 primitiva por stack → 🏁 veredicto y ranking
<!-- /nav -->


## 🎯 El problema que ambos resuelven

La extracción del módulo de pricing de un monolito hacia un servicio independiente. La variante big bang cambia el contrato de una vez y rompe a todos los consumidores que usan el esquema anterior. La variante compatible mantiene un proxy adaptador que normaliza el contrato durante la transición gradual.

---

## 🐘 PHP: Undefined Array Key, operador ??, cutover por fase

**Runtime:** PHP-FPM. Los contratos entre módulos se expresan como arrays PHP. Un campo ausente en un array produce un Warning (PHP 8: TypeError si se usa typed), y el acceso directo `$data['field']` lanza una excepción de tipo `ValueError` o `InvalidArgumentException` si el código lo valida.

**El fallo big bang en PHP:**
```php
function processPricingBigBang(array $data): float {
    // Asume que el nuevo contrato ya está en vigor
    return $data['price'] * $data['quantity'];
    // Si un consumidor legacy envía 'cost_usd' en lugar de 'price':
    // PHP 8: Warning "Undefined array key 'price'" → null → 0 * quantity = 0
    // O InvalidArgumentException si se valida explícitamente
}
```
Un campo renombrado en el contrato (`cost_usd` → `price`) produce resultado silenciosamente incorrecto o falla explícita. No hay camino de escape para el consumidor que no ha migrado.

**La corrección en PHP — Adapter con operador `??`:**
```php
function processPricingCompatible(array $data): float {
    // Operador de fusión nula: intenta múltiples claves en orden de prioridad
    $price = $data['price']       // contrato nuevo
          ?? $data['cost_usd']    // contrato legacy v1
          ?? $data['unit_cost']   // contrato legacy v2
          ?? $data['legacy_val']  // fallback final
          ?? 0.0;

    $quantity = $data['quantity'] ?? $data['qty'] ?? 1;
    return (float)$price * (int)$quantity;
}
```
`??` es el operador de fusión nula de PHP 8. Evalúa cada operando de izquierda a derecha y retorna el primero que no sea `null`. Permite absorber múltiples versiones del contrato sin condicionales explícitos.

**Cutover en PHP:**
```php
$phases = ['legacy', 'shadow', 'canary', 'parallel', 'extracted'];
// POST /cutover/advance avanza la fase en el array circular
$currentIndex = array_search($state['phase'], $phases);
$state['phase'] = $phases[min($currentIndex + 1, count($phases) - 1)];
```

---

## 🐍 Python: KeyError nativo, operador `or`, cadena de .get()

**Runtime:** `ThreadingHTTPServer`. Los contratos se expresan como dicts Python. El acceso directo `data["field"]` lanza `KeyError` si la clave no existe. `data.get("field")` retorna `None` sin excepción.

**El fallo big bang en Python:**
```python
def process_pricing_bigbang(data: dict) -> float:
    return data["price"] * data["quantity"]
    # Si un consumidor legacy envía "cost_usd" → KeyError inmediato
    # Detiene la operación con HTTP 409
```
`data["price"]` lanza `KeyError` si la clave no existe. A diferencia de PHP 8 que emite un Warning, Python falla de inmediato con excepción. Más ruidoso que PHP en este caso, lo que hace el problema más visible.

**La corrección en Python — cadena de `.get()` con fallback:**
```python
def process_pricing_compatible(data: dict) -> float:
    # .get() nunca lanza KeyError — retorna None si la clave no existe
    price = (
        data.get("price")       # contrato nuevo
        or data.get("cost_usd") # contrato legacy v1
        or data.get("unit_cost")# contrato legacy v2
        or data.get("legacy_val")
        or 0.0
    )
    quantity = data.get("quantity") or data.get("qty") or 1
    return float(price) * int(quantity)
```
`.get()` + `or` en Python es el equivalente directo del `??` de PHP. La diferencia: `or` en Python también evalúa como falsy a `0` y `""`, mientras que `??` en PHP solo evalúa `null`. Para precios, esto es relevante: `or` descartaría un precio de `0.0` como si fuera ausente. En el caso, esto se documenta explícitamente como comportamiento esperado.

**Cutover en Python:**
```python
PHASES = ["legacy", "shadow", "canary", "parallel", "extracted"]
current_idx = PHASES.index(state["phase"])
state["phase"] = PHASES[min(current_idx + 1, len(PHASES) - 1)]
```
Idéntica lógica. Python usa `list.index()` donde PHP usa `array_search()`.

---

## 🟢 Node.js: Proxy nativo + EventEmitter para cutover

**Runtime:** Node.js 22. La compatibilidad de contrato vive en un objeto `Proxy` que intercepta el llamado al modulo nuevo y traduce el shape antes de delegar. El cutover por consumer se publica en un `EventEmitter`.

**El fallo big bang en Node:**
```javascript
const newPricingModule = {
  computeFinalPrice(payload) {
    if (typeof payload.price !== 'number') {
      throw new TypeError(`Contrato roto: 'price' esperado, llego ${Object.keys(payload)}`);
    }
    return Number((payload.price * 1.21).toFixed(2));
  },
};
// payload legacy: { cost_usd: 100 } → TypeError
```

**La compatibilidad como Proxy nativo:**
```javascript
const compatibilityProxy = new Proxy(newPricingModule, {
  get(target, prop, receiver) {
    if (prop === 'computeFinalPrice') {
      return (payload) => {
        if (payload?.cost_usd !== undefined && payload.price === undefined) {
          payload = { ...payload, price: payload.cost_usd };   // traduccion en vuelo
        }
        return Reflect.get(target, prop, receiver).call(target, payload);
      };
    }
    return Reflect.get(target, prop, receiver);
  },
});
// El codigo de negocio sigue llamando computeFinalPrice — el Proxy traduce sin que se note.
```
`Proxy` es la primitiva del lenguaje (ECMAScript 2015) para interceptar operaciones. La traduccion vive en un solo lugar (el `get` trap), no esparcida en `if` por toda la aplicacion. Cuando el cutover termina, basta con hacer que el codigo apunte al `newPricingModule` directo en lugar del `compatibilityProxy` — ningun cambio en la fuente del consumidor.

**El cutover events con `EventEmitter`:**
```javascript
const cutoverBus = new EventEmitter();
cutoverBus.on('advance', ({ consumer, before, after }) => {
  cutoverLog.push({ consumer, before, after, at: new Date().toISOString() });
});
// En cada avance:
cutoverBus.emit('advance', { consumer, before: cur, after: next });
```
Otros listeners (alerting, audit log, slack notifier) pueden engancharse al `cutoverBus` sin tocar el flujo principal — pub/sub nativo.

---

## ☕ Java 21: `Function` proxy de compatibilidad + `CopyOnWriteArrayList<Consumer>` event bus

**Runtime:** JVM con thread pool. El event bus tiene lectores frecuentes (cada emit recorre suscriptores) y escritores raros (add/remove subscriber) — `CopyOnWriteArrayList` es exactamente este trade-off.

**El fallo legacy en Java:**
```java
// nuevo modulo solo entiende {price, currency}; consumer manda {cost_usd}
return "contract_violation";   // checkout, partners, backoffice todos rotos
```

**La correccion en Java:**
```java
Function<PriceRequestOld, PriceRequestNew> compatProxy = old ->
    new PriceRequestNew(old.sku(), old.costUsd() * 1.0, "USD");

PriceRequestNew translated = compatProxy.apply(old);   // {cost_usd}→{price,currency}
cutoverProgress.put(consumer, true);
emit("cutover_done:" + consumer);                       // CopyOnWriteArrayList<Consumer<String>>
```

**Por que `Function` y no `java.lang.reflect.Proxy`:** `Proxy` dinamico es overkill para traducir contratos planos — requiere `InvocationHandler` y reflection. `Function<Old,New>` es declarativo, tipado, sin overhead. Mismo efecto, menos boilerplate.

---

## 🔵 .NET 8: Func<Old,New> como proxy + ImmutableList<Action<string>> como event bus

**Runtime:** .NET 8 sobre `HttpListener`. CLR `ThreadPool` con state compartido. Cutover gradual con proxy de traduccion + bus de eventos thread-safe.

**El fallo big-bang en C#:**
```csharp
// Nuevo modulo solo entiende {Price, Currency}; consumer manda {CostUsd}
return "contract_violation";   // checkout, partners, backoffice todos rotos
```
Cambio de contrato sin proxy → todos los consumidores sensibles rotos al mismo tiempo.

**La correccion en C#:**
```csharp
private static readonly Func<PriceRequestOld, PriceRequestNew> compatProxy =
    old => new PriceRequestNew(old.Sku, old.CostUsd * 1.0, "USD");

PriceRequestNew translated = compatProxy(old);   // {CostUsd}→{Price,Currency}
cutoverProgress[consumer] = true;
Emit($"cutover_done:{consumer}");                 // event bus thread-safe
```

**Event bus thread-safe con `ImmutableList<Action<string>>`:**
```csharp
private static ImmutableList<Action<string>> subscribers = ImmutableList<Action<string>>.Empty;

public static void Subscribe(Action<string> h) =>
    ImmutableInterlocked.Update(ref subscribers, list => list.Add(h));

public static void Emit(string evt) {
    foreach (var h in Volatile.Read(ref subscribers)) h(evt);   // lectores sin lock
}
```

**Notas idiomaticas vs los otros stacks:**
- `Func<Old,New>` es 1:1 con `Function<Old,New>` Java o las arrow functions Node.
- `record PriceRequestOld(string Sku, double CostUsd)` / `record PriceRequestNew(string Sku, double Price, string Currency)` son inmutables con `with`-expressions.
- `ImmutableList<T>` (System.Collections.Immutable) es el equivalente exacto del `CopyOnWriteArrayList` Java: lectores nunca bloquean, escritores generan nueva lista persistente.
- `ImmutableInterlocked.Update` es CAS-loop encapsulado — mas seguro que reimplementarlo a mano.
- A diferencia de Node, .NET no tiene `Proxy` nativo de metaprogramacion en el callsite (existe `DispatchProxy` pero es para escenarios mas pesados); el adapter explicito via `Func<>` es el patron idiomatico.

---

---

## 🐹 Go 1.23: bus por canal, con la politica de descarte explicita

Java modela el bus con `CopyOnWriteArrayList<Consumer<Event>>`, .NET con un `event` del CLR, Node con `EventEmitter`. Los tres comparten una propiedad que rara vez se nota hasta que duele: **el `emit()` corre los subscribers en el thread del request**. Un subscriber lento penaliza al consumer que disparo el evento.

```go
func emit(name string) {
    select {
    case cutoverBus <- busEvent{...}:
    default:                            // buffer lleno → se descarta
    }
}
```

`emit()` empuja al canal y vuelve; la goroutine suscriptora consume a su ritmo. Y el `select` con `default` declara una decision que los otros stacks suelen dejar implicita: **si el buffer se llena, se pierde telemetria en vez de frenar trafico**. Estan las dos lineas y es auditable.

---

## 🦀 Rust 1.83: `mpsc` — el tipo dice cuantos consumidores hay

```go
ch := make(chan busEvent, 256)   // Go: cualquiera puede enviar Y recibir
```
```rust
let (tx, rx) = mpsc::channel();  // Rust: tx se clona, rx es UNICO
```

`mpsc` significa multi-producer, **single-consumer**, y el compilador lo impone: `Receiver` no implementa `Clone`. Si alguien intentara consumir el bus desde dos threads, no compila.

En Go, dos goroutines leyendo el mismo canal se reparten los mensajes en silencio. A veces es lo que querias —un pool de workers— y a veces es la razon por la que la mitad de tus eventos de auditoria terminaron en el consumidor equivocado. El canal no distingue una intencion de la otra; el tipo de Rust si.

**Diferencia honesta entre ambos:** el canal de `std` en Rust **no es acotado**, asi que `send` no bloquea ni descarta — la cola crece. Es una eleccion distinta a la de Go, con un riesgo distinto (memoria en vez de latencia). El caso 15 del roadmap es el que estudia esa decision a fondo.

## ⚖️ Diferencias de decision, no de correccion

> Los siete stacks implementan el **mismo algoritmo**. Esta tabla contrasta como lo expresa cada uno.

| Aspecto | PHP | Python | Node.js | Java | .NET | Go | Rust | Razon |
|---|---|---|---|---|---|---|---|---|
| Bus de eventos | hooks sincronos | lista de callbacks | `EventEmitter` | `CopyOnWriteArrayList<Consumer>` | `event` del CLR | **canal bufferizado** | **`mpsc`** | Los cinco primeros corren los subscribers en el thread del request. |
| ¿Publicar bloquea al consumer? | si | si | si | si | si | **no** | **no** | Un subscriber lento penaliza a quien disparo el evento en cinco de siete stacks. |
| Politica si el buffer se llena | n/a | n/a | n/a | n/a | n/a | **descarta (`select`+`default`), explicito** | cola sin limite: crece | Go elige perder telemetria antes que frenar trafico; Rust elige memoria. Ambas son decisiones, no descuidos. |
| ¿Cuantos consumidores permite el tipo? | n/a | n/a | varios | varios | varios | varios (dos goroutines se reparten mensajes en silencio) | **uno — `Receiver` no es `Clone`** | En Go, dos lectores del mismo canal se reparten los eventos y nadie avisa. |
| ACL de contrato | funcion | funcion | `Proxy` | `Function<Old,New>` | `Func<Old,New>` | funcion | **structs distintos + funcion** | En Rust los dos contratos son tipos separados, no un mapa con claves opcionales. |

**Lo distintivo de Node:** `Proxy` permite que el adapter sea **transparente al codigo de negocio**. El consumidor sigue llamando `pricing.computeFinalPrice(payload)`, sin if/else de versiones — la traduccion vive en una sola capa. Cuando el cutover termina, eliminar el Proxy es una sola linea. PHP y Python necesitan el if/else explicito en el consumidor.

---

## 📊 Primitiva central por stack

> Los siete stacks resuelven el mismo problema. Lo que cambia es la primitiva y donde duele.

| Stack | Primitiva central en este caso |
|---|---|
| PHP | hooks sincronos |
| Python | callbacks en lista |
| Node.js | `EventEmitter` + `Proxy` de compatibilidad |
| Java 21 | `CopyOnWriteArrayList<Consumer>` — corre en el thread del request |
| .NET 8 | `ImmutableList<Action<string>>` |
| Go 1.23 | **canal + `select` con `default`: descarta antes que frenar trafico** |
| Rust 1.83 | **`mpsc`: single-consumer impuesto por el tipo** (canal no acotado — la cola crece) |

---

## 🏁 Veredicto: que stack resuelve mejor **este** problema

> ⚠️ **Ranking de fit, no de calidad de lenguaje.** Mide que tan directamente las primitivas nativas del runtime expresan la solucion de *este* caso concreto. El orden cambia — a veces se invierte — de un caso a otro.

| | Stack | Por que |
|---|---|---|
| 🥇 | **Go 1.23** | El canal desacopla publicacion de consumo **y** el `select` con `default` deja escrita la politica de backpressure en dos lineas auditables. |
| 🥈 | **Rust 1.83** | `mpsc` desacopla igual y ademas impone single-consumer por tipo. Pierde el primer puesto porque el canal de `std` no es acotado: la cola crece sin politica. |
| 🥉 | **Node.js 22** | `EventEmitter` + `Proxy` para el ACL es lo mas idiomatico del set, aunque los subscribers corren en el thread del request. |
| 4º | **Java 21 / .NET 8** | `CopyOnWriteArrayList` y `event` del CLR son thread-safe y sincronos: un subscriber lento penaliza al consumer. |
| 6º | **Python 3.12 / PHP 8.3** | Listas de callbacks y hooks sincronos. Simples y sin desacople. |

**Lectura honesta:** La pregunta del caso no es "¿puedo publicar eventos?" sino "¿que pasa cuando el consumidor no da abasto?". Solo Go la responde de forma explicita en el codigo.
