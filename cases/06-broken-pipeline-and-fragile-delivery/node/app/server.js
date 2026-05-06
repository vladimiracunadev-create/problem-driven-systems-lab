'use strict';

const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { URL } = require('url');
const { performance } = require('perf_hooks');

const APP_STACK = 'Node.js 20';
const CASE_NAME = '06 - Pipeline roto y entrega fragil';
const STORAGE_DIR = path.join(os.tmpdir(), 'pdsl-case06-node');
const STATE_PATH = path.join(STORAGE_DIR, 'delivery-state.json');
const TELEMETRY_PATH = path.join(STORAGE_DIR, 'telemetry.json');

const SCENARIO_HINTS = {
  ok: 'El flujo tiene precondiciones correctas y pasa de punta a punta.',
  missing_secret: 'Falta un secreto critico en el ambiente de destino.',
  config_drift: 'La configuracion de runtime no coincide con la esperada por el release.',
  failing_smoke: 'El deploy parece correcto, pero el smoke test falla despues del cambio.',
  migration_risk: 'La migracion necesita validacion previa o backfill antes de aplicarse.',
};
const SCENARIOS = Object.keys(SCENARIO_HINTS);

const SECRETS = { API_KEY: '12345' };

const ensureStorageDir = () => fs.mkdirSync(STORAGE_DIR, { recursive: true });
const nowIso = () => new Date().toISOString();
const reqId = (prefix) => `${prefix}-${crypto.randomBytes(4).toString('hex')}`;

const baseEnv = (release, configHash, secretState) => ({
  current_release: release,
  schema_version: release,
  health: 'healthy',
  config_hash: configHash,
  secrets_status: secretState,
  last_good_release: release,
  last_deploy_at: null,
  last_failure_reason: null,
});

const initialState = () => ({
  environments: {
    dev: baseEnv('2026.04.0', 'dev-baseline', 'ready'),
    staging: baseEnv('2026.04.0', 'staging-baseline', 'ready'),
    prod: baseEnv('2026.04.0', 'prod-baseline', 'ready'),
  },
  history: [],
});

const initialModeMetrics = () => ({
  successes: 0,
  failures: 0,
  rollbacks: 0,
  preflight_blocks: 0,
  by_scenario: {},
  by_environment: {},
});

const initialTelemetry = () => ({
  requests: 0,
  samples_ms: [],
  routes: {},
  status_counts: { '2xx': 0, '4xx': 0, '5xx': 0 },
  last_path: null,
  last_status: 200,
  last_updated: null,
  modes: { legacy: initialModeMetrics(), controlled: initialModeMetrics() },
  deployments: [],
});

const readJson = (file, fallback) => {
  ensureStorageDir();
  if (!fs.existsSync(file)) return fallback();
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (_e) {
    return fallback();
  }
};
const writeJson = (file, data) => {
  ensureStorageDir();
  fs.writeFileSync(file, JSON.stringify(data, null, 2));
};
const readState = () => readJson(STATE_PATH, initialState);
const writeState = (s) => writeJson(STATE_PATH, s);
const readTelemetry = () => readJson(TELEMETRY_PATH, initialTelemetry);
const writeTelemetry = (t) => writeJson(TELEMETRY_PATH, t);

const sanitizeRelease = (r) => (/^[A-Za-z0-9._-]{3,32}$/.test(r) ? r : '2026.04.1');
const clampInt = (v, min, max) => {
  const n = Number.parseInt(v, 10);
  if (Number.isNaN(n)) return min;
  return Math.max(min, Math.min(max, n));
};

const stepDelay = async (signal, baseMs) => {
  const elapsed = baseMs + Math.floor(Math.random() * 17) + 8;
  await new Promise((resolve, reject) => {
    const t = setTimeout(resolve, elapsed);
    if (signal) {
      const onAbort = () => {
        clearTimeout(t);
        reject(new Error('pipeline_aborted'));
      };
      if (signal.aborted) onAbort();
      else signal.addEventListener('abort', onAbort, { once: true });
    }
  });
  return elapsed;
};

