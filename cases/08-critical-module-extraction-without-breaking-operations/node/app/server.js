'use strict';

const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { URL } = require('url');
const { performance } = require('perf_hooks');
const { EventEmitter } = require('events');

const APP_STACK = 'Node.js 20';
const CASE_NAME = '08 - Extraccion de modulo critico sin romper operacion';
const STORAGE_DIR = path.join(os.tmpdir(), 'pdsl-case08-node');
const STATE_PATH = path.join(STORAGE_DIR, 'extraction-state.json');
const TELEMETRY_PATH = path.join(STORAGE_DIR, 'telemetry.json');

const SCENARIO_CATALOG = {
  stable: { bigbang_status: 200, compatible_status: 200, bigbang_blast: 76, compatible_blast: 28, compatible_proxy_hits: 1, compatible_progress: 20, hint: 'Cambio sano, pero la extraccion gradual igual reduce radio de impacto.' },
  rule_drift: { bigbang_status: 500, compatible_status: 200, bigbang_blast: 92, compatible_blast: 32, compatible_proxy_hits: 3, compatible_progress: 15, hint: 'Los consumidores no esperan exactamente la misma regla o estructura.' },
  shared_write: { bigbang_status: 409, compatible_status: 200, bigbang_blast: 88, compatible_blast: 36, compatible_proxy_hits: 4, compatible_progress: 10, hint: 'Dos rutas escribiendo el mismo recurso exigen proxy y corte progresivo.' },
  peak_sale: { bigbang_status: 502, compatible_status: 200, bigbang_blast: 95, compatible_blast: 34, compatible_proxy_hits: 5, compatible_progress: 10, hint: 'La venta pico castiga cualquier extraccion sin protecciones de compatibilidad.' },
  partner_contract: { bigbang_status: 500, compatible_status: 200, bigbang_blast: 90, compatible_blast: 30, compatible_proxy_hits: 4, compatible_progress: 20, hint: 'El partner externo necesita contrato estable mientras cambia la implementacion.' },
};
const SCENARIOS = Object.keys(SCENARIO_CATALOG);
const ALLOWED_CONSUMERS = ['checkout', 'marketplace', 'backoffice', 'partner_api'];

// EventEmitter para señalar cutovers — en Node es la forma natural de
// publicar eventos de dominio sin atar a quienes consumen.
const cutoverBus = new EventEmitter();
const cutoverLog = [];
cutoverBus.on('advance', ({ consumer, before, after }) => {
  cutoverLog.push({ consumer, before, after, at: new Date().toISOString() });
  if (cutoverLog.length > 50) cutoverLog.splice(0, cutoverLog.length - 50);
});

// Proxy de compatibilidad: traduce contratos viejos al nuevo modulo. Usa Proxy nativo.
const newPricingModule = {
  computeFinalPrice(payload) {
    if (typeof payload.price !== 'number') {
      throw new TypeError(`Contrato roto: nuevo modulo asume key 'price', recibio ${JSON.stringify(Object.keys(payload))}`);
    }
    return Number((payload.price * 1.21).toFixed(2));
  },
};
const compatibilityProxy = new Proxy(newPricingModule, {
  get(target, prop, receiver) {
    if (prop === 'computeFinalPrice') {
      return (payload) => {
        if (payload && payload.cost_usd !== undefined && payload.price === undefined) {
          payload = { ...payload, price: payload.cost_usd }; // traduccion in-flight
        }
        return Reflect.get(target, prop, receiver).call(target, payload);
      };
    }
    return Reflect.get(target, prop, receiver);
  },
});

const ensureDir = () => fs.mkdirSync(STORAGE_DIR, { recursive: true });
const nowIso = () => new Date().toISOString();
const reqId = (p) => `${p}-${crypto.randomBytes(4).toString('hex')}`;

const initialState = () => ({
  extraction: {
    consumers: { checkout: 0, marketplace: 0, backoffice: 0, partner_api: 0 },
    contract_tests: 14,
    compatibility_proxy_hits: 0,
    shadow_traffic_percent: 15,
    cutover_events: 0,
    last_release: null,
  },
});

