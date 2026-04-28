# Caso 11 — Comparativa PHP vs Python: Reportes pesados que bloquean la operación

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

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Razon |
|---|---|---|---|
| Mecanismo de lock | `flock()` — kernel del SO, cross-process | `threading.Lock()` — en proceso, cross-thread | PHP tiene procesos separados → necesita lock de SO. Python tiene hilos → lock de threading. |
| Non-blocking | `flock($fp, LOCK_EX \| LOCK_NB)` | `lock.acquire(blocking=False)` | API diferente, semántica idéntica: retorna inmediatamente si no puede adquirir. |
| Granularidad | Archivo de lock por dominio | Objeto Lock por dominio | Mismo concepto. PHP en disco, Python en memoria. |
| Costo de contention | Syscall de kernel (flock) | Lock de threading (futex en Linux) | Python es más eficiente: `threading.Lock` usa futex, más barato que flock. |
| Scope | Cross-process (todos los workers FPM) | Intra-process (todos los hilos del servidor) | PHP necesita sincronización entre OS processes. Python sincroniza entre OS threads del mismo process. |

**La diferencia más importante:** `flock()` de PHP es la herramienta correcta para PHP-FPM (multiproceso). `threading.Lock` de Python es la herramienta correcta para ThreadingHTTPServer (multihilo). Usar `flock()` en Python sería una traducción literal incorrecta — funcionaría en Linux pero sería innecesariamente complejo y más lento que `threading.Lock`. Esta es la divergencia más clara entre los dos stacks: **misma solución lógica, herramienta de sincronización diferente por modelo de concurrencia**.
