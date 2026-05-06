'use strict';

const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { URL } = require('url');
const { performance } = require('perf_hooks');

const APP_STACK = 'Node.js 20';
const CASE_NAME = '09 - Integracion externa inestable';
const STORAGE_DIR = path.join(os.tmpdir(), 'pdsl-case09-node');
const STATE_PATH = path.join(STORAGE_DIR, 'integration-state.json');
const TELEMETRY_PATH = path.join(STORAGE_DIR, 'telemetry.json');

const SCENARIO_CATALOG = {
  ok: { legacy_status: 200, hardened_status: 200, cached_response: 0, schema_protected: 0, quota_saved: 0, hint: 'Proveedor estable y contrato sin cambios.' },
  schema_drift: { legacy_status: 502, hardened_status: 200, cached_response: 0, schema_protected: 1, quota_saved: 1, hint: 'El proveedor cambia nombres o estructura sin avisar.' },
  rate_limited: { legacy_status: 429, hardened_status: 200, cached_response: 1, schema_protected: 0, quota_saved: 3, hint: 'El tercero aplica cuota y los llamados directos quedan sin budget.' },
  partial_payload: { legacy_status: 502, hardened_status: 200, cached_response: 0, schema_protected: 1, quota_saved: 1, hint: 'La respuesta llega incompleta y obliga a validar o completar con snapshot previo.' },
  maintenance_window: { legacy_status: 503, hardened_status: 200, cached_response: 1, schema_protected: 0, quota_saved: 4, hint: 'El proveedor entra en mantenimiento y la continuidad depende de cache o cola.' },
};
const SCENARIOS = Object.keys(SCENARIO_CATALOG);

// Circuit breaker en memoria — primitivamente simple en Node con cierres + setTimeout.
const breakerState = {
  status: 'closed', // closed | open | half_open
  failureCount: 0,
  threshold: 3,
  cooldownMs: 5000,
  reopenAt: null,
};
const breakerHit = (success) => {
  const now = Date.now();
  if (breakerState.status === 'open' && breakerState.reopenAt && now >= breakerState.reopenAt) {
    breakerState.status = 'half_open';
  }
  if (success) {
    breakerState.failureCount = 0;
    breakerState.status = 'closed';
    breakerState.reopenAt = null;
  } else {
    breakerState.failureCount += 1;
    if (breakerState.failureCount >= breakerState.threshold) {
      breakerState.status = 'open';
      breakerState.reopenAt = now + breakerState.cooldownMs;
    }
  }
};

const ensureDir = () => fs.mkdirSync(STORAGE_DIR, { recursive: true });
const nowIso = () => new Date().toISOString();
const reqId = (p) => `${p}-${crypto.randomBytes(4).toString('hex')}`;
const sanitizeSku = (s) => (/^[A-Z0-9-]{4,20}$/.test(s) ? s : 'SKU-100');
const productSnapshot = (sku) => {
  const seed = [...sku].reduce((a, c) => a + c.charCodeAt(0), 0);
  return {
    sku,
    title: `Product ${sku}`,
    price_usd: Number((14 + (seed % 11) * 3.25).toFixed(2)),
    stock: 20 + (seed % 45),
    provider_version: 'v1',
  };
};

const initialState = () => ({
  integration: {
    provider_name: 'catalog-hub',
    rate_limit_budget: 12,
    cache: { snapshot_version: '2026.04', cached_skus: 48, age_seconds: 90 },
    contract: { provider_version: 'v1', adapter_version: 'v1', schema_mappings: 3 },
    quarantine_events: 0,
    last_successful_sync: null,
  },
});

