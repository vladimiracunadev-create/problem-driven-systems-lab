'use strict';

const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { URL } = require('url');
const { performance, monitorEventLoopDelay } = require('perf_hooks');

const APP_STACK = 'Node.js 20';
const CASE_NAME = '11 - Reportes pesados que bloquean la operacion';
const STORAGE_DIR = path.join(os.tmpdir(), 'pdsl-case11-node');
const STATE_PATH = path.join(STORAGE_DIR, 'reporting-state.json');
const TELEMETRY_PATH = path.join(STORAGE_DIR, 'telemetry.json');

const SCENARIO_CATALOG = {
  end_of_month: { legacy_load: 52, legacy_lock: 34, isolated_queue: 18, isolated_lag: 22, hint: 'Cierre de mes con gran volumen y prioridad financiera.' },
  finance_audit: { legacy_load: 46, legacy_lock: 28, isolated_queue: 14, isolated_lag: 18, hint: 'Auditoria que necesita cortes historicos consistentes.' },
  ad_hoc_export: { legacy_load: 35, legacy_lock: 20, isolated_queue: 10, isolated_lag: 12, hint: 'Export solicitado por negocio sin planificacion previa.' },
  mixed_peak: { legacy_load: 62, legacy_lock: 38, isolated_queue: 24, isolated_lag: 24, hint: 'Reporting pesado compitiendo justo cuando la operacion transaccional esta en pico.' },
};
const SCENARIOS = Object.keys(SCENARIO_CATALOG);

// Histograma del event loop — primitiva nativa Node para medir lag real bajo carga.
const ELOOP = monitorEventLoopDelay({ resolution: 10 });
ELOOP.enable();

const ensureDir = () => fs.mkdirSync(STORAGE_DIR, { recursive: true });
const nowIso = () => new Date().toISOString();
const reqId = (p) => `${p}-${crypto.randomBytes(4).toString('hex')}`;

const initialState = () => ({
  reporting: {
    primary_load: 28,
    lock_pressure: 12,
    replica_lag_s: 4,
    snapshot_freshness_min: 15,
    queue_depth: 0,
    total_exports: 0,
    total_operational_writes: 0,
    last_report_at: null,
  },
});

const initialModeMetrics = () => ({
  successes: 0,
  failures: 0,
  primary_load_samples: [],
  ops_impact_samples: [],
  replica_lag_samples: [],
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
  modes: {
    legacy: initialModeMetrics(),
    isolated: initialModeMetrics(),
    operations: initialModeMetrics(),
  },
  activity: [],
});

const readJson = (file, fb) => { ensureDir(); if (!fs.existsSync(file)) return fb(); try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch (_e) { return fb(); } };
const writeJson = (file, d) => { ensureDir(); fs.writeFileSync(file, JSON.stringify(d, null, 2)); };
const readState = () => readJson(STATE_PATH, initialState);
const writeState = (s) => writeJson(STATE_PATH, s);
const readTelemetry = () => readJson(TELEMETRY_PATH, initialTelemetry);
const writeTelemetry = (t) => writeJson(TELEMETRY_PATH, t);

const pressureLevel = (st) => {
  const load = st.primary_load || 0;
  const locks = st.lock_pressure || 0;
  if (load >= 90 || locks >= 75) return 'critical';
  if (load >= 65 || locks >= 45) return 'warning';
  return 'healthy';
};

const stateSummary = () => {
  const st = readState().reporting;
  return { ...st, pressure_level: pressureLevel(st) };
};

// Bloqueo sincronico que castiga el event loop — equivalente a un long-running query.
const blockEventLoop = (ms) => {
  const end = Date.now() + ms;
  while (Date.now() < end) { /* spin */ }
};

