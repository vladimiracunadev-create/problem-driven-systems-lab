# Caso 05 — Comparativa multi-stack: Presión de memoria y fugas de recursos (PHP · Python · Node.js)

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

## Node.js: V8 heap medido con `process.memoryUsage()`, fuga en array de modulo

**Runtime:** Node.js 20 single-thread con event loop. El proceso vive indefinidamente, igual que Python — y como Python, las referencias a nivel de modulo persisten entre requests. Esa es la fuga "autentica" del laboratorio.

**Medicion del heap V8:**
```javascript
const heapSnapshotKb = () => {
  const m = process.memoryUsage();
  return {
    heap_used_kb: Number((m.heapUsed / 1024).toFixed(2)),
    heap_total_kb: Number((m.heapTotal / 1024).toFixed(2)),
    rss_kb: Number((m.rss / 1024).toFixed(2)),
    external_kb: Number((m.external / 1024).toFixed(2)),
  };
};
```
La api `process.memoryUsage()` expone cuatro metricas distintas que **no existen en PHP ni Python con esa separacion**:
- `heapUsed`: bytes vivos en el heap V8 (objetos JS).
- `heapTotal`: bytes reservados por V8 para el heap.
- `rss`: memoria del proceso entero (incluye stack, native code, etc.).
- `external`: Buffers/ArrayBuffer fuera del heap V8 (I/O nativa).

Permite distinguir "fuga de objetos JS" (heapUsed sube) de "fuga de I/O nativa" (external/rss suben sin que heapUsed lo haga). Esa separacion no la tiene `memory_get_usage()` de PHP ni `tracemalloc` de Python.

**La fuga real:**
```javascript
const legacyRetained = [];   // Modulo. V8 no puede reclamar nunca mientras la raiz exista.
const LEGACY_HARD_CAP = 2000;

const runBatch = async (mode, ...) => {
  if (mode === 'legacy') {
    for (let i = 0; i < documents; i += 1) {
      const raw = crypto.randomBytes((payloadKb * 1024) / 8);
      legacyRetained.push(raw.toString('base64'));
    }
    if (legacyRetained.length > LEGACY_HARD_CAP) {
      legacyRetained.splice(0, legacyRetained.length - LEGACY_HARD_CAP);
    }
  }
};
```
La referencia vive en el cierre del modulo. V8 no puede reclamar mientras esa raiz exista. El array crece request a request hasta el cap.

**La sanitizacion:**
```javascript
const optimizedCache = new Map();
const OPTIMIZED_CACHE_MAX = 24;

if (mode === 'optimized') {
  for (let i = 0; i < documents; i += 1) {
    const raw = crypto.randomBytes((payloadKb * 1024) / 8);
    const digest = crypto.createHash('sha256').update(raw).digest('hex').slice(0, 16);
    optimizedCache.set(digest, true);
    // raw sale de scope; V8 GC lo reclama en el siguiente minor GC
  }
  if (optimizedCache.size > OPTIMIZED_CACHE_MAX) {
    const drop = [...optimizedCache.keys()].slice(0, optimizedCache.size - OPTIMIZED_CACHE_MAX);
    for (const k of drop) optimizedCache.delete(k);
  }
  if (typeof globalThis.gc === 'function') globalThis.gc();
}
```
`raw` vive en scope local de la iteracion. Despues de calcular el digest, el scope termina y V8 marca el buffer como reclamable. Solo el digest (16 chars) queda en el `Map` con eviction. Si Node corre con `--expose-gc`, `globalThis.gc()` fuerza un ciclo; sin el flag, V8 reclama solo (la presion baja casi igual).

---

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Node.js | Razon |
|---|---|---|---|---|
| Modelo de vida del proceso | Muere por request (FPM) | Vive indefinidamente | Vive indefinidamente | PHP libera al morir. Python y Node necesitan gestion explicita. |
| Fuga real | Dentro de la request (heap crece) | En estado de modulo (persiste) | En array de modulo (persiste) | Solo Python y Node simulan fuga long-running real. |
| Medicion de memoria | `memory_get_usage()` (heap PHP) | `sys.getsizeof()` + `tracemalloc` | `process.memoryUsage()` con 4 metricas | Solo Node separa heap V8 de RSS y de Buffers externos. |
| Liberacion explicita | `unset($var)` | `del var` + `gc.collect()` | scope local + opcional `globalThis.gc()` | Tres APIs, mismo efecto. |
| Evicción FIFO | `array_shift()` | `del dict[oldest_key]` | `Map.delete([...keys].slice(0, ...))` | Tres idiomas, misma estructura ordenada. Solo Node usa `Map` formal en lugar de `array`/`dict` polimorfico. |

**La diferencia mas importante:** en PHP la fuga "se limpia" al morir el proceso. En Python y Node la fuga persiste en el modulo — comportamiento autentico de servicios long-running reales (workers, daemons, servidores web). Lo distintivo de Node: `process.memoryUsage().external` permite detectar fugas de Buffers/I/O nativa que `tracemalloc` no ve, y `process.memoryUsage().rss` mide el costo total para el OS independiente del runtime.
