# Caso 08 — Comparativa multi-stack: Extracción de módulo crítico sin romper la operación (PHP · Python · Node.js)

## El problema que ambos resuelven

La extracción del módulo de pricing de un monolito hacia un servicio independiente. La variante big bang cambia el contrato de una vez y rompe a todos los consumidores que usan el esquema anterior. La variante compatible mantiene un proxy adaptador que normaliza el contrato durante la transición gradual.

---

## PHP: Undefined Array Key, operador ??, cutover por fase

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

## Python: KeyError nativo, operador `or`, cadena de .get()

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

## Node.js: Proxy nativo + EventEmitter para cutover

**Runtime:** Node.js 20. La compatibilidad de contrato vive en un objeto `Proxy` que intercepta el llamado al modulo nuevo y traduce el shape antes de delegar. El cutover por consumer se publica en un `EventEmitter`.

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

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Node.js | Razon |
|---|---|---|---|---|
| Acceso a clave ausente | Warning + null (PHP 8) | KeyError (excepción inmediata) | TypeError al acceder propiedad de undefined | Tres comportamientos, similar visibilidad en tests. |
| Fusión / traduccion de contratos | Operador `??` | `.get()` + `or` | `Proxy` con `Reflect.get` + asignacion | PHP/Python fusionan claves en el callsite. Node usa metaprogramacion nativa: el Proxy es el adapter. |
| Trampa de operadores | `??` ignora solo `null` | `or` ignora todo falsy | `??` (ECMAScript) ignora solo `null`/`undefined` | Node y PHP comparten semantica de fusion estricta; Python es mas amplio. |
| Cutover events | Estado en JSON | Estado en JSON | `EventEmitter` + estado en JSON | Solo Node tiene pub/sub nativo en stdlib (`events` modulo). |
| Estado del proxy | JSON en disco | JSON en disco | JSON en disco | Idéntico. El estado de cutover debe sobrevivir reinicios. |

**Lo distintivo de Node:** `Proxy` permite que el adapter sea **transparente al codigo de negocio**. El consumidor sigue llamando `pricing.computeFinalPrice(payload)`, sin if/else de versiones — la traduccion vive en una sola capa. Cuando el cutover termina, eliminar el Proxy es una sola linea. PHP y Python necesitan el if/else explicito en el consumidor.