const runReportFlow = async (mode, scenario, rows) => {
  const meta = SCENARIO_CATALOG[scenario];
  const state = readState();
  const reporting = state.reporting;
  const flowId = reqId('report');
  const primaryBefore = reporting.primary_load;
  const lockBefore = reporting.lock_pressure;
  let httpStatus = 200;
  let errorMessage = null;

  try {
    if (mode === 'legacy') {
      // Legacy: el reporting bloquea el event loop principal — afecta a TODA peticion concurrente.
      reporting.primary_load = Math.min(100, primaryBefore + meta.legacy_load + Math.floor(rows / 150000));
      reporting.lock_pressure = Math.min(100, lockBefore + meta.legacy_lock);
      const blockMs = Math.min(900, 200 + Math.floor(rows / 1000));
      blockEventLoop(blockMs);
    } else {
      // Isolated: encolamos en background sin tocar el primary load — usamos setImmediate
      // para programar el trabajo "fuera" del path critico de la request.
      await new Promise((r) => setImmediate(r));
      reporting.queue_depth = Math.min(120, (reporting.queue_depth || 0) + meta.isolated_queue);
      reporting.replica_lag_s = Math.min(180, (reporting.replica_lag_s || 0) + meta.isolated_lag);
    }
  } catch (e) {
    httpStatus = 503;
    errorMessage = `Error I/O: ${e.message}`;
  }

  reporting.total_exports = (reporting.total_exports || 0) + 1;
  reporting.last_report_at = nowIso();
  writeState(state);
  const summary = stateSummary();
  if (mode === 'legacy' && summary.pressure_level === 'critical') {
    httpStatus = 503;
    errorMessage = errorMessage || 'Critical Pressure Reached';
  }
  const outcome = httpStatus >= 400 ? 'failure' : 'success';
  const opsImpactMs = Math.round(summary.primary_load * 3.1 + summary.lock_pressure * 2.4);

  const payload = {
    mode,
    scenario,
    rows,
    status: httpStatus >= 400 ? 'failed' : 'completed',
    message:
      mode === 'legacy' && httpStatus >= 400
        ? errorMessage
        : mode === 'legacy'
          ? 'Legacy bloqueo el event loop con un volcado pesado — el efecto se nota en /order-write concurrente.'
          : 'Isolated programa el reporte fuera del path critico, dejando snapshot/replica para el consumo pesado.',
    flow_id: flowId,
    primary_load_before: primaryBefore,
    primary_load_after: summary.primary_load,
    lock_pressure_before: lockBefore,
    lock_pressure_after: summary.lock_pressure,
    replica_lag_s: summary.replica_lag_s,
    queue_depth: summary.queue_depth,
    ops_latency_impact_ms: opsImpactMs,
    event_loop_lag_ms_p99: Number((ELOOP.percentile(99) / 1e6).toFixed(2)),
    event_loop_lag_ms_max: Number((ELOOP.max / 1e6).toFixed(2)),
    scenario_hint: meta.hint,
    reporting_state: summary,
  };
  if (httpStatus >= 400) payload.error = 'El mecanismo de lock bloqueo duramente los escritores concurrentes.';
  return {
    http_status: httpStatus,
    payload,
    context: {
      mode, scenario, rows, outcome,
      primary_load_after: summary.primary_load,
      ops_latency_impact_ms: opsImpactMs,
      replica_lag_s: summary.replica_lag_s,
      flow_id: flowId,
    },
  };
};

