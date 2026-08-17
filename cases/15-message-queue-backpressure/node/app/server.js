'use strict';

/**
 * Caso 15 — Backpressure en colas de mensajes — stack Node.js 22.
 *
 * Unbounded: un array que crece sin techo. El productor nunca se entera de que
 * el consumidor no da abasto.
 * Bounded: capacidad fija y una politica explicita cuando esta llena.
 *
 * Primitiva Node distintiva:
 *   `stream.Writable` con `highWaterMark`. Node es el unico stack del
 *   laboratorio donde el backpressure **es parte del protocolo del runtime**,
 *   no algo que uno construye encima:
 *
 *       const ok = writable.write(chunk);
 *       if (!ok) await once(writable, 'drain');   // el freno
 *
 *   `write()` devuelve `false` cuando el buffer interno paso el `highWaterMark`.
 *   Eso NO es un error ni un rechazo: el chunk se acepto igual. Es una señal de
 *   cortesia — "segui si queres, pero estoy acumulando".
 *
 *   Y ahi esta la trampa que este caso demuestra: **ignorar ese `false` compila,
 *   pasa los tests y funciona en desarrollo**. El unico sintoma es que el buffer
 *   interno crece sin limite hasta el OOM. Es un freno que hay que apretar a
 *   mano, y la firma no obliga a nadie a mirarlo.
 *
 * La leccion del caso es que ninguna politica es gratis: bloquear frena al
 * productor, descartar pierde datos, y la DLQ muda el problema a otra cola que
 * alguien tiene que mirar (eso es el caso 20).
 */

const http = require('http');
const { Writable } = require('stream');
const { once } = require('events');
const { URL } = require('url');
const { performance } = require('perf_hooks');

const APP_STACK = process.env.APP_STACK || 'Node.js 22';
const CASE_NAME = '15 - Backpressure en colas de mensajes';

const POLICIES = ['block', 'drop_oldest', 'dead_letter'];
const MSG_BYTES = 2048;

let dlq = [];
let lastState = {};

const slot = () => ({
  runs: 0,
  produced: 0,
  consumed: 0,
  dropped: 0,
  deadLettered: 0,
  maxQueueDepth: 0,
  maxOldestAgeMs: 0,
  producerBlockedMs: 0,
});
const initialMetrics = () => ({ unbounded: slot(), bounded: slot() });
let metrics = initialMetrics();

/**
 * El consumidor: un Writable que tarda `consumeMs` por mensaje.
 *
 * `highWaterMark` en objectMode se cuenta en objetos, no en bytes. Es el umbral
 * a partir del cual `write()` empieza a devolver `false`.
 */
function makeConsumer(consumeMs, highWaterMark, stats) {
  return new Writable({
    objectMode: true,
    highWaterMark,
    write(msg, _enc, cb) {
      // Se mide ANTES de procesar: la edad del mensaje mas viejo es la latencia
      // real del consumidor final, y sin limite crece sin techo aunque el
      // throughput se vea sano.
      const age = performance.now() - msg.enqueuedAt;
      stats.maxOldestAgeMs = Math.max(stats.maxOldestAgeMs, age);
      setTimeout(() => {
        stats.consumed += 1;
        cb();
      }, consumeMs);
    },
  });
}

const endConsumer = (w) =>
  new Promise((resolve) => {
    w.end(() => resolve());
  });

// ---------------------------------------------------------------------------
// Variante unbounded: se ignora el false de write()
// ---------------------------------------------------------------------------

async function runUnbounded(messages, consumeMs) {
  const stats = { consumed: 0, maxOldestAgeMs: 0 };
  // highWaterMark absurdamente alto = el freno nunca se activa. Equivale a no
  // tener limite: es exactamente lo que pasa cuando alguien "arregla" un
  // warning de backpressure subiendo el highWaterMark en vez de respetarlo.
  const consumer = makeConsumer(consumeMs, Number.MAX_SAFE_INTEGER, stats);

  const started = performance.now();
  let peak = 0;
  for (let seq = 0; seq < messages; seq += 1) {
    // Se ignora el valor de retorno a proposito. Compila, pasa los tests, y el
    // buffer interno crece hasta donde de la memoria del proceso.
    consumer.write({ seq, enqueuedAt: performance.now() });
    peak = Math.max(peak, consumer.writableLength);
  }
  const depthAtEnd = consumer.writableLength;
  await endConsumer(consumer);
  const wallMs = performance.now() - started;

  return {
    variant: 'unbounded',
    policy: null,
    capacity: null,
    produced: messages,
    consumed: stats.consumed,
    dropped: 0,
    dead_lettered: 0,
    queue_depth_peak: peak,
    queue_depth_at_end_of_production: depthAtEnd,
    queue_bytes_peak: peak * MSG_BYTES,
    oldest_msg_age_ms_peak: Number(stats.maxOldestAgeMs.toFixed(2)),
    producer_blocked_ms: 0,
    backpressure_signals: 0,
    wall_ms: Number(wallMs.toFixed(2)),
    throughput_msg_s: wallMs > 0 ? Number((messages / (wallMs / 1000)).toFixed(2)) : 0,
    note:
      'Se ignora el false de write() y el highWaterMark queda en infinito: el productor nunca se entera y el ' +
      'buffer interno crece hasta el OOM. El throughput se ve sano mientras la latencia del mensaje mas viejo sube.',
  };
}

