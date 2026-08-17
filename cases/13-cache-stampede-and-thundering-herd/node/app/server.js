'use strict';

/**
 * Caso 13 — Cache stampede (thundering herd) — stack Node.js 22.
 *
 * Naive: la clave expira y los N llamadores concurrentes entran todos al camino
 * de recomputo. `origin_computations === concurrency`.
 * Single-flight: `origin_computations === 1` sin importar cuantos lleguen.
 *
 * Primitiva Node distintiva:
 *   Un `Map<key, Promise>`. La Promise **es** el single-flight: no hace falta
 *   lock ni Event porque una Promise ya representa "un resultado que todavia no
 *   esta, al que cualquiera puede suscribirse". El lider la guarda en el Map
 *   antes de empezar; los siguientes hacen `await` sobre la MISMA Promise.
 *
 *   Es la version mas corta del patron en todo el lab — tres lineas — y por eso
 *   tambien la mas facil de escribir mal: si el `Map.set` ocurre DESPUES del
 *   primer `await`, la ventana entre ambos deja pasar la estampida entera.
 *
 * Dos honestidades sobre este stack:
 *
 *   1. El origen es CPU real (digest iterativo), no `setTimeout`. Con un timer
 *      el event loop absorbe N esperas sin costo y el caso no probaria nada: lo
 *      que duele en una estampida real es que el origen HACE el trabajo N veces.
 *
 *   2. `stampede_depth` mide cuantos llamadores estaban a la vez DENTRO del
 *      camino de recomputo, no cuantos nucleos ardian. Node tiene un solo hilo:
 *      los N digests se ejecutan en fila, no en paralelo. El dano no es
 *      contencion de CPU sino que el event loop queda bloqueado N veces mas
 *      tiempo — y con el, todo lo demas que el proceso tenia que atender.
 */

const http = require('http');
const { URL } = require('url');
const { performance } = require('perf_hooks');

const APP_STACK = process.env.APP_STACK || 'Node.js 22';
const CASE_NAME = '13 - Cache stampede y thundering herd';

const TTL_BASE_MS = 4000;
const JITTER_PCT = 25;
const SOFT_FRACTION = 0.6;

/** key -> { value, computedAt, softMs, hardMs } */
const cache = new Map();
/** key -> Promise<string>  — el single-flight entero vive aca. */
const inflight = new Map();

let originActive = 0;
let originPeak = 0;

function slot() {
  return {
    runs: 0,
    originComputations: 0,
    cacheHits: 0,
    coalescedWaiters: 0,
    servedStale: 0,
    maxStampedeDepth: 0,
    wallSamplesMs: [],
  };
}

const initialMetrics = () => ({ naive: slot(), singleflight: slot(), originTotal: 0 });

let metrics = initialMetrics();

// ---------------------------------------------------------------------------
// Origen: trabajo real, no un timer
// ---------------------------------------------------------------------------

function digestWork(key, rounds) {
  let h = 0;
  const salt = key.length || 1;
  const iterations = rounds * 2000;
  for (let i = 0; i < iterations; i += 1) {
    h = (Math.imul(h, 31) + (i ^ salt)) >>> 0;
  }
  return h.toString(16).padStart(8, '0');
}

// El `setImmediate` cede el turno al event loop: sin el, el primer llamador
// terminaria su digest completo antes de que el segundo llegue siquiera a mirar
// la cache, y la estampida quedaria escondida detras del modelo de ejecucion.
const yieldToLoop = () => new Promise((resolve) => setImmediate(resolve));

/** Recalculo del origen instrumentado: cuenta cuantos llamadores coinciden. */
async function computeOrigin(key, rounds) {
  originActive += 1;
  originPeak = Math.max(originPeak, originActive);
  try {
    await yieldToLoop();
    const digest = digestWork(key, rounds);
    cacheStore(key, digest);
    return digest;
  } finally {
    originActive -= 1;
  }
}

/**
 * Double check dentro del vuelo. Devuelve si REALMENTE hubo que recalcular.
 *
 * Sin esto el single-flight funciona pero no alcanza: el lider de la primera
 * generacion termina, borra su entrada del Map, y los llamadores que todavia
 * no habian llegado al `get` se vuelven lideres de una segunda generacion. Con
 * `cost` chico eso da 3 o 4 recalculos en vez de 1 — no por un bug del patron
 * sino porque falta este `if`.
 */
async function computeOriginIfNeeded(key, rounds) {
  if (cacheLookup(key).state === 'fresh') return false;
  await computeOrigin(key, rounds);
  return true;
}

