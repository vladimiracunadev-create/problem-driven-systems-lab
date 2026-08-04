# ⚖️ Caso 03 — Comparativa multi-stack: Observabilidad deficiente y logs inútiles (PHP · Python · Node.js · Java · .NET · Go · Rust)

> **TL;DR** — el mismo fallo pasa de `[ERROR] checkout failed` a un evento JSON con `correlation_id`, `reason` y `limit`. Lo que cambia entre stacks es **quien garantiza que el contexto no se pierda**: nadie, el revisor, o el compilador.

<!-- nav -->
`🐘 PHP` · `🐍 Python` · `🟢 Node.js` · `☕ Java 21` · `🔵 .NET 8` · `🐹 Go 1.23` · `🦀 Rust 1.83`

**Estructura:** 🎯 el problema → una seccion por stack → ⚖️ tabla de decision → 📊 primitiva por stack → 🏁 veredicto y ranking
<!-- /nav -->


## 🎯 El problema que ambos resuelven

Un flujo de checkout con 4 pasos y dependencias externas. La variante legacy emite logs que no permiten responder ninguna pregunta de diagnóstico. La variante observable emite logs estructurados con correlation IDs que permiten reconstruir la traza completa de cada request.

---

## 🐘 PHP: concatenación de strings vs json_encode con correlation ID

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

## 🐍 Python: logging.basicConfig vs logging.LoggerAdapter + JsonFormatter

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

## 🟢 Node.js: `WorkflowFailure` extends Error, JSON sin libreria, append por linea

**Runtime:** Node.js 22 single-thread. Cada request es una funcion `async (req, res) => {...}` que comparte el mismo proceso con todos los handlers. El logger es ad-hoc — `fs.appendFileSync()` con `JSON.stringify()` — porque agregar una dependencia (winston, pino) ocultaria la decision detras de una libreria.

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

## ☕ Java 21: `ThreadLocal<RequestContext>` para correlation, log estructurado JSON inline, `/logs` endpoint

**Runtime:** JVM con thread pool. Cada request entra a un thread propio; `ThreadLocal` propaga el contexto durante toda la cadena de llamadas dentro del handler sin pasarlo por parametros.

**Motor de logs:** Sin libreria (Log4j/SLF4J quedan fuera para no agregar deps). `StringBuilder` manual produce JSON estructurado escrito a un `Deque<String>` sincronizado con cap=200. `/logs` devuelve los ultimos 200 al estilo Loki/journald compacto.

**El fallo legacy en Java:**
```java
System.out.println("[INFO] processing checkout");
if (total > 500) {
    System.out.println("[ERROR] checkout failed");   // sin id, sin total, sin razon
}
```
Stdout sin contexto. Bajo carga concurrente con N threads, los `println` se intercalan — imposible asociar "checkout failed" a una request especifica.

**La correccion en Java:**
```java
CTX.set(new RequestContext(corrId, "checkout-observable", Instant.now().toString()));
try {
    structuredLog("error", "checkout_failed", Map.of(
        "total", String.valueOf(total),
        "reason", "exceeds_limit",
        "limit", "500"));
} finally {
    CTX.remove();   // critico: evita leak del contexto al proximo handler en este thread
}
```
`structuredLog()` lee `ThreadLocal<RequestContext>` y agrega `correlation_id` y `route` al JSON. `CTX.remove()` en `finally` es la disciplina necesaria — sin esto el thread retiene contexto del request anterior y los logs proximos quedan mal taggeados.

**ScopedValue (JDK 21):** la API moderna para esto es `ScopedValue.where(CTX, value).run(handler)`. Aqui usamos `ThreadLocal` porque requiere menos flags de compilacion. La migracion es ~10 lineas. `ScopedValue` es especialmente util con virtual threads (Loom): millones de virtual threads × `ThreadLocal` consume mucha memoria, `ScopedValue` no.

---

## 🔵 .NET 8: AsyncLocal<T> que fluye por await, System.Text.Json para logs estructurados

**Runtime:** .NET 8 sobre `HttpListener`. El CLR ejecuta handlers async sobre el `ThreadPool`. Un `await` puede retomar en otro thread — un `ThreadLocal<T>` no sobreviviria.

