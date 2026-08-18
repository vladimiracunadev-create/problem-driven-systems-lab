'use strict';

/**
 * Caso 17 — Migracion de esquema sin downtime — stack Node.js 22.
 *
 * Blocking: la migracion se ejecuta de corrido y bloquea el event loop. Nada
 * mas se atiende hasta que termina.
 * Expand-contract: cuatro fases, y entre lote y lote se cede el turno al loop.
 *
 * Primitiva Node distintiva — y es, otra vez, una ausencia:
 *
 *   **Node no tiene locks porque no tiene hilos.** No hay `RWMutex`, no hay
 *   `ReaderWriterLockSlim`, no hay nada que adquirir. Y sin embargo este caso
 *   ocurre igual — de la forma mas literal de las siete.
 *
 *   El "lock exclusivo" en Node **es el event loop**. Un bucle sincronico que
 *   tarda 400 ms no bloquea una tabla: bloquea el proceso entero. Ningun
 *   request se atiende, ningun timer dispara, ningun socket se lee. La
 *   migracion no compite con los lectores por un recurso — se los come.
 *
 *   Por eso la variante blocking de este archivo usa un bucle sincronico de
 *   verdad (`Atomics.wait` sobre un SharedArrayBuffer, la unica forma de dormir
 *   sin ceder el turno) y la corregida usa `setTimeout`, que sí lo cede.
 *
 *   La consecuencia practica es dura: **en Node, `await` entre lotes no es una
 *   optimizacion, es el unico mecanismo de equidad que existe.** El lector no
 *   tiene deadline que lo salve, porque el timeout tampoco puede dispararse
 *   mientras el loop este tomado.
 *
 * El tiempo de migracion se modela con espera, no con CPU: un ALTER TABLE se
 * demora esperando I/O del motor.
 */

const http = require('http');
const { URL } = require('url');
const { performance } = require('perf_hooks');

const APP_STACK = process.env.APP_STACK || 'Node.js 22';
const CASE_NAME = '17 - Migracion de esquema sin downtime';

const READ_TIMEOUT_MS = 120;

let table = { rows: 20000, hasNewColumn: false, backfilled: 0, oldColumnDropped: false };
let readFromNewColumn = false;
let phase = 'idle';

const slot = () => ({
  runs: 0,
  lockHeldMs: 0,
  readersServed: 0,
  readersFailed: 0,
  maxReadWaitMs: 0,
  backfillBatches: 0,
});
const initialMetrics = () => ({ blocking: slot(), expand_contract: slot() });
let metrics = initialMetrics();

const resetTable = (rows) => {
  table = { rows, hasNewColumn: false, backfilled: 0, oldColumnDropped: false };
  readFromNewColumn = false;
  phase = 'idle';
};

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Espera SIN ceder el event loop — la unica forma de bloquearlo de verdad.
 *
 * `Atomics.wait` sobre un SharedArrayBuffer duerme el hilo entero. Es lo que
 * hace un `ALTER TABLE` sincronico en la practica: durante ese tiempo, el
 * proceso no atiende nada.
 */
const shared = new Int32Array(new SharedArrayBuffer(4));
const blockLoop = (ms) => {
  if (ms <= 0) return;
  Atomics.wait(shared, 0, 0, ms);
};

/**
 * Los lectores.
 *
 * En Node no piden un lock: piden un turno del event loop. Si el loop esta
 * tomado, no hay deadline que los salve — su propio timeout tampoco puede
 * dispararse. Por eso el "fallo" se mide como turno que llego demasiado tarde.
 */
async function reader(stopAt, stats) {
  while (performance.now() < stopAt) {
    const t0 = performance.now();
    await sleep(0);                       // pedir un turno
    const waited = performance.now() - t0;
    stats.waits.push(waited);
    if (waited > READ_TIMEOUT_MS) stats.failed += 1;
    else stats.served += 1;
    await sleep(2);
  }
}

// ---------------------------------------------------------------------------
// Variante blocking: el event loop tomado de punta a punta
// ---------------------------------------------------------------------------

function migrateBlocking(rows, msPer1k) {
  resetTable(rows);
  phase = 'expand';
  const durationMs = (rows / 1000) * msPer1k;

  const t0 = performance.now();
  // Sin ceder el turno ni una vez: nada mas corre durante todo esto.
  blockLoop(durationMs);
  table.hasNewColumn = true;
  table.backfilled = rows;
  table.oldColumnDropped = true;
  readFromNewColumn = true;
  const held = performance.now() - t0;
  phase = 'done';
  return { held, batches: 1 };
}

// ---------------------------------------------------------------------------
// Variante expand-contract: se cede el turno entre lotes
// ---------------------------------------------------------------------------

