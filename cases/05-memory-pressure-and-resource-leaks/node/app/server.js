'use strict';

const http = require('http');
const crypto = require('crypto');
const { URL } = require('url');
const { performance } = require('perf_hooks');
const fs = require('fs');
const os = require('os');
const path = require('path');

const APP_STACK = 'Node.js 20';
const CASE_NAME = '05 - Presion de memoria y fugas de recursos';
const STORAGE_DIR = path.join(os.tmpdir(), 'pdsl-case05-node');
const STATE_PATH = path.join(STORAGE_DIR, 'state.json');
const TELEMETRY_PATH = path.join(STORAGE_DIR, 'telemetry.json');

const ensureStorageDir = () => fs.mkdirSync(STORAGE_DIR, { recursive: true });
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// Module-level retention — this IS the leak. V8 will keep these alive across requests.
const legacyRetained = [];
const LEGACY_HARD_CAP = 2000;

const optimizedCache = new Map();
const OPTIMIZED_CACHE_MAX = 24;

const SCENARIO_FACTORS = {
  cache_growth: { leak_factor: 1.45, descriptor_factor: 0.4 },
  descriptor_drift: { leak_factor: 0.7, descriptor_factor: 1.4 },
  mixed_pressure: { leak_factor: 1.1, descriptor_factor: 0.9 },
};
const ALLOWED_SCENARIOS = Object.keys(SCENARIO_FACTORS);

const initialState = () => {
  const nowStr = new Date().toISOString();
  return {
    thresholds: {
      warning_retained_kb: 8192,
      critical_retained_kb: 16384,
      warning_descriptors: 60,
      critical_descriptors: 120,
    },
    modes: {
      legacy: {
        retained_kb: 0,
        retained_bytes: 0,
        retained_objects: 0,
        cache_entries: 0,
        descriptor_pressure: 0,
        gc_cycles: 0,
        last_cleanup_at: null,
        last_updated: null,
      },
      optimized: {
        retained_kb: 0,
        retained_bytes: 0,
        retained_objects: 0,
        cache_entries: 0,
        descriptor_pressure: 0,
        gc_cycles: 1,
        last_cleanup_at: nowStr,
        last_updated: null,
      },
    },
  };
};

const readState = () => {
  ensureStorageDir();
  if (!fs.existsSync(STATE_PATH)) return initialState();
  try {
    return JSON.parse(fs.readFileSync(STATE_PATH, 'utf8'));
  } catch (_e) {
    return initialState();
  }
};

const writeState = (state) => {
  ensureStorageDir();
  fs.writeFileSync(STATE_PATH, JSON.stringify(state, null, 2));
};

const initialModeMetrics = () => ({
  successes: 0,
  failures: 0,
  documents_total: 0,
  avg_peak_request_kb_samples: [],
  avg_retained_after_kb_samples: [],
  pressure_counts: { healthy: 0, warning: 0, critical: 0 },
  by_scenario: {},
});

const initialTelemetry = () => ({
  requests: 0,
  samples_ms: [],
  routes: {},
  status_counts: {},
  last_path: null,
  last_status: 200,
  last_updated: null,
  modes: { legacy: initialModeMetrics(), optimized: initialModeMetrics() },
  runs: [],
});

const readTelemetry = () => {
  ensureStorageDir();
  if (!fs.existsSync(TELEMETRY_PATH)) return initialTelemetry();
  try {
    const parsed = JSON.parse(fs.readFileSync(TELEMETRY_PATH, 'utf8'));
    const seed = initialTelemetry();
    return {
      ...seed,
      ...parsed,
      modes: {
        legacy: { ...initialModeMetrics(), ...((parsed.modes || {}).legacy || {}) },
        optimized: { ...initialModeMetrics(), ...((parsed.modes || {}).optimized || {}) },
      },
      routes: parsed.routes || {},
      status_counts: parsed.status_counts || {},
      samples_ms: parsed.samples_ms || [],
      runs: parsed.runs || [],
    };
  } catch (_e) {
    return initialTelemetry();
  }
};

const writeTelemetry = (t) => {
  ensureStorageDir();
  fs.writeFileSync(TELEMETRY_PATH, JSON.stringify(t, null, 2));
};

const percentile = (values, percent) => {
  if (!values.length) return 0;
  const sorted = [...values].sort((a, b) => a - b);
  const idx = Math.max(0, Math.min(sorted.length - 1, Math.ceil((percent / 100) * sorted.length) - 1));
  return Number(sorted[idx].toFixed(2));
};

