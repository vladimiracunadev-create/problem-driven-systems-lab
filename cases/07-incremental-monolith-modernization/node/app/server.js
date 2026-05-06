'use strict';

const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { URL } = require('url');
const { performance } = require('perf_hooks');

const APP_STACK = 'Node.js 20';
const CASE_NAME = '07 - Modernizacion incremental de monolito';
const STORAGE_DIR = path.join(os.tmpdir(), 'pdsl-case07-node');
const STATE_PATH = path.join(STORAGE_DIR, 'state.json');
const TELEMETRY_PATH = path.join(STORAGE_DIR, 'telemetry.json');

const SCENARIO_CATALOG = {
  billing_change: {
    legacy_modules: 6,
    legacy_risk: 82,
    strangler_modules: 2,
    strangler_risk: 28,
    legacy_status: 200,
    strangler_status: 200,
    hint: 'Cambio comun con mucho acoplamiento en legacy y limite de impacto en strangler.',
  },
  shared_schema: {
    legacy_modules: 7,
    legacy_risk: 94,
    strangler_modules: 3,
    strangler_risk: 36,
    legacy_status: 500,
    strangler_status: 200,
    hint: 'Legacy rompe por esquema compartido; strangler contiene con ACL y contrato.',
  },
  parallel_conflict: {
    legacy_modules: 5,
    legacy_risk: 88,
    strangler_modules: 2,
    strangler_risk: 24,
    legacy_status: 409,
    strangler_status: 200,
    hint: 'La modernizacion incremental permite trabajo paralelo con menos conflictos.',
  },
};
const SCENARIOS = Object.keys(SCENARIO_CATALOG);
const ALLOWED_CONSUMERS = ['web', 'mobile', 'backoffice'];

// Tabla de routing del strangler: handlers por consumer registrables en runtime.
// Es la primitiva mas directa en Node — Map mutable, no requiere clases ni reload.
const newModuleHandlers = new Map();
const registerNewHandler = (consumer, handler) => newModuleHandlers.set(consumer, handler);
ALLOWED_CONSUMERS.forEach((c) =>
  registerNewHandler(c, ({ scenario }) => ({
    handled_by: 'extracted_module',
    scenario_resolution: SCENARIO_CATALOG[scenario]?.hint || 'ok',
  }))
);

const ensureDir = () => fs.mkdirSync(STORAGE_DIR, { recursive: true });
const nowIso = () => new Date().toISOString();
const reqId = (p) => `${p}-${crypto.randomBytes(4).toString('hex')}`;

const initialState = () => ({
  migration: {
    consumers: { web: 0, mobile: 0, backoffice: 0 },
    extracted_module_coverage: 18,
    contract_tests: 12,
    anti_corruption_layer_enabled: true,
    last_release: null,
  },
});

const initialModeMetrics = () => ({
  successes: 0,
  failures: 0,
  blast_radius_samples: [],
  risk_score_samples: [],
  by_scenario: {},
});

const initialTelemetry = () => ({
  requests: 0,
  samples_ms: [],
  routes: {},
  status_counts: { '2xx': 0, '4xx': 0, '5xx': 0 },
  last_path: null,
  last_status: 200,
  last_updated: null,
  modes: { legacy: initialModeMetrics(), strangler: initialModeMetrics() },
  runs: [],
});

const readJson = (file, fb) => {
  ensureDir();
  if (!fs.existsSync(file)) return fb();
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (_e) {
    return fb();
  }
};
const writeJson = (file, d) => {
  ensureDir();
  fs.writeFileSync(file, JSON.stringify(d, null, 2));
};
const readState = () => readJson(STATE_PATH, initialState);
const writeState = (s) => writeJson(STATE_PATH, s);
const readTelemetry = () => readJson(TELEMETRY_PATH, initialTelemetry);
const writeTelemetry = (t) => writeJson(TELEMETRY_PATH, t);

