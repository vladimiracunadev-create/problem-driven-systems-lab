'use strict';

const http = require('http');
const crypto = require('crypto');
const { URL } = require('url');
const { performance } = require('perf_hooks');
const fs = require('fs');
const os = require('os');
const path = require('path');

const APP_STACK = 'Node.js 20';
const CASE_NAME = '04 - Cadena de timeouts y tormentas de reintentos';
const STORAGE_DIR = path.join(os.tmpdir(), 'pdsl-case04-node');
const TELEMETRY_PATH = path.join(STORAGE_DIR, 'telemetry.json');
const DEPENDENCY_PATH = path.join(STORAGE_DIR, 'dependency_state.json');

const ensureStorageDir = () => fs.mkdirSync(STORAGE_DIR, { recursive: true });

const POLICIES = {
  legacy: {
    timeout_ms: 360,
    max_attempts: 4,
    backoff_base_ms: 0,
    use_circuit_breaker: false,
    allow_fallback: false,
  },
  resilient: {
    timeout_ms: 220,
    max_attempts: 2,
    backoff_base_ms: 80,
    use_circuit_breaker: true,
    allow_fallback: true,
  },
};
const CB_OPEN_THRESHOLD = 2;
const CB_OPEN_DURATION_MS = 30000;

const ALLOWED_SCENARIOS = [
  'ok',
  'slow_provider',
  'flaky_provider',
  'provider_down',
  'burst_then_recover',
];

const sleep = (ms, signal) =>
  new Promise((resolve, reject) => {
    if (signal && signal.aborted) {
      reject(new Error('aborted'));
      return;
    }
    const t = setTimeout(() => resolve(), ms);
    if (signal) {
      signal.addEventListener('abort', () => {
        clearTimeout(t);
        reject(new Error('aborted'));
      });
    }
  });

const initialDependencyState = () => ({
  provider: {
    name: 'carrier-gateway',
    consecutive_failures: 0,
    opened_until: null,
    last_outcome: 'unknown',
    last_latency_ms: 0,
    last_updated: null,
    open_events: 0,
    short_circuit_count: 0,
    fallback_quote: {
      quote_id: 'cached-quote-seed',
      amount: 47.8,
      currency: 'USD',
      source: 'cached',
      cached_at: new Date().toISOString(),
    },
  },
});

const readDependencyState = () => {
  ensureStorageDir();
  if (!fs.existsSync(DEPENDENCY_PATH)) return initialDependencyState();
  try {
    return JSON.parse(fs.readFileSync(DEPENDENCY_PATH, 'utf8'));
  } catch (_e) {
    return initialDependencyState();
  }
};

const writeDependencyState = (state) => {
  ensureStorageDir();
  fs.writeFileSync(DEPENDENCY_PATH, JSON.stringify(state, null, 2));
};

const initialModeMetrics = () => ({
  successes: 0,
  failures: 0,
  attempts_total: 0,
  retries_total: 0,
  timeouts_total: 0,
  fallbacks_used: 0,
  circuit_opens: 0,
  short_circuits: 0,
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
  modes: { legacy: initialModeMetrics(), resilient: initialModeMetrics() },
  recent_incidents: [],
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
        resilient: { ...initialModeMetrics(), ...((parsed.modes || {}).resilient || {}) },
      },
      routes: parsed.routes || {},
      status_counts: parsed.status_counts || {},
      samples_ms: parsed.samples_ms || [],
      recent_incidents: parsed.recent_incidents || [],
    };
  } catch (_e) {
    return initialTelemetry();
  }
};