const routeSummary = (routes) => {
  const out = {};
  for (const [route, samples] of Object.entries(routes || {})) {
    const values = Array.isArray(samples) ? samples : [];
    const count = values.length;
    out[route] = {
      count,
      avg_ms: count ? Number((values.reduce((a, b) => a + b, 0) / count).toFixed(2)) : 0,
      p95_ms: percentile(values, 95),
      p99_ms: percentile(values, 99),
      max_ms: count ? Number(Math.max(...values).toFixed(2)) : 0,
    };
  }
  return Object.fromEntries(Object.entries(out).sort(([a], [b]) => a.localeCompare(b)));
};

const telemetrySummary = (t) => {
  const samples = t.samples_ms || [];
  const count = samples.length;
  return {
    requests_tracked: t.requests || 0,
    sample_count: count,
    avg_ms: count ? Number((samples.reduce((a, b) => a + b, 0) / count).toFixed(2)) : 0,
    p95_ms: percentile(samples, 95),
    p99_ms: percentile(samples, 99),
    max_ms: count ? Number(Math.max(...samples).toFixed(2)) : 0,
    last_path: t.last_path,
    last_status: t.last_status,
    last_updated: t.last_updated,
    status_counts: t.status_counts || {},
    modes: t.modes,
    routes: routeSummary(t.routes),
  };
};

const statusBucket = (status) => (status >= 500 ? '5xx' : status >= 400 ? '4xx' : '2xx');

const pressureLevel = (retainedKb, descriptor, thresholds) => {
  if (
    retainedKb >= thresholds.critical_retained_kb ||
    descriptor >= thresholds.critical_descriptors
  ) {
    return 'critical';
  }
  if (
    retainedKb >= thresholds.warning_retained_kb ||
    descriptor >= thresholds.warning_descriptors
  ) {
    return 'warning';
  }
  return 'healthy';
};

const heapSnapshotKb = () => {
  const m = process.memoryUsage();
  return {
    heap_used_kb: Number((m.heapUsed / 1024).toFixed(2)),
    heap_total_kb: Number((m.heapTotal / 1024).toFixed(2)),
    rss_kb: Number((m.rss / 1024).toFixed(2)),
    external_kb: Number((m.external / 1024).toFixed(2)),
  };
};

const deepSizeOfBlobList = (list) => {
  let bytes = 0;
  for (const blob of list) {
    if (typeof blob === 'string') bytes += blob.length * 2;
    else if (Buffer.isBuffer(blob)) bytes += blob.length;
  }
  return bytes;
};

const deepSizeOfMap = (map) => {
  let bytes = 0;
  for (const [k, v] of map.entries()) {
    if (typeof k === 'string') bytes += k.length * 2;
    if (typeof v === 'string') bytes += v.length * 2;
    else if (typeof v === 'boolean') bytes += 4;
  }
  return bytes;
};

