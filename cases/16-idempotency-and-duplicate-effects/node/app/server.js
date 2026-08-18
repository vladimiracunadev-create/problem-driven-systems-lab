'use strict';

/**
 * Caso 16 — Idempotencia y efectos duplicados — stack Node.js 22.
 *
 * Unsafe: N reintentos del mismo pago aplican N cargos.
 * Idempotent: `Idempotency-Key` persistida + outbox pattern.
 *
 * Primitiva Node distintiva — y es una ausencia, no una presencia:
 *
 *   Node **no tiene ninguna operacion atomica de mapa**, porque no la necesita.
 *   No hay `putIfAbsent`, ni `TryAdd`, ni `LoadOrStore`, ni `entry()`. Un
 *   `Map.has()` seguido de un `Map.set()` es indivisible por construccion: entre
 *   las dos lineas no puede correr nada mas, porque no hay otro hilo.
 *
 *       if (!table.has(key)) table.set(key, entry);   // atomico en Node
 *
 *   En Java, Go o Rust esas dos lineas son un bug de concurrencia. Aca son
 *   correctas — y esa es exactamente la trampa del stack: **el codigo ingenuo
 *   funciona en un proceso y deja de funcionar en cuanto hay dos**.
 *
 *   Con `cluster`, con PM2 en modo fork o con dos pods de Kubernetes, cada
 *   proceso tiene su propio Map y ninguno ve las claves del otro. El bug no
 *   aparece al escribir el codigo ni al testearlo: aparece al escalar.
 *
 *   Por eso la tabla de idempotencia que sirve de verdad no vive en memoria:
 *   vive en Redis con `SET NX` o en la base con un `UNIQUE`. La version en
 *   memoria de este archivo es correcta para un proceso y esta documentada como
 *   lo que es.
 *
 * La segunda mitad del caso es el **outbox pattern**: el cargo va a la base y el
 * email a una cola, sin transaccion que los abarque. El outbox escribe el efecto
 * en la misma escritura que el cargo y deja que un worker lo entregue.
 */

const http = require('http');
const { URL } = require('url');
const { performance } = require('perf_hooks');

const APP_STACK = process.env.APP_STACK || 'Node.js 22';
const CASE_NAME = '16 - Idempotencia y efectos duplicados';

const DEDUPE_WINDOW_MS = 24 * 60 * 60 * 1000;

let ledger = new Map();
let idempotency = new Map();
let outbox = [];
let delivered = [];

const slot = () => ({
  runs: 0,
  attempts: 0,
  charges_applied: 0,
  duplicates_prevented: 0,
  duplicates_applied: 0,
  idempotency_hits: 0,
  side_effects_emitted: 0,
  overcharged_cents: 0,
});
const initialMetrics = () => ({ unsafe: slot(), idempotent: slot() });
let metrics = initialMetrics();

const nowIso = () => new Date().toISOString();

const applyCharge = (account, amount) => {
  const next = (ledger.get(account) || 0) + amount;
  ledger.set(account, next);
  return next;
};

/**
 * El efecto DIRECTO, fuera de la transaccion del cargo.
 *
 * Si el proceso muere entre el cargo y esta linea, el cobro existe y el aviso
 * no. Y si sale pero el cargo se revierte, se aviso de algo que no paso.
 */
const emitDirect = (key, amount) => {
  delivered.push({ key, kind: 'payment_receipt_email', amount_cents: amount, at: nowIso(), via: 'direct' });
  if (delivered.length > 200) delivered = delivered.slice(-200);
};

/** Escribe el efecto en el outbox, junto al cargo. No lo entrega. */
const enqueueOutbox = (key, amount) => {
  outbox.push({ key, kind: 'payment_receipt_email', amount_cents: amount, at: nowIso(), status: 'pending' });
  if (outbox.length > 200) outbox = outbox.slice(-200);
};

/** El worker que mueve el outbox al destino real. Idempotente por diseño. */
const drainOutbox = () => {
  let moved = 0;
  for (const row of outbox) {
    if (row.status === 'pending') {
      row.status = 'delivered';
      delivered.push({ ...row, via: 'outbox' });
      moved += 1;
    }
  }
  if (delivered.length > 200) delivered = delivered.slice(-200);
  return moved;
};

// ---------------------------------------------------------------------------
// Los dos intentos
// ---------------------------------------------------------------------------

const attemptUnsafe = (key, account, amount) => {
  applyCharge(account, amount);
  emitDirect(key, amount);
  return { applied: true, hit: false, lookupMs: 0 };
};

