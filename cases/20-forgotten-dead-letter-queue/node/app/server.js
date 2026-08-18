/**
 * Caso 20 — La dead letter queue olvidada — stack Node.js 22.
 *
 * Cierra el arco que abrió el caso 15: allí la DLQ **nace**, como la política de
 * rechazo que salva al productor de bloquearse. Acá se ve qué pasa cuando nadie
 * vuelve a mirarla.
 *
 * Silencioso: el consumidor falla, manda el mensaje a la DLQ y sigue. Sin
 * clasificar, sin reintentar, sin medir, sin alerta. La cola crece durante meses
 * y el pipeline se ve sano: throughput normal, latencia normal, cero errores —
 * porque los errores se fueron a otro lado.
 *
 * Observado: el error se clasifica antes de decidir. Lo transitorio se reintenta
 * y casi todo se recupera; lo venenoso va a la DLQ con su clase de error y una
 * muestra del payload; la profundidad y la antigüedad se publican; hay umbral.
 *
 * La distinción que ordena el caso:
 *
 *   transitorio  — el mismo mensaje funciona en el próximo intento
 *   venenoso     — el mismo mensaje NUNCA va a funcionar
 *
 *   Reintentar lo venenoso es quemar CPU. Mandar lo transitorio a la DLQ es
 *   tirar trabajo que se podía salvar. El consumidor que no distingue hace las
 *   dos cosas mal a la vez.
 *
 * Primitiva Node distintiva — y es la más débil de los siete para este caso:
 *
 *   **Los errores de JavaScript son objetos comunes, sin jerarquía obligatoria.**
 *   `class ErrorVenenoso extends Error` funciona, y `instanceof` funciona —
 *   mientras el error no cruce un límite que rompa la cadena de prototipos:
 *
 *     - Dos copias del mismo paquete en `node_modules` producen dos clases
 *       distintas, y `instanceof` da `false` entre ellas.
 *     - Un error serializado a través de un `worker_thread` o de un mensaje
 *       llega como objeto plano: la clase se perdió.
 *     - Errores de bibliotecas nativas y de la propia `fs`/`net` no heredan de
 *       ninguna jerarquía de dominio: traen `err.code` como string.
 *
 *   El resultado en producción es que la clasificación degrada a comparar
 *   strings: `if (err.code === 'ETIMEDOUT' || /timeout/i.test(err.message))`.
 *   Funciona hasta que alguien cambia un mensaje de error.
 *
 *   Lo que sí ayuda desde ES2022 es `error.cause`, que preserva la cadena:
 *
 *     throw new ErrorVenenoso('schema_mismatch', {{ cause: errOriginal }});
 *
 *   Es el equivalente del `%w` de Go y del `__cause__` de Python, y llegó
 *   bastante después que los dos.
 */

const http = require('http');

const APP_STACK = process.env.APP_STACK || 'Node.js 22';
const CASE_NAME = '20 - La dead letter queue olvidada';
const POISON_CLASSES = ['schema_mismatch', 'unknown_field', 'null_required', 'invalid_encoding'];

const nowMs = () => Number(process.hrtime.bigint() / 1000n) / 1000;

/** El mismo mensaje funciona en el próximo intento. */
class ErrorTransitorio extends Error {
  constructor(msg, opts) { super(msg, opts); this.name = 'ErrorTransitorio'; }
}

/** El mismo mensaje NUNCA va a funcionar. */
class ErrorVenenoso extends Error {
  constructor(clase, opts) { super(clase, opts); this.name = 'ErrorVenenoso'; this.clase = clase; }
}

let dlq = [];
let alertsFired = 0;
let metrics = initialMetrics();

function initialMetrics() {
  const slot = () => ({ runs: 0, consumed: 0, succeeded: 0, retried: 0, dead_lettered: 0, alerts_fired: 0 });
  return { silent: slot(), observed: slot() };
}

function resetAll() { dlq = []; alertsFired = 0; }

/**
 * Procesa un mensaje. Lanza transitorio o venenoso según el mensaje.
 * El transitorio falla solo en el primer intento — es la definición de
 * transitorio, y es lo que hace que reintentarlo tenga sentido.
 */
function procesar(idx, transientPct, poisonPct, attempt) {
  if ((idx * 53) % 101 < poisonPct) throw new ErrorVenenoso(POISON_CLASSES[idx % POISON_CLASSES.length]);
  if ((idx * 37) % 101 < transientPct && attempt === 0) throw new ErrorTransitorio('timeout del downstream');
  return true;
}