const initialModeMetrics = () => ({
  successes: 0,
  failures: 0,
  cached_response_samples: [],
  schema_protected_samples: [],
  quota_saved_total: 0,
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
  modes: { legacy: initialModeMetrics(), hardened: initialModeMetrics() },
  events: [],
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
  return {
    provider_name: s.integration.provider_name,
    rate_limit_budget: s.integration.rate_limit_budget,
    cache: s.integration.cache,
    contract: s.integration.contract,
    quarantine_events: s.integration.quarantine_events,
    last_successful_sync: s.integration.last_successful_sync,
    breaker: { ...breakerState },
  };
};

// Simulated remote call protected by AbortSignal.timeout — primitiva nativa Node 18+.
const callProvider = async (mode, scenario, sku) => {
  const timeoutMs = mode === 'hardened' ? 250 : 1500; // hardened apreta el budget
  const signal = AbortSignal.timeout(timeoutMs);
  const fakeLatency = ['rate_limited', 'maintenance_window'].includes(scenario)
    ? 4000
    : ['schema_drift', 'partial_payload'].includes(scenario)
      ? 80
      : 50;
  await new Promise((resolve, reject) => {
    const t = setTimeout(resolve, fakeLatency);
    signal.addEventListener('abort', () => {
      clearTimeout(t);
      reject(new Error(`AbortSignal.timeout: provider call exceeded ${timeoutMs}ms`));
    }, { once: true });
  });
  if (scenario === 'schema_drift' || scenario === 'partial_payload') {
    return { sku, cost: 45 }; // payload con shape incorrecto
  }
  return { ...productSnapshot(sku) };
};

const runCatalogFlow = async (mode, scenario, sku) => {
  const meta = SCENARIO_CATALOG[scenario];
  const state = readState();
  const flowId = reqId('sync');
  const integration = state.integration;
  const budgetBefore = integration.rate_limit_budget;
  const quotaCost = mode === 'legacy' ? 3 : 1;
  let quotaSaved = mode === 'hardened' ? meta.quota_saved : 0;
  let cachedResponse = mode === 'hardened' ? meta.cached_response : 0;
  let schemaProtected = mode === 'hardened' ? meta.schema_protected : 0;
  let httpStatus = 200;
  let errorMessage = null;
  let priceFinal = null;

  try {
    // Hardened consulta primero el breaker — si esta abierto, va a cache.
    if (mode === 'hardened' && breakerState.status === 'open') {
      cachedResponse = 1;
      const cached = productSnapshot(sku);
      priceFinal = Number((cached.price_usd * 1.5).toFixed(2));
    } else {
      let raw;
      try {
        raw = await callProvider(mode, scenario, sku);
        breakerHit(true);
      } catch (timeoutErr) {
        breakerHit(false);
        if (mode === 'legacy') throw timeoutErr;
        // hardened: fallback automatico a cache
        cachedResponse = 1;
        raw = productSnapshot(sku);
      }

      if (mode === 'legacy') {
        if (raw.price_usd === undefined) {
          throw new Error("Schema drift no mitigado: undefined 'price_usd'");
        }
        priceFinal = Number((raw.price_usd * 1.5).toFixed(2));
      } else {
        // Adapter: si falta price_usd, lo derivamos de cost.
        const normalized = { ...raw };
        if (normalized.price_usd === undefined) {
          normalized.price_usd = normalized.cost ?? 0;
          schemaProtected = 1;
        }
        priceFinal = Number((normalized.price_usd * 1.5).toFixed(2));
      }
    }
  } catch (e) {
    httpStatus = scenario === 'rate_limited' ? 429 : scenario === 'maintenance_window' ? 503 : 502;
    errorMessage = `Error bloqueante de Sistema: ${e.message}`;
  }

  integration.rate_limit_budget = Math.max(0, Math.min(12, budgetBefore - quotaCost + quotaSaved));
  integration.cache.age_seconds = cachedResponse === 1
    ? Math.min(900, integration.cache.age_seconds + 30)
    : Math.max(0, integration.cache.age_seconds - 45);
  if (schemaProtected === 1) {
    integration.contract.adapter_version = 'v1+mapping';
    integration.contract.schema_mappings = (integration.contract.schema_mappings || 0) + 1;
  }
  if (httpStatus >= 400) {
    integration.quarantine_events = (integration.quarantine_events || 0) + 1;
  } else {
    integration.last_successful_sync = nowIso();
    integration.cache.snapshot_version = `2026.04.${1 + Math.floor(Math.random() * 9)}`;
  }
  writeState(state);

  const product = productSnapshot(sku);
  product.source = cachedResponse === 1 ? 'cached_snapshot' : 'live_provider';
  if (schemaProtected === 1) product.provider_version = 'v2-normalized';

  const outcome = httpStatus >= 400 ? 'failure' : 'success';
  const payload = {
    mode,
    scenario,
    sku,
    status: httpStatus >= 400 ? 'failed' : 'completed',
    message:
      mode === 'legacy' && httpStatus >= 400
        ? errorMessage
        : 'Hardened agrega adapter, cache local y AbortSignal.timeout protegiendo el pipeline.',
    flow_id: flowId,
    cached_response: !!cachedResponse,
    schema_protected: !!schemaProtected,
    quota_saved: quotaSaved,
    rate_limit_budget_before: budgetBefore,
    rate_limit_budget_after: integration.rate_limit_budget,
    breaker: { ...breakerState },
    product,
    final_price: priceFinal,
    scenario_hint: meta.hint,
    integration_state: stateSummary(),
  };

  return {
    http_status: httpStatus,
    payload,
    context: {
      mode,
      scenario,
      sku,
      outcome,
      cached_response: cachedResponse,
      schema_protected: schemaProtected,
      quota_saved: quotaSaved,
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
    m.cached_response_samples.push(ctx.cached_response || 0);
    m.schema_protected_samples.push(ctx.schema_protected || 0);
    if (m.cached_response_samples.length > 200) m.cached_response_samples = m.cached_response_samples.slice(-200);
    if (m.schema_protected_samples.length > 200) m.schema_protected_samples = m.schema_protected_samples.slice(-200);
    m.quota_saved_total += ctx.quota_saved || 0;
    if (ctx.outcome === 'success') m.successes += 1;
    else m.failures += 1;
    t.modes[ctx.mode] = m;
    t.events.push({ ...ctx, status_code: status, elapsed_ms: Number(elapsedMs.toFixed(2)), timestamp_utc: nowIso() });
    if (t.events.length > 60) t.events = t.events.slice(-60);
  }
  writeTelemetry(t);
};

const telemetrySummary = (t) => {
  const samples = t.samples_ms || [];
  const count = samples.length;
  const modes = {};
  for (const [name, m] of Object.entries(t.modes || {})) {
    const c = m.cached_response_samples || [];
    const sp = m.schema_protected_samples || [];
    modes[name] = {
      successes: m.successes || 0,
      failures: m.failures || 0,
      avg_cached_response: c.length ? Number((c.reduce((a, b) => a + b, 0) / c.length).toFixed(2)) : 0,
      avg_schema_protected: sp.length ? Number((sp.reduce((a, b) => a + b, 0) / sp.length).toFixed(2)) : 0,
      quota_saved_total: m.quota_saved_total || 0,
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
    recent_events: [...(t.events || [])].reverse(),
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
    out.push(`app_integration_success_total{mode="${lm}"} ${m.successes}`);
    out.push(`app_integration_failure_total{mode="${lm}"} ${m.failures}`);
    out.push(`app_integration_quota_saved_total{mode="${lm}"} ${m.quota_saved_total}`);
    out.push(`app_integration_avg_cached_response{mode="${lm}"} ${m.avg_cached_response}`);
    out.push(`app_integration_avg_schema_protected{mode="${lm}"} ${m.avg_schema_protected}`);
  }
  out.push(`app_rate_limit_budget ${st.rate_limit_budget}`);
  out.push(`app_quarantine_events_total ${st.quarantine_events}`);
  out.push(`app_breaker_open ${breakerState.status === 'open' ? 1 : 0}`);
  out.push(`app_breaker_failure_count ${breakerState.failureCount}`);
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
        goal: 'Comparar consumo directo a un proveedor inestable contra un adapter endurecido con cache, cuota y circuit breaker.',
        node_specific:
          'AbortSignal.timeout(ms) marca el deadline del llamado externo sin atornillar timers manualmente. El circuit breaker es un objeto de modulo con tres estados (closed/open/half_open) que se reabre automaticamente despues de cooldown.',
        routes: {
          '/health': 'Estado basico del servicio.',
          '/catalog-legacy?scenario=rate_limited&sku=SKU-100': 'Consume al proveedor sin amortiguacion suficiente.',
          '/catalog-hardened?scenario=rate_limited&sku=SKU-100': 'Aplica adapter, cache, AbortSignal.timeout y breaker.',
          '/sync-events?limit=10': 'Ultimos flujos observados.',
          '/diagnostics/summary': 'Resumen de cache, schema y cuota.',
          '/metrics': 'Metricas JSON.',
          '/metrics-prometheus': 'Metricas en formato Prometheus.',
          '/reset-lab': 'Reinicia estado y metricas.',
        },
        allowed_scenarios: SCENARIOS,
      };
    } else if (uri === '/health') {
      payload = { status: 'ok', stack: APP_STACK };
    } else if (uri === '/catalog-legacy' || uri === '/catalog-hardened') {
      const mode = uri === '/catalog-legacy' ? 'legacy' : 'hardened';
      let scenario = url.searchParams.get('scenario') || 'ok';
      if (!SCENARIOS.includes(scenario)) scenario = 'ok';
      const sku = sanitizeSku(url.searchParams.get('sku') || 'SKU-100');
      const result = await runCatalogFlow(mode, scenario, sku);
      status = result.http_status;
      ctx = result.context;
      payload = result.payload;
    } else if (uri === '/sync-events') {
      const limit = clampInt(url.searchParams.get('limit') || '10', 1, 60);
      payload = { limit, events: telemetrySummary(readTelemetry()).recent_events.slice(0, limit) };
    } else if (uri === '/diagnostics/summary') {
      payload = {
        case: CASE_NAME,
        stack: APP_STACK,
        integration: stateSummary(),
        metrics: telemetrySummary(readTelemetry()),
        interpretation: {
          legacy: 'Legacy llama directo al proveedor sin adapter, cache ni timeouts: cualquier ruido externo se vuelve incidente propio.',
          hardened: 'Hardened mete AbortSignal.timeout, adapter de schema, cache local y circuit breaker. El proveedor se cae y el sistema sigue.',
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
      breakerState.status = 'closed';
      breakerState.failureCount = 0;
      breakerState.reopenAt = null;
      payload = { status: 'reset', message: 'Estado, breaker y metricas reiniciados.' };
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
