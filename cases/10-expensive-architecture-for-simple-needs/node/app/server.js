'use strict';

const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { URL } = require('url');
const { performance } = require('perf_hooks');

const APP_STACK = 'Node.js 20';
const CASE_NAME = '10 - Arquitectura cara para un problema simple';
const STORAGE_DIR = path.join(os.tmpdir(), 'pdsl-case10-node');
const STATE_PATH = path.join(STORAGE_DIR, 'architecture-state.json');
const TELEMETRY_PATH = path.join(STORAGE_DIR, 'telemetry.json');

const SCENARIO_CATALOG = {
  basic_crud: {
    complex: { status: 200, services: 8, cost: 5400, lead: 11, coordination: 7, fit: 18 },
    right_sized: { status: 200, services: 2, cost: 850, lead: 3, coordination: 2, fit: 88 },
    hint: 'El problema real es simple y no justifica una coreografia de servicios.',
  },
  small_campaign: {
    complex: { status: 200, services: 9, cost: 6200, lead: 14, coordination: 8, fit: 22 },
    right_sized: { status: 200, services: 3, cost: 1100, lead: 4, coordination: 2, fit: 82 },
    hint: 'El alcance de negocio sigue siendo acotado y pide velocidad mas que sofisticacion.',
  },
  audit_needed: {
    complex: { status: 200, services: 7, cost: 5000, lead: 9, coordination: 6, fit: 44 },
    right_sized: { status: 200, services: 3, cost: 1350, lead: 5, coordination: 3, fit: 79 },
    hint: 'Incluso con auditoria, puede resolverse con menos capas y menos costo operativo.',
  },
  seasonal_peak: {
    complex: { status: 502, services: 10, cost: 6800, lead: 16, coordination: 9, fit: 30 },
    right_sized: { status: 200, services: 4, cost: 1800, lead: 6, coordination: 3, fit: 76 },
    hint: 'La sobrearquitectura introduce mas puntos de falla justo cuando el negocio pide foco y throughput.',
  },
};
const SCENARIOS = Object.keys(SCENARIO_CATALOG);

const ensureDir = () => fs.mkdirSync(STORAGE_DIR, { recursive: true });
const nowIso = () => new Date().toISOString();
const reqId = (p) => `${p}-${crypto.randomBytes(4).toString('hex')}`;

const initialState = () => ({
  architecture: {
    decision_log_count: 0,
    simplification_backlog: 6,
    last_feature_release: null,
    baselines: {
      complex_services: 8,
      right_sized_services: 2,
      complex_monthly_cost_usd: 5400,
      right_sized_monthly_cost_usd: 850,
    },
  },
});

const initialModeMetrics = () => ({
  successes: 0,
  failures: 0,
  cost_samples: [],
  lead_samples: [],
  coordination_samples: [],
  by_scenario: {},
});

const initialTelemetry = () => ({
  requests: 0,
  samples_ms: [],
  status_counts: { '2xx': 0, '4xx': 0, '5xx': 0 },
  routes: {},
  last_path: null,
  last_status: 200,
  last_updated: null,
  modes: { complex: initialModeMetrics(), right_sized: initialModeMetrics() },
  decisions: [],
});

const readJson = (file, fb) => { ensureDir(); if (!fs.existsSync(file)) return fb(); try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch (_e) { return fb(); } };
const writeJson = (file, d) => { ensureDir(); fs.writeFileSync(file, JSON.stringify(d, null, 2)); };
const readState = () => readJson(STATE_PATH, initialState);
const writeState = (s) => writeJson(STATE_PATH, s);
const readTelemetry = () => readJson(TELEMETRY_PATH, initialTelemetry);
const writeTelemetry = (t) => writeJson(TELEMETRY_PATH, t);

const stateSummary = () => {
  const s = readState();
  return {
    decision_log_count: s.architecture.decision_log_count || 0,
    simplification_backlog: s.architecture.simplification_backlog || 0,
    last_feature_release: s.architecture.last_feature_release,
    baselines: s.architecture.baselines || {},
  };
};

