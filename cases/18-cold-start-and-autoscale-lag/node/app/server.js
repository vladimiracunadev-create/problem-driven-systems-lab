/**
 * Caso 18 — Arranque en frio y retraso del autoescalado — stack Node.js 22.
 *
 * Frio: el autoescalador levanta instancias cuando el trafico ya subio. El
 * proceso queda vivo al instante y `/health` responde 200 — pero la instancia
 * no puede servir nada hasta terminar de inicializar. El balanceador que mira
 * liveness en vez de readiness manda trafico a ese hueco. Ahi nacen los 503.
 *
 * Templado: pool tibio ya inicializado y ya ejercitado, y balanceador que
 * enruta por `/ready`. Ninguna peticion cae en una instancia a medio levantar.
 *
 * Que es real y que esta modelado:
 *
 *   La curva de calentamiento se MIDE, no se simula. El trabajo por peticion es
 *   un lazo entero puro, identico en los siete stacks, sin sleep de ninguna
 *   clase. `p99_first_100_ms` contra `p99_after_1000_ms` es lo que V8 hace de
 *   verdad con el mismo codigo repetido.
 *
 *   La parte de I/O de la inicializacion (abrir el pool, DNS, TLS) es un sleep
 *   de `io_ms`: esperar a la red no quema CPU, y fijarlo hace comparables a los
 *   siete stacks. La parte de CPU —construir la tabla— es trabajo real.
 *
 * Primitiva Node distintiva:
 *
 *   V8 tiene JIT de verdad, y en capas: Ignition interpreta el bytecode,
 *   Sparkplug compila sin optimizar, Maglev y TurboFan optimizan segun el
 *   perfil de ejecucion. La misma funcion se vuelve mas rapida SOLO por
 *   repetirse — y si el tipo de un argumento cambia, TurboFan deoptimiza y
 *   vuelve a empezar. `warmup_speedup_x` mide ese efecto sin simularlo.
 *
 *   Pero el costo de arranque de Node no esta en el JIT: esta en el GRAFO DE
 *   `require`. Cada modulo se lee del disco, se parsea y se ejecuta. Un
 *   servicio con 800 dependencias transitivas tarda cientos de milisegundos
 *   antes de la primera linea de codigo propio. Node tiene una salida —
 *   `node --build-snapshot` y los SEA— pero no es el camino por defecto.
 */

const http = require('http');

const APP_STACK = process.env.APP_STACK || 'Node.js 22';
const CASE_NAME = '18 - Arranque en frio y retraso del autoescalado';

const WORK_ITERS = 150000;     // calibrado para ~0.3 ms por peticion en V8 caliente
const INIT_TABLE_ROWS = 400000; // parte de CPU de la inicializacion: trabajo real

const nowMs = () => Number(process.hrtime.bigint() / 1000n) / 1000;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Trabajo por peticion: lazo entero puro, sin sleep, sin I/O.
 * Identico en los siete stacks. Lo que cambia es lo que el runtime hace con el
 * mismo codigo repetido mil veces — que es lo que este caso mide.
 */
function work(iters) {
  let h = 2166136261;
  for (let i = 0; i < iters; i++) {
    h = Math.imul(h ^ i, 16777619) >>> 0;
  }
  return h;
}

class Instance {
  constructor(id) {
    this.id = id;
    this.live = true;      // el proceso arranco: /health responde 200 YA
    this.ready = false;    // todavia no: falta inicializar
    this.liveAt = nowMs();
    this.readyAt = null;
    this.served = 0;
    this.table = null;
  }

  async boot(ioMs) {
    // Parte de CPU: construir la tabla de configuracion. Trabajo de verdad.
    const table = new Uint32Array(256);
    let h = 2166136261;
    for (let i = 0; i < INIT_TABLE_ROWS; i++) {
      h = Math.imul(h ^ i, 16777619) >>> 0;
      table[h & 0xff] = h;
    }
    // Parte de I/O: abrir el pool, resolver DNS, negociar TLS.
    await sleep(ioMs);
    this.table = table;
    this.readyAt = nowMs();
    this.ready = true;
  }

