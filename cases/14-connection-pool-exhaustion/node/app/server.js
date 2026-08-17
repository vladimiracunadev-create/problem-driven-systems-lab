'use strict';

/**
 * Caso 14 — Agotamiento del pool de conexiones — stack Node.js 22.
 *
 * Leaky: sin deadline de adquisicion y con la devolucion solo en el camino
 * feliz. Cada excepcion se lleva una conexion que nunca vuelve al pool.
 * Managed: `AbortSignal.timeout` para el deadline y `finally` para la
 * devolucion garantizada.
 *
 * Lo que este stack aporta, y por que su modo de falla es el peor de los siete:
 *
 *   En Java o Go, un hilo bloqueado esperando una conexion sigue siendo un
 *   objeto que un thread dump muestra. En Node no hay hilo: el que espera es
 *   una **Promise que nadie va a resolver nunca**. No aparece en ningun stack
 *   trace, no consume CPU, no dispara ninguna alarma. El request simplemente
 *   no responde, y el cliente se queda colgado hasta su propio timeout.
 *
 *   Por eso `AbortSignal.timeout()` no es un lujo aca: es la unica forma de que
 *   la espera tenga un final observable. Una Promise sin deadline es un leak de
 *   memoria y un request perdido a la vez.
 *
 * El "query" es un `setTimeout` a proposito, al reves que en el caso 13. Una
 * conexion se retiene mientras se espera a la red, no mientras se quema CPU.
 */

const http = require('http');
const { URL } = require('url');
const { performance } = require('perf_hooks');

const APP_STACK = process.env.APP_STACK || 'Node.js 22';
const CASE_NAME = '14 - Agotamiento del pool de conexiones';

const ACQUIRE_TIMEOUT_MS = 200;
// Sin deadline, la variante leaky no terminaria nunca. El watchdog existe para
// que la demo termine — no es parte del arreglo, es lo que permite medirlo.
const LEAKY_WATCHDOG_MS = 2000;

class Pool {
  constructor(size) {
    this.size = size;
    this.free = Array.from({ length: size }, (_, i) => ({ id: i + 1, uses: 0 }));
    this.waiters = [];
    this.acquired = 0;
    this.released = 0;
    this.waitingPeak = 0;
  }

  /** Devuelve la conexion, o null si vencio el deadline. */
  acquire(timeoutMs) {
    if (this.free.length > 0) {
      const conn = this.free.pop();
      conn.uses += 1;
      this.acquired += 1;
      return Promise.resolve(conn);
    }

    return new Promise((resolve) => {
      const waiter = { resolve: null, done: false };
      // AbortSignal.timeout no necesita un clearTimeout manual: el runtime
      // libera el temporizador cuando la señal se aborta o se recolecta.
      const signal = AbortSignal.timeout(timeoutMs);
      const onAbort = () => {
        if (waiter.done) return;
        waiter.done = true;
        const i = this.waiters.indexOf(waiter);
        if (i >= 0) this.waiters.splice(i, 1);
        resolve(null);
      };
      signal.addEventListener('abort', onAbort, { once: true });
      waiter.resolve = (conn) => {
        if (waiter.done) return false;
        waiter.done = true;
        signal.removeEventListener('abort', onAbort);
        conn.uses += 1;
        this.acquired += 1;
        resolve(conn);
        return true;
      };
      this.waiters.push(waiter);
      this.waitingPeak = Math.max(this.waitingPeak, this.waiters.length);
    });
  }

  release(conn) {
    if (!conn) return;
    this.released += 1;
    while (this.waiters.length > 0) {
      const waiter = this.waiters.shift();
      if (waiter.resolve(conn)) return; // se lo lleva el que esperaba
    }
    this.free.push(conn);
  }

  get available() {
    return this.free.length;
  }

  get leaked() {
    return this.acquired - this.released;
  }
}

let pool = new Pool(4);

const slot = () => ({
  runs: 0,
  completed: 0,
  failedQuery: 0,
  failedTimeout: 0,
  hung: 0,
  leaked: 0,
  waitSamplesMs: [],
});
const initialMetrics = () => ({ leaky: slot(), managed: slot() });
let metrics = initialMetrics();

/**
 * Reparto determinista de fallos.
 *
 * `idx % 100 < failRate` parece equivalente y no lo es: con 24 requests y
 * failRate=25 fallarian las 24, porque todos los indices son menores que 25.
 */
const fails = (idx, failRate) => (idx * 37) % 100 < failRate;