function ttlWithJitter() {
  const spread = Math.floor((TTL_BASE_MS * JITTER_PCT) / 100);
  const jitter = Math.floor(Math.random() * (spread * 2 + 1)) - spread;
  const hard = TTL_BASE_MS + jitter;
  return { hard, soft: Math.floor(hard * SOFT_FRACTION), jitter };
}

function cacheLookup(key) {
  const entry = cache.get(key);
  if (!entry) return { value: null, state: 'miss' };
  const age = performance.now() - entry.computedAt;
  if (age <= entry.softMs) return { value: entry.value, state: 'fresh' };
  if (age <= entry.hardMs) return { value: entry.value, state: 'stale' };
  return { value: null, state: 'miss' };
}

function cacheStore(key, value) {
  const { hard, soft } = ttlWithJitter();
  cache.set(key, { value, computedAt: performance.now(), softMs: soft, hardMs: hard });
}

// ---------------------------------------------------------------------------
// Variante naive: cada llamador recalcula
// ---------------------------------------------------------------------------

async function callerNaive(key, rounds) {
  const started = performance.now();
  const { state } = cacheLookup(key);
  if (state === 'fresh') {
    return { waitMs: performance.now() - started, computed: false, stale: false, waited: false };
  }
  await computeOrigin(key, rounds);
  return { waitMs: performance.now() - started, computed: true, stale: false, waited: false };
}

// ---------------------------------------------------------------------------
// Variante single-flight: Map<key, Promise>
// ---------------------------------------------------------------------------

async function callerSingleflight(key, rounds) {
  const started = performance.now();
  const { state } = cacheLookup(key);
  if (state === 'fresh') {
    return { waitMs: performance.now() - started, computed: false, stale: false, waited: false };
  }

  const existing = inflight.get(key);
  if (existing) {
    // Soft TTL vencida pero dentro de la hard: se sirve el valor viejo sin
    // esperar al refresh. El llamador no paga la latencia del origen.
    if (state === 'stale') {
      return { waitMs: performance.now() - started, computed: false, stale: true, waited: false };
    }
    await existing;
    return { waitMs: performance.now() - started, computed: false, stale: false, waited: true };
  }

  // Orden critico: la Promise entra al Map ANTES del primer await de
  // computeOrigin. Invertirlo abre la ventana por la que se cuela la estampida.
  const flight = computeOriginIfNeeded(key, rounds);
  inflight.set(key, flight);
  let didCompute = false;
  try {
    didCompute = await flight;
  } finally {
    inflight.delete(key);
  }
  return {
    waitMs: performance.now() - started,
    computed: didCompute,
    stale: false,
    waited: !didCompute,
  };
}

// ---------------------------------------------------------------------------
// Orquestacion de la rafaga
// ---------------------------------------------------------------------------

async function runBurst(variant, key, concurrency, rounds) {
  const worker = variant === 'naive' ? callerNaive : callerSingleflight;
  originPeak = 0;
  const started = performance.now();
  const results = await Promise.all(
    Array.from({ length: concurrency }, () => worker(key, rounds))
  );
  const wallMs = performance.now() - started;

  const computations = results.filter((r) => r.computed).length;
  const stale = results.filter((r) => r.stale).length;
  const waiters = results.filter((r) => r.waited).length;
  const hits = results.length - computations - stale - waiters;
  const waits = results.map((r) => r.waitMs).sort((a, b) => a - b);

  const s = metrics[variant];
  s.runs += 1;
  s.originComputations += computations;
  s.cacheHits += hits;
  s.coalescedWaiters += waiters;
  s.servedStale += stale;
  s.maxStampedeDepth = Math.max(s.maxStampedeDepth, originPeak);
  s.wallSamplesMs.push(Number(wallMs.toFixed(2)));
  if (s.wallSamplesMs.length > 200) s.wallSamplesMs = s.wallSamplesMs.slice(-200);
  metrics.originTotal += computations;

  return {
    variant,
    key,
    concurrency,
    cost_rounds: rounds,
    origin_computations: computations,
    cache_hits: hits,
    coalesced_waiters: waiters,
    served_stale: stale,
    stampede_depth: originPeak,
    wall_ms: Number(wallMs.toFixed(2)),
    p99_wait_ms: percentile(waits, 99),
    max_wait_ms: waits.length ? Number(waits[waits.length - 1].toFixed(2)) : 0,
    value_digest: cacheLookup(key).value,
    ttl_base_ms: TTL_BASE_MS,
    jitter_pct: JITTER_PCT,
    note:
      variant === 'naive'
        ? 'Sin coordinacion: cada llamador que ve el miss recalcula. El origen recibe la rafaga entera.'
        : 'Map<key, Promise>: el lider calcula, el resto await sobre la misma Promise o recibe el valor stale.',
  };
}