// ---------------------------------------------------------------------------
// Variante silenciosa: cualquier fallo va a la DLQ, y nadie vuelve
// ---------------------------------------------------------------------------

function consumeSilent(messages, transientPct, poisonPct) {
  resetAll();
  let consumed = 0; let succeeded = 0; let dead = 0;
  const t0 = nowMs();

  for (let i = 0; i < messages; i++) {
    consumed++;
    try {
      procesar(i, transientPct, poisonPct, 0);
      succeeded++;
    } catch {
      // El bug entero, en tres líneas. No clasifica, no reintenta, no guarda
      // por qué falló. El mensaje se va a la DLQ y el consumidor sigue.
      dlq.push({ id: `msg-${i}`, error_class: 'unclassified', attempts: 1, firstSeenMs: nowMs(), sample: null });
      dead++;
    }
  }
  return { consumed, succeeded, retried: 0, dead_lettered: dead, alerts_fired: 0, sampled: 0,
           wall_ms: Number((nowMs() - t0).toFixed(2)) };
}

// ---------------------------------------------------------------------------
// Variante observada: clasificar, reintentar, medir, alertar
// ---------------------------------------------------------------------------

function consumeObserved(messages, transientPct, poisonPct, maxRetries, alertThreshold, sampleSize) {
  resetAll();
  let consumed = 0; let succeeded = 0; let retried = 0; let dead = 0; let sampled = 0;
  const t0 = nowMs();

  for (let i = 0; i < messages; i++) {
    consumed++;
    for (let attempt = 0; attempt <= maxRetries; attempt++) {
      try {
        procesar(i, transientPct, poisonPct, attempt);
        succeeded++;
        break;
      } catch (err) {
        // Acá está la debilidad del stack: `instanceof` es la única herramienta,
        // y deja de funcionar en cuanto el error cruza un límite de paquete o
        // de worker. En producción esto degrada a comparar `err.code`.
        if (err instanceof ErrorTransitorio) {
          retried++;
          if (attempt === maxRetries) {
            dlq.push({ id: `msg-${i}`, error_class: 'transient_exhausted', attempts: attempt + 1,
                       firstSeenMs: nowMs(), sample: null });
            dead++;
          }
          continue;
        }
        if (err instanceof ErrorVenenoso) {
          // Reintentarlo es quemar CPU. Va a la DLQ ya mismo, con su clase y
          // —para los primeros— una muestra del payload.
          let muestra = null;
          if (sampled < sampleSize) { muestra = { idx: i, payload: `{"id": ${i}, "campo": "..."}` }; sampled++; }
          dlq.push({ id: `msg-${i}`, error_class: err.clase, attempts: attempt + 1,
                     firstSeenMs: nowMs(), sample: muestra });
          dead++;
          break;
        }
        throw err;   // un error que no supimos clasificar NO va a la DLQ: sube
      }
    }
  }

  let alerts = 0;
  if (dlq.length > alertThreshold) { alertsFired++; alerts = 1; }

  return { consumed, succeeded, retried, dead_lettered: dead, alerts_fired: alerts, sampled,
           wall_ms: Number((nowMs() - t0).toFixed(2)) };
}

// ---------------------------------------------------------------------------
// La DLQ como cola observable, no como agujero
// ---------------------------------------------------------------------------

function dlqStats(alertThreshold) {
  const porClase = {};
  for (const m of dlq) porClase[m.error_class] = (porClase[m.error_class] || 0) + 1;
  const now = nowMs();
  const oldest = dlq.length ? Math.max(...dlq.map((m) => now - m.firstSeenMs)) : 0;
  return {
    dlq_depth: dlq.length,
    dlq_oldest_msg_age_ms: Number(oldest.toFixed(2)),
    by_error_class: Object.fromEntries(Object.entries(porClase).sort()),
    alert_threshold: alertThreshold,
    over_threshold: dlq.length > alertThreshold,
    alerts_fired: alertsFired,
    samples: dlq.filter((m) => m.sample).slice(0, 5).map((m) => m.sample),
    note: 'Una DLQ sin profundidad publicada, sin antigüedad del mensaje más viejo y sin desglose por clase de '
      + 'error no es una cola: es un agujero. `by_error_class` convierte "hay 4.000 mensajes" en "hay un bug de '
      + 'schema y tres timeouts".',
  };
}

