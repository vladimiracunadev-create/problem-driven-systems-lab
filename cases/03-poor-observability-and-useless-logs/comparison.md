# Caso 03 — Comparativa multi-stack: Observabilidad deficiente y logs inútiles (PHP · Python · Node.js)

## El problema que ambos resuelven

Un flujo de checkout con 4 pasos y dependencias externas. La variante legacy emite logs que no permiten responder ninguna pregunta de diagnóstico. La variante observable emite logs estructurados con correlation IDs que permiten reconstruir la traza completa de cada request.

---

## PHP: concatenación de strings vs json_encode con correlation ID

**Runtime:** PHP-FPM. Cada proceso es efímero y aislado. Los logs son la única evidencia que persiste entre el final de un proceso y el inicio del diagnóstico.

**El fallo legacy en PHP:**
```php
function appendLegacyLog(string $msg): void {
    file_put_contents(
        $logPath,
        '[' . date('c') . '] ' . $msg . "\n",
        FILE_APPEND
    );
}

appendLegacyLog('processing customer=' . $customerId);
appendLegacyLog('checkout failed');
appendLegacyLog('external dependency issue');
```
El resultado es texto plano no parseable. No hay forma de saber qué request generó qué línea bajo carga concurrente. Un `grep "checkout failed"` devuelve líneas de todas las requests mezcladas.

**La corrección en PHP:**
```php
$traceId = bin2hex(random_bytes(4));   // entropía criptográfica
$requestId = bin2hex(random_bytes(4));

function appendStructuredLog(array $record): void {
    $record['timestamp_utc'] = gmdate('Y-m-d\TH:i:s\Z');
    file_put_contents($logPath, json_encode($record) . "\n", FILE_APPEND);
}

appendStructuredLog([
    'level'       => 'error',
    'event'       => 'dependency_failed',
    'request_id'  => $requestId,
    'trace_id'    => $traceId,
    'step'        => $step['name'],
    'dependency'  => $step['dependency'],
    'elapsed_ms'  => $elapsedMs,
    'error_class' => $errorClass,
]);
```
`json_encode()` produce una línea consultable por cualquier motor de búsqueda. Unir eventos por `trace_id` reconstruye la traza completa de una request.

**Excepción estructurada en PHP:**
```php
class WorkflowFailure extends RuntimeException {
    public function __construct(
        string $message,
        public readonly string $step,
        public readonly string $dependency,
        public readonly int $httpStatus,
        public readonly string $requestId,
        public readonly string $traceId,
        public readonly array $events,
    ) { parent::__construct($message); }
}
```
PHP captura el estado completo en el momento exacto del fallo. `getTraceAsString()` disponible para debugging profundo.

---

## Python: logging.basicConfig vs logging.LoggerAdapter + JsonFormatter

**Runtime:** `ThreadingHTTPServer`. Múltiples hilos procesan requests concurrentemente. El módulo `logging` de Python es thread-safe por diseño — tiene su propio lock interno por handler.

**El fallo legacy en Python:**
```python
# Lo que hace alguien que nunca pensó en observabilidad después del sprint 1
logging.basicConfig(
    format="[%(asctime)s] %(levelname)s %(message)s",
    datefmt="%Y-%m-%dT%H:%M:%SZ",
)
logging.info("checkout started")
logging.info("processing customer=%s", customer_id)
logging.error("checkout failed")
logging.error("external dependency issue")
```
Texto plano con formato fijo. Sin `request_id`, sin `trace_id`. Bajo carga concurrente, líneas de diferentes requests se entrelazan sin posibilidad de correlación.

**La corrección en Python — la diferencia idiomática real:**
```python
class JsonFormatter(logging.Formatter):
    """Emite cada LogRecord como JSON de una línea."""
    def format(self, record: logging.LogRecord) -> str:
        record.message = record.getMessage()
        doc = {
            "timestamp_utc": self.formatTime(record, "%Y-%m-%dT%H:%M:%SZ"),
            "level": record.levelname,
            "event": record.message,
        }
        # Todos los campos extra del LogRecord van al JSON automáticamente
        for key, val in record.__dict__.items():
            if key not in _LOG_RECORD_BUILTINS and not key.startswith("_"):
                doc[key] = val
        return json.dumps(doc, ensure_ascii=False, default=str)

# LoggerAdapter inyecta request_id y trace_id en CADA llamada sin que
# el código de negocio tenga que pasarlos explícitamente.
adapter = logging.LoggerAdapter(
    _observable_logger,
    extra={"request_id": req_id, "trace_id": trace_id, "customer_id": customer_id},
)
adapter.error(
    "dependency_failed",
    extra={"step": step["name"], "elapsed_ms": elapsed_ms, "hint": hint},
)
```

**La diferencia clave entre PHP y Python aquí:**

PHP usa `json_encode()` sobre un array construido manualmente en cada llamada. El developer tiene que recordar incluir `request_id` y `trace_id` en cada `appendStructuredLog(...)`.