const runBatch = async (mode, scenario, documents, payloadKb) => {
  const factors = SCENARIO_FACTORS[scenario] || SCENARIO_FACTORS.mixed_pressure;
  const state = readState();
  const thresholds = state.thresholds;
  const ms = state.modes[mode];

  const heapBefore = heapSnapshotKb();
  const retainedKbBefore = ms.retained_kb;
  const peakRequestKb = documents * payloadKb;

  let retainedBytes = 0;
  let retainedKbAfter = 0;

  if (mode === 'legacy') {
    for (let i = 0; i < documents; i += 1) {
      const raw = crypto.randomBytes(Math.max(1, (payloadKb * 1024) / 8));
      legacyRetained.push(raw.toString('base64'));
    }
    if (legacyRetained.length > LEGACY_HARD_CAP) {
      legacyRetained.splice(0, legacyRetained.length - LEGACY_HARD_CAP);
    }
    retainedBytes = deepSizeOfBlobList(legacyRetained);
    retainedKbAfter = retainedBytes / 1024;

    ms.retained_bytes = retainedBytes;
    ms.retained_kb = Number(retainedKbAfter.toFixed(2));
    ms.retained_objects = legacyRetained.length;
    ms.cache_entries = (ms.cache_entries || 0) + documents;
    ms.descriptor_pressure =
      (ms.descriptor_pressure || 0) +
      Math.max(1, Math.round((documents / 6) * factors.descriptor_factor));
  } else {
    for (let i = 0; i < documents; i += 1) {
      const raw = crypto.randomBytes(Math.max(1, (payloadKb * 1024) / 8));
      const digest = crypto.createHash('sha256').update(raw).digest('hex').slice(0, 16);
      optimizedCache.set(digest, true);
      // raw goes out of scope; V8 GC reclaims when convenient
    }
    if (optimizedCache.size > OPTIMIZED_CACHE_MAX) {
      const drop = [...optimizedCache.keys()].slice(0, optimizedCache.size - OPTIMIZED_CACHE_MAX);
      for (const k of drop) optimizedCache.delete(k);
    }
    if (typeof globalThis.gc === 'function') globalThis.gc();
    retainedBytes = deepSizeOfMap(optimizedCache);
    retainedKbAfter = retainedBytes / 1024;

    ms.retained_bytes = retainedBytes;
    ms.retained_kb = Number(retainedKbAfter.toFixed(2));
    ms.retained_objects = optimizedCache.size;
    ms.cache_entries = optimizedCache.size;
    ms.descriptor_pressure = Math.max(
      0,
      (ms.descriptor_pressure || 0) - Math.max(1, Math.floor(documents / 4))
    );
    ms.gc_cycles = (ms.gc_cycles || 0) + 1;
    ms.last_cleanup_at = new Date().toISOString();
  }

  ms.last_updated = new Date().toISOString();
  const heapAfter = heapSnapshotKb();
  const descriptorAfter = ms.descriptor_pressure;
  const level = pressureLevel(retainedKbAfter, descriptorAfter, thresholds);
  const httpStatus = mode === 'legacy' && level === 'critical' ? 503 : 200;

  const penaltyMs =
    documents * 2.8 +
    payloadKb * 1.4 +
    retainedKbAfter / 256 +
    descriptorAfter * (mode === 'legacy' ? 2.1 : 0.7);
  await sleep(Math.min(650, Math.max(30, penaltyMs)));

  state.modes[mode] = ms;
  writeState(state);

  return {
    mode,
    scenario,
    documents,
    payload_kb: payloadKb,
    status: httpStatus === 503 ? 'degraded' : 'ok',
    pressure_level: level,
    memory: {
      peak_request_kb: Number(peakRequestKb.toFixed(2)),
      retained_kb_before: Number(retainedKbBefore.toFixed(2)),
      retained_kb_after: Number(retainedKbAfter.toFixed(2)),
      retained_bytes: ms.retained_bytes,
      retained_objects: ms.retained_objects,
      heap_before: heapBefore,
      heap_after: heapAfter,
      heap_used_delta_kb: Number((heapAfter.heap_used_kb - heapBefore.heap_used_kb).toFixed(2)),
      rss_delta_kb: Number((heapAfter.rss_kb - heapBefore.rss_kb).toFixed(2)),
      cache_entries: ms.cache_entries,
      descriptor_pressure: descriptorAfter,
      gc_cycles: ms.gc_cycles || 0,
    },
    thresholds,
    http_status: httpStatus,
  };
};

const stateSummary = () => {
  const state = readState();
  const thresholds = state.thresholds;
  const summary = {
    thresholds,
    modes: {},
    process: {
      legacy_retained_objects: legacyRetained.length,
      legacy_retained_bytes: deepSizeOfBlobList(legacyRetained),
      optimized_cache_objects: optimizedCache.size,
      optimized_cache_bytes: deepSizeOfMap(optimizedCache),
      heap: heapSnapshotKb(),
    },
  };
  for (const [mode, ms] of Object.entries(state.modes || {})) {
    summary.modes[mode] = {
      ...ms,
      pressure_level: pressureLevel(ms.retained_kb, ms.descriptor_pressure || 0, thresholds),
    };
  }
  return summary;
};