async function migrateExpandContract(rows, msPer1k, batchSize, pauseMs) {
  resetTable(rows);
  const totalMs = (rows / 1000) * msPer1k;
  let held = 0;
  let batches = 0;

  // 1. EXPAND — columna nullable: metadata, instantaneo.
  phase = 'expand';
  let t0 = performance.now();
  table.hasNewColumn = true;
  held += performance.now() - t0;

  // 2. BACKFILL — por lotes. Cada lote bloquea el loop lo que dura EL LOTE, y
  // entre lotes se cede el turno con setTimeout.
  phase = 'backfill';
  let done = 0;
  const perBatchMs = totalMs * (batchSize / Math.max(1, rows));
  while (done < rows) {
    const chunk = Math.min(batchSize, rows - done);
    t0 = performance.now();
    blockLoop(perBatchMs);
    table.backfilled += chunk;
    held += performance.now() - t0;
    done += chunk;
    batches += 1;
    // El await es el mecanismo de equidad: sin el, esto es la variante blocking
    // escrita en pedazos.
    await sleep(pauseMs);
  }

  // 3. SWITCH — feature flag. No toca datos: reversible en un segundo.
  phase = 'switch';
  readFromNewColumn = true;

  // 4. CONTRACT — recien ahora se borra la vieja.
  phase = 'contract';
  t0 = performance.now();
  table.oldColumnDropped = true;
  held += performance.now() - t0;
  phase = 'done';
  return { held, batches };
}

// ---------------------------------------------------------------------------
// Orquestacion
// ---------------------------------------------------------------------------

async function runMigration(variant, rows, readers, msPer1k, batchSize, pauseMs) {
  const budgetMs = (rows / 1000) * msPer1k + (rows / Math.max(1, batchSize)) * pauseMs + 400;
  const started = performance.now();
  const stopAt = started + budgetMs;

  const stats = Array.from({ length: readers }, () => ({ served: 0, failed: 0, waits: [] }));
  const readerTasks = stats.map((s) => reader(stopAt, s));

  const result = variant === 'blocking'
    ? migrateBlocking(rows, msPer1k)
    : await migrateExpandContract(rows, msPer1k, batchSize, pauseMs);
  const migrationMs = performance.now() - started;

  await Promise.all(readerTasks);
  const wallMs = performance.now() - started;

  const served = stats.reduce((a, s) => a + s.served, 0);
  const failed = stats.reduce((a, s) => a + s.failed, 0);
  const waits = stats.flatMap((s) => s.waits).sort((a, b) => a - b);
  const maxWait = waits.length ? waits[waits.length - 1] : 0;

  const m = metrics[variant];
  m.runs += 1;
  m.lockHeldMs += result.held;
  m.readersServed += served;
  m.readersFailed += failed;
  m.maxReadWaitMs = Math.max(m.maxReadWaitMs, maxWait);
  m.backfillBatches += result.batches;

  return {
    variant,
    rows_total: table.rows,
    readers,
    phase,
    lock_held_ms: Number(result.held.toFixed(2)),
    longest_single_lock_ms: Number(
      (variant === 'blocking' ? result.held : result.held / Math.max(1, result.batches)).toFixed(2)
    ),
    readers_served: served,
    readers_failed: failed,
    availability_pct: Number((served * 100 / Math.max(1, served + failed)).toFixed(2)),
    p99_read_wait_ms: percentile(waits, 99),
    max_read_wait_ms: Number(maxWait.toFixed(2)),
    read_timeout_ms: READ_TIMEOUT_MS,
    backfill_batches: result.batches,
    backfill_progress_pct: Number((table.backfilled * 100 / Math.max(1, table.rows)).toFixed(2)),
    migration_ms: Number(migrationMs.toFixed(2)),
    wall_ms: Number(wallMs.toFixed(2)),
    note:
      variant === 'blocking'
        ? 'El event loop tomado de punta a punta: ningun request se atiende, ningun timer dispara. En Node el lock exclusivo no bloquea una tabla — bloquea el proceso entero.'
        : 'Cada lote bloquea el loop lo que dura el lote, y entre lotes se cede el turno con await. En Node el await no es una optimizacion: es el unico mecanismo de equidad que existe.',
  };
}

function percentile(sorted, pct) {
  if (!sorted.length) return 0;
  const idx = Math.max(0, Math.min(sorted.length - 1, Math.ceil((pct / 100) * sorted.length) - 1));
  return Number(sorted[idx].toFixed(2));
}

const migrationState = () => ({
  phase,
  phases: ['idle', 'expand', 'backfill', 'switch', 'contract', 'done'],
  rows_total: table.rows,
  has_new_column: table.hasNewColumn,
  backfilled: table.backfilled,
  backfill_progress_pct: Number((table.backfilled * 100 / Math.max(1, table.rows)).toFixed(2)),
  old_column_dropped: table.oldColumnDropped,
  read_from_new_column: readFromNewColumn,
  read_timeout_ms: READ_TIMEOUT_MS,
  note: 'El feature flag read_from_new_column es lo unico reversible en un segundo. Por eso el switch va antes del contract, y no al reves.',
});