function percentile(values, pct) {
  if (!values.length) return 0;
  const sorted = [...values].sort((a, b) => a - b);
  const idx = Math.max(0, Math.min(sorted.length - 1, Math.ceil((pct / 100) * sorted.length) - 1));
  return Number(sorted[idx].toFixed(2));
}

function cacheState() {
  const entries = {};
  for (const [key, entry] of cache.entries()) {
    const age = performance.now() - entry.computedAt;
    entries[key] = {
      age_ms: Number(age.toFixed(2)),
      soft_ttl_ms: entry.softMs,
      hard_ttl_ms: entry.hardMs,
      soft_expired: age > entry.softMs,
      hard_expired: age > entry.hardMs,
      value_digest: entry.value,
    };
  }
  return {
    entries,
    ttl_base_ms: TTL_BASE_MS,
    jitter_pct: JITTER_PCT,
    soft_fraction: SOFT_FRACTION,
    inflight_keys: [...inflight.keys()].sort(),
  };
}

function diagnostics() {
  const variants = {};
  for (const name of ['naive', 'singleflight']) {
    const s = metrics[name];
    const samples = s.wallSamplesMs;
    variants[name] = {
      runs: s.runs,
      origin_computations: s.originComputations,
      cache_hits: s.cacheHits,
      coalesced_waiters: s.coalescedWaiters,
      served_stale: s.servedStale,
      max_stampede_depth: s.maxStampedeDepth,
      avg_wall_ms: samples.length
        ? Number((samples.reduce((a, b) => a + b, 0) / samples.length).toFixed(2))
        : 0,
      p99_wall_ms: percentile(samples, 99),
    };
  }
  return {
    stack: APP_STACK,
    case: CASE_NAME,
    variants,
    origin_total_computations: metrics.originTotal,
    interpretation: {
      naive: 'origin_computations crece linealmente con la concurrencia: el origen ve la rafaga completa.',
      singleflight: 'origin_computations se mantiene en 1 por expiracion, sin importar cuantos llamadores lleguen.',
      node_note: 'stampede_depth cuenta llamadores simultaneos en el camino de recomputo, no nucleos: Node los ejecuta en fila y bloquea el event loop N veces mas tiempo.',
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

  const key = (url.searchParams.get('key') || 'report-alpha').slice(0, 60);
  const concurrency = clampInt(url.searchParams.get('concurrency'), 16, 1, 128);
  const rounds = clampInt(url.searchParams.get('cost'), 40, 1, 400);

  try {
    if (uri === '/' || uri === '/index') {
      payload = {
        lab: 'Problem-Driven Systems Lab',
        case: CASE_NAME,
        stack: APP_STACK,
        goal: 'Mostrar cuantas veces pega el origen cuando una clave caliente expira con N llamadores encima.',
        node_specific:
          'Map<key, Promise>: la Promise ya ES el single-flight. El orden importa — se guarda en el Map antes del primer await.',
        routes: {
          '/health': 'Estado basico del servicio.',
          '/cache-naive?key=report-alpha&concurrency=16&cost=40': 'Rafaga sin single-flight.',
          '/cache-singleflight?key=report-alpha&concurrency=16&cost=40': 'Misma rafaga con single-flight, jitter y soft TTL.',
          '/cache/state': 'Edad, soft/hard TTL y claves en vuelo.',
          '/diagnostics/summary': 'Comparativa de origin_computations entre variantes.',
          '/reset-lab': 'Vacia cache y contadores.',
        },
      };
    } else if (uri === '/health') {
      payload = { status: 'ok', stack: APP_STACK, case: CASE_NAME };
    } else if (uri === '/cache-naive') {
      payload = await runBurst('naive', key, concurrency, rounds);
    } else if (uri === '/cache-singleflight') {
      payload = await runBurst('singleflight', key, concurrency, rounds);
    } else if (uri === '/cache/state') {
      payload = cacheState();
    } else if (uri === '/diagnostics/summary') {
      payload = diagnostics();
    } else if (uri === '/reset-lab') {
      cache.clear();
      inflight.clear();
      metrics = initialMetrics();
      payload = { status: 'reset', message: 'Cache y metricas reiniciadas.' };
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