/** El trabajo que retiene la conexion: una espera, no CPU. */
const runQuery = (conn, queryMs, shouldFail) =>
  new Promise((resolve, reject) => {
    setTimeout(() => {
      if (shouldFail) reject(new Error(`query fallo en la conexion ${conn.id}`));
      else resolve(conn.id);
    }, queryMs);
  });

// ---------------------------------------------------------------------------
// Variante leaky
// ---------------------------------------------------------------------------

async function workerLeaky(idx, queryMs, failRate) {
  const started = performance.now();
  const conn = await pool.acquire(LEAKY_WATCHDOG_MS);
  const waitMs = performance.now() - started;
  if (!conn) return { outcome: 'hung', waitMs };

  // El bug: no hay finally. Si runQuery rechaza, la linea de release nunca se
  // ejecuta y la conexion se pierde. Nada en los logs dice "se fugo una
  // conexion" — el pool simplemente se achica en silencio.
  try {
    await runQuery(conn, queryMs, fails(idx, failRate));
  } catch {
    return { outcome: 'failed_query', waitMs };
  }
  pool.release(conn);
  return { outcome: 'completed', waitMs };
}

// ---------------------------------------------------------------------------
// Variante managed
// ---------------------------------------------------------------------------

async function workerManaged(idx, queryMs, failRate) {
  const started = performance.now();
  const conn = await pool.acquire(ACQUIRE_TIMEOUT_MS);
  const waitMs = performance.now() - started;
  if (!conn) {
    // Falla rapido y de forma observable, en vez de dejar una Promise que
    // nadie va a resolver nunca.
    return { outcome: 'failed_timeout', waitMs };
  }

  try {
    await runQuery(conn, queryMs, fails(idx, failRate));
    return { outcome: 'completed', waitMs };
  } catch {
    return { outcome: 'failed_query', waitMs };
  } finally {
    // Corre en los tres caminos: exito, excepcion y return temprano.
    pool.release(conn);
  }
}

// ---------------------------------------------------------------------------
// Ley de Little
// ---------------------------------------------------------------------------

function littlesLaw(requests, queryMs, wallMs) {
  if (wallMs <= 0) {
    return { avg_throughput_rps: 0, avg_query_ms: queryMs, recommended_pool_size: 1 };
  }
  const rps = requests / (wallMs / 1000);
  return {
    avg_throughput_rps: Number(rps.toFixed(2)),
    avg_query_ms: queryMs,
    recommended_pool_size: Math.max(1, Math.ceil(rps * (queryMs / 1000)) + 2),
    formula: 'ceil(throughput_rps * query_s) + 2 de buffer',
  };
}

function percentile(values, pct) {
  if (!values.length) return 0;
  const sorted = [...values].sort((a, b) => a - b);
  const idx = Math.max(0, Math.min(sorted.length - 1, Math.ceil((pct / 100) * sorted.length) - 1));
  return Number(sorted[idx].toFixed(2));
}

// ---------------------------------------------------------------------------
// Orquestacion
// ---------------------------------------------------------------------------

async function runLoad(variant, requests, poolSize, queryMs, failRate) {
  pool = new Pool(poolSize);
  const worker = variant === 'leaky' ? workerLeaky : workerManaged;
  const started = performance.now();
  const results = await Promise.all(
    Array.from({ length: requests }, (_, i) => worker(i, queryMs, failRate))
  );
  const wallMs = performance.now() - started;

  const counts = { completed: 0, failed_query: 0, failed_timeout: 0, hung: 0 };
  for (const r of results) counts[r.outcome] = (counts[r.outcome] || 0) + 1;
  const waits = results.map((r) => r.waitMs);

  const s = metrics[variant];
  s.runs += 1;
  s.completed += counts.completed;
  s.failedQuery += counts.failed_query;
  s.failedTimeout += counts.failed_timeout;
  s.hung += counts.hung;
  s.leaked = Math.max(s.leaked, pool.leaked);
  s.waitSamplesMs.push(...waits.map((w) => Number(w.toFixed(2))));
  if (s.waitSamplesMs.length > 500) s.waitSamplesMs = s.waitSamplesMs.slice(-500);

  return {
    variant,
    requests,
    pool_size: poolSize,
    query_ms: queryMs,
    fail_rate_pct: failRate,
    acquire_timeout_ms: variant === 'managed' ? ACQUIRE_TIMEOUT_MS : null,
    completed: counts.completed,
    failed_query: counts.failed_query,
    failed_timeout: counts.failed_timeout,
    hung: counts.hung,
    acquired: pool.acquired,
    released: pool.released,
    leaked: pool.leaked,
    pool_available_after: pool.available,
    pool_waiting_peak: pool.waitingPeak,
    pool_wait_ms_p99: percentile(waits, 99),
    pool_wait_ms_max: waits.length ? Number(Math.max(...waits).toFixed(2)) : 0,
    wall_ms: Number(wallMs.toFixed(2)),
    littles_law: littlesLaw(requests, queryMs, wallMs),
    note:
      variant === 'leaky'
        ? 'Sin deadline y con release solo en el camino feliz: cada excepcion se lleva una conexion y los que esperan son Promises que nadie va a resolver.'
        : 'AbortSignal.timeout para el deadline + finally para la devolucion: los fallos siguen ocurriendo, pero fallan rapido y devuelven la conexion.',
  };
}

