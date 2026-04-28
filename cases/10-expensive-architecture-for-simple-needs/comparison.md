# Caso 10 — Comparativa PHP vs Python: Arquitectura cara para un problema simple

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

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Razon |
|---|---|---|---|
| Overhead simulado | `json_encode/decode` + `(array)(object)` | `json.dumps/loads` + `type("Entity", (), e)()` | Ambos usan serialización redundante para simular overhead multi-capa. |
| Object casting | `(array)(object)$e` | `type("Entity", (), e)()` + `vars()` | PHP castea arrays a objetos y de vuelta. Python crea clases dinámicas con `type()` — más costoso. |
| Lookup O(1) | Array asociativo PHP `$store[$key]` | Dict Python `FEATURE_STORE.get(key)` | Ambos son hash tables. `.get()` en Python agrega seguridad contra KeyError. |
| Costo real | CPU: json cycles × accounts × hops | CPU: json cycles + type() × accounts × hops | Python añade el overhead de `type()` dinámico, que PHP no tiene con el cast. |
| Constante vs variable | `const FEATURE_STORE` | Módulo-level `dict` | PHP usa constantes de clase. Python usa variables de módulo (no hay constantes reales en Python). |

**El principio que ambos demuestran es idéntico:** la complejidad debe ser proporcional al problema. Un feature flag es un lookup de configuración. No necesita event sourcing, ORM, ni serialización en cada hop. PHP y Python llegan al mismo resultado con diferentes herramientas de overhead, pero el punto de enseñanza es el mismo.