const appendHistory = (state, entry) => {
  state.history.push(entry);
  if (state.history.length > 80) state.history = state.history.slice(-80);
};

const getSecretReal = (key) => {
  if (!Object.prototype.hasOwnProperty.call(SECRETS, key)) {
    throw new Error(`Missing critical secret: ${key}`);
  }
  return SECRETS[key];
};

const buildResult = (httpStatus, payload, context) => ({ http_status: httpStatus, payload, context });

const runLegacyDeployment = async (environment, release, scenario, signal) => {
  const state = readState();
  const env = { ...state.environments[environment] };
  const previousRelease = env.current_release;
  const deploymentId = reqId('deploy');
  const steps = [];

  steps.push({ step: 'package_release', status: 'ok', elapsed_ms: await stepDelay(signal, 70) });

  try {
    if (scenario === 'missing_secret') {
      getSecretReal('DB_PASSWORD');
    } else if (scenario === 'migration_risk') {
      const migrator = Object.create(null);
      migrator.runNonExistentMethod();
    }
  } catch (e) {
    steps.push({
      step: 'fatal_crash',
      status: 'error',
      elapsed_ms: await stepDelay(signal, 120),
      message: `Node revento la compilacion: ${e.message}`,
    });
    env.schema_version = `${release}-partial`;
    env.health = 'degraded';
    env.last_failure_reason = e.constructor.name;
    env.last_deploy_at = nowIso();
    state.environments[environment] = env;
    appendHistory(state, {
      deployment_id: deploymentId,
      mode: 'legacy',
      environment,
      release,
      scenario,
      outcome: 'failure',
      rollback_performed: false,
      preflight_blocked: false,
      timestamp_utc: nowIso(),
    });
    writeState(state);
    return buildResult(
      500,
      {
        mode: 'legacy',
        environment,
        release,
        scenario,
        status: 'failed',
        message: `Ejecucion fallida por falta de validacion. Excepcion nativa registrada: ${e.constructor.name}`,
        deployment_id: deploymentId,
        steps,
        environment_after: env,
      },
      { mode: 'legacy', environment, release, scenario, outcome: 'failure', rollback_performed: false, preflight_blocked: false, deployment_id: deploymentId }
    );
  }

  steps.push({ step: 'switch_traffic', status: 'ok', elapsed_ms: await stepDelay(signal, 55) });
  env.current_release = release;
  env.last_deploy_at = nowIso();
  env.health = 'warming';

  const shouldFailSmoke = ['missing_secret', 'config_drift', 'failing_smoke'].includes(scenario);
  if (shouldFailSmoke) {
    steps.push({
      step: 'smoke_test',
      status: 'error',
      elapsed_ms: await stepDelay(signal, 75),
      message: SCENARIO_HINTS[scenario],
    });
    env.health = 'degraded';
    env.last_failure_reason = scenario;
    state.environments[environment] = env;
    appendHistory(state, {
      deployment_id: deploymentId,
      mode: 'legacy',
      environment,
      release,
      scenario,
      outcome: 'failure',
      rollback_performed: false,
      preflight_blocked: false,
      timestamp_utc: nowIso(),
    });
    writeState(state);
    return buildResult(
      502,
      {
        mode: 'legacy',
        environment,
        release,
        scenario,
        status: 'failed',
        message: 'Legacy encontro el problema despues de cambiar trafico y dejo el ambiente degradado.',
        deployment_id: deploymentId,
        previous_release: previousRelease,
        steps,
        environment_after: env,
      },
      { mode: 'legacy', environment, release, scenario, outcome: 'failure', rollback_performed: false, preflight_blocked: false, deployment_id: deploymentId }
    );
  }

  steps.push({ step: 'smoke_test', status: 'ok', elapsed_ms: await stepDelay(signal, 75) });
  env.health = 'healthy';
  env.schema_version = release;
  env.last_good_release = release;
  env.last_failure_reason = null;
  state.environments[environment] = env;
  appendHistory(state, {
    deployment_id: deploymentId,
    mode: 'legacy',
    environment,
    release,
    scenario,
    outcome: 'success',
    rollback_performed: false,
    preflight_blocked: false,
    timestamp_utc: nowIso(),
  });
  writeState(state);
  return buildResult(
    200,
    {
      mode: 'legacy',
      environment,
      release,
      scenario,
      status: 'completed',
      message: 'El pipeline legacy logro desplegar, pero sin controles fuertes previos.',
      deployment_id: deploymentId,
      previous_release: previousRelease,
      steps,
      environment_after: env,
    },
    { mode: 'legacy', environment, release, scenario, outcome: 'success', rollback_performed: false, preflight_blocked: false, deployment_id: deploymentId }
  );
};