/**
 * Replay desde la DLQ. Lo que se recupera vuelve; lo venenoso sigue ahí.
 * El drenaje es la mitad que casi nunca se construye: una DLQ que solo recibe
 * es un cementerio; una de la que se puede volver es un buffer.
 */
function dlqDrain(limit, transientPct, poisonPct, maxRetries) {
  const t0 = nowMs();
  const lote = dlq.slice(0, limit);
  const resto = dlq.slice(limit);
  let ok = 0; let fallo = 0;
  const quedan = [];

  for (const m of lote) {
    const idx = Number.parseInt(m.id.split('-')[1], 10);
    let recuperado = false;
    for (let attempt = 1; attempt <= maxRetries; attempt++) {
      try { procesar(idx, transientPct, poisonPct, attempt); recuperado = true; break; } catch (err) {
        if (err instanceof ErrorVenenoso) break;
      }
    }
    if (recuperado) ok++;
    else { fallo++; m.attempts += maxRetries; quedan.push(m); }
  }
  dlq = quedan.concat(resto);

  return {
    drain_limit: limit,
    drained_ok: ok,
    drain_failed: fallo,
    recovered_pct: Number(((ok * 100) / Math.max(1, ok + fallo)).toFixed(2)),
    drain_duration_ms: Number((nowMs() - t0).toFixed(2)),
    dlq_depth_after: dlq.length,
    note: 'Lo que se recupera en el replay es exactamente lo que nunca debería haber estado acá: errores '
      + 'transitorios que un reintento habría resuelto. Lo que sigue fallando es veneno de verdad, y necesita un '
      + 'cambio de código o de datos — no otro reintento.',
  };
}

function runScenario(variant, messages, transientPct, poisonPct, maxRetries, alertThreshold, sampleSize) {
  const r = variant === 'silent'
    ? consumeSilent(messages, transientPct, poisonPct)
    : consumeObserved(messages, transientPct, poisonPct, maxRetries, alertThreshold, sampleSize);
  const stats = dlqStats(alertThreshold);

  const slot = metrics[variant];
  slot.runs++;
  for (const k of ['consumed', 'succeeded', 'retried', 'dead_lettered', 'alerts_fired']) slot[k] += r[k];

  const payload = {
    variant, messages, transient_pct: transientPct, poison_pct: poisonPct,
    max_retries: variant === 'observed' ? maxRetries : 0, ...r,
    dlq_depth: stats.dlq_depth,
    dlq_oldest_msg_age_ms: stats.dlq_oldest_msg_age_ms,
    by_error_class: stats.by_error_class,
    alert_threshold: stats.alert_threshold,
    over_threshold: stats.over_threshold,
  };
  payload.dead_letter_rate_pct = Number(((r.dead_lettered * 100) / Math.max(1, r.consumed)).toFixed(2));
  payload.note = variant === 'silent'
    ? 'El consumidor no clasificó nada: transitorio y venenoso fueron al mismo lugar, sin reintentar y sin '
      + 'registrar por qué. El pipeline se ve sano —throughput normal, cero errores— porque los errores se fueron '
      + 'a otro lado. Y nadie va a volver.'
    : 'Lo transitorio se reintentó y casi todo se recuperó; solo el veneno llegó a la DLQ, con su clase de error y '
      + 'una muestra del payload. La profundidad está publicada y el umbral disparó alerta.';
  payload.node_note = 'Los errores de JavaScript son objetos comunes sin jerarquía obligatoria: `instanceof` '
    + 'funciona hasta que el error cruza un límite de paquete duplicado, un worker_thread o una biblioteca nativa. '
    + 'En producción la clasificación degrada a comparar `err.code` o el texto del mensaje. `error.cause` (ES2022) '
    + 'preserva la cadena y es el equivalente del %w de Go — llegó bastante después.';
  return payload;
}

