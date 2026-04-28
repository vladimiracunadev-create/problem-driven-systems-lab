# Caso 07 — Comparativa PHP vs Python: Modernización incremental de monolito

## El problema que ambos resuelven

Un dominio de precios acoplado a un monolito. La variante legacy toca múltiples módulos en cada cambio, amplificando el blast radius. La variante strangler usa un ACL (Anti-Corruption Layer) que desacopla el dominio nuevo del legado y migra consumidores uno a uno.

---

## PHP: stdClass como god class, unset para simular rotura, façade pattern

**Runtime:** PHP-FPM. El estado del monolito se modela como un objeto `stdClass` con propiedades dinámicas. Las propiedades pueden ser eliminadas con `unset()`, simulando la rotura que ocurre cuando se elimina un módulo del que otros dependen.

**El fallo legacy en PHP:**
```php
$monolithApp = new stdClass();
$monolithApp->billingModule = new BillingLegacy();
$monolithApp->inventoryModule = new InventoryLegacy();
$monolithApp->sharedSessionDb = new SharedDatabase();
// ...

// Alguien migra sharedSessionDb a un servicio externo y lo elimina
unset($monolithApp->sharedSessionDb);

// Cualquier otro módulo que lo use falla con Fatal Error
$monolithApp->billingModule->processPayment();
// PHP fatal: Attempt to read property "sharedSessionDb" on null
```
`unset()` sobre una propiedad de `stdClass` la elimina completamente. El acceso posterior lanza un `Error` fatal de PHP — no una `Exception` controlable, sino un `Error` del engine que detiene el proceso.

**La corrección en PHP — Facade + ACL:**
```php
class BillingAdapter {
    private BillingLegacy $legacy;

    public function __construct(private PricingServiceNew $newService) {
        $this->legacy = new BillingLegacy();
    }

    public function changePrice(string $productId, float $price): array {
        // Traduce del modelo legacy al modelo nuevo
        $legacyResult = $this->legacy->updatePrice($productId, $price);
        // Propaga al nuevo servicio solo si el consumidor está migrado
        if ($this->isMigrated($productId)) {
            $this->newService->setPrice($productId, $price);
        }
        return $legacyResult;
    }
}
```
El `BillingAdapter` actúa como Facade que encapsula el acceso. Los consumidores que ya migraron acceden via el nuevo servicio; los que no, siguen por el path legacy. El ACL traduce entre los dos modelos.

**Seguimiento de migración en PHP:**
```php
$state['migration']['consumers'][$consumer] = [
    'migrated' => true,
    'migrated_at' => gmdate('c'),
];
```

---

## Python: dict como god class, KeyError como rotura, dict como ACL

**Runtime:** `ThreadingHTTPServer`. El estado del monolito se modela como un `dict` Python con acceso dinámico por clave. La eliminación de una clave con `del` simula la eliminación de un módulo.

**El fallo legacy en Python:**
```python
monolith = {
    "billing": BillingLegacy(),
    "inventory": InventoryLegacy(),
    "shared_session": SharedDatabase(),
}

# Alguien migra shared_session y lo elimina
del monolith["shared_session"]

# Cualquier acceso posterior lanza KeyError
monolith["billing"].process_payment()
# → accede internamente a monolith["shared_session"] → KeyError
```
`del monolith["shared_session"]` elimina la clave. Cualquier acceso posterior lanza `KeyError` — la excepción Python para clave inexistente en dict. Equivalente al `Fatal Error` de PHP en `stdClass`.

**La corrección en Python — ACL como dict mediador:**
```python
def build_billing_adapter(state: dict) -> dict:
    return {
        "translate": lambda product_id, price: {
            "legacy_field": price,
            "new_field": price,
            "product_ref": product_id,
        },
        "route": lambda consumer: state["migration"]["consumers"].get(
            consumer, {}
        ).get("migrated", False),
    }

def run_strangler_change(product_id, new_price, consumer, state):
    adapter = build_billing_adapter(state)
    translation = adapter["translate"](product_id, new_price)

    if adapter["route"](consumer):
        # Consumidor migrado: usa el modelo nuevo
        result = new_pricing_service(translation["new_field"])
    else:
        # Consumidor no migrado: usa el modelo legacy
        result = legacy_billing(translation["legacy_field"])

    # Registra la migración del consumidor
    state["migration"]["consumers"][consumer] = {"migrated": True}
    return result
```
El ACL en Python es un dict de funciones (`translate`, `route`). Esto es más funcional y menos OOP que el PHP, pero logra el mismo aislamiento: los consumidores no acceden directamente al dominio — lo hacen a través del adaptador.

---

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Razon |
|---|---|---|---|
| Modelo del monolito | `stdClass` con propiedades dinámicas | `dict` con claves dinámicas | PHP tiene objetos genéricos como stdClass. Python usa dicts para estado estructurado dinámico. |
| Rotura del módulo | `unset($obj->prop)` → Fatal Error | `del dict["key"]` → KeyError | El efecto es idéntico: acceso posterior falla. La excepción es diferente (Error vs KeyError). |
| ACL / Adapter | Clase `BillingAdapter` con métodos | Dict de funciones (`{"translate": lambda, "route": lambda}`) | PHP orienta naturalmente a clases. Python acepta funciones de primera clase como valores de dict. |
| Seguimiento de migración | `$state['migration']['consumers'][$consumer]` | `state["migration"]["consumers"][consumer]` | Idéntica estructura. Sintaxis diferente. |
| Progreso incremental | `consumers_migrated` / `consumers_total` | `consumers_migrated` / `consumers_total` | Idéntico. Ambos muestran el porcentaje de avance de la migración. |

**El patron Strangler Fig es idéntico en ambos:** un mediador (ACL) intercepta el acceso al dominio, traduce entre modelos, y enruta al servicio nuevo solo para los consumidores que ya migraron. La implementación en PHP usa POO; en Python usa funciones de primera clase. El resultado operacional es el mismo.