const attemptIdempotent = (key, account, amount) => {
  const t0 = performance.now();

  const existing = idempotency.get(key);
  if (existing && Date.now() - existing.storedAt > DEDUPE_WINDOW_MS) {
    // Fuera de la ventana: la clave caduco y esto es una operacion nueva.
    idempotency.delete(key);
  }

  const found = idempotency.get(key);
  if (found) {
    // Reintento: se devuelve exactamente la misma respuesta que habria recibido
    // el intento original. Ni un error, ni un cuerpo distinto.
    return { applied: false, hit: true, lookupMs: performance.now() - t0, response: found.response };
  }

  // has() + set() sin await en el medio: indivisible en Node porque no hay otro
  // hilo. En Java o Go estas dos lineas serian un bug de concurrencia.
  const entry = { response: null, storedAt: Date.now() };
  idempotency.set(key, entry);

  // El cargo y el efecto pendiente se escriben JUNTOS.
  const balance = applyCharge(account, amount);
  enqueueOutbox(key, amount);
  entry.response = { status: 'charged', key, account, amount_cents: amount, balance_cents: balance };

  return { applied: true, hit: false, lookupMs: performance.now() - t0, response: entry.response };
};

// ---------------------------------------------------------------------------
// Orquestacion
// ---------------------------------------------------------------------------

function runAttempts(variant, key, account, amount, attempts) {
  const worker = variant === 'unsafe' ? attemptUnsafe : attemptIdempotent;
  const started = performance.now();
  const results = [];
  for (let i = 0; i < attempts; i += 1) results.push(worker(key, account, amount));
  const wallMs = performance.now() - started;

  const applied = results.filter((r) => r.applied).length;
  const hits = results.filter((r) => r.hit).length;
  const lookups = results.map((r) => r.lookupMs).filter((v) => v > 0);
  const deliveredNow = variant === 'idempotent' ? drainOutbox() : 0;

  const balance = ledger.get(account) || 0;
  const pending = outbox.filter((r) => r.status === 'pending').length;
  const overcharged = Math.max(0, applied - 1) * amount;

  const s = metrics[variant];
  s.runs += 1;
  s.attempts += attempts;
  s.charges_applied += applied;
  s.duplicates_prevented += hits;
  s.duplicates_applied += Math.max(0, applied - 1);
  s.idempotency_hits += hits;
  s.side_effects_emitted += variant === 'unsafe' ? attempts : deliveredNow;
  s.overcharged_cents += overcharged;

  return {
    variant,
    key,
    account,
    attempts,
    amount_cents: amount,
    charges_applied: applied,
    duplicates_prevented: hits,
    duplicates_applied: Math.max(0, applied - 1),
    idempotency_hits: hits,
    balance_cents: balance,
    overcharged_cents: overcharged,
    side_effects_emitted: variant === 'unsafe' ? attempts : deliveredNow,
    side_effect_transport:
      variant === 'unsafe'
        ? 'directo, fuera de la transaccion'
        : 'outbox, en la misma escritura que el cargo',
    outbox_pending: pending,
    outbox_delivered: delivered.length,
    lookup_overhead_ms: lookups.length
      ? Number((lookups.reduce((a, b) => a + b, 0) / lookups.length).toFixed(3))
      : 0,
    dedupe_window_ms: DEDUPE_WINDOW_MS,
    wall_ms: Number(wallMs.toFixed(2)),
    note:
      variant === 'unsafe'
        ? 'Sin clave de idempotencia: cada reintento aplica su propio cargo y publica su propio efecto. El cliente reintento por un timeout, no porque quisiera pagar de nuevo.'
        : 'Map como tabla de idempotencia + outbox en la misma escritura que el cargo. Correcto en un proceso; con cluster o dos pods hace falta Redis SET NX o un UNIQUE en la base.',
  };
}

const idempotencyState = () => {
  const keys = {};
  for (const [k, v] of idempotency.entries()) {
    const age = Date.now() - v.storedAt;
    keys[k] = { age_ms: age, expired: age > DEDUPE_WINDOW_MS, has_response: v.response !== null };
  }
  return {
    keys,
    key_count: idempotency.size,
    ledger_cents: Object.fromEntries(ledger),
    dedupe_window_ms: DEDUPE_WINDOW_MS,
    note: 'La tabla de idempotencia vive en el heap de ESTE proceso. Con cluster o dos pods, cada uno tiene la suya y ninguno ve las claves del otro.',
  };
};