const runControlledDeployment = async (environment, release, scenario, signal) => {
  const state = readState();
  const env = { ...state.environments[environment] };
  const previousRelease = env.current_release;
  const deploymentId = reqId('deploy');
  const steps = [];

  steps.push({ step: 'build_artifact', status: 'ok', elapsed_ms: await stepDelay(signal, 65) });
  steps.push({ step: 'tests_and_contracts', status: 'ok', elapsed_ms: await stepDelay(signal, 60) });

  let validationBlocked = false;
  let validationMessage = '';
  try {
    if (scenario === 'missing_secret') {
      getSecretReal('DB_PASSWORD');
    } else if (scenario === 'migration_risk') {
      throw new Error('Migration pre-flight checksum missed');
    } else if (scenario === 'config_drift') {
      throw new Error('La configuracion de runtime no coincide con la esperada por el release.');
    }
  } catch (e) {
    validationBlocked = true;
    validationMessage = `Pre-flight fallido estructuralmente: ${e.message}`;
  }

  if (validationBlocked) {
    steps.push({
      step: 'preflight_validation',
      status: 'blocked',
      elapsed_ms: await stepDelay(signal, 55),
      message: validationMessage,
    });
    appendHistory(state, {
      deployment_id: deploymentId,
      mode: 'controlled',
      environment,
      release,
      scenario,
      outcome: 'failure',
      rollback_performed: false,
      preflight_blocked: true,
      timestamp_utc: nowIso(),
    });
    writeState(state);
    return buildResult(
      409,
      {
        mode: 'controlled',
        environment,
        release,
        scenario,
        status: 'blocked',
        message: 'El pipeline controlado detecto el riesgo antes de tocar el ambiente.',
        deployment_id: deploymentId,
        previous_release: previousRelease,
        steps,
        environment_after: env,
      },
      { mode: 'controlled', environment, release, scenario, outcome: 'failure', rollback_performed: false, preflight_blocked: true, deployment_id: deploymentId }
    );
  }

  if (scenario === 'migration_risk') {
    steps.push({
      step: 'migration_dry_run',
      status: 'blocked',
      elapsed_ms: await stepDelay(signal, 70),
      message: SCENARIO_HINTS[scenario],
    });
    appendHistory(state, {
      deployment_id: deploymentId,
      mode: 'controlled',
      environment,
      release,
      scenario,
      outcome: 'failure',
      rollback_performed: false,
      preflight_blocked: true,
      timestamp_utc: nowIso(),
    });
    writeState(state);
    return buildResult(
      409,
      {
        mode: 'controlled',
        environment,
        release,
        scenario,
        status: 'blocked',
        message: 'El pipeline controlado detuvo la migracion antes del deploy.',
        deployment_id: deploymentId,
        previous_release: previousRelease,
        steps,
        environment_after: env,
      },
      { mode: 'controlled', environment, release, scenario, outcome: 'failure', rollback_performed: false, preflight_blocked: true, deployment_id: deploymentId }
    );
  }

  steps.push({ step: 'deploy_canary', status: 'ok', elapsed_ms: await stepDelay(signal, 60) });

  if (scenario === 'failing_smoke') {
    steps.push({
      step: 'smoke_test',
      status: 'error',
      elapsed_ms: await stepDelay(signal, 70),
      message: SCENARIO_HINTS[scenario],
    });
    steps.push({
      step: 'rollback',
      status: 'ok',
      elapsed_ms: await stepDelay(signal, 45),
      message: 'Se revierte al ultimo release sano.',
    });
    env.current_release = previousRelease;
    env.health = 'healthy';
    env.last_failure_reason = scenario;
    env.last_deploy_at = nowIso();
    state.environments[environment] = env;
    appendHistory(state, {
      deployment_id: deploymentId,
      mode: 'controlled',
      environment,
      release,
      scenario,
      outcome: 'failure',
      rollback_performed: true,
      preflight_blocked: false,
      timestamp_utc: nowIso(),
    });
    writeState(state);
    return buildResult(
      502,
      {
        mode: 'controlled',
        environment,
        release,
        scenario,
        status: 'rolled_back',
        message: 'El smoke test fallo, pero el pipeline controlado hizo rollback automatico.',
        deployment_id: deploymentId,
        previous_release: previousRelease,
        steps,
        environment_after: env,
      },
      { mode: 'controlled', environment, release, scenario, outcome: 'failure', rollback_performed: true, preflight_blocked: false, deployment_id: deploymentId }
    );
  }

  steps.push({ step: 'smoke_test', status: 'ok', elapsed_ms: await stepDelay(signal, 70) });
  steps.push({ step: 'promote_release', status: 'ok', elapsed_ms: await stepDelay(signal, 40) });
  env.current_release = release;
  env.schema_version = release;
  env.health = 'healthy';
  env.last_good_release = release;
  env.last_failure_reason = null;
  env.last_deploy_at = nowIso();
  state.environments[environment] = env;
  appendHistory(state, {
    deployment_id: deploymentId,
    mode: 'controlled',
    environment,
    release,
    scenario,
    outcome: 'success',
    rollback_performed: false,
    preflight_blocked: false,
    timestamp_utc: nowIso(),
  });
  writeState(state);
  return buildResult(
    200,
    {
      mode: 'controlled',
      environment,
      release,
      scenario,
      status: 'completed',
      message: 'El pipeline controlado valido, desplego y promovio el release de forma segura.',
      deployment_id: deploymentId,
      previous_release: previousRelease,
      steps,
      environment_after: env,
    },
    { mode: 'controlled', environment, release, scenario, outcome: 'success', rollback_performed: false, preflight_blocked: false, deployment_id: deploymentId }
  );
};