// ---------------------------------------------------------------------------
// Variante bounded: se respeta el false de write()
// ---------------------------------------------------------------------------

async function runBounded(messages, capacity, policy, consumeMs) {
  const stats = { consumed: 0, maxOldestAgeMs: 0 };
  const consumer = makeConsumer(consumeMs, capacity, stats);

  const started = performance.now();
  let produced = 0;
  let dropped = 0;
  let dead = 0;
  let signals = 0;
  let blockedMs = 0;
  let peak = 0;

  for (let seq = 0; seq < messages; seq += 1) {
    const msg = { seq, enqueuedAt: performance.now() };

    if (consumer.writableLength >= capacity) {
      signals += 1;
      if (policy === 'block') {
        // El freno: esperar el evento 'drain' es respetar el protocolo que el
        // runtime ya ofrece. Nada se pierde; el productor se frena.
        const t0 = performance.now();
        await once(consumer, 'drain');
        blockedMs += performance.now() - t0;
      } else if (policy === 'drop_oldest') {
        // No hay forma de sacar el mas viejo del buffer interno de un Writable,
        // asi que se descarta el mas nuevo — que es lo que el propio Node hace
        // en `drop_newest`. Se cuenta igual como perdida de datos.
        dropped += 1;
        peak = Math.max(peak, consumer.writableLength);
        continue;
      } else {
        dlq.push({ seq, reason: 'queue_full', at: new Date().toISOString() });
        if (dlq.length > 200) dlq = dlq.slice(-200);
        dead += 1;
        peak = Math.max(peak, consumer.writableLength);
        continue;
      }
    }

    consumer.write(msg);
    produced += 1;
    peak = Math.max(peak, consumer.writableLength);
  }

  const depthAtEnd = consumer.writableLength;
  await endConsumer(consumer);
  const wallMs = performance.now() - started;

  const notes = {
    block:
      "Se respeta el false de write() y se espera el evento 'drain': el protocolo de backpressure que el runtime " +
      'ya trae. Nada se pierde, pero el productor se frena y esa lentitud viaja aguas arriba.',
    drop_oldest:
      'Se descarta cuando el buffer llego al highWaterMark: el productor nunca se frena, pero se pierden datos en ' +
      'silencio. Aceptable para telemetria, inaceptable para pagos.',
    dead_letter:
      'Lo que no entra va a la DLQ: no se frena ni se pierde, pero el problema se muda a otra cola que alguien ' +
      'tiene que mirar. Si nadie la mira, es el caso 20.',
  };

  return {
    variant: 'bounded',
    policy,
    capacity,
    produced,
    consumed: stats.consumed,
    dropped,
    dead_lettered: dead,
    queue_depth_peak: peak,
    queue_depth_at_end_of_production: depthAtEnd,
    queue_bytes_peak: peak * MSG_BYTES,
    oldest_msg_age_ms_peak: Number(stats.maxOldestAgeMs.toFixed(2)),
    producer_blocked_ms: Number(blockedMs.toFixed(2)),
    backpressure_signals: signals,
    wall_ms: Number(wallMs.toFixed(2)),
    throughput_msg_s: wallMs > 0 ? Number((produced / (wallMs / 1000)).toFixed(2)) : 0,
    note: notes[policy],
  };
}

function record(variant, r) {
  const s = metrics[variant];
  s.runs += 1;
  s.produced += r.produced;
  s.consumed += r.consumed;
  s.dropped += r.dropped;
  s.deadLettered += r.dead_lettered;
  s.maxQueueDepth = Math.max(s.maxQueueDepth, r.queue_depth_peak);
  s.maxOldestAgeMs = Math.max(s.maxOldestAgeMs, r.oldest_msg_age_ms_peak);
  s.producerBlockedMs += r.producer_blocked_ms;
  lastState = {
    last_variant: variant,
    last_policy: r.policy,
    capacity: r.capacity,
    queue_depth_peak: r.queue_depth_peak,
    queue_bytes_peak: r.queue_bytes_peak,
    oldest_msg_age_ms_peak: r.oldest_msg_age_ms_peak,
  };
}

const queueState = () => ({
  ...lastState,
  dlq_depth: dlq.length,
  msg_bytes: MSG_BYTES,
  policies: POLICIES,
  note: 'queue_depth_peak x msg_bytes es lo que el buffer del Writable llego a ocupar. Sin highWaterMark util, no tiene techo.',
});

const dlqView = (limit) => ({
  dlq_depth: dlq.length,
  limit,
  messages: [...dlq].reverse().slice(0, limit),
  note: 'La DLQ no resuelve el backpressure: lo muda. El caso 20 trata que pasa cuando nadie la mira.',
});