const outboxView = (limit) => ({
  outbox_pending: outbox.filter((r) => r.status === 'pending').length,
  outbox_total: outbox.length,
  delivered_total: delivered.length,
  limit,
  outbox: [...outbox].reverse().slice(0, limit),
  delivered: [...delivered].reverse().slice(0, limit),
  note: 'El outbox se escribe en la misma transaccion que el cargo. El worker que lo drena puede reintentar sin miedo: entregar dos veces el mismo row es visible y corregible, perder el efecto no.',
});

const diagnostics = () => ({
  stack: APP_STACK,
  case: CASE_NAME,
  variants: metrics,
  outbox_pending: outbox.filter((r) => r.status === 'pending').length,
  outbox_delivered: delivered.length,
  interpretation: {
    unsafe:
      'charges_applied = attempts: cada reintento cobro de nuevo. overcharged_cents es plata real que el negocio tiene que devolver.',
    idempotent:
      'charges_applied = 1 y duplicates_prevented = attempts - 1, sin importar cuantas veces reintente el cliente.',
    node_note:
      'Node no tiene operacion atomica de mapa porque no la necesita: has() + set() es indivisible con un solo hilo. Y esa es la trampa — el codigo ingenuo funciona en un proceso y deja de funcionar en cuanto hay dos.',
  },
});

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

const handler = (req, res) => {
  const url = new URL(req.url || '/', 'http://127.0.0.1');
  const uri = url.pathname || '/';
  let status = 200;
  let payload;

  const key = (url.searchParams.get('key') || 'order-4711').slice(0, 60);
  const account = (url.searchParams.get('account') || 'acct-1').slice(0, 40);
  const attempts = clampInt(url.searchParams.get('attempts'), 5, 1, 64);
  const amount = clampInt(url.searchParams.get('amount'), 2500, 1, 10000000);
  const limit = clampInt(url.searchParams.get('limit'), 20, 1, 200);

  try {
    if (uri === '/' || uri === '/index') {
      payload = {
        lab: 'Problem-Driven Systems Lab',
        case: CASE_NAME,
        stack: APP_STACK,
        goal: "Mostrar que un reintento por timeout se convierte en un segundo cobro salvo que el servidor sepa distinguir 'es la primera vez que veo esto' de 'ya procese esto'.",
        node_specific:
          'Map como tabla de idempotencia: has() + set() es atomico con un solo hilo, y deja de serlo en cuanto hay dos procesos.',
        routes: {
          '/health': 'Estado basico del servicio.',
          '/charge-unsafe?key=order-4711&attempts=5&amount=2500': 'N reintentos, N cargos.',
          '/charge-idempotent?key=order-4711&attempts=5&amount=2500': 'N reintentos, un cargo y un efecto.',
          '/idempotency/state': 'Claves guardadas, edad, ventana de dedupe y saldo por cuenta.',
          '/outbox?limit=20': 'Efectos pendientes y entregados.',
          '/diagnostics/summary': 'Comparativa entre variantes.',
          '/reset-lab': 'Vacia ledger, claves y outbox.',
        },
      };
    } else if (uri === '/health') {
      payload = { status: 'ok', stack: APP_STACK, case: CASE_NAME };
    } else if (uri === '/charge-unsafe') {
      payload = runAttempts('unsafe', key, account, amount, attempts);
    } else if (uri === '/charge-idempotent') {
      payload = runAttempts('idempotent', key, account, amount, attempts);
    } else if (uri === '/idempotency/state') {
      payload = idempotencyState();
    } else if (uri === '/outbox') {
      payload = outboxView(limit);
    } else if (uri === '/diagnostics/summary') {
      payload = diagnostics();
    } else if (uri === '/reset-lab') {
      ledger = new Map();
      idempotency = new Map();
      outbox = [];
      delivered = [];
      metrics = initialMetrics();
      payload = { status: 'reset', message: 'Ledger, claves de idempotencia y outbox reiniciados.' };
    } else {
      status = 404;
      payload = { error: 'Ruta no encontrada', path: uri };
    }
  } catch (error) {
    status = 500;
    payload = { error: 'Fallo al procesar la solicitud', message: error.message, path: uri };
  }

  payload.timestamp_utc = nowIso();
  payload.pid = process.pid;
  sendJson(res, status, payload);
};

const PORT = Number.parseInt(process.env.PORT || '8080', 10);
http.createServer(handler).listen(PORT, '0.0.0.0', () => {
  console.log(`Servidor Node escuchando en ${PORT}`);
});