const initialModeMetrics = () => ({
  successes: 0,
  failures: 0,
  avg_blast_samples: [],
  by_scenario: {},
  by_consumer: {},
  proxy_hits_total: 0,
});

const initialTelemetry = () => ({
  requests: 0,
  samples_ms: [],
  status_counts: { '2xx': 0, '4xx': 0, '5xx': 0 },
  routes: {},
  last_path: null,
  last_status: 200,
  last_updated: null,
  modes: { bigbang: initialModeMetrics(), compatible: initialModeMetrics() },
  runs: [],
});

const readJson = (file, fb) => {
  ensureDir();
  if (!fs.existsSync(file)) return fb();
  try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch (_e) { return fb(); }
};
const writeJson = (file, d) => { ensureDir(); fs.writeFileSync(file, JSON.stringify(d, null, 2)); };
const readState = () => readJson(STATE_PATH, initialState);
const writeState = (s) => writeJson(STATE_PATH, s);
const readTelemetry = () => readJson(TELEMETRY_PATH, initialTelemetry);
const writeTelemetry = (t) => writeJson(TELEMETRY_PATH, t);

const stateSummary = () => {
  const s = readState();
  const consumers = s.extraction.consumers || {};
  const values = Object.values(consumers);
  const avg = values.length ? Number((values.reduce((a, b) => a + b, 0) / values.length).toFixed(2)) : 0;
  return {
    consumers,
    average_cutover_percent: avg,
    contract_tests: s.extraction.contract_tests || 0,
    compatibility_proxy_hits: s.extraction.compatibility_proxy_hits || 0,
    shadow_traffic_percent: s.extraction.shadow_traffic_percent || 0,
    cutover_events: s.extraction.cutover_events || 0,
    last_release: s.extraction.last_release,
  };
};

const advanceCutover = (consumer, step = 25) => {
  const state = readState();
  const current = state.extraction.consumers[consumer] || 0;
  const next = Math.min(100, current + step);
  state.extraction.consumers[consumer] = next;
  state.extraction.cutover_events = (state.extraction.cutover_events || 0) + 1;
  state.extraction.last_release = `cutover-${nowIso().replace(/[-:]/g, '').slice(0, 15)}`;
  writeState(state);
  cutoverBus.emit('advance', { consumer, before: current, after: next });
  return { consumer, before: current, after: next, cutover_events: state.extraction.cutover_events };
};