  gapMs() {
    return Number(((this.readyAt === null ? nowMs() : this.readyAt) - this.liveAt).toFixed(2));
  }
}

let fleet = [];
let warmPool = [];
let metrics = initialMetrics();

function initialMetrics() {
  const slot = () => ({ runs: 0, served: 0, rejected_cold_start: 0, cold_starts: 0, max_ready_at_ms: 0 });
  return { cold: slot(), warmed: slot() };
}

function percentile(values, pct) {
  if (!values.length) return 0;
  const sv = [...values].sort((a, b) => a - b);
  const idx = Math.max(0, Math.min(sv.length - 1, Math.ceil((pct / 100) * sv.length) - 1));
  return Number(sv[idx].toFixed(3));
}

// ---------------------------------------------------------------------------
// El pool tibio: instancias ya inicializadas Y ya ejercitadas
// ---------------------------------------------------------------------------

async function buildWarmPool(instances, ioMs, prime, workIters) {
  const t0 = nowMs();
  const pool = Array.from({ length: instances }, (_, i) => new Instance(`warm-${i}`));
  await Promise.all(pool.map((inst) => inst.boot(ioMs)));
  const initMs = nowMs() - t0;

  // Ejercitar: cruzar el umbral de TurboFan. Esta mitad SI depende del runtime,
  // y en Node se nota: la funcion optimizada corre varias veces mas rapido que
  // la misma funcion recien interpretada.
  for (let i = 0; i < prime; i++) work(workIters);
  for (const inst of pool) inst.served += Math.floor(prime / Math.max(1, instances));

  warmPool = pool;
  return {
    warm_pool_size: pool.length,
    init_ms: Number(initMs.toFixed(2)),
    prime_requests: prime,
    warmup_duration_ms: Number((nowMs() - t0).toFixed(2)),
  };
}

// ---------------------------------------------------------------------------
// El balanceador: la diferencia entre mirar /health y mirar /ready
// ---------------------------------------------------------------------------

function pick(pool, byReadiness, counter) {
  const n = pool.length;
  for (let k = 0; k < n; k++) {
    const inst = pool[(counter + k) % n];
    if (byReadiness ? inst.ready : inst.live) return inst;
  }
  return null;
}

async function clientLoop(idx, clients, requests, pool, byReadiness, paceMs, workIters, out) {
  let served = 0;
  let rejected = 0;
  const mine = Math.floor(requests / clients) + (idx < requests % clients ? 1 : 0);
  for (let k = 0; k < mine; k++) {
    const inst = pick(pool, byReadiness, idx + k);
    const t0 = nowMs();
    if (!inst || !inst.ready) {
      // El proceso esta vivo, el healthcheck da verde, y la peticion se cae
      // igual. Ninguna alerta de disponibilidad de proceso dispara.
      rejected++;
    } else {
      work(workIters);
      inst.served++;
      out.push(nowMs() - t0);
      served++;
    }
    // La pausa es el ritmo de llegada del trafico — y en Node es ademas lo
    // unico que le devuelve el event loop a los timers del arranque.
    if (paceMs) await sleep(paceMs);
    else await Promise.resolve();
  }
  return { served, rejected };
}