const runWriteFlow = async (orders) => {
  const state = readState();
  const reporting = state.reporting;
  const flowId = reqId('write');
  const primaryLoad = reporting.primary_load || 0;
  const lockPressure = reporting.lock_pressure || 0;
  const latencyMs = Math.round(35 + orders * 2.1 + primaryLoad * 1.6 + lockPressure * 1.3);
  let httpStatus = 200;

  if (primaryLoad >= 90 || lockPressure >= 75) {
    httpStatus = 503;
  } else {
    await new Promise((r) => setTimeout(r, Math.min(500, latencyMs)));
  }

  reporting.primary_load = Math.min(100, primaryLoad + Math.max(1, Math.ceil(orders / 8)));
  reporting.total_operational_writes = (reporting.total_operational_writes || 0) + orders;
  writeState(state);

  const summary = stateSummary();
  const outcome = httpStatus >= 400 ? 'failure' : 'success';
  const payload = {
    mode: 'operations',
    scenario: 'write_path',
    orders,
    status: httpStatus >= 400 ? 'failed' : 'completed',
    message:
      httpStatus >= 400
        ? 'La operacion ya siente el bloqueo del reporting y la escritura queda degradada.'
        : 'La escritura sigue viva, pero el costo ya refleja la presion que deja el reporting sobre la operacion.',
    flow_id: flowId,
    write_latency_ms: latencyMs,
    primary_load_after: summary.primary_load,
    lock_pressure_after: summary.lock_pressure,
    event_loop_lag_ms_p99: Number((ELOOP.percentile(99) / 1e6).toFixed(2)),
    reporting_state: summary,
  };
  return {
    http_status: httpStatus,
    payload,
    context: {
      mode: 'operations', scenario: 'write_path', rows: orders, outcome,
      primary_load_after: summary.primary_load,
      ops_latency_impact_ms: latencyMs,
      replica_lag_s: summary.replica_lag_s,
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
    m.primary_load_samples.push(ctx.primary_load_after || 0);
    m.ops_impact_samples.push(ctx.ops_latency_impact_ms || 0);
    m.replica_lag_samples.push(ctx.replica_lag_s || 0);
    if (m.primary_load_samples.length > 200) m.primary_load_samples = m.primary_load_samples.slice(-200);
    if (m.ops_impact_samples.length > 200) m.ops_impact_samples = m.ops_impact_samples.slice(-200);
    if (m.replica_lag_samples.length > 200) m.replica_lag_samples = m.replica_lag_samples.slice(-200);
    if (ctx.outcome === 'success') m.successes += 1;
    else m.failures += 1;
    t.modes[ctx.mode] = m;
    t.activity.push({ ...ctx, status_code: status, elapsed_ms: Number(elapsedMs.toFixed(2)), timestamp_utc: nowIso() });
    if (t.activity.length > 80) t.activity = t.activity.slice(-80);
  }
  writeTelemetry(t);
};

const telemetrySummary = (t) => {
  const samples = t.samples_ms || [];
  const count = samples.length;
  const modes = {};
  for (const [name, m] of Object.entries(t.modes || {})) {
    const pl = m.primary_load_samples || [];
    const oi = m.ops_impact_samples || [];
    const rl = m.replica_lag_samples || [];
    modes[name] = {
      successes: m.successes || 0,
      failures: m.failures || 0,
      avg_primary_load_after: pl.length ? Number((pl.reduce((a, b) => a + b, 0) / pl.length).toFixed(2)) : 0,
      avg_ops_latency_impact_ms: oi.length ? Number((oi.reduce((a, b) => a + b, 0) / oi.length).toFixed(2)) : 0,
      avg_replica_lag_s: rl.length ? Number((rl.reduce((a, b) => a + b, 0) / rl.length).toFixed(2)) : 0,
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
    event_loop: {
      p50_ms: Number((ELOOP.percentile(50) / 1e6).toFixed(2)),
      p99_ms: Number((ELOOP.percentile(99) / 1e6).toFixed(2)),
      max_ms: Number((ELOOP.max / 1e6).toFixed(2)),
    },
    recent_activity: [...(t.activity || [])].reverse(),
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
    out.push(`app_report_success_total{mode="${lm}"} ${m.successes}`);
    out.push(`app_report_failure_total{mode="${lm}"} ${m.failures}`);
    out.push(`app_report_avg_primary_load{mode="${lm}"} ${m.avg_primary_load_after}`);
    out.push(`app_report_avg_ops_latency_impact_ms{mode="${lm}"} ${m.avg_ops_latency_impact_ms}`);
  }
  out.push(`app_primary_load_current ${st.primary_load}`);
  out.push(`app_lock_pressure_current ${st.lock_pressure}`);
  out.push(`app_event_loop_lag_p50_ms ${sum.event_loop.p50_ms}`);
  out.push(`app_event_loop_lag_p99_ms ${sum.event_loop.p99_ms}`);
  out.push(`app_event_loop_lag_max_ms ${sum.event_loop.max_ms}`);
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
        goal: 'Mostrar como reportes pesados castigan al sistema operacional, y como aislarlos para no romper el path transaccional.',
        node_specific:
          'Medimos el efecto con `perf_hooks.monitorEventLoopDelay()`. En `report-legacy` el handler ejecuta un bloqueo sincronico que castiga al event loop entero — `event_loop_lag_ms_p99` sube y `/order-write` concurrente lo paga. En `report-isolated` el trabajo se programa con setImmediate, dejando el path critico libre.',
        routes: {
          '/health': 'Estado basico del servicio.',
          '/report-legacy?scenario=end_of_month&rows=600000': 'Reporte sobre la operacion transaccional.',
          '/report-isolated?scenario=end_of_month&rows=600000': 'Reporte programado fuera del path critico.',
          '/order-write?orders=25': 'Simula escritura operativa con la presion actual.',
          '/activity?limit=10': 'Ultimas actividades observadas.',
          '/diagnostics/summary': 'Resumen de presion, lag y event loop.',
          '/metrics': 'Metricas JSON.',
          '/metrics-prometheus': 'Metricas en formato Prometheus.',
          '/reset-lab': 'Reinicia estado y metricas.',
        },
        allowed_scenarios: SCENARIOS,
      };
    } else if (uri === '/health') {
      payload = { status: 'ok', stack: APP_STACK };
    } else if (uri === '/report-legacy' || uri === '/report-isolated') {
      const mode = uri === '/report-legacy' ? 'legacy' : 'isolated';
      let scenario = url.searchParams.get('scenario') || 'end_of_month';
      if (!SCENARIOS.includes(scenario)) scenario = 'end_of_month';
      const rows = clampInt(url.searchParams.get('rows') || '300000', 1000, 5000000);
      const result = await runReportFlow(mode, scenario, rows);
      status = result.http_status;
      ctx = result.context;
      payload = result.payload;
    } else if (uri === '/order-write') {
      const orders = clampInt(url.searchParams.get('orders') || '25', 1, 500);
      const result = await runWriteFlow(orders);
      status = result.http_status;
      ctx = result.context;
      payload = result.payload;
    } else if (uri === '/activity') {
      const limit = clampInt(url.searchParams.get('limit') || '10', 1, 80);
      payload = { limit, activity: telemetrySummary(readTelemetry()).recent_activity.slice(0, limit) };
    } else if (uri === '/diagnostics/summary') {
      payload = {
        case: CASE_NAME,
        stack: APP_STACK,
        reporting: stateSummary(),
        metrics: telemetrySummary(readTelemetry()),
        interpretation: {
          legacy: 'Legacy ejecuta el reporte sobre el primary y bloquea el event loop — ops latency sube en paralelo.',
          isolated: 'Isolated mueve el reporte a snapshot/replica/cola programada y deja el primary libre.',
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
      ELOOP.reset();
      payload = { status: 'reset', message: 'Estado, metricas y event loop histogram reiniciados.' };
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