const percentile = (values, p) => {
  if (!values.length) return 0;
  const sorted = [...values].sort((a, b) => a - b);
  const idx = Math.max(0, Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1));
  return Number(sorted[idx].toFixed(2));
};

const statusBucket = (s) => (s >= 500 ? '5xx' : s >= 400 ? '4xx' : '2xx');

const recordRequestTelemetry = (uri, status, elapsedMs, ctx) => {
  const t = readTelemetry();
  t.requests = (t.requests || 0) + 1;
  t.samples_ms.push(Number(elapsedMs.toFixed(2)));
  if (t.samples_ms.length > 3000) t.samples_ms = t.samples_ms.slice(-3000);
  t.routes[uri] = t.routes[uri] || [];
  t.routes[uri].push(Number(elapsedMs.toFixed(2)));
  if (t.routes[uri].length > 500) t.routes[uri] = t.routes[uri].slice(-500);
  const bucket = statusBucket(status);
  t.status_counts[bucket] = (t.status_counts[bucket] || 0) + 1;
  t.last_path = uri;
  t.last_status = status;
  t.last_updated = nowIso();
  if (ctx) {
    const m = t.modes[ctx.mode] || initialModeMetrics();
    m.by_scenario[ctx.scenario] = (m.by_scenario[ctx.scenario] || 0) + 1;
    m.by_environment[ctx.environment] = (m.by_environment[ctx.environment] || 0) + 1;
    if (ctx.rollback_performed) m.rollbacks += 1;
    if (ctx.preflight_blocked) m.preflight_blocks += 1;
    if (ctx.outcome === 'success') m.successes += 1;
    else m.failures += 1;
    t.modes[ctx.mode] = m;
    t.deployments.push({
      ...ctx,
      status_code: status,
      elapsed_ms: Number(elapsedMs.toFixed(2)),
      timestamp_utc: nowIso(),
    });
    if (t.deployments.length > 60) t.deployments = t.deployments.slice(-60);
  }
  writeTelemetry(t);
};