**El fallo legacy en C#:**
```csharp
Console.WriteLine("[INFO] processing checkout");
if (total > 500) {
    Console.WriteLine("[ERROR] checkout failed");   // sin id, sin total
}
```
Tras tres `await` el thread fisico puede haber cambiado dos veces. Cualquier intento de `ThreadLocal<RequestContext>` aqui pierde el contexto silenciosamente.

**La correccion en C#:**
```csharp
private static readonly AsyncLocal<RequestContext?> CTX = new();

CTX.Value = new RequestContext(corrId, "checkout-observable", DateTime.UtcNow.ToString("o"));
StructuredLog("error", "checkout_failed", new Dictionary<string,string> {
    ["total"]  = total.ToString(),
    ["reason"] = "exceeds_limit",
    ["limit"]  = "500"
});
// → {"ts":"...","correlation_id":"<guid>","route":"checkout-observable", ...}
```
`AsyncLocal<T>` esta amarrado al `ExecutionContext`, que el CLR captura y restaura en cada `await`. El correlation_id sobrevive thread hops sin disciplina manual.

**Notas idiomaticas vs los otros stacks:**
- `AsyncLocal<T>` es el equivalente exacto del `ScopedValue` Java (JDK 21) y de `contextvars` Python. Resuelve el mismo problema que `ThreadLocal` no resuelve en codigo async.
- `Guid.NewGuid()` reemplaza `crypto.randomBytes` Node, `secrets.token_hex` Python o `random_bytes` PHP.
- `System.Text.Json.JsonSerializer.Serialize(dict)` reemplaza `JSON.stringify` Node o `json_encode` PHP, sin librerias externas.
- C# tiene un sistema de excepciones tipadas tan rico como Java; `class WorkflowFailure : Exception` es 1:1 con el equivalente Java.

---

---

## 🐹 Go 1.23: `context.Context` como parametro explicito + `log/slog` de la stdlib

**La primitiva:** el correlation ID viaja en un `context.Context` que se pasa **como parametro**, no en almacenamiento ambiente. La clave del contexto es un tipo privado (`type ctxKey struct{}`) para que nadie de afuera pueda colisionar con ella.

```go
func structuredLog(ctx context.Context, level, event string, fields map[string]any)
```

La firma obliga a tener contexto para loguear. Y la variante legacy **no recibe `ctx`** — esa ausencia en la firma es la señal.

**`log/slog`:** unico stack del lab donde el logger estructurado viene en la biblioteca estandar. Emite el mismo evento a stdout que `/logs` devuelve, sin elegir entre uno u otro.

**Lo que Go NO hace, y conviene no exagerar:** el compilador **no obliga** a propagar el contexto. Lanzar `go func(){ ... }()` sin pasarle el `ctx` compila perfectamente y ese trabajo queda sin correlacionar. Go hace la dependencia **visible en la firma**, no obligatoria. Es una mejora de legibilidad y de revision de codigo frente a `ThreadLocal`/`AsyncLocal`, no una garantia del compilador.

---

## 🦀 Rust 1.83: `&RequestCtx` prestado, con lifetime acotado al request

**La primitiva:** el contexto se presta por referencia, y el borrow checker impide que esa referencia sobreviva al handler. Guardarla en una estructura de vida mas larga **no compila**.

```rust
fn structured_log(ctx: &RequestCtx, level: &str, event: &str, fields: &[(&str, &str)])
```

**La categoria de bug que esto cierra:** en los modelos ambiente (`ThreadLocal`, `AsyncLocal`, `AsyncLocalStorage`), un contexto que sobrevive a su request —porque el thread se reutiliza y nadie limpio el slot— hace que los logs del usuario siguiente lleven el `correlation_id` del anterior. Es silencioso y desagradable de auditar. Aca no se puede escribir.

**Aqui esta la garantia que Go no da:** Go hace visible la dependencia; Rust la hace verificable. Es el unico de los siete stacks donde el compilador impide que el contexto se filtre fuera de su request.

**Contrapartida honesta:** `std` no trae logger estructurado. Go tiene `log/slog` desde 1.21; en Rust el ecosistema usa `tracing` o `log`, y para mantener el caso sin dependencias el JSON se arma con `format!` a mano. Es menos ergonomico.