const writeTelemetry = (telemetry) => {
  ensureStorageDir();
  fs.writeFileSync(TELEMETRY_PATH, JSON.stringify(telemetry, null, 2));
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

const telemetrySummary = (telemetry) => {
  const samples = telemetry.samples_ms || [];
  const count = samples.length;
  return {
    requests_tracked: telemetry.requests || 0,
    sample_count: count,
    avg_ms: count ? Number((samples.reduce((a, b) => a + b, 0) / count).toFixed(2)) : 0,
    p95_ms: percentile(samples, 95),
    p99_ms: percentile(samples, 99),
    max_ms: count ? Number(Math.max(...samples).toFixed(2)) : 0,
    last_path: telemetry.last_path,
    last_status: telemetry.last_status,
    last_updated: telemetry.last_updated,
    status_counts: telemetry.status_counts || {},
    modes: telemetry.modes,
    routes: routeSummary(telemetry.routes),
    recent_incidents: [...(telemetry.recent_incidents || [])].reverse().slice(0, 50),
  };
};

const statusBucket = (status) => (status >= 500 ? '5xx' : status >= 400 ? '4xx' : '2xx');

const recordRequestTelemetry = (uri, status, elapsedMs, flowContext) => {
  const telemetry = readTelemetry();
  telemetry.requests = (telemetry.requests || 0) + 1;
  telemetry.samples_ms.push(Number(elapsedMs.toFixed(2)));
  if (telemetry.samples_ms.length > 3000) telemetry.samples_ms = telemetry.samples_ms.slice(-3000);
  telemetry.routes[uri] = telemetry.routes[uri] || [];
  telemetry.routes[uri].push(Number(elapsedMs.toFixed(2)));
  if (telemetry.routes[uri].length > 500) {
    telemetry.routes[uri] = telemetry.routes[uri].slice(-500);
  }
  telemetry.last_path = uri;
  telemetry.last_status = status;
  telemetry.last_updated = new Date().toISOString();
  const bucket = statusBucket(status);
  telemetry.status_counts[bucket] = (telemetry.status_counts[bucket] || 0) + 1;

  if (flowContext) {
    const m = telemetry.modes[flowContext.mode];
    m.attempts_total += flowContext.attempts || 0;
    m.retries_total += flowContext.retries || 0;
    m.timeouts_total += flowContext.timeout_count || 0;
    if (flowContext.short_circuited) m.short_circuits += 1;
    if (flowContext.circuit_opened) m.circuit_opens += 1;
    if (flowContext.fallback_used) m.fallbacks_used += 1;
    const success = flowContext.outcome === 'success' || flowContext.status === 'completed';
    if (success) m.successes += 1;
    else m.failures += 1;
    const sc = flowContext.scenario || 'unknown';
    m.by_scenario[sc] = m.by_scenario[sc] || { successes: 0, failures: 0 };
    if (success) m.by_scenario[sc].successes += 1;
    else m.by_scenario[sc].failures += 1;

    telemetry.recent_incidents.push({
      mode: flowContext.mode,
      scenario: sc,
      status: flowContext.status,
      http_status: status,
      attempts: flowContext.attempts || 0,
      retries: flowContext.retries || 0,
      timeout_count: flowContext.timeout_count || 0,
      fallback_used: !!flowContext.fallback_used,
      short_circuited: !!flowContext.short_circuited,
      elapsed_ms: Number(elapsedMs.toFixed(2)),
      timestamp_utc: new Date().toISOString(),
    });
    if (telemetry.recent_incidents.length > 50) {
      telemetry.recent_incidents = telemetry.recent_incidents.slice(-50);
    }
  }
  writeTelemetry(telemetry);
};

const clampInt = (value, min, max) => {
  const parsed = Number.parseInt(value, 10);
  if (Number.isNaN(parsed)) return min;
  return Math.max(min, Math.min(max, parsed));
};

const randBetween = (min, max) => min + Math.random() * (max - min);

const simulateProviderCall = async (scenario, attempt, signal) => {
  let latencyMs;
  let success;

  if (scenario === 'ok') {
    latencyMs = randBetween(115, 150);
    success = true;
  } else if (scenario === 'slow_provider') {
    latencyMs = randBetween(640, 730);
    success = false;
  } else if (scenario === 'flaky_provider') {
    if (attempt === 1) {
      latencyMs = randBetween(520, 560);
      success = false;
    } else {
      latencyMs = randBetween(150, 195);
      success = true;
    }
  } else if (scenario === 'provider_down') {
    latencyMs = 1000;
    success = false;
  } else if (scenario === 'burst_then_recover') {
    if (attempt <= 2) {
      latencyMs = randBetween(500, 580);
      success = false;
    } else {
      latencyMs = randBetween(130, 180);
      success = true;
    }
  } else {
    latencyMs = randBetween(115, 150);
    success = true;
  }

  await sleep(latencyMs, signal);
  return { latencyMs, success };
};

const callWithTimeout = async (scenario, attempt, timeoutMs) => {
  const ac = new AbortController();
  const t = setTimeout(() => ac.abort(), timeoutMs);
  const started = performance.now();
  try {
    const { latencyMs, success } = await simulateProviderCall(scenario, attempt, ac.signal);
    clearTimeout(t);
    return { elapsedMs: performance.now() - started, success, timedOut: false, latencyMs };
  } catch (_e) {
    clearTimeout(t);
    return { elapsedMs: performance.now() - started, success: false, timedOut: true, latencyMs: timeoutMs };
  }
};

const backoffForAttempt = (policy, attempt) => {
  if (policy.backoff_base_ms === 0) return 0;
  const jitter = randBetween(15, 45);
  return policy.backoff_base_ms * Math.pow(2, Math.max(0, attempt - 1)) + jitter;
};

const runQuote = async (mode, scenario, customerId, items) => {
  const policy = POLICIES[mode];
  const reqId = `req-${crypto.randomBytes(4).toString('hex')}`;
  const traceId = `trace-${crypto.randomBytes(4).toString('hex')}`;
  const events = [];
  let attempt = 0;
  let timeoutCount = 0;
  let circuitOpenedThisCall = false;
  let shortCircuited = false;
  let finalQuote = null;
  let finalStatus = 'failed';
  let httpStatus = 200;

  let depState = readDependencyState();
  let provider = depState.provider;

  if (policy.use_circuit_breaker && provider.opened_until) {
    if (Date.now() < provider.opened_until) {
      provider.short_circuit_count = (provider.short_circuit_count || 0) + 1;
      depState.provider = provider;
      writeDependencyState(depState);
      shortCircuited = true;
    } else {
      provider.opened_until = null;
      provider.consecutive_failures = 0;
      depState.provider = provider;
      writeDependencyState(depState);
    }
  }

  if (shortCircuited) {
    events.push({ event: 'circuit_open', action: 'short_circuit', attempt: 0 });
    if (policy.allow_fallback) {
      finalQuote = { ...provider.fallback_quote, source: 'fallback' };
      finalStatus = 'degraded';
      httpStatus = 200;
    } else {
      httpStatus = 503;
    }
  } else {
    while (attempt < policy.max_attempts) {
      attempt += 1;
      if (attempt > 1) {
        const wait = backoffForAttempt(policy, attempt - 1);
        if (wait > 0) {
          await sleep(wait);
          events.push({ event: 'backoff', attempt, wait_ms: Number(wait.toFixed(2)) });
        }
      }

      const { elapsedMs, success, timedOut } = await callWithTimeout(
        scenario,
        attempt,
        policy.timeout_ms
      );

      if (!success || timedOut) {
        timeoutCount += 1;
        events.push({
          event: 'attempt_failed',
          attempt,
          reason: timedOut ? 'timeout' : 'provider_error',
          elapsed_ms: Number(elapsedMs.toFixed(2)),
        });
        depState = readDependencyState();
        provider = depState.provider;
        provider.consecutive_failures = (provider.consecutive_failures || 0) + 1;
        provider.last_outcome = 'failure';
        provider.last_latency_ms = Number(elapsedMs.toFixed(2));
        provider.last_updated = new Date().toISOString();

        if (
          policy.use_circuit_breaker &&
          provider.consecutive_failures >= CB_OPEN_THRESHOLD &&
          !provider.opened_until
        ) {
          provider.opened_until = Date.now() + CB_OPEN_DURATION_MS;
          provider.open_events = (provider.open_events || 0) + 1;
          circuitOpenedThisCall = true;
          events.push({
            event: 'circuit_opened',
            attempt,
            consecutive_failures: provider.consecutive_failures,
          });
        }
        depState.provider = provider;
        writeDependencyState(depState);

        if (circuitOpenedThisCall && policy.allow_fallback) break;
      } else {
        events.push({ event: 'attempt_success', attempt, elapsed_ms: Number(elapsedMs.toFixed(2)) });
        depState = readDependencyState();
        provider = depState.provider;
        provider.consecutive_failures = 0;
        provider.opened_until = null;
        provider.last_outcome = 'success';
        provider.last_latency_ms = Number(elapsedMs.toFixed(2));
        provider.last_updated = new Date().toISOString();
        depState.provider = provider;
        writeDependencyState(depState);

        finalQuote = {
          quote_id: `quote-${crypto.randomBytes(5).toString('hex')}`,
          amount: 47.8,
          currency: 'USD',
          source: 'live',
        };
        finalStatus = 'completed';
        httpStatus = 200;
        break;
      }
    }

    if (!finalQuote) {
      if (policy.allow_fallback) {
        depState = readDependencyState();
        finalQuote = { ...depState.provider.fallback_quote, source: 'fallback' };
        finalStatus = 'degraded';
        httpStatus = 200;
        events.push({ event: 'fallback_used' });
      } else {
        finalStatus = 'failed';
        httpStatus = 503;
      }
    }
  }

  depState = readDependencyState();
  const opened = depState.provider.opened_until;
  const circuitOpen = !!(opened && Date.now() < opened);

  return {
    result: {
      mode,
      scenario,
      status: finalStatus,
      request_id: reqId,
      trace_id: traceId,
      customer_id: customerId,
      items,
      quote: finalQuote,
      attempts: attempt,
      retries: Math.max(0, attempt - 1),
      timeout_count: timeoutCount,
      events,
      dependency: {
        name: depState.provider.name,
        circuit_status: circuitOpen ? 'open' : 'closed',
        consecutive_failures: depState.provider.consecutive_failures,
        last_outcome: depState.provider.last_outcome,
        last_latency_ms: depState.provider.last_latency_ms,
        last_updated: depState.provider.last_updated,
      },
    },
    http_status: httpStatus,
    short_circuited: shortCircuited,
    circuit_opened: circuitOpenedThisCall,
    fallback_used: (finalQuote || {}).source === 'fallback',
  };
};

const prometheusLabel = (v) =>
  String(v).replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, ' ');