function diagnostics(alertThreshold) {
  return {
    stack: APP_STACK,
    case: CASE_NAME,
    variants: metrics,
    dlq: dlqStats(alertThreshold),
    arco_con_el_caso_15: 'En el caso 15 la DLQ NACE: es la política de rechazo que salva al productor de '
      + 'bloquearse cuando la cola se llena. Acá se ve qué pasa cuando nadie vuelve a mirarla. Los dos casos son '
      + 'el mismo mecanismo en dos momentos distintos.',
    fidelity: {
      real: 'La clasificación de errores, el reintento con presupuesto acotado, el desglose por clase, el muestreo '
        + 'de payloads y el replay desde la DLQ son código de verdad.',
      modelado: 'La DLQ es un array en memoria, no SQS ni RabbitMQ. La clase de error de cada mensaje es '
        + 'determinista para que el escenario sea reproducible.',
      honesto: 'Lo que define el caso no es el broker: es que un mensaje que falla tiene que ir a algún lado, y '
        + 'que ese lado necesita profundidad, antigüedad, clasificación y una salida.',
    },
    interpretation: {
      silent: 'dead_letter_rate_pct alto, by_error_class con una sola entrada ("unclassified") y alerts_fired en '
        + 'cero. El pipeline se ve sano.',
      observed: 'dead_letter_rate_pct bajo —solo el veneno—, by_error_class desglosado y la alerta disparada.',
      node_note: 'Es el stack más débil del set para clasificar: sin jerarquía obligatoria, `instanceof` es frágil '
        + 'y la alternativa práctica es comparar strings.',
    },
  };
}

const clampInt = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
function queryInt(params, key, def) {
  const raw = params.get(key);
  if (raw === null) return def;
  const n = Number.parseInt(raw, 10);
  return Number.isNaN(n) ? def : n;
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const uri = url.pathname;
  const q = url.searchParams;
  let status = 200;
  let payload;

  const messages = clampInt(queryInt(q, 'messages', 3000), 10, 200000);
  const transientPct = clampInt(queryInt(q, 'transient_pct', 12), 0, 100);
  const poisonPct = clampInt(queryInt(q, 'poison_pct', 4), 0, 100);
  const maxRetries = clampInt(queryInt(q, 'max_retries', 3), 0, 20);
  const alertThreshold = clampInt(queryInt(q, 'alert_threshold', 50), 0, 100000);
  const sampleSize = clampInt(queryInt(q, 'sample_size', 20), 0, 1000);
  const limit = clampInt(queryInt(q, 'limit', 500), 1, 200000);

  if (uri === '/' || uri === '/index') {
    payload = {
      lab: 'Problem-Driven Systems Lab',
      case: CASE_NAME,
      stack: APP_STACK,
      goal: 'Mostrar que un pipeline con throughput normal y cero errores puede estar perdiendo el 16% de los '
        + 'mensajes, porque los errores se fueron a un lugar que nadie mira.',
      arco: 'Cierra el arco del caso 15, donde la DLQ nace como política de rechazo.',
      node_specific: 'Los errores son objetos comunes sin jerarquía obligatoria: `instanceof` es frágil y la '
        + 'clasificación degrada a comparar strings.',
      routes: {
        '/health': 'Estado básico del servicio.',
        '/consume-silent?messages=3000': 'Cualquier fallo a la DLQ, sin clasificar ni reintentar.',
        '/consume-observed?messages=3000': 'Clasificar, reintentar lo transitorio, alertar.',
        '/dlq/stats': 'Profundidad, antigüedad del más viejo y desglose por clase de error.',
        '/dlq/drain?limit=500': 'Replay desde la DLQ: qué se recupera y qué sigue siendo veneno.',
        '/diagnostics/summary': 'Comparativa entre variantes.',
        '/reset-lab': 'Vacía la DLQ y las métricas.',
      },
    };
  } else if (uri === '/health') {
    payload = { status: 'ok', stack: APP_STACK, case: CASE_NAME };
  } else if (uri === '/consume-silent') {
    payload = runScenario('silent', messages, transientPct, poisonPct, maxRetries, alertThreshold, sampleSize);
  } else if (uri === '/consume-observed') {
    payload = runScenario('observed', messages, transientPct, poisonPct, maxRetries, alertThreshold, sampleSize);
  } else if (uri === '/dlq/stats') {
    payload = dlqStats(alertThreshold);
  } else if (uri === '/dlq/drain') {
    payload = dlqDrain(limit, transientPct, poisonPct, maxRetries);
  } else if (uri === '/diagnostics/summary') {
    payload = diagnostics(alertThreshold);
  } else if (uri === '/reset-lab') {
    resetAll();
    metrics = initialMetrics();
    payload = { status: 'reset', message: 'DLQ y métricas reiniciadas.' };
  } else {
    status = 404;
    payload = { error: 'Ruta no encontrada', path: uri };
  }

  payload.timestamp_utc = new Date().toISOString().replace(/\.\d+Z$/, 'Z');
  payload.pid = process.pid;
  const body = JSON.stringify(payload, null, 2);
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(body);
});

const PORT = Number(process.env.PORT || 8080);
server.listen(PORT, '0.0.0.0', () => console.log(`Servidor Node escuchando en ${PORT}`));