## ⚖️ Diferencias de decision, no de correccion

> Los siete stacks implementan el **mismo algoritmo**. Esta tabla contrasta como lo expresa cada uno.

| Aspecto | PHP | Python | Node.js | Java | .NET | Go | Rust | Razon |
|---|---|---|---|---|---|---|---|---|
| Propagacion del contexto | manual por parametro | `LoggerAdapter` | `AsyncLocalStorage` | `ThreadLocal` | `AsyncLocal` | **`context.Context` como parametro** | **`&RequestCtx` prestado** | Los cuatro del medio son contexto *ambiente*: se lee algo que otro dejo en el hilo. |
| ¿Se puede perder en silencio? | si | si | si (al saltar de contexto async) | si (thread reutilizado) | si | si — `go func(){}()` sin `ctx` compila | **no — la referencia no puede sobrevivir al request** | Rust es el unico con garantia del compilador; Go solo lo hace visible en la firma. |
| Logger estructurado | manual / monolog | `JsonFormatter` stdlib | manual / pino | manual | `System.Text.Json` | **`log/slog` en la stdlib** | manual con `format!` | Go es el unico con JSON logging de fabrica y sin dependencia. |
| Riesgo de fuga entre requests | bajo (proceso muere) | bajo | medio | **alto (pool de threads)** | medio | bajo | **nulo** | Un `ThreadLocal` sin limpiar hace que el log del usuario siguiente lleve el id del anterior. |

**El concepto que los siete stacks demuestran es idéntico** (y estos tres, en detalle): logs sin estructura y sin correlación hacen el diagnóstico imposible. La diferencia practica es que Python tiene la API mas dificil de violar accidentalmente; PHP y Node confian en disciplina del developer (o de una libreria como winston/pino para Node, monolog para PHP).

---

## 📊 Primitiva central por stack

> Los siete stacks resuelven el mismo problema. Lo que cambia es la primitiva y donde duele.

| Stack | Primitiva central en este caso |
|---|---|
| PHP | contexto por request en el proceso FPM |
| Python | `logging.LoggerAdapter` + `JsonFormatter` |
| Node.js | `AsyncLocalStorage` |
| Java 21 | `ThreadLocal<RequestContext>` |
| .NET 8 | `AsyncLocal<T>` que fluye por `await` |
| Go 1.23 | `context.Context` **como parametro**; `log/slog` en la stdlib |
| Rust 1.83 | **`&RequestCtx` prestado**; el borrow checker impide que sobreviva al request |

---

## 🏁 Veredicto: que stack resuelve mejor **este** problema

> ⚠️ **Ranking de fit, no de calidad de lenguaje.** Mide que tan directamente las primitivas nativas del runtime expresan la solucion de *este* caso concreto. El orden cambia — a veces se invierte — de un caso a otro: leer varios rankings juntos dice mas que cualquiera por separado.

| | Stack | Por que |
|---|---|---|
| 🥇 | **Rust 1.83** | Unico stack donde **el compilador impide** que el contexto sobreviva al request. La fuga de correlacion entre usuarios no se puede escribir. |
| 🥈 | **Go 1.23** | `context.Context` explicito + `log/slog` en la stdlib. Hace la dependencia visible en la firma, pero **no la impone**: `go func(){}()` sin `ctx` compila. |
| 🥉 | **Python 3.12** | `LoggerAdapter` + `JsonFormatter` de stdlib: la API mas dificil de violar por accidente entre los interpretados. |
| 4º | **.NET 8** | `AsyncLocal` fluye correctamente por `await`, que es mas de lo que logra un `ThreadLocal`. |
| 5º | **Java 21** | `ThreadLocal` funciona, pero un thread reutilizado sin limpiar arrastra el id al request siguiente. |
| 6º | **Node.js 22 / PHP 8.3** | `AsyncLocalStorage` y disciplina manual. Funcionan; nada las respalda. |

**Lectura honesta:** Es el caso donde mas se nota la diferencia entre *hacer algo visible* (Go) y *hacerlo imposible de romper* (Rust).