Python usa `logging.LoggerAdapter`: el `request_id` y `trace_id` se inyectan **una sola vez** al crear el adapter, y aparecen automáticamente en **cada** log call del flujo. El `JsonFormatter` los extrae del `LogRecord` sin que el código de negocio los repita. Esto es imposible de olvidar por diseño.

**Excepción estructurada en Python:**
```python
class WorkflowFailure(Exception):
    def __init__(self, message, step, dependency, http_status,
                 request_id, trace_id, events):
        super().__init__(message)
        self.step = step
        self.dependency = dependency
        self.http_status = http_status
        self.request_id = request_id
        self.trace_id = trace_id
        self.events = events
```
Misma filosofía que PHP: la excepción lleva el contexto completo del fallo. La diferencia es que Python usa atributos de instancia en lugar de `readonly` properties.

---

## Node.js: `WorkflowFailure` extends Error, JSON sin libreria, append por linea

**Runtime:** Node.js 20 single-thread. Cada request es una funcion `async (req, res) => {...}` que comparte el mismo proceso con todos los handlers. El logger es ad-hoc — `fs.appendFileSync()` con `JSON.stringify()` — porque agregar una dependencia (winston, pino) ocultaria la decision detras de una libreria.

**El fallo legacy en Node.js:**
```javascript
const appendLegacyLog = (msg) => {
  fs.appendFileSync(LEGACY_LOG_PATH, `[${new Date().toISOString()}] ${msg}\n`);
};

appendLegacyLog('checkout started');
appendLegacyLog(`processing customer=${customerId}`);
appendLegacyLog('checkout failed');
```
Texto plano. Sin correlacion. Bajo concurrencia las lineas se intercalan en el archivo y no hay forma de saber que pertenece a que request.

**La corrección en Node.js:**
```javascript
const reqId = `req-${crypto.randomBytes(4).toString('hex')}`;
const traceId = `trace-${crypto.randomBytes(4).toString('hex')}`;

const appendStructuredLog = (record) => {
  const line = JSON.stringify({ ...record, timestamp_utc: new Date().toISOString() });
  fs.appendFileSync(OBSERVABLE_LOG_PATH, line + '\n');
};

appendStructuredLog({
  level: 'error', event: 'dependency_failed',
  request_id: reqId, trace_id: traceId,
  customer_id: customerId, step: step.name,
  dependency: step.dependency, elapsed_ms: elapsedMs,
  error_class: scenarioMeta.error_class, hint: scenarioMeta.hint,
});
```
JSON-per-line. `crypto.randomBytes` para entropia criptografica. Los campos `request_id` y `trace_id` se pasan explicitamente — Node no tiene un equivalente built-in de `LoggerAdapter` de Python en stdlib, asi que la disciplina queda en el codigo de negocio (o en una libreria si se la introduce).

**Excepcion estructurada en Node.js:**
```javascript
class WorkflowFailure extends Error {
  constructor(message, step, dependency, httpStatus, requestId, traceId, events) {
    super(message);
    this.step = step;
    this.dependency = dependency;
    this.httpStatus = httpStatus;
    this.requestId = requestId;
    this.traceId = traceId;
    this.events = events;
  }
}
```
Misma filosofia que PHP/Python. Node soporta clases ES6 nativamente y el `extends Error` preserva la stack trace.

---

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Node.js | Razon |
|---|---|---|---|---|
| API de logging | `file_put_contents` + `json_encode` manual | `logging.LoggerAdapter` + `JsonFormatter` | `fs.appendFileSync` + `JSON.stringify` manual | Solo Python tiene en stdlib una abstraccion formal de logging con adapters. |
| Thread safety | No aplica (un proceso por request) | Lock interno del modulo `logging` | No aplica (single-thread event loop) | Cada runtime resuelve la concurrencia distinto. |
| Correlation ID | `bin2hex(random_bytes(4))` | `secrets.token_hex(4)` | `crypto.randomBytes(4).toString('hex')` | Misma entropia criptografica, tres APIs distintas de stdlib. |
| Propagación de contexto | Manual en cada llamada | Automática via `LoggerAdapter.extra` | Manual (sin equivalente built-in) | Solo Python lo hace por diseño; PHP y Node dependen de disciplina o libreria. |
| Excepcion con contexto | `class WorkflowFailure { ... }` | `class WorkflowFailure(Exception): ...` | `class WorkflowFailure extends Error { ... }` | ES6 classes en Node alinean con PHP/Python sin sintaxis especial. |

**El concepto que los tres demuestran es idéntico:** logs sin estructura y sin correlación hacen el diagnóstico imposible. La diferencia practica es que Python tiene la API mas dificil de violar accidentalmente; PHP y Node confian en disciplina del developer (o de una libreria como winston/pino para Node, monolog para PHP).
