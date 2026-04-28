# Caso 05 — Comparativa PHP vs Python: Presión de memoria y fugas de recursos

## El problema que ambos resuelven

Un proceso de lotes que recibe documentos con payloads variables. La variante legacy acumula buffers sin limpiar, haciendo crecer la presión hasta degradar el servicio. La variante optimizada libera recursos tras cada item, manteniendo un footprint constante.

---

## PHP: memory_limit, str_repeat, unset, gc_collect_cycles

**Runtime:** PHP-FPM en su modelo clásico "nace para morir" — el proceso muere al final de cada request y la memoria se libera automáticamente. El problema aparece en **workers de larga vida** o en requests con payloads muy grandes que exceden `memory_limit`.

**El fallo legacy en PHP:**
```php
$buffers = [];
for ($i = 0; $i < $documents; $i++) {
    $payload = str_repeat('x', $payloadKb * 1024);
    $encoded = base64_encode($payload);
    $buffers[] = $encoded;  // Referencia activa: GC no puede reclamar
}
// $buffers sigue vivo — todos los strings están en heap
```
`str_repeat()` genera el payload en heap. `base64_encode()` crea una copia codificada. Ambos quedan referenciados en `$buffers`. PHP no puede liberar lo que tiene referencias activas. El heap crece `O(N)` con `documents`.

**La corrección en PHP:**
```php
for ($i = 0; $i < $documents; $i++) {
    $payload = str_repeat('x', $payloadKb * 1024);
    $hash = hash('sha256', $payload);     // Solo 64 bytes, no payload_kb KB
    $buffers[] = $hash;
    if (count($buffers) > 24) {
        array_shift($buffers);            // Evicción FIFO: O(1) en espacio
    }
    unset($payload);                      // Elimina la referencia explícitamente
}
gc_collect_cycles();                      // Ciclo GC forzado para referencias circulares
```
`unset($payload)` elimina la única referencia al string grande. Sin referencias activas, el GC de PHP lo reclama en el siguiente ciclo. `array_shift()` mantiene el array acotado a 24 entradas.

**Medición en PHP:** `memory_get_usage()` y `memory_get_peak_usage()` reportan uso real del heap PHP. El caso persiste estos valores en estado JSON para mostrar la evolución entre requests.

---

## Python: tracemalloc, sys.getsizeof, gc.collect, referencias reales

**Runtime:** `ThreadingHTTPServer`. El proceso vive indefinidamente. A diferencia de PHP-FPM, **una referencia activa en un módulo persiste entre requests**. Esto hace posible simular fugas reales, no solo dentro de una request.

**El fallo legacy en Python — fuga real, no simulada:**
```python
# Módulo-nivel: persiste entre requests
_legacy_buffer_pool: list = []
LEGACY_HARD_CAP = 2000  # Cap de seguridad para evitar OOM en demo

def run_batch_legacy(documents, payload_kb):
    for i in range(documents):
        raw = secrets.token_bytes(max(1, payload_kb * 1024 // 8))
        b64 = base64.b64encode(raw).decode("ascii")
        _legacy_buffer_pool.append(b64)   # Referencia viva en módulo
        if len(_legacy_buffer_pool) > LEGACY_HARD_CAP:
            _legacy_buffer_pool[:] = _legacy_buffer_pool[-LEGACY_HARD_CAP:]
```
`_legacy_buffer_pool` vive en el módulo — no muere con la request. Cada llamada acumula más strings base64. El GC de Python no puede reclamarlos porque la lista los referencia activamente.

**Medición con `sys.getsizeof` — tamaño real del objeto:**
```python
def deep_sizeof(obj) -> int:
    if isinstance(obj, list):
        return sys.getsizeof(obj) + sum(sys.getsizeof(x) for x in obj)
    if isinstance(obj, dict):
        return sys.getsizeof(obj) + sum(
            sys.getsizeof(k) + sys.getsizeof(v) for k, v in obj.items()
        )
    return sys.getsizeof(obj)

retained_kb = deep_sizeof(_legacy_buffer_pool) / 1024  # Bytes reales
```
`sys.getsizeof()` de stdlib reporta el tamaño real del objeto en bytes. No es una estimación manual — es lo que Python realmente tiene en heap.

**La corrección en Python — evicción real, del explícito, gc.collect:**
```python
_optimized_cache: dict = {}  # Máximo 24 entradas

def run_batch_optimized(documents, payload_kb):
    for i in range(documents):
        raw = secrets.token_bytes(max(1, payload_kb * 1024 // 8))
        digest = hashlib.sha256(raw).hexdigest()[:16]
        del raw                            # Elimina la única referencia al buffer grande
        _optimized_cache[f"{i}-{digest}"] = digest
        if len(_optimized_cache) > 24:
            oldest = next(iter(_optimized_cache))
            del _optimized_cache[oldest]   # Evicción FIFO

    gc.collect()                           # Ciclo GC explícito post-batch
    retained_kb = deep_sizeof(_optimized_cache) / 1024
```
`del raw` elimina la referencia al buffer grande inmediatamente. `gc.collect()` libera cualquier ciclo de referencia que el contador de referencias no detectó. El cache está acotado a 24 × 16 bytes = 384 bytes máximo.

**tracemalloc para snapshot antes/después:**
```python
if not tracemalloc.is_tracing():
    tracemalloc.start()
snapshot_before = tracemalloc.take_snapshot()
# ... batch ...
snapshot_after = tracemalloc.take_snapshot()
stats = snapshot_after.compare_to(snapshot_before, "lineno")
delta_kb = sum(s.size_diff for s in stats) / 1024
```
`tracemalloc` de stdlib mide la memoria asignada por el propio intérprete Python. El delta entre snapshots muestra exactamente cuánto asignó el batch — incluyendo overhead interno de listas y strings.

---

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Razon |
|---|---|---|---|
| Modelo de vida del proceso | Muere por request (FPM) | Vive indefinidamente (ThreadingHTTPServer) | PHP libera automáticamente al morir. Python necesita gestión explícita. |
| Fuga real | Dentro de la request (heap crece) | En estado de módulo (persiste entre requests) | La fuga Python es más auténtica: sobrevive a la request, como una fuga real de producción. |
| Medición de memoria | `memory_get_usage()` (PHP heap) | `sys.getsizeof()` + `tracemalloc` (Python heap) | Ambas miden memoria real. Python tiene dos herramientas: una por objeto, una por snapshot. |
| Liberación explícita | `unset($var)` | `del var` + `gc.collect()` | Idiomas distintos, mismo efecto: eliminar la referencia para que el GC pueda reclamar. |
| Evicción FIFO | `array_shift()` | `del dict[oldest_key]` | PHP usa arrays como colas. Python usa dicts ordenados (desde 3.7, orden de inserción garantizado). |

**La diferencia más importante:** en PHP la fuga ocurre dentro de una request y el proceso la "limpia" al morir. En Python la fuga persiste en el módulo entre requests — lo que lo hace un escenario más representativo de lo que ocurre en servicios long-running reales (workers, daemons, servidores web).