const renderPrometheusMetrics = () => {
  const summary = telemetrySummary(readTelemetry());
  const dep = readDependencyState();
  const lines = [];
  lines.push('# HELP app_requests_total Total de requests observados.');
  lines.push('# TYPE app_requests_total counter');
  lines.push(`app_requests_total ${summary.requests_tracked || 0}`);

  for (const [mode, m] of Object.entries(summary.modes || {})) {
    const lm = prometheusLabel(mode);
    lines.push(`app_flow_success_total{mode="${lm}"} ${m.successes || 0}`);
    lines.push(`app_flow_failure_total{mode="${lm}"} ${m.failures || 0}`);
    lines.push(`app_flow_timeouts_total{mode="${lm}"} ${m.timeouts_total || 0}`);
    lines.push(`app_flow_fallback_total{mode="${lm}"} ${m.fallbacks_used || 0}`);
    lines.push(`app_flow_circuit_open_total{mode="${lm}"} ${m.circuit_opens || 0}`);
    const denom = Math.max(1, (m.successes || 0) + (m.failures || 0));
    lines.push(`app_flow_avg_attempts{mode="${lm}"} ${((m.attempts_total || 0) / denom).toFixed(3)}`);
  }
  const provider = dep.provider || {};
  const cbOpen = provider.opened_until && Date.now() < provider.opened_until ? 1 : 0;
  lines.push(`dependency_circuit_open{provider="${prometheusLabel(provider.name || 'unknown')}"} ${cbOpen}`);
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
          'Comparar una politica de reintentos agresiva (legacy) contra una resiliente con AbortController, circuit breaker y fallback.',
        routes: {
          '/health': 'Estado basico del servicio.',
          '/quote-legacy?scenario=slow_provider&customer_id=42&items=3':
            'Flujo legacy: 4 reintentos sin backoff ni circuit breaker.',
          '/quote-resilient?scenario=slow_provider&customer_id=42&items=3':
            'Flujo resiliente: AbortController + CB + backoff exponencial + fallback.',
          '/dependency/state': 'Estado actual del proveedor carrier-gateway.',
          '/incidents?limit=10': 'Ultimos incidentes registrados.',
          '/diagnostics/summary': 'Resumen completo de telemetria.',
          '/metrics': 'Metricas JSON.',
          '/metrics-prometheus': 'Metricas en formato Prometheus.',
          '/reset-lab': 'Reinicia estado y telemetria.',
        },
        allowed_scenarios: ALLOWED_SCENARIOS,
        node_specific:
          'Los timeouts se aplican via AbortController/AbortSignal, no via wall-clock sleep. Esto permite cancelar la operacion en curso de forma cooperativa, libera el event loop y deja la primitiva expuesta como senal de cancelacion para todo el stack.',
      };
    } else if (uri === '/health') {
      payload = { status: 'ok', stack: APP_STACK };
    } else if (uri === '/quote-legacy' || uri === '/quote-resilient') {
      const mode = uri === '/quote-legacy' ? 'legacy' : 'resilient';
      let scenario = url.searchParams.get('scenario') || 'ok';
      if (!ALLOWED_SCENARIOS.includes(scenario)) scenario = 'ok';
      const customerId = clampInt(url.searchParams.get('customer_id') || '42', 1, 5000);
      const items = clampInt(url.searchParams.get('items') || '3', 1, 50);

      const bundle = await runQuote(mode, scenario, customerId, items);
      const result = bundle.result;
      status = bundle.http_status;

      flowContext = {
        mode,
        scenario,
        status: result.status,
        outcome: result.status === 'completed' ? 'success' : 'failure',
        attempts: result.attempts,
        retries: result.retries,
        timeout_count: result.timeout_count,
        fallback_used: bundle.fallback_used,
        short_circuited: bundle.short_circuited,
        circuit_opened: bundle.circuit_opened,
      };
      payload = result;
    } else if (uri === '/dependency/state') {
      const dep = readDependencyState();
      const opened = dep.provider.opened_until;
      payload = {
        ...dep.provider,
        circuit_status: opened && Date.now() < opened ? 'open' : 'closed',
      };
    } else if (uri === '/incidents') {
      const limit = clampInt(url.searchParams.get('limit') || '10', 1, 50);
      const telemetry = readTelemetry();
      payload = {
        limit,
        incidents: [...(telemetry.recent_incidents || [])].reverse().slice(0, limit),
      };
    } else if (uri === '/diagnostics/summary') {
      const summary = telemetrySummary(readTelemetry());
      const dep = readDependencyState();
      const opened = dep.provider.opened_until;
      payload = {
        case: CASE_NAME,
        stack: APP_STACK,
        metrics: summary,
        dependency: {
          ...dep.provider,
          circuit_status: opened && Date.now() < opened ? 'open' : 'closed',
        },
        policies: POLICIES,
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
      writeTelemetry(initialTelemetry());
      writeDependencyState(initialDependencyState());
      payload = { status: 'reset', message: 'Estado y telemetria reiniciados.' };
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
