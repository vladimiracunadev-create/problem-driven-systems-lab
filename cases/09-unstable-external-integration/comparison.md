# Caso 09 — Comparativa PHP vs Python: Integración externa inestable

## El problema que ambos resuelven

Un consumo de catálogo externo donde el proveedor puede cambiar su esquema, limitar cuota, o enviar datos malformados. La variante legacy acepta todo sin validar. La variante hardened sanitiza SKUs, valida el esquema, garantiza idempotencia y procesa parcialmente los batches con items inválidos.

---

## PHP: try/catch Throwable, operador ??, CURLOPT_TIMEOUT, adapter de contrato

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

## Python: re.match, dict.get, set para idempotencia, procesamiento parcial

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

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Razon |
|---|---|---|---|
| Validación de SKU | `preg_match('/^[A-Z0-9-]{4,20}$/', $sku)` | `re.compile(r"^[A-Z0-9\-]{4,20}$").match(sku)` | Misma regex. Python precompila con `re.compile()` para reutilización. |
| Fusión de contrato | `$item['price'] ?? $item['price_usd'] ?? null` | `item.get("price") or item.get("price_usd")` | `??` en PHP solo fusiona `null`. `or` en Python también fusiona `0` — cuidado con precios cero. |
| Idempotencia | Array PHP en disco (JSON) | `set` de módulo en memoria | PHP necesita disco por aislamiento FPM. Python puede usar estado en memoria por ser single-process. |
| Fallo de batch | Item inválido: `continue` (procesamiento parcial) | Item inválido: `continue` (procesamiento parcial) | Idéntico. Ambos descartan el item y continúan el batch. |
| KeyError vs Warning | PHP 8: Warning + null | Python: KeyError inmediato | Python falla más ruidosamente. Ventaja en tests; PHP es más silencioso en producción. |

**El patrón de integración defensiva es idéntico:** validar el contrato de entrada antes de procesarlo, garantizar idempotencia por event_id, descartar items inválidos en lugar de fallar el batch completo. Las diferencias son de API (`preg_match` vs `re.match`, `??` vs `.get()`) no de estrategia.