function poolState() {
  return {
    initialized: true,
    pool_size: pool.size,
    available: pool.available,
    acquired_total: pool.acquired,
    released_total: pool.released,
    leaked: pool.leaked,
    waiting_now: pool.waiters.length,
    waiting_peak: pool.waitingPeak,
    acquire_timeout_ms: ACQUIRE_TIMEOUT_MS,
    leaky_watchdog_ms: LEAKY_WATCHDOG_MS,
  };
}

function diagnostics() {
  const variants = {};
  for (const name of ['leaky', 'managed']) {
    const s = metrics[name];
    const samples = s.waitSamplesMs;
    variants[name] = {
      runs: s.runs,
      completed: s.completed,
      failed_query: s.failedQuery,
      failed_timeout: s.failedTimeout,
      hung: s.hung,
      max_leaked: s.leaked,
      avg_wait_ms: samples.length
        ? Number((samples.reduce((a, b) => a + b, 0) / samples.length).toFixed(2))
        : 0,
      p99_wait_ms: percentile(samples, 99),
    };
  }
  return {
    stack: APP_STACK,
    case: CASE_NAME,
    variants,
    pool: poolState(),
    interpretation: {
      leaky: 'leaked > 0 y hung > 0: las conexiones perdidas en el camino de excepcion no vuelven, y lo que llega despues espera a algo que ya no existe.',
      managed: 'leaked = 0 siempre. Los fallos de query se siguen contando, pero la conexion vuelve al pool y el que no alcanza recibe un timeout rapido.',
      node_note: 'El que espera no es un hilo sino una Promise. Sin AbortSignal.timeout no aparece en ningun stack trace ni consume CPU: el request simplemente no responde nunca.',
    },
  };
}

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

  const requests = clampInt(url.searchParams.get('requests'), 24, 1, 200);
  const poolSize = clampInt(url.searchParams.get('pool'), 4, 1, 64);
  const queryMs = clampInt(url.searchParams.get('query_ms'), 25, 1, 500);
  const failRate = clampInt(url.searchParams.get('fail_rate'), 25, 0, 100);

  try {
    if (uri === '/' || uri === '/index') {
      payload = {
        lab: 'Problem-Driven Systems Lab',
        case: CASE_NAME,
        stack: APP_STACK,
        goal: 'Mostrar como un pool chico sin deadline de adquisicion y con fugas en el camino de excepcion deja de dar conexiones para siempre.',
        node_specific:
          'El que espera es una Promise, no un hilo: sin AbortSignal.timeout no hay stack trace, no hay CPU y el request no responde nunca.',
        routes: {
          '/health': 'Estado basico del servicio.',
          '/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25': 'Sin deadline y con fuga en excepciones.',
          '/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25': 'Con deadline de adquisicion y devolucion garantizada.',
          '/pool/state': 'Tamaño, disponibles, adquiridas, devueltas y fugadas.',
          '/diagnostics/summary': 'Comparativa entre variantes + ley de Little.',
          '/reset-lab': 'Reconstruye el pool y limpia contadores.',
        },
      };
    } else if (uri === '/health') {
      payload = { status: 'ok', stack: APP_STACK, case: CASE_NAME };
    } else if (uri === '/pool-leaky') {
      payload = await runLoad('leaky', requests, poolSize, queryMs, failRate);
    } else if (uri === '/pool-managed') {
      payload = await runLoad('managed', requests, poolSize, queryMs, failRate);
    } else if (uri === '/pool/state') {
      payload = poolState();
    } else if (uri === '/diagnostics/summary') {
      payload = diagnostics();
    } else if (uri === '/reset-lab') {
      pool = new Pool(poolSize);
      metrics = initialMetrics();
      payload = { status: 'reset', message: 'Pool reconstruido y metricas reiniciadas.' };
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
