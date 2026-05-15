# Caso 11 — Comparativa multi-stack: Reportes pesados que bloquean la operación (PHP · Python · Node.js · Java)

## El problema que ambos resuelven

Un proceso de reporting analítico que compite con escrituras operacionales por el mismo recurso de bloqueo. La variante legacy comparte el lock entre reporting y escrituras, causando contention. La variante isolated usa locks separados por dominio, protegiendo las escrituras del impacto analítico.

---

## PHP: flock() — bloqueo a nivel de sistema operativo

**Runtime:** PHP-FPM. Cada request es un proceso separado. No hay estado compartido en memoria entre procesos. El único mecanismo de sincronización cross-process es el sistema de archivos — por eso PHP usa `flock()`.

**El fallo legacy en PHP:**
```php
// El reporte y las escrituras comparten el MISMO archivo de lock
$lockFile = '/tmp/pdsl-case11-php/shared.lock';

// Ruta de reporte (proceso FPM #1):
$fp = fopen($lockFile, 'w');
flock($fp, LOCK_EX);                 // Adquiere lock exclusivo — bloquea
usleep($reportDurationUs);           // Simula procesamiento largo (segundos)
flock($fp, LOCK_UN);                 // Libera

// Ruta de escritura (proceso FPM #2, concurrente):
$fp2 = fopen($lockFile, 'w');
if (!flock($fp2, LOCK_EX | LOCK_NB)) {  // Non-blocking: falla si ocupado
    http_response_code(503);
    echo json_encode(['error' => 'lock_contention', 'blocked_ms' => $elapsed]);
    exit;
}
```
`flock(LOCK_EX)` es un bloqueo exclusivo a nivel de kernel del SO. Cuando el proceso de reporte lo retiene, `flock(LOCK_EX | LOCK_NB)` en el proceso de escritura retorna `false` inmediatamente. El `LOCK_NB` (non-blocking) es la clave: sin él, la escritura esperaría indefinidamente.

**La corrección en PHP — locks separados:**
```php
// Reporte aislado: usa su propio archivo de lock
$reportLockFile = '/tmp/pdsl-case11-php/reporting.lock';
$opLockFile     = '/tmp/pdsl-case11-php/operational.lock';

// El reporte solo toca el reporting lock — nunca el operational
$fp = fopen($reportLockFile, 'w');
flock($fp, LOCK_EX);
// ... procesamiento analítico ...
flock($fp, LOCK_UN);

// Las escrituras solo tocan el operational lock — nunca el reporting
$fp2 = fopen($opLockFile, 'w');
if (!flock($fp2, LOCK_EX | LOCK_NB)) { /* contention en op lock */ }
```
Dos archivos de lock distintos. Los dominios son físicamente separados a nivel de kernel. El reporting nunca interfiere con las escrituras.

**Por qué PHP necesita `flock()` y no threading:** PHP-FPM ejecuta procesos OS separados. No hay `threading.Lock` inter-proceso en PHP. `flock()` es la única primitiva de sincronización cross-process portable de PHP stdlib.

---

## Python: threading.Lock — bloqueo en proceso

**Runtime:** `ThreadingHTTPServer`. Todos los handlers de request corren como hilos del mismo proceso Python. `threading.Lock` es la primitiva de sincronización correcta para hilos en el mismo proceso.

**El fallo legacy en Python:**
```python
# Lock compartido entre reporting y escrituras
_shared_lock = threading.Lock()

# Ruta de reporte (hilo 1):
def handle_report_legacy(rows, period_days):
    _shared_lock.acquire(blocking=True)   # Adquiere — bloquea hasta conseguirlo
    try:
        time.sleep(report_duration)        # Simula procesamiento largo
    finally:
        _shared_lock.release()

# Ruta de escritura (hilo 2, concurrente):
def handle_order_write(mode="legacy"):
    acquired = _shared_lock.acquire(blocking=False)  # Non-blocking
    if not acquired:
        return {"error": "lock_contention", "blocked": True}, 503
    try:
        # ... procesa la escritura ...
    finally:
        _shared_lock.release()
```
`threading.Lock().acquire(blocking=False)` es el equivalente directo de `flock(LOCK_EX | LOCK_NB)`. Si el lock está retenido por el reporte, la escritura retorna `False` inmediatamente y devuelve HTTP 503.

**La corrección en Python — locks separados por dominio:**
```python
# Dos locks completamente independientes
_report_lock = threading.Lock()       # Solo para reporting
_operational_lock = threading.Lock()  # Solo para escrituras

def handle_report_isolated(rows, period_days):
    _report_lock.acquire(blocking=True)    # Solo toca el reporting lock
    try:
        time.sleep(report_duration)
    finally:
        _report_lock.release()

def handle_order_write(mode="isolated"):
    acquired = _operational_lock.acquire(blocking=False)  # Solo toca el op lock
    if not acquired:
        return {"error": "lock_contention"}, 503
    # El reporting NUNCA retiene _operational_lock → writes nunca bloquean
```
Los dos locks son objetos distintos en memoria. Son completamente independientes — no pueden interferir entre sí. Ningún código que toque `_report_lock` puede bloquear a código que toque `_operational_lock`.