async function runScenario(variant, requests, instances, clients, ioMs, paceMs, workIters, prime) {
  let warmInfo = null;
  let byReadiness;
  let coldStarts;
  let boots = null;

  if (variant === 'cold') {
    // El autoescalador reacciona tarde: las instancias arrancan CON el trafico
    // encima, no antes.
    fleet = Array.from({ length: instances }, (_, i) => new Instance(`cold-${i}`));
    boots = Promise.all(fleet.map((inst) => inst.boot(ioMs)));
    byReadiness = false;   // el balanceador ingenuo mira /health
    coldStarts = instances;
  } else {
    if (warmPool.length < instances) {
      warmInfo = await buildWarmPool(instances, ioMs, prime, workIters);
    }
    fleet = warmPool.slice(0, instances);
    byReadiness = true;    // el balanceador correcto mira /ready
    coldStarts = 0;
  }

  const ordered = [];
  const t0 = nowMs();
  const results = await Promise.all(
    Array.from({ length: clients }, (_, i) =>
      clientLoop(i, clients, requests, fleet, byReadiness, paceMs, workIters, ordered)),
  );
  if (boots) await boots;
  const wall = nowMs() - t0;

  const served = results.reduce((a, r) => a + r.served, 0);
  const rejected = results.reduce((a, r) => a + r.rejected, 0);

  const first100 = ordered.slice(0, 100);
  let after1000 = ordered.slice(1000);
  if (!after1000.length) after1000 = ordered.slice(-100);
  const p99First = percentile(first100, 99);
  const p99After = percentile(after1000, 99);
  const readyAt = fleet.length ? Math.max(...fleet.map((i) => i.gapMs())) : 0;

  const slot = metrics[variant];
  slot.runs++;
  slot.served += served;
  slot.rejected_cold_start += rejected;
  slot.cold_starts += coldStarts;
  slot.max_ready_at_ms = Math.max(slot.max_ready_at_ms, readyAt);

  const payload = {
    variant,
    instances,
    requests,
    clients,
    lb_routes_by: byReadiness ? 'readiness (/ready)' : 'liveness (/health)',
    cold_start_count: coldStarts,
    warm_pool_size: warmPool.length,
    ready_at_ms: Number(readyAt.toFixed(2)),
    health_vs_ready_gap_ms: coldStarts ? Number(readyAt.toFixed(2)) : 0,
    first_response_ms: ordered.length ? Number(ordered[0].toFixed(3)) : 0,
    p99_first_100_ms: p99First,
    p99_after_1000_ms: p99After,
    warmup_speedup_x: p99After > 0 ? Number((p99First / p99After).toFixed(2)) : 1,
    p50_ms: percentile(ordered, 50),
    served,
    rejected_cold_start: rejected,
    availability_pct: Number(((served / Math.max(1, served + rejected)) * 100).toFixed(2)),
    work_iters: workIters,
    io_ms: ioMs,
    pace_ms: paceMs,
    wall_ms: Number(wall.toFixed(2)),
  };
  if (warmInfo) payload.warm_pool_built_now = warmInfo;
  payload.note = variant === 'cold'
    ? 'El proceso esta vivo desde el milisegundo cero y /health lo confirma, pero la instancia no sirve nada hasta '
      + 'terminar de inicializar. El balanceador que enruta por liveness manda trafico a ese hueco: los 503 salen '
      + 'de una instancia que ninguna alerta considera caida.'
    : 'El pool ya estaba inicializado y ya ejercitado, y el balanceador enruta por readiness. Ninguna peticion cae '
      + 'en una instancia a medio levantar: 0 rechazos y la latencia parte donde la otra variante recien termina.';
  payload.node_note = 'V8 optimiza en capas: Ignition interpreta, Sparkplug compila sin optimizar, TurboFan '
    + 'optimiza segun el perfil. warmup_speedup_x mide ese efecto. El costo de arranque de Node, en cambio, no '
    + 'esta aqui: esta en el grafo de require, que corre antes de la primera linea propia.';
  return payload;
}

function readyState() {
  const instances = fleet.map((i) => ({
    id: i.id,
    live: i.live,
    ready: i.ready,
    ready_at_ms: i.gapMs(),
    requests_served: i.served,
  }));
  return {
    ready: instances.length > 0 && instances.every((i) => i.ready),
    instances,
    warm_pool_size: warmPool.length,
    note: '`/health` responde 200 apenas el proceso arranca. `/ready` responde 200 recien cuando la instancia '
      + 'puede servir. Si el balanceador mira la primera en vez de la segunda, el hueco entre las dos es tiempo '
      + 'de caida que nadie registra como caida.',
  };
}