const runExtractionFlow = async (mode, scenario, consumer) => {
  const meta = SCENARIO_CATALOG[scenario];
  const state = readState();
  const flowId = reqId('extr');
  const baseDelay = mode === 'bigbang' ? 140 : 90;
  await new Promise((r) => setTimeout(r, baseDelay));

  let httpStatus = mode === 'bigbang' ? meta.bigbang_status : meta.compatible_status;
  let blastRadius = mode === 'bigbang' ? meta.bigbang_blast : meta.compatible_blast;
  let errorMessage = null;
  let proxyHits = 0;
  let finalPrice = null;

  try {
    const incomingPayload = { cost_usd: 100 }; // legacy contract: cost_usd
    if (mode === 'bigbang') {
      // Llamamos al nuevo modulo directo. Si el contrato no esta alineado, rompe.
      if (scenario === 'rule_drift') {
        finalPrice = newPricingModule.computeFinalPrice(incomingPayload); // crash
      } else if (['shared_write', 'peak_sale', 'partner_contract'].includes(scenario)) {
        throw new Error(`bigbang fallo en ${scenario}: rutas concurrentes / pico / contrato externo sin proteccion.`);
      } else {
        finalPrice = newPricingModule.computeFinalPrice({ ...incomingPayload, price: incomingPayload.cost_usd });
      }
    } else {
      // Modo compatible: el Proxy traduce, no hay crash.
      finalPrice = compatibilityProxy.computeFinalPrice(incomingPayload);
      proxyHits = meta.compatible_proxy_hits;
      state.extraction.compatibility_proxy_hits = (state.extraction.compatibility_proxy_hits || 0) + proxyHits;
      const cur = state.extraction.consumers[consumer] || 0;
      state.extraction.consumers[consumer] = Math.min(100, cur + meta.compatible_progress);
      state.extraction.cutover_events = (state.extraction.cutover_events || 0) + 1;
      state.extraction.last_release = `cutover-${nowIso().replace(/[-:]/g, '').slice(0, 15)}`;
      writeState(state);
      cutoverBus.emit('advance', { consumer, before: cur, after: state.extraction.consumers[consumer] });
    }
  } catch (e) {
    httpStatus = scenario === 'shared_write' ? 409 : scenario === 'peak_sale' ? 502 : 500;
    errorMessage = e.message;
  }

  const outcome = httpStatus >= 400 ? 'failure' : 'success';
  const statusText = httpStatus >= 400 ? 'failed' : 'completed';
  const payload = {
    mode,
    scenario,
    consumer,
    status: statusText,
    message:
      mode === 'bigbang' && httpStatus >= 400
        ? `Bigbang fallo: ${errorMessage || 'extraccion sin proteccion de compatibilidad.'}`
        : 'Compatible usa Proxy, intercepta llamadas, transforma contratos y hace cutover gradual sin romper codigo.',
    flow_id: flowId,
    blast_radius_score: blastRadius,
    final_price: finalPrice,
    proxy_hits: proxyHits,
    scenario_hint: meta.hint,
    extraction_state: stateSummary(),
  };
  if (httpStatus >= 400) payload.error = 'La extraccion exploto de forma dura. Excepcion capturada.';

  return {
    http_status: httpStatus,
    payload,
    context: {
      mode,
      scenario,
      consumer,
      outcome,
      blast_radius_score: blastRadius,
      proxy_hits: proxyHits,
      flow_id: flowId,
    },
  };
};

const percentile = (vs, p) => {
  if (!vs.length) return 0;
  const sorted = [...vs].sort((a, b) => a - b);
  const idx = Math.max(0, Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1));
  return Number(sorted[idx].toFixed(2));
};
const statusBucket = (s) => (s >= 500 ? '5xx' : s >= 400 ? '4xx' : '2xx');
const clampInt = (v, mn, mx) => {
  const n = Number.parseInt(v, 10);
  if (Number.isNaN(n)) return mn;
  return Math.max(mn, Math.min(mx, n));
};

const recordRequest = (uri, status, elapsedMs, ctx) => {
  const t = readTelemetry();
  t.requests = (t.requests || 0) + 1;
  t.samples_ms.push(Number(elapsedMs.toFixed(2)));
  if (t.samples_ms.length > 3000) t.samples_ms = t.samples_ms.slice(-3000);
  t.routes[uri] = t.routes[uri] || [];
  t.routes[uri].push(Number(elapsedMs.toFixed(2)));
  if (t.routes[uri].length > 500) t.routes[uri] = t.routes[uri].slice(-500);
  t.status_counts[statusBucket(status)] = (t.status_counts[statusBucket(status)] || 0) + 1;
  t.last_path = uri;
  t.last_status = status;
  t.last_updated = nowIso();
  if (ctx) {
    const m = t.modes[ctx.mode] || initialModeMetrics();
    m.by_scenario[ctx.scenario] = (m.by_scenario[ctx.scenario] || 0) + 1;
    m.by_consumer[ctx.consumer] = (m.by_consumer[ctx.consumer] || 0) + 1;
    m.avg_blast_samples.push(ctx.blast_radius_score);
    if (m.avg_blast_samples.length > 200) m.avg_blast_samples = m.avg_blast_samples.slice(-200);
    m.proxy_hits_total = (m.proxy_hits_total || 0) + (ctx.proxy_hits || 0);
    if (ctx.outcome === 'success') m.successes += 1;
    else m.failures += 1;
    t.modes[ctx.mode] = m;
    t.runs.push({ ...ctx, status_code: status, elapsed_ms: Number(elapsedMs.toFixed(2)), timestamp_utc: nowIso() });
    if (t.runs.length > 60) t.runs = t.runs.slice(-60);
  }
  writeTelemetry(t);
};