const telemetrySummary = (t) => {
  const samples = t.samples_ms || [];
  const count = samples.length;
  const routes = {};
  for (const [route, vals] of Object.entries(t.routes || {})) {
    const v = Array.isArray(vals) ? vals : [];
    routes[route] = {
      count: v.length,
      avg_ms: v.length ? Number((v.reduce((a, b) => a + b, 0) / v.length).toFixed(2)) : 0,
      p95_ms: percentile(v, 95),
      p99_ms: percentile(v, 99),
      max_ms: v.length ? Number(Math.max(...v).toFixed(2)) : 0,
    };
  }
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
    status_counts: t.status_counts || { '2xx': 0, '4xx': 0, '5xx': 0 },
    modes: t.modes || {},
    routes: Object.fromEntries(Object.entries(routes).sort(([a], [b]) => a.localeCompare(b))),
    recent_deployments: [...(t.deployments || [])].reverse(),
  };
};

const stateSummary = () => {
  const s = readState();
  return { environments: s.environments, history: [...(s.history || [])].reverse() };
};

const promLabel = (v) =>
  String(v).replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, ' ');

const renderPrometheus = () => {
  const sum = telemetrySummary(readTelemetry());
  const st = stateSummary();
  const out = [];
  out.push('# HELP app_requests_total Total de requests observados por el laboratorio.');
  out.push('# TYPE app_requests_total counter');
  out.push(`app_requests_total ${sum.requests_tracked}`);
  out.push('# HELP app_request_latency_ms Latencia agregada de requests en milisegundos.');
  out.push('# TYPE app_request_latency_ms gauge');
  out.push(`app_request_latency_ms{stat="avg"} ${sum.avg_ms}`);
  out.push(`app_request_latency_ms{stat="p95"} ${sum.p95_ms}`);
  out.push(`app_request_latency_ms{stat="p99"} ${sum.p99_ms}`);
  for (const [mode, stats] of Object.entries(sum.modes || {})) {
    const lm = promLabel(mode);
    out.push(`app_deploy_success_total{mode="${lm}"} ${stats.successes || 0}`);
    out.push(`app_deploy_failure_total{mode="${lm}"} ${stats.failures || 0}`);
    out.push(`app_deploy_rollbacks_total{mode="${lm}"} ${stats.rollbacks || 0}`);
    out.push(`app_deploy_preflight_blocks_total{mode="${lm}"} ${stats.preflight_blocks || 0}`);
  }
  for (const [envName, env] of Object.entries(st.environments || {})) {
    const lm = promLabel(envName);
    out.push(`app_environment_healthy{environment="${lm}"} ${env.health === 'healthy' ? 1 : 0}`);
  }
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
  let skipStoreMetrics = false;
  let ctx = null;

  try {
    if (uri === '/' || uri === '') {
      payload = {
        lab: 'Problem-Driven Systems Lab',
        case: CASE_NAME,
        stack: APP_STACK,
        goal: 'Comparar un pipeline fragil que falla tarde con otro que bloquea riesgos temprano y sabe hacer rollback.',
        node_specific:
          'Cada paso del pipeline corre dentro de un AbortController. Si el cliente cancela la request o el deadline se vence, el AbortSignal propaga la cancelacion y los pasos restantes nunca se ejecutan — limpieza cooperativa nativa de Node, sin polling de un flag.',
        routes: {
          '/health': 'Estado basico del servicio.',
          '/deploy-legacy?environment=staging&release=2026.04.1&scenario=missing_secret':
            'Simula un deploy fragil y tardio.',
          '/deploy-controlled?environment=staging&release=2026.04.1&scenario=missing_secret':
            'Simula un flujo con preflight checks, canary y rollback.',
          '/environments': 'Estado actual de dev, staging y prod.',
          '/deployments?limit=10': 'Ultimos despliegues observados por el laboratorio.',
          '/diagnostics/summary': 'Resumen de despliegues, rollbacks y salud por ambiente.',
          '/metrics': 'Metricas JSON del laboratorio.',
          '/metrics-prometheus': 'Metricas en formato Prometheus.',
          '/reset-lab': 'Reinicia ambientes e historial.',
        },
        allowed_scenarios: SCENARIOS,
      };
    } else if (uri === '/health') {
      payload = { status: 'ok', stack: APP_STACK };
    } else if (uri === '/deploy-legacy' || uri === '/deploy-controlled') {
      const mode = uri === '/deploy-legacy' ? 'legacy' : 'controlled';
      const state = readState();
      let environment = url.searchParams.get('environment') || 'staging';
      if (!Object.prototype.hasOwnProperty.call(state.environments, environment)) {
        environment = 'staging';
      }
      let scenario = url.searchParams.get('scenario') || 'ok';
      if (!SCENARIOS.includes(scenario)) scenario = 'ok';
      const release = sanitizeRelease(url.searchParams.get('release') || '2026.04.1');

      const ac = new AbortController();
      const onClose = () => ac.abort();
      req.once('close', onClose);

      let result;
      try {
        result = mode === 'legacy'
          ? await runLegacyDeployment(environment, release, scenario, ac.signal)
          : await runControlledDeployment(environment, release, scenario, ac.signal);
      } finally {
        req.removeListener('close', onClose);
      }

      status = result.http_status;
      ctx = result.context;
      payload = result.payload;
    } else if (uri === '/environments') {
      payload = stateSummary().environments;
    } else if (uri === '/deployments') {
      const limit = clampInt(url.searchParams.get('limit') || '10', 1, 50);
      payload = {
        limit,
        deployments: telemetrySummary(readTelemetry()).recent_deployments.slice(0, limit),
      };
    } else if (uri === '/diagnostics/summary') {
      payload = {
        case: CASE_NAME,
        stack: APP_STACK,
        state: stateSummary(),
        metrics: telemetrySummary(readTelemetry()),
        interpretation: {
          legacy:
            'Legacy detecta varios problemas demasiado tarde y deja ambientes degradados o esquemas a medio aplicar.',
          controlled:
            'Controlled mueve validaciones a preflight, hace canary y puede revertir si el fallo aparece despues del deploy.',
        },
      };
    } else if (uri === '/metrics') {
      payload = { case: CASE_NAME, stack: APP_STACK, ...telemetrySummary(readTelemetry()) };
    } else if (uri === '/metrics-prometheus') {
      skipStoreMetrics = true;
      res.writeHead(200, { 'Content-Type': 'text/plain; version=0.0.4; charset=utf-8' });
      res.end(renderPrometheus());
      return;
    } else if (uri === '/reset-lab') {
      writeState(initialState());
      writeTelemetry(initialTelemetry());
      payload = { status: 'reset', message: 'Ambientes, historial y metricas reiniciados.' };
    } else {
      status = 404;
      payload = { error: 'Ruta no encontrada', path: uri };
    }
  } catch (error) {
    status = 500;
    payload = { error: 'Fallo al procesar la solicitud', message: error.message, path: uri };
  }

  const elapsedMs = performance.now() - started;
  if (!skipStoreMetrics && uri !== '/metrics' && uri !== '/reset-lab') {
    recordRequestTelemetry(uri, status, elapsedMs, ctx);
  }
  payload.elapsed_ms = Number(elapsedMs.toFixed(2));
  payload.timestamp_utc = nowIso();
  payload.pid = process.pid;
  sendJson(res, status, payload);
};

ensureStorageDir();
const PORT = Number.parseInt(process.env.PORT || '8080', 10);
http.createServer(handler).listen(PORT, '0.0.0.0', () => {
  console.log(`Servidor Node escuchando en ${PORT}`);
});