function backfillStep(batchSize, msPer1k) {
  if (!table.hasNewColumn) {
    return { status: 'skipped', reason: 'la columna nueva todavia no existe: falta la fase expand' };
  }
  if (table.backfilled >= table.rows) {
    return { status: 'complete', backfilled: table.backfilled, rows_total: table.rows };
  }
  const chunk = Math.min(batchSize, table.rows - table.backfilled);
  const t0 = performance.now();
  blockLoop((table.rows / 1000) * msPer1k * (chunk / Math.max(1, table.rows)));
  table.backfilled += chunk;
  return {
    status: 'batch_done',
    batch_size: chunk,
    lock_held_ms: Number((performance.now() - t0).toFixed(2)),
    backfilled: table.backfilled,
    rows_total: table.rows,
    backfill_progress_pct: Number((table.backfilled * 100 / Math.max(1, table.rows)).toFixed(2)),
  };
}

const diagnostics = () => {
  const variants = {};
  for (const [name, s] of Object.entries(metrics)) {
    variants[name] = {
      runs: s.runs,
      lock_held_ms: Number(s.lockHeldMs.toFixed(2)),
      readers_served: s.readersServed,
      readers_failed: s.readersFailed,
      max_read_wait_ms: Number(s.maxReadWaitMs.toFixed(2)),
      backfill_batches: s.backfillBatches,
    };
  }
  return {
    stack: APP_STACK,
    case: CASE_NAME,
    variants,
    migration: migrationState(),
    interpretation: {
      blocking:
        'readers_failed > 0 y max_read_wait_ms = la duracion entera de la migracion: la app estuvo caida todo ese tiempo aunque el proceso siguiera vivo.',
      expand_contract:
        'readers_failed = 0 y max_read_wait_ms = lo que dura UN lote. El trabajo total es el mismo; lo que cambia es como se reparte.',
      node_note:
        'Node no tiene locks porque no tiene hilos — y el caso ocurre igual, de la forma mas literal: el lock exclusivo ES el event loop. Un bucle sincronico de 400 ms no bloquea una tabla, bloquea el proceso entero, y ni siquiera los timeouts de los lectores pueden dispararse.',
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

  const rows = clampInt(url.searchParams.get('rows'), 20000, 1000, 500000);
  const readers = clampInt(url.searchParams.get('readers'), 8, 1, 64);
  const msPer1k = clampInt(url.searchParams.get('ms_per_1k'), 20, 1, 200);
  const batch = clampInt(url.searchParams.get('batch'), 2000, 100, 100000);
  const pauseMs = clampInt(url.searchParams.get('pause_ms'), 5, 0, 200);

  try {
    if (uri === '/' || uri === '/index') {
      payload = {
        lab: 'Problem-Driven Systems Lab',
        case: CASE_NAME,
        stack: APP_STACK,
        goal: 'Mostrar que el trabajo total de una migracion es el mismo; lo que cambia es si se cobra todo junto con la app caida o repartido en lotes que nadie nota.',
        node_specific:
          'Node no tiene locks porque no tiene hilos: el lock exclusivo ES el event loop, y el await entre lotes es el unico mecanismo de equidad.',
        routes: {
          '/health': 'Estado basico del servicio.',
          '/migrate-blocking?rows=20000&readers=8': 'El event loop tomado de punta a punta.',
          '/migrate-expand-contract?rows=20000&readers=8&batch=2000&pause_ms=5': 'Cuatro fases, cediendo el turno entre lotes.',
          '/migration/state': 'Fase actual, progreso del backfill y estado del feature flag.',
          '/backfill?batch=2000': 'Un lote suelto.',
          '/diagnostics/summary': 'Comparativa entre variantes.',
          '/reset-lab': 'Vuelve la tabla al esquema viejo.',
        },
      };
    } else if (uri === '/health') {
      payload = { status: 'ok', stack: APP_STACK, case: CASE_NAME };
    } else if (uri === '/migrate-blocking') {
      payload = await runMigration('blocking', rows, readers, msPer1k, batch, pauseMs);
    } else if (uri === '/migrate-expand-contract') {
      payload = await runMigration('expand_contract', rows, readers, msPer1k, batch, pauseMs);
    } else if (uri === '/migration/state') {
      payload = migrationState();
    } else if (uri === '/backfill') {
      payload = backfillStep(batch, msPer1k);
    } else if (uri === '/diagnostics/summary') {
      payload = diagnostics();
    } else if (uri === '/reset-lab') {
      resetTable(rows);
      metrics = initialMetrics();
      payload = { status: 'reset', message: 'Tabla, fase y metricas reiniciadas.' };
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

resetTable(20000);
const PORT = Number.parseInt(process.env.PORT || '8080', 10);
http.createServer(handler).listen(PORT, '0.0.0.0', () => {
  console.log(`Servidor Node escuchando en ${PORT}`);
});