const runFeatureFlow = async (mode, scenario, accounts) => {
  const meta = SCENARIO_CATALOG[scenario][mode];
  const state = readState();
  const flowId = reqId('arch');
  const servicesTouched = meta.services + Math.floor(accounts / 250);
  const monthlyCost = meta.cost + Number((accounts * (mode === 'complex' ? 2.8 : 0.7)).toFixed(2));
  const leadTimeDays = meta.lead;
  const coordinationPoints = meta.coordination;
  const problemFitScore = meta.fit;
  let httpStatus = meta.status;
  let errorMessage = null;

  try {
    // Carga real: serializaciones repetidas en complex (overhead inter-servicios)
    // contra acceso directo en right_sized.
    let entities = Array.from({ length: Math.min(8000, Math.max(100, accounts * 15)) }, () => ({
      id: 100 + Math.floor(Math.random() * 900),
    }));
    if (mode === 'complex') {
      for (let hop = 0; hop < servicesTouched; hop++) {
        const json = JSON.stringify(entities);
        entities = JSON.parse(json);
        // hidratacion fake: convertir a "models"
        entities = entities.map((e) => Object.assign(Object.create(null), e));
      }
      if (scenario === 'seasonal_peak') {
        throw new Error('Gateway Timeout: demasiados hops serializando bajo pico estacional.');
      }
    }
    const _ = entities[0]?.id;
  } catch (e) {
    httpStatus = 502;
    errorMessage = `Error Critico Node: ${e.message}`;
  }

  state.architecture.decision_log_count = (state.architecture.decision_log_count || 0) + 1;
  if (mode === 'right_sized') {
    state.architecture.simplification_backlog = Math.max(0, (state.architecture.simplification_backlog || 0) - 1);
  }
  state.architecture.last_feature_release = `${mode}-${nowIso().replace(/[-:]/g, '').slice(0, 15)}`;
  writeState(state);

  const outcome = httpStatus >= 400 ? 'failure' : 'success';
  const payload = {
    mode,
    scenario,
    accounts,
    status: httpStatus >= 400 ? 'failed' : 'completed',
    message:
      mode === 'complex' && httpStatus >= 400
        ? errorMessage
        : mode === 'complex'
          ? 'La solucion encarece innecesariamente con CPU overhead serializando datos entre hops.'
          : 'Right-sized resuelve la necesidad directo, sin coreografia inter-servicios.',
    flow_id: flowId,
    services_touched: servicesTouched,
    monthly_cost_usd: monthlyCost,
    lead_time_days: leadTimeDays,
    coordination_points: coordinationPoints,
    problem_fit_score: problemFitScore,
    scenario_hint: SCENARIO_CATALOG[scenario].hint,
    architecture_state: stateSummary(),
  };
  if (httpStatus >= 400) {
    payload.error = 'La complejidad agregada introdujo demasiados puntos de coordinacion para el valor real del caso.';
  }

  return {
    http_status: httpStatus,
    payload,
    context: {
      mode,
      scenario,
      accounts,
      outcome,
      monthly_cost_usd: monthlyCost,
      services_touched: servicesTouched,
      lead_time_days: leadTimeDays,
      coordination_points: coordinationPoints,
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
    m.cost_samples.push(ctx.monthly_cost_usd);
    m.lead_samples.push(ctx.lead_time_days);
    m.coordination_samples.push(ctx.coordination_points);
    if (m.cost_samples.length > 200) m.cost_samples = m.cost_samples.slice(-200);
    if (m.lead_samples.length > 200) m.lead_samples = m.lead_samples.slice(-200);
    if (m.coordination_samples.length > 200) m.coordination_samples = m.coordination_samples.slice(-200);
    if (ctx.outcome === 'success') m.successes += 1;
    else m.failures += 1;
    t.modes[ctx.mode] = m;
    t.decisions.push({ ...ctx, status_code: status, elapsed_ms: Number(elapsedMs.toFixed(2)), timestamp_utc: nowIso() });
    if (t.decisions.length > 60) t.decisions = t.decisions.slice(-60);
  }
  writeTelemetry(t);
};

const telemetrySummary = (t) => {
  const samples = t.samples_ms || [];
  const count = samples.length;
  const modes = {};
  for (const [name, m] of Object.entries(t.modes || {})) {
    const cs = m.cost_samples || [];
    const ls = m.lead_samples || [];
    const ks = m.coordination_samples || [];
    modes[name] = {
      successes: m.successes || 0,
      failures: m.failures || 0,
      avg_monthly_cost_usd: cs.length ? Number((cs.reduce((a, b) => a + b, 0) / cs.length).toFixed(2)) : 0,
      avg_lead_time_days: ls.length ? Number((ls.reduce((a, b) => a + b, 0) / ls.length).toFixed(2)) : 0,
      avg_coordination_points: ks.length ? Number((ks.reduce((a, b) => a + b, 0) / ks.length).toFixed(2)) : 0,
      by_scenario: m.by_scenario || {},
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
    recent_decisions: [...(t.decisions || [])].reverse(),
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
    out.push(`app_arch_success_total{mode="${lm}"} ${m.successes}`);
    out.push(`app_arch_failure_total{mode="${lm}"} ${m.failures}`);
    out.push(`app_arch_avg_monthly_cost_usd{mode="${lm}"} ${m.avg_monthly_cost_usd}`);
    out.push(`app_arch_avg_lead_time_days{mode="${lm}"} ${m.avg_lead_time_days}`);
    out.push(`app_arch_avg_coordination_points{mode="${lm}"} ${m.avg_coordination_points}`);
  }
  out.push(`app_decision_log_count ${st.decision_log_count}`);
  out.push(`app_simplification_backlog ${st.simplification_backlog}`);
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
        goal: 'Comparar costo, lead time y coordinacion entre solucion compleja y right-sized.',
        node_specific:
          'El costo de la sobrearquitectura es CPU real: en `complex` el handler hace N rondas de JSON.stringify/parse sobre arrays grandes — el evento loop se ve castigado y `seasonal_peak` rompe con timeout. En `right_sized` el mismo dato se accede en O(1) sin hops.',
        routes: {
          '/health': 'Estado basico del servicio.',
          '/feature-complex?scenario=basic_crud&accounts=120': 'Ejecuta la solucion costosa y sobrecompuesta.',
          '/feature-right-sized?scenario=basic_crud&accounts=120': 'Ejecuta una variante proporcional al problema.',
          '/decisions?limit=10': 'Ultimas decisiones observadas.',
          '/diagnostics/summary': 'Resumen de costo, lead y backlog de simplificacion.',
          '/metrics': 'Metricas JSON.',
          '/metrics-prometheus': 'Metricas en formato Prometheus.',
          '/reset-lab': 'Reinicia estado y metricas.',
        },
        allowed_scenarios: SCENARIOS,
      };
    } else if (uri === '/health') {
      payload = { status: 'ok', stack: APP_STACK };
    } else if (uri === '/feature-complex' || uri === '/feature-right-sized') {
      const mode = uri === '/feature-complex' ? 'complex' : 'right_sized';
      let scenario = url.searchParams.get('scenario') || 'basic_crud';
      if (!SCENARIOS.includes(scenario)) scenario = 'basic_crud';
      const accounts = clampInt(url.searchParams.get('accounts') || '120', 1, 5000);
      const result = await runFeatureFlow(mode, scenario, accounts);
      status = result.http_status;
      ctx = result.context;
      payload = result.payload;
    } else if (uri === '/decisions') {
      const limit = clampInt(url.searchParams.get('limit') || '10', 1, 60);
      payload = { limit, decisions: telemetrySummary(readTelemetry()).recent_decisions.slice(0, limit) };
    } else if (uri === '/diagnostics/summary') {
      payload = {
        case: CASE_NAME,
        stack: APP_STACK,
        architecture: stateSummary(),
        metrics: telemetrySummary(readTelemetry()),
        interpretation: {
          complex: 'Complex convierte una necesidad simple en mas costo, mas equipos y mas friccion operacional.',
          right_sized: 'Right-sized busca proporcionalidad: la arquitectura acompana al problema real en vez de sobredimensionarlo.',
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
  if (!skip && uri !== '/metrics' && uri !== '/reset-lab') {
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
