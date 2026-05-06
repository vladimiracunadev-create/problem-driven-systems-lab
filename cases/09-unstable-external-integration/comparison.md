# Caso 09 — Comparativa multi-stack: Integración externa inestable (PHP · Python · Node.js)

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

## Node.js: AbortSignal.timeout + circuit breaker en memoria

**Runtime:** Node.js 20. El proveedor externo se consume como Promise. La novedad Node es **`AbortSignal.timeout(ms)`**, primitiva ECMAScript estandarizada (Node 18+) que marca el deadline del llamado sin atornillar `setTimeout` manualmente.

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

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Node.js | Razon |
|---|---|---|---|---|
| Timeout del llamado externo | `CURLOPT_TIMEOUT` | `requests.get(timeout=...)` | `AbortSignal.timeout(ms)` (estandar) | Solo Node usa una primitiva del lenguaje, no de la libreria HTTP. |
| Validación de SKU | `preg_match('/^[A-Z0-9-]{4,20}$/', $sku)` | `re.compile(...)` | `/^[A-Z0-9-]{4,20}$/.test(sku)` | RegExp como literal del lenguaje en JS. |
| Fusión de contrato | `??` (solo null) | `.get() or` (todo falsy) | `??` (null/undefined) | Node y PHP comparten semantica estricta. |
| Idempotencia | Array PHP en disco | `set` de modulo en memoria | `Map` o `Set` de modulo en memoria | Node y Python son single-process long-running. |
| Circuit breaker | Estado en disco (JSON) | Estado en memoria | Estado en memoria + `setTimeout` virtual | Node maneja el reapertura via comparacion de timestamp, sin timer real. |
| Cleanup en cancelacion | `curl_close()` manual | Context manager | `AbortSignal` propaga al runtime | Solo Node tiene cleanup automatico via signal. |

**Lo distintivo de Node:** `AbortSignal.timeout` desacopla el deadline de la libreria HTTP. El mismo signal se puede pasar a `fetch`, a una promesa custom, o a un `EventTarget` — la cancelacion se propaga al runtime sin que el codigo tenga que limpiar timers manualmente. PHP y Python lo hacen via parametros de `cURL`/`requests`, atando deadline a la libreria.