function diagnostics() {
  return {
    stack: APP_STACK,
    case: CASE_NAME,
    variants: metrics,
    fleet: readyState(),
    fidelity: {
      medido: 'La curva de calentamiento. El trabajo por peticion es un lazo entero puro sin sleep, identico en '
        + 'los 7 stacks; p99_first_100_ms vs p99_after_1000_ms es lo que V8 hace de verdad.',
      modelado: 'La parte de I/O de la inicializacion (abrir pool, DNS, TLS) es un sleep de io_ms: esperar a la '
        + 'red no quema CPU, y fijarlo es lo que hace comparables a los 7 stacks.',
      real: 'La parte de CPU de la inicializacion construye una tabla de 400.000 iteraciones. Eso si es trabajo.',
    },
    interpretation: {
      cold: 'rejected_cold_start > 0 con el proceso vivo todo el tiempo. health_vs_ready_gap_ms es la ventana '
        + 'exacta en la que el balanceador mando trafico a una instancia que no podia servirlo.',
      warmed: 'rejected_cold_start = 0. El pool ya estaba, y el balanceador enruta por readiness.',
      node_note: 'Node es de la familia con JIT: warmup_speedup_x sale por encima de 1. Pero su cold start real '
        + 'vive en el require graph, no en TurboFan — y ahi la unica salida es --build-snapshot o un SEA.',
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

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const uri = url.pathname;
  const q = url.searchParams;
  let status = 200;
  let payload;

  const requests = clampInt(queryInt(q, 'requests', 2400), 100, 20000);
  const instances = clampInt(queryInt(q, 'instances', 3), 1, 32);
  const clients = clampInt(queryInt(q, 'clients', 8), 1, 64);
  const ioMs = clampInt(queryInt(q, 'io_ms', 150), 0, 5000);
  const paceMs = clampInt(queryInt(q, 'pace_ms', 1), 0, 100);
  const workIters = clampInt(queryInt(q, 'work_iters', WORK_ITERS), 100, 5000000);
  const prime = clampInt(queryInt(q, 'prime', 1500), 0, 100000);

  if (uri === '/' || uri === '/index') {
    payload = {
      lab: 'Problem-Driven Systems Lab',
      case: CASE_NAME,
      stack: APP_STACK,
      goal: 'Mostrar que el hueco entre "el proceso esta vivo" y "la instancia puede servir" es tiempo de caida '
        + 'real que ningun healthcheck registra como caida.',
      node_specific: 'V8 optimiza en capas y el efecto se mide. Pero el cold start de Node vive en el grafo de '
        + 'require, que corre antes de la primera linea de codigo propio.',
      routes: {
        '/health': 'Liveness: responde 200 apenas el proceso arranca.',
        '/ready': 'Readiness: responde 200 recien cuando la instancia puede servir.',
        '/boot-cold?requests=2400&instances=3': 'Instancias frias con el trafico ya encima.',
        '/boot-warmed?requests=2400&instances=3': 'Pool tibio y balanceador que mira readiness.',
        '/warmup?instances=3&prime=1500': 'Construye el pool tibio antes de que llegue el trafico.',
        '/diagnostics/summary': 'Comparativa entre variantes.',
        '/reset-lab': 'Vacia la flota, el pool tibio y las metricas.',
      },
    };
  } else if (uri === '/health') {
    payload = {
      status: 'ok',
      stack: APP_STACK,
      case: CASE_NAME,
      note: 'Liveness. Esto responde 200 aunque la instancia no pueda servir una sola peticion.',
    };
  } else if (uri === '/ready') {
    payload = readyState();
  } else if (uri === '/boot-cold') {
    payload = await runScenario('cold', requests, instances, clients, ioMs, paceMs, workIters, prime);
  } else if (uri === '/boot-warmed') {
    payload = await runScenario('warmed', requests, instances, clients, ioMs, paceMs, workIters, prime);
  } else if (uri === '/warmup') {
    payload = await buildWarmPool(instances, ioMs, prime, workIters);
    payload.status = 'warm';
    payload.note = 'Inicializar deja la instancia lista. Ejercitarla deja al runtime listo. Las dos mitades hacen '
      + 'falta, y solo la segunda depende del lenguaje.';
  } else if (uri === '/diagnostics/summary') {
    payload = diagnostics();
  } else if (uri === '/reset-lab') {
    fleet = [];
    warmPool = [];
    metrics = initialMetrics();
    payload = { status: 'reset', message: 'Flota, pool tibio y metricas reiniciados.' };
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