const stateSummary = () => {
  const s = readState();
  const consumers = s.migration.consumers || {};
  const fullyMigrated = Object.values(consumers).filter((p) => p >= 100).length;
  return {
    consumers,
    consumers_total: Object.keys(consumers).length,
    consumers_fully_migrated: fullyMigrated,
    extracted_module_coverage: s.migration.extracted_module_coverage || 0,
    contract_tests: s.migration.contract_tests || 0,
    anti_corruption_layer_enabled: !!s.migration.anti_corruption_layer_enabled,
    last_release: s.migration.last_release,
  };
};

const runChangeFlow = async (mode, scenario, consumer) => {
  const meta = SCENARIO_CATALOG[scenario];
  const state = readState();
  const changeId = reqId('mod');
  const modulesTouched = mode === 'legacy' ? meta.legacy_modules : meta.strangler_modules;
  const riskScore = mode === 'legacy' ? meta.legacy_risk : meta.strangler_risk;
  const blastRadius = modulesTouched * 12 + (mode === 'legacy' ? 18 : 4);
  const baseDelay = modulesTouched * 38 + (mode === 'legacy' ? 120 : 60);
  await new Promise((r) => setTimeout(r, baseDelay));

  let httpStatus = mode === 'legacy' ? meta.legacy_status : meta.strangler_status;
  let errorMessage = null;

  try {
    if (mode === 'legacy') {
      // God object con acoplamiento explicito.
      const monolith = { billingEngine: {}, sharedSessionDb: { fetchData: () => 'session_blob' } };
      if (scenario === 'shared_schema') {
        delete monolith.sharedSessionDb;
        const _ = monolith.sharedSessionDb.fetchData(); // referencia muerta — TypeError
      } else if (scenario === 'parallel_conflict') {
        throw new Error('Git Merge Error: ramas de feature colisionan sobre la God Class compartida.');
      }
    } else {
      const handler = newModuleHandlers.get(consumer);
      if (!handler) throw new Error(`Sin handler nuevo para consumer=${consumer}`);
      handler({ scenario });
      const cur = state.migration.consumers[consumer] || 0;
      state.migration.consumers[consumer] = Math.min(100, cur + 25);
      state.migration.extracted_module_coverage = Math.min(
        92,
        (state.migration.extracted_module_coverage || 0) + 6
      );
      state.migration.contract_tests = Math.min(180, (state.migration.contract_tests || 0) + 4);
      state.migration.last_release = `strangler-${nowIso().replace(/[-:]/g, '').slice(0, 15)}`;
      state.migration.anti_corruption_layer_enabled = true;
      writeState(state);
    }
  } catch (e) {
    httpStatus = 502;
    errorMessage = `Fatal Regresion en el Monolito: ${e.message}`;
  }

  const outcome = httpStatus >= 400 ? 'failure' : 'success';
  const statusText = httpStatus >= 400 ? 'failed' : 'completed';
  const payload = {
    mode,
    scenario,
    consumer,
    status: statusText,
    message:
      mode === 'legacy' && httpStatus >= 400
        ? errorMessage || 'Legacy toca demasiados modulos y concentra mas riesgo por cambio.'
        : 'Strangler reduce blast radius usando Facades, sube cobertura y mueve el consumidor de forma gradual.',
    change_id: changeId,
    modules_touched: modulesTouched,
    blast_radius_score: blastRadius,
    risk_score: riskScore,
    scenario_hint: meta.hint,
    migration_state: stateSummary(),
  };
  if (httpStatus >= 400) payload.error = 'Crash grave de Acoplamiento capturado por handler Node.';

  return {
    http_status: httpStatus,
    payload,
    context: {
      mode,
      scenario,
      consumer,
      outcome,
      blast_radius_score: blastRadius,
      risk_score: riskScore,
      change_id: changeId,
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
    m.blast_radius_samples.push(ctx.blast_radius_score);
    m.risk_score_samples.push(ctx.risk_score);
    if (m.blast_radius_samples.length > 200) m.blast_radius_samples = m.blast_radius_samples.slice(-200);
    if (m.risk_score_samples.length > 200) m.risk_score_samples = m.risk_score_samples.slice(-200);
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
    const br = m.blast_radius_samples || [];
    const rs = m.risk_score_samples || [];
    modes[name] = {
      successes: m.successes || 0,
      failures: m.failures || 0,
      avg_blast_radius_score: br.length ? Number((br.reduce((a, b) => a + b, 0) / br.length).toFixed(2)) : 0,
      avg_risk_score: rs.length ? Number((rs.reduce((a, b) => a + b, 0) / rs.length).toFixed(2)) : 0,
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
    recent_runs: [...(t.runs || [])].reverse(),
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
    out.push(`app_change_success_total{mode="${lm}"} ${m.successes}`);
    out.push(`app_change_failure_total{mode="${lm}"} ${m.failures}`);
    out.push(`app_change_avg_blast_radius{mode="${lm}"} ${m.avg_blast_radius_score}`);
    out.push(`app_change_avg_risk_score{mode="${lm}"} ${m.avg_risk_score}`);
  }
  out.push(`app_consumers_total ${st.consumers_total}`);
  out.push(`app_consumers_fully_migrated ${st.consumers_fully_migrated}`);
  out.push(`app_extracted_module_coverage ${st.extracted_module_coverage}`);
  out.push(`app_contract_tests ${st.contract_tests}`);
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
        goal: 'Comparar un cambio con alto blast radius en monolito vs uno aislado por strangler.',
        node_specific:
          'El strangler vive como Map<consumer, handler> mutable en runtime. Registrar handlers nuevos para mover trafico es ~5 lineas, sin reemplazar clases ni recargar modulos. La ACL es un closure que filtra el contrato esperado.',
        routes: {
          '/health': 'Estado basico del servicio.',
          '/change-legacy?scenario=shared_schema&consumer=web': 'Cambio sobre el monolito acoplado.',
          '/change-strangler?scenario=shared_schema&consumer=web': 'Mismo cambio con strangler.',
          '/flows?limit=10': 'Ultimos cambios observados.',
          '/diagnostics/summary': 'Resumen de blast radius, riesgo y progreso.',
          '/metrics': 'Metricas JSON.',
          '/metrics-prometheus': 'Metricas en formato Prometheus.',
          '/reset-lab': 'Reinicia estado y metricas.',
        },
        allowed_scenarios: SCENARIOS,
        allowed_consumers: ALLOWED_CONSUMERS,
      };
    } else if (uri === '/health') {
      payload = { status: 'ok', stack: APP_STACK };
    } else if (uri === '/change-legacy' || uri === '/change-strangler') {
      const mode = uri === '/change-legacy' ? 'legacy' : 'strangler';
      let scenario = url.searchParams.get('scenario') || 'billing_change';
      if (!SCENARIOS.includes(scenario)) scenario = 'billing_change';
      let consumer = url.searchParams.get('consumer') || 'web';
      if (!ALLOWED_CONSUMERS.includes(consumer)) consumer = 'web';
      const result = await runChangeFlow(mode, scenario, consumer);
      status = result.http_status;
      ctx = result.context;
      payload = result.payload;
    } else if (uri === '/flows') {
      const limit = clampInt(url.searchParams.get('limit') || '10', 1, 60);
      payload = { limit, runs: telemetrySummary(readTelemetry()).recent_runs.slice(0, limit) };
    } else if (uri === '/diagnostics/summary') {
      payload = {
        case: CASE_NAME,
        stack: APP_STACK,
        migration: stateSummary(),
        metrics: telemetrySummary(readTelemetry()),
        interpretation: {
          legacy: 'Legacy mantiene demasiado acoplamiento y convierte cambios locales en regresiones de alto radio.',
          strangler: 'Strangler mueve consumidores gradualmente, sube contratos y limita el costo de cada corte.',
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