const telemetrySummary = (t) => {
  const samples = t.samples_ms || [];
  const count = samples.length;
  const modes = {};
  for (const [name, m] of Object.entries(t.modes || {})) {
    const br = m.avg_blast_samples || [];
    modes[name] = {
      successes: m.successes || 0,
      failures: m.failures || 0,
      avg_blast_radius_score: br.length ? Number((br.reduce((a, b) => a + b, 0) / br.length).toFixed(2)) : 0,
      proxy_hits_total: m.proxy_hits_total || 0,
      by_scenario: m.by_scenario || {},
      by_consumer: m.by_consumer || {},
    };
  }
  return {
    requests_tracked: t.requests || 0,
    sample_count: count,
    avg_ms: count ? Number((samples.reduce((a, b) => a + b, 0) / count).toFixed(2)) : 0,
    p95_ms: percentile(samples, 95),
    p99_ms: percentile(samples, 99),
    last_path: t.last_path,
    last_status: t.last_status,
    last_updated: t.last_updated,
    status_counts: t.status_counts,
    modes,
    recent_runs: [...(t.runs || [])].reverse(),
    cutover_log: [...cutoverLog].reverse(),
  };
};

const promLabel = (v) => String(v).replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, ' ');
const renderPrometheus = () => {
  const sum = telemetrySummary(readTelemetry());
  const st = stateSummary();
  const out = [];
  out.push('# HELP app_requests_total Total de requests observados.');
  out.push('# TYPE app_requests_total counter');
  out.push(`app_requests_total ${sum.requests_tracked}`);
  for (const [mode, m] of Object.entries(sum.modes)) {
    const lm = promLabel(mode);
    out.push(`app_extraction_success_total{mode="${lm}"} ${m.successes}`);
    out.push(`app_extraction_failure_total{mode="${lm}"} ${m.failures}`);
    out.push(`app_extraction_avg_blast_radius{mode="${lm}"} ${m.avg_blast_radius_score}`);
    out.push(`app_extraction_proxy_hits_total{mode="${lm}"} ${m.proxy_hits_total}`);
  }
  for (const [c, p] of Object.entries(st.consumers)) {
    out.push(`app_consumer_cutover_progress{consumer="${promLabel(c)}"} ${p}`);
  }
  out.push(`app_cutover_events_total ${st.cutover_events}`);
  return `${out.join('\n')}\n`;
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
  const started = performance.now();
  const url = new URL(req.url || '/', 'http://127.0.0.1');
  const uri = url.pathname || '/';
  let status = 200;
  let payload = {};
  let skip = false;
  let ctx = null;

  try {
    if (uri === '/' || uri === '') {
      payload = {
        lab: 'Problem-Driven Systems Lab',
        case: CASE_NAME,
        stack: APP_STACK,
        goal: 'Comparar extraccion big bang vs compatible con Proxy, contratos y cutover gradual.',
        node_specific:
          'La compatibilidad de contrato vive en un Proxy nativo. El Proxy intercepta `computeFinalPrice` y traduce `cost_usd` -> `price` antes de llamar al modulo nuevo. Sin patches, sin if/else en el codigo de negocio. EventEmitter publica cada avance de cutover.',
        routes: {
          '/health': 'Estado basico del servicio.',
          '/pricing-bigbang?scenario=rule_drift&consumer=checkout': 'Intenta extraer el modulo critico de una vez.',
          '/pricing-compatible?scenario=rule_drift&consumer=checkout': 'Mueve el modulo con compatibilidad y corte gradual.',
          '/cutover/advance?consumer=checkout': 'Fuerza un avance manual del cutover por consumidor.',
          '/extraction/state': 'Estado de cutover.',
          '/flows?limit=10': 'Ultimos flujos observados.',
          '/diagnostics/summary': 'Resumen de blast, proxy hits y cutover.',
          '/metrics': 'Metricas JSON.',
          '/metrics-prometheus': 'Metricas en formato Prometheus.',
          '/reset-lab': 'Reinicia estado y metricas.',
        },
        allowed_scenarios: SCENARIOS,
        allowed_consumers: ALLOWED_CONSUMERS,
      };
    } else if (uri === '/health') {
      payload = { status: 'ok', stack: APP_STACK };
    } else if (uri === '/pricing-bigbang' || uri === '/pricing-compatible') {
      const mode = uri === '/pricing-bigbang' ? 'bigbang' : 'compatible';
      let scenario = url.searchParams.get('scenario') || 'stable';
      if (!SCENARIOS.includes(scenario)) scenario = 'stable';
      let consumer = url.searchParams.get('consumer') || 'checkout';
      if (!ALLOWED_CONSUMERS.includes(consumer)) consumer = 'checkout';
      const result = await runExtractionFlow(mode, scenario, consumer);
      status = result.http_status;
      ctx = result.context;
      payload = result.payload;
    } else if (uri === '/cutover/advance') {
      let consumer = url.searchParams.get('consumer') || 'checkout';
      if (!ALLOWED_CONSUMERS.includes(consumer)) consumer = 'checkout';
      const step = clampInt(url.searchParams.get('step') || '25', 1, 100);
      payload = { status: 'advanced', ...advanceCutover(consumer, step), state: stateSummary() };
    } else if (uri === '/extraction/state') {
      payload = stateSummary();
    } else if (uri === '/flows') {
      const limit = clampInt(url.searchParams.get('limit') || '10', 1, 60);
      payload = { limit, runs: telemetrySummary(readTelemetry()).recent_runs.slice(0, limit) };
    } else if (uri === '/diagnostics/summary') {
      payload = {
        case: CASE_NAME,
        stack: APP_STACK,
        extraction: stateSummary(),
        metrics: telemetrySummary(readTelemetry()),
        interpretation: {
          bigbang: 'Bigbang corta el modulo de una vez y rompe consumidores ante cualquier desalineacion de contrato.',
          compatible: 'Compatible desacopla el modulo con Proxy, contratos y cutover gradual para no romper checkout, partners ni backoffice.',
        },
      };
    } else if (uri === '/metrics') {
      payload = { case: CASE_NAME, stack: APP_STACK, ...telemetrySummary(readTelemetry()) };
    } else if (uri === '/metrics-prometheus') {
      skip = true;
      res.writeHead(200, { 'Content-Type': 'text/plain; version=0.0.4; charset=utf-8' });
      res.end(renderPrometheus());
      return;
    } else if (uri === '/reset-lab') {
      writeState(initialState());
      writeTelemetry(initialTelemetry());
      cutoverLog.length = 0;
      payload = { status: 'reset', message: 'Estado y metricas reiniciados.' };
    } else {
      status = 404;
      payload = { error: 'Ruta no encontrada', path: uri };
    }
  } catch (error) {
    status = 500;
    payload = { error: 'Fallo al procesar la solicitud', message: error.message, path: uri };
  }

  const elapsedMs = performance.now() - started;
  if (!skip && uri !== '/metrics' && uri !== '/reset-lab' && uri !== '/cutover/advance') {
    recordRequest(uri, status, elapsedMs, ctx);
  }
  payload.elapsed_ms = Number(elapsedMs.toFixed(2));
  payload.timestamp_utc = nowIso();
  payload.pid = process.pid;
  sendJson(res, status, payload);
};

ensureDir();
const PORT = Number.parseInt(process.env.PORT || '8080', 10);
http.createServer(handler).listen(PORT, '0.0.0.0', () => {
  console.log(`Servidor Node escuchando en ${PORT}`);
});