---

## Node.js: monitorEventLoopDelay() + setImmediate — el lock es el event loop

**Runtime:** Node.js 20 single-thread. **No hay locks** porque no hay concurrencia paralela en el codigo JS — todo corre en un solo thread del event loop. La "contencion" es de otro tipo: una operacion sincronica costosa **bloquea el loop entero** y todas las requests concurrentes lo pagan.

**El fallo legacy en Node — bloqueo sincronico del event loop:**
```javascript
const blockEventLoop = (ms) => {
  const end = Date.now() + ms;
  while (Date.now() < end) { /* spin */ }   // CPU sincronico, no cede el loop
};

const runReportFlow = async (mode, scenario, rows) => {
  if (mode === 'legacy') {
    const blockMs = Math.min(900, 200 + Math.floor(rows / 1000));
    blockEventLoop(blockMs);   // bloquea TODAS las requests concurrentes
  }
};
```
Mientras el `while` corre, ninguna otra request puede ser atendida — el writer concurrente espera. Es el equivalente Node de un `flock(LOCK_EX)` que no cede.

**La medicion del impacto con primitiva Node:**
```javascript
const { monitorEventLoopDelay } = require('perf_hooks');
const ELOOP = monitorEventLoopDelay({ resolution: 10 });
ELOOP.enable();

// En las metricas:
event_loop: {
  p50_ms: Number((ELOOP.percentile(50) / 1e6).toFixed(2)),
  p99_ms: Number((ELOOP.percentile(99) / 1e6).toFixed(2)),
  max_ms: Number((ELOOP.max / 1e6).toFixed(2)),
}
```
`monitorEventLoopDelay()` devuelve un histograma de Node nativo del lag del event loop. Tras correr `report-legacy`, `event_loop_lag_ms_p99` sube notoriamente y el efecto sobre `/order-write` es directamente visible. PHP y Python no tienen esa metrica nativa porque su modelo de concurrencia es diferente.

**La correccion isolated en Node:**
```javascript
if (mode === 'isolated') {
  await new Promise((r) => setImmediate(r));   // cede el loop al final del tick actual
  reporting.queue_depth = Math.min(120, ...);  // simula encolar a worker pool/replica
}
```
`setImmediate` es la forma idiomatica Node de decir "deja que otras operaciones pendientes corran antes". Para isolation real, el siguiente paso seria `worker_threads` — un thread paralelo de verdad para CPU-heavy. Lo dejamos como evolucion del caso.

---

## Java 21: `ThreadPoolExecutor` saturation observable + `ExecutorService` dedicado para reporting

**Runtime:** El `HttpServer` JDK usa un `Executor` que entregamos — un `ThreadPoolExecutor` acotado (4 threads) hace que la saturacion sea **realista y observable**. Java no tiene event loop; el equivalente es saturacion del pool.

**El fallo legacy en Java:**
```java
// /report-legacy corre SINCRONO en el thread del HttpServer (mainPool)
for (int i = 0; i < rows; i++) checksum += (i * 13L) % 7;
// → mainPool.getActiveCount sube; /order-write queda en queue
```

**La correccion en Java:**
```java
ExecutorService reportingPool = Executors.newFixedThreadPool(2);

CompletableFuture<Long> fut = CompletableFuture.supplyAsync(() -> {
    for (int i = 0; i < rows; i++) checksum += (i * 13L) % 7;
    return checksum;
}, reportingPool);    // pool separado, mainPool intacto
```

**Senal propia del runtime:** `mainPool.getActiveCount()` y `mainPool.getQueue().size()` se exponen en `/activity`. Es el equivalente Java de `monitorEventLoopDelay()` de Node — observabilidad nativa de saturacion sin agente externo.

---

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Node.js | Razon |
|---|---|---|---|---|
| Mecanismo de "lock" | `flock()` — kernel SO | `threading.Lock()` — futex | Bloqueo sincronico del event loop | Node no tiene locks porque no tiene concurrencia paralela en JS. |
| Non-blocking | `LOCK_NB` flag | `acquire(blocking=False)` | El loop nunca bloquea por design — bloquea por accion | Solo Node lo hace por error de codigo, no por construccion. |
| Granularidad | Archivo por dominio | Objeto por dominio | `setImmediate` o `worker_threads` para offload | En Node, "isolation" es offload del loop principal. |
| Medicion del impacto | Tiempo de espera en lock | Tiempo de espera en lock | `monitorEventLoopDelay()` — lag p50/p99/max nativo | Solo Node tiene la primitiva nativa para medir el efecto. |
| Modelo de concurrencia | Multi-proceso | Multi-thread con GIL | Single-thread + event loop | Tres modelos distintos para el mismo problema. |

**Lo distintivo de Node:** el problema **no es concurrencia paralela** — es que un trabajo CPU-bound bloquea el loop entero y degrada todo el servicio. La medicion via `monitorEventLoopDelay()` es la primitiva exacta que detecta el bloqueo, sin instrumentacion adicional.