const diagnostics = () => {
  const variants = {};
  for (const [name, s] of Object.entries(metrics)) {
    variants[name] = {
      runs: s.runs,
      produced: s.produced,
      consumed: s.consumed,
      dropped: s.dropped,
      dead_lettered: s.deadLettered,
      max_queue_depth: s.maxQueueDepth,
      max_oldest_age_ms: Number(s.maxOldestAgeMs.toFixed(2)),
      producer_blocked_ms: Number(s.producerBlockedMs.toFixed(2)),
    };
  }
  return {
    stack: APP_STACK,
    case: CASE_NAME,
    variants,
    dlq_depth: dlq.length,
    interpretation: {
      unbounded:
        'producer_blocked_ms = 0 y dropped = 0 se ven bien hasta que se mira queue_depth_peak y oldest_msg_age_ms_peak.',
      bounded:
        'Las tres politicas pagan algo distinto: block paga latencia del productor, drop_oldest paga datos, ' +
        'dead_letter paga deuda operativa. No hay una cuarta opcion gratis.',
      node_note:
        "write() devuelve false y 'drain' avisa cuando se puede seguir: es el unico stack donde el backpressure es " +
        'parte del protocolo del runtime. Tambien es el unico donde ignorarlo compila y pasa los tests.',
    },
  };
};

const clampInt = (raw, fallback, min, max) => {
  const n = Number.parseInt(raw, 10);
  if (Number.isNaN(n)) return fallback;
  return Math.max(min, Math.min(max, n));
};

const sendJson = (res, status, payload) => {
  const body = JSON.stringify(payload, null, 2);
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(body),
  });
  res.end(body);
};

const handler = async (req, res) => {
  const url = new URL(req.url || '/', 'http://127.0.0.1');
  const uri = url.pathname || '/';
  let status = 200;
  let payload;

  const messages = clampInt(url.searchParams.get('messages'), 120, 1, 2000);
  const capacity = clampInt(url.searchParams.get('capacity'), 32, 1, 1000);
  const consumeMs = clampInt(url.searchParams.get('consume_ms'), 2, 0, 100);
  const limit = clampInt(url.searchParams.get('limit'), 20, 1, 200);
  let policy = url.searchParams.get('policy') || 'block';
  if (!POLICIES.includes(policy)) policy = 'block';

  try {
    if (uri === '/' || uri === '/index') {
      payload = {
        lab: 'Problem-Driven Systems Lab',
        case: CASE_NAME,
        stack: APP_STACK,
        goal: 'Mostrar que una cola sin limite no es la opcion sin costo: es la opcion con el freno roto.',
        node_specific:
          "stream.Writable con highWaterMark: write() devuelve false y 'drain' avisa cuando seguir. El backpressure es parte del protocolo — e ignorarlo compila.",
        routes: {
          '/health': 'Estado basico del servicio.',
          '/produce-unbounded?messages=120&consume_ms=2': 'Se ignora el false de write().',
          '/produce-bounded?messages=120&capacity=32&policy=block&consume_ms=2': "Se espera el evento 'drain'.",
          '/produce-bounded?messages=120&capacity=32&policy=drop_oldest': 'Se descarta al llegar al highWaterMark.',
          '/produce-bounded?messages=120&capacity=32&policy=dead_letter': 'Lo que no entra va a la DLQ.',
          '/queue/state': 'Profundidad pico, bytes y edad del mensaje mas viejo.',
          '/dlq?limit=20': 'Contenido de la dead letter queue.',
          '/diagnostics/summary': 'Comparativa entre variantes y politicas.',
          '/reset-lab': 'Limpia DLQ y contadores.',
        },
        allowed_policies: POLICIES,
      };
    } else if (uri === '/health') {
      payload = { status: 'ok', stack: APP_STACK, case: CASE_NAME };
    } else if (uri === '/produce-unbounded') {
      payload = await runUnbounded(messages, consumeMs);
      record('unbounded', payload);
    } else if (uri === '/produce-bounded') {
      payload = await runBounded(messages, capacity, policy, consumeMs);
      record('bounded', payload);
    } else if (uri === '/queue/state') {
      payload = queueState();
    } else if (uri === '/dlq') {
      payload = dlqView(limit);
    } else if (uri === '/diagnostics/summary') {
      payload = diagnostics();
    } else if (uri === '/reset-lab') {
      dlq = [];
      metrics = initialMetrics();
      lastState = {};
      payload = { status: 'reset', message: 'DLQ y metricas reiniciadas.' };
    } else {
      status = 404;
      payload = { error: 'Ruta no encontrada', path: uri };
    }
  } catch (error) {
    status = 500;
    payload = { error: 'Fallo al procesar la solicitud', message: error.message, path: uri };
  }

  payload.timestamp_utc = new Date().toISOString();
  payload.pid = process.pid;
  sendJson(res, status, payload);
};

const PORT = Number.parseInt(process.env.PORT || '8080', 10);
http.createServer(handler).listen(PORT, '0.0.0.0', () => {
  console.log(`Servidor Node escuchando en ${PORT}`);
});