const recordRequestTelemetry = (uri, status, elapsedMs, flowContext) => {
  const t = readTelemetry();
  t.requests = (t.requests || 0) + 1;
  t.samples_ms.push(Number(elapsedMs.toFixed(2)));
  if (t.samples_ms.length > 3000) t.samples_ms = t.samples_ms.slice(-3000);
  t.routes[uri] = t.routes[uri] || [];
  t.routes[uri].push(Number(elapsedMs.toFixed(2)));
  if (t.routes[uri].length > 500) t.routes[uri] = t.routes[uri].slice(-500);
  t.last_path = uri;
  t.last_status = status;
  t.last_updated = new Date().toISOString();
  const bucket = statusBucket(status);
  t.status_counts[bucket] = (t.status_counts[bucket] || 0) + 1;

  if (flowContext) {
    const m = t.modes[flowContext.mode];
    m.documents_total = (m.documents_total || 0) + (flowContext.documents || 0);
    m.avg_peak_request_kb_samples.push(Number((flowContext.peak_request_kb || 0).toFixed(2)));
    if (m.avg_peak_request_kb_samples.length > 500) {
      m.avg_peak_request_kb_samples = m.avg_peak_request_kb_samples.slice(-500);
    }
    m.avg_retained_after_kb_samples.push(Number((flowContext.retained_kb_after || 0).toFixed(2)));
    if (m.avg_retained_after_kb_samples.length > 500) {
      m.avg_retained_after_kb_samples = m.avg_retained_after_kb_samples.slice(-500);
    }
    const level = flowContext.pressure_level || 'healthy';
    m.pressure_counts[level] = (m.pressure_counts[level] || 0) + 1;
    if (status < 500) m.successes = (m.successes || 0) + 1;
    else m.failures = (m.failures || 0) + 1;

    const sc = flowContext.scenario || 'unknown';
    m.by_scenario[sc] = m.by_scenario[sc] || { runs: 0, failures: 0 };
    m.by_scenario[sc].runs += 1;
    if (status >= 500) m.by_scenario[sc].failures += 1;

    t.runs.push({
      mode: flowContext.mode,
      scenario: sc,
      documents: flowContext.documents || 0,
      payload_kb: flowContext.payload_kb || 0,
      pressure_level: level,
      retained_kb_after: Number((flowContext.retained_kb_after || 0).toFixed(2)),
      heap_used_delta_kb: flowContext.heap_used_delta_kb || 0,
      http_status: status,
      elapsed_ms: Number(elapsedMs.toFixed(2)),
      timestamp_utc: new Date().toISOString(),
    });
    if (t.runs.length > 80) t.runs = t.runs.slice(-80);
  }
  writeTelemetry(t);
};

const clampInt = (value, min, max) => {
  const parsed = Number.parseInt(value, 10);
  if (Number.isNaN(parsed)) return min;
  return Math.max(min, Math.min(max, parsed));
};

const prometheusLabel = (v) =>
  String(v).replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, ' ');

const renderPrometheusMetrics = () => {
  const summary = telemetrySummary(readTelemetry());
  const st = stateSummary();
  const lines = [];
  lines.push('# HELP app_requests_total Total de requests observados.');
  lines.push('# TYPE app_requests_total counter');
  lines.push(`app_requests_total ${summary.requests_tracked || 0}`);

  for (const [mode, m] of Object.entries(summary.modes || {})) {
    const lm = prometheusLabel(mode);
    lines.push(`app_flow_success_total{mode="${lm}"} ${m.successes || 0}`);
    lines.push(`app_flow_failure_total{mode="${lm}"} ${m.failures || 0}`);
  }
  for (const [mode, ms] of Object.entries(st.modes || {})) {
    const lm = prometheusLabel(mode);
    lines.push(`app_retained_memory_kb{mode="${lm}"} ${ms.retained_kb || 0}`);
    lines.push(`app_retained_objects{mode="${lm}"} ${ms.retained_objects || 0}`);
    lines.push(`app_descriptor_pressure{mode="${lm}"} ${ms.descriptor_pressure || 0}`);
    for (const level of ['healthy', 'warning', 'critical']) {
      const v = (summary.modes[mode] || {}).pressure_counts?.[level] || 0;
      lines.push(`app_pressure_level{mode="${lm}",level="${level}"} ${v}`);
    }
  }
  const proc = st.process || {};
  lines.push(`app_heap_used_kb ${proc.heap?.heap_used_kb || 0}`);
  lines.push(`app_heap_total_kb ${proc.heap?.heap_total_kb || 0}`);
  lines.push(`app_rss_kb ${proc.heap?.rss_kb || 0}`);
  lines.push(`app_legacy_retained_objects ${proc.legacy_retained_objects || 0}`);
  lines.push(`app_optimized_cache_objects ${proc.optimized_cache_objects || 0}`);
  return `${lines.join('\n')}\n`;
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
  let skipStoreMetrics = false;
  let flowContext = null;

  try {
    if (uri === '/' || uri === '') {
      payload = {
        lab: 'Problem-Driven Systems Lab',
        case: CASE_NAME,
        stack: APP_STACK,
        goal:
          'Comparar un procesamiento que acumula memoria sin limpiar (legacy) contra uno que controla su huella (optimized) usando primitivas Node.',
        measurement: 'process.memoryUsage() para heap_used / heap_total / rss / external',
        routes: {
          '/health': 'Estado basico.',
          '/batch-legacy?scenario=mixed_pressure&documents=24&payload_kb=64':
            'Modo legacy: array de modulo retiene blobs base64 — fuga real cross-request.',
          '/batch-optimized?scenario=mixed_pressure&documents=24&payload_kb=64':
            'Modo optimizado: Map acotado con eviction + scope limpio para los Buffers.',
          '/state': 'Estado actual de memoria y descriptores por modo, con medicion real de heap V8.',
          '/runs?limit=10': 'Ultimas runs registradas.',
          '/diagnostics/summary': 'Resumen completo de telemetria.',
          '/metrics': 'Metricas JSON.',
          '/metrics-prometheus': 'Metricas en formato Prometheus.',
          '/reset-lab': 'Reinicia estado, telemetria y referencias de modulo.',
        },
        allowed_scenarios: ALLOWED_SCENARIOS,
        node_specific:
          'En Node, retener cierres y arrays grandes se ve directo en heapUsed/heapTotal/RSS. La diferencia entre legacy y optimized es la diferencia entre dejar al GC sin trabajo posible (referencias vivas en modulo) y permitirle reclamar (scope cerrado + Map acotado).',
      };
    } else if (uri === '/health') {
      payload = { status: 'ok', stack: APP_STACK };
    } else if (uri === '/batch-legacy' || uri === '/batch-optimized') {
      const mode = uri === '/batch-legacy' ? 'legacy' : 'optimized';
      let scenario = url.searchParams.get('scenario') || 'mixed_pressure';
      if (!ALLOWED_SCENARIOS.includes(scenario)) scenario = 'mixed_pressure';
      const documents = clampInt(url.searchParams.get('documents') || '24', 1, 200);
      const payloadKb = clampInt(url.searchParams.get('payload_kb') || '64', 1, 512);

      const result = await runBatch(mode, scenario, documents, payloadKb);
      status = result.http_status;
      flowContext = {
        mode,
        scenario,
        documents,
        payload_kb: payloadKb,
        pressure_level: result.pressure_level,
        peak_request_kb: result.memory.peak_request_kb,
        retained_kb_after: result.memory.retained_kb_after,
        heap_used_delta_kb: result.memory.heap_used_delta_kb,
      };
      payload = { ...result };
      delete payload.http_status;
    } else if (uri === '/state') {
      payload = stateSummary();
    } else if (uri === '/runs') {
      const limit = clampInt(url.searchParams.get('limit') || '10', 1, 80);
      const t = readTelemetry();
      payload = { limit, runs: [...(t.runs || [])].reverse().slice(0, limit) };
    } else if (uri === '/diagnostics/summary') {
      payload = {
        case: CASE_NAME,
        stack: APP_STACK,
        metrics: telemetrySummary(readTelemetry()),
        state: stateSummary(),
        scenario_factors: SCENARIO_FACTORS,
      };
    } else if (uri === '/metrics') {
      payload = { case: CASE_NAME, stack: APP_STACK, ...telemetrySummary(readTelemetry()) };
    } else if (uri === '/metrics-prometheus') {
      skipStoreMetrics = true;
      res.writeHead(200, { 'Content-Type': 'text/plain; version=0.0.4; charset=utf-8' });
      res.end(renderPrometheusMetrics());
      return;
    } else if (uri === '/reset-lab') {
      skipStoreMetrics = true;
      legacyRetained.length = 0;
      optimizedCache.clear();
      if (typeof globalThis.gc === 'function') globalThis.gc();
      writeState(initialState());
      writeTelemetry(initialTelemetry());
      payload = {
        status: 'reset',
        message: 'Estado, telemetria y referencias de modulo reiniciados.',
      };
    } else {
      status = 404;
      payload = { error: 'Ruta no encontrada', path: uri };
    }
  } catch (error) {
    status = 500;
    payload = { error: 'Fallo al procesar la solicitud', message: error.message, path: uri };
  }

  const elapsedMs = performance.now() - started;
  if (!skipStoreMetrics && uri !== '/metrics') {
    recordRequestTelemetry(uri, status, elapsedMs, flowContext);
  }
  payload.elapsed_ms = Number(elapsedMs.toFixed(2));
  payload.timestamp_utc = new Date().toISOString();
  payload.pid = process.pid;
  sendJson(res, status, payload);
};

ensureStorageDir();
const PORT = Number.parseInt(process.env.PORT || '8080', 10);
http.createServer(handler).listen(PORT, '0.0.0.0', () => {
  console.log(`Servidor Node escuchando en ${PORT}`);
});
