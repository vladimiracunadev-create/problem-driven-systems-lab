'use strict';

const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { URL } = require('url');
const { performance } = require('perf_hooks');

const APP_STACK = 'Node.js 20';
const CASE_NAME = '12 - Punto unico de conocimiento y riesgo operacional';
const STORAGE_DIR = path.join(os.tmpdir(), 'pdsl-case12-node');
const STATE_PATH = path.join(STORAGE_DIR, 'knowledge-state.json');
const TELEMETRY_PATH = path.join(STORAGE_DIR, 'telemetry.json');

const SCENARIO_CATALOG = {
  owner_available: { legacy: { status: 200, mttr: 18, blockers: 0, handoff: 40 }, distributed: { mttr: 24, blockers: 0, handoff: 72 }, hint: 'La persona clave esta disponible y responde rapido.' },
  owner_absent: { legacy: { status: 503, mttr: 95, blockers: 3, handoff: 12 }, distributed: { mttr: 34, blockers: 1, handoff: 78 }, hint: 'La persona clave no esta y se revela el bus factor real del sistema.' },
  night_shift: { legacy: { status: 503, mttr: 88, blockers: 2, handoff: 18 }, distributed: { mttr: 36, blockers: 1, handoff: 74 }, hint: 'El problema ocurre fuera de horario y obliga a depender de runbooks y backups reales.' },
  recent_change: { legacy: { status: 502, mttr: 72, blockers: 2, handoff: 25 }, distributed: { mttr: 42, blockers: 1, handoff: 70 }, hint: 'Un cambio reciente tensiona la necesidad de contexto compartido.' },
  tribal_script: { legacy: { status: 500, mttr: 81, blockers: 3, handoff: 15 }, distributed: { mttr: 39, blockers: 1, handoff: 76 }, hint: 'Existe un procedimiento critico que vive fuera de runbooks y depende de memoria tribal.' },
};
const SCENARIOS = Object.keys(SCENARIO_CATALOG);
const ALLOWED_DOMAINS = ['billing', 'deployments', 'integrations', 'reporting'];
const ALLOWED_ACTIVITIES = ['runbook', 'pairing', 'drill'];

const ensureDir = () => fs.mkdirSync(STORAGE_DIR, { recursive: true });
const nowIso = () => new Date().toISOString();
const reqId = (p) => `${p}-${crypto.randomBytes(4).toString('hex')}`;

const initialState = () => ({
  knowledge: {
    domains: {
      billing: { runbook_score: 25, backup_people: 0, drill_score: 10 },
      deployments: { runbook_score: 35, backup_people: 1, drill_score: 15 },
      integrations: { runbook_score: 20, backup_people: 0, drill_score: 8 },
      reporting: { runbook_score: 30, backup_people: 1, drill_score: 12 },
    },
    docs_indexed: 4,
    pairing_sessions: 0,
    drills_completed: 0,
    last_update: null,
  },
});

const initialModeMetrics = () => ({
  successes: 0,
  failures: 0,
  mttr_samples: [],
  blocker_samples: [],
  handoff_samples: [],
  by_scenario: {},
  by_domain: {},
});

const initialTelemetry = () => ({
  requests: 0,
  samples_ms: [],
  status_counts: { '2xx': 0, '4xx': 0, '5xx': 0 },
  routes: {},
  last_path: null,
  last_status: 200,
  last_updated: null,
  modes: { legacy: initialModeMetrics(), distributed: initialModeMetrics() },
  incidents: [],
});

const readJson = (file, fb) => { ensureDir(); if (!fs.existsSync(file)) return fb(); try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch (_e) { return fb(); } };
const writeJson = (file, d) => { ensureDir(); fs.writeFileSync(file, JSON.stringify(d, null, 2)); };
const readState = () => readJson(STATE_PATH, initialState);
const writeState = (s) => writeJson(STATE_PATH, s);
const readTelemetry = () => readJson(TELEMETRY_PATH, initialTelemetry);
const writeTelemetry = (t) => writeJson(TELEMETRY_PATH, t);

const stateSummary = () => {
  const k = readState().knowledge;
  const domains = k.domains || {};
  let busFactor = 10;
  const coverage = {};
  for (const [name, meta] of Object.entries(domains)) {
    busFactor = Math.min(busFactor, (meta.backup_people || 0) + 1);
    coverage[name] = Number((((meta.runbook_score || 0) + (meta.drill_score || 0)) / 2).toFixed(2));
  }
  return {
    domains,
    coverage,
    docs_indexed: k.docs_indexed || 0,
    pairing_sessions: k.pairing_sessions || 0,
    drills_completed: k.drills_completed || 0,
    bus_factor_min: busFactor === 10 ? 0 : busFactor,
    last_update: k.last_update,
  };
};

const readinessScore = (d) =>
  Math.round(((d.runbook_score || 0) * 0.45) + (((d.backup_people || 0) + 1) * 18) + ((d.drill_score || 0) * 0.25));

const shareKnowledge = (domain, activity) => {
  const state = readState();
  const d = state.knowledge.domains[domain];
  if (activity === 'runbook') {
    d.runbook_score = Math.min(100, (d.runbook_score || 0) + 20);
    state.knowledge.docs_indexed = (state.knowledge.docs_indexed || 0) + 1;
  } else if (activity === 'pairing') {
    d.backup_people = Math.min(4, (d.backup_people || 0) + 1);
    state.knowledge.pairing_sessions = (state.knowledge.pairing_sessions || 0) + 1;
  } else {
    d.drill_score = Math.min(100, (d.drill_score || 0) + 18);
    state.knowledge.drills_completed = (state.knowledge.drills_completed || 0) + 1;
  }
  state.knowledge.domains[domain] = d;
  state.knowledge.last_update = nowIso();
  writeState(state);
  return stateSummary();
};

const runIncidentFlow = async (mode, scenario, domain) => {
  const meta = SCENARIO_CATALOG[scenario];
  const state = readState();
  const domainState = state.knowledge.domains[domain];
  const readiness = readinessScore(domainState);
  const flowId = reqId('incident');
  let httpStatus, mttr, blockers, handoff;
  let errorMessage = null;

  try {
    if (mode === 'legacy') {
      httpStatus = meta.legacy.status;
      mttr = meta.legacy.mttr;
      blockers = meta.legacy.blockers;
      handoff = meta.legacy.handoff;
      if (scenario === 'tribal_script' || scenario === 'owner_absent') {
        // Acceso ciego a estructura anidada — equivalente a memoria tribal.
        const opaque = {};
        const _ = opaque.config.system[2].is_active; // TypeError
      }
    } else {
      mttr = Math.max(15, meta.distributed.mttr - Math.floor(readiness / 12));
      blockers = Math.max(0, meta.distributed.blockers - Math.floor(((domainState.backup_people || 0) + 1) / 2));
      handoff = Math.min(95, meta.distributed.handoff + Math.floor(readiness / 10));
      httpStatus = readiness < 28 && scenario !== 'owner_available' ? 409 : 200;
      if (scenario === 'tribal_script' || scenario === 'owner_absent') {
        // Defensivo con optional chaining — el runbook esta codificado en el lenguaje.
        const opaque = {};
        const _ = opaque?.config?.system?.[2]?.is_active ?? false;
      }
    }
  } catch (e) {
    httpStatus = 500;
    errorMessage = `Fallo de ejecucion por deuda tecnica: ${e.message}`;
    mttr = mttr ?? 80;
    blockers = blockers ?? 3;
    handoff = handoff ?? 15;
  }

  await new Promise((r) => setTimeout(r, Math.min(800, mttr * 8 + Math.floor(Math.random() * 35) + 20)));
  const outcome = httpStatus >= 400 ? 'failure' : 'success';
  const payload = {
    mode,
    scenario,
    domain,
    status: httpStatus >= 400 ? 'blocked' : 'resolved',
    message:
      mode === 'legacy' && httpStatus >= 400
        ? errorMessage || 'Legacy depende demasiado de quien ya sabe el camino y sufre.'
        : 'Distributed combina runbooks, backups y optional chaining defensivo (el runbook codificado en el lenguaje).',
    incident_id: flowId,
    mttr_min: mttr,
    blocker_count: blockers,
    handoff_quality: handoff,
    readiness_score: readiness,
    scenario_hint: meta.hint,
    knowledge_state: stateSummary(),
  };
  if (httpStatus >= 400) payload.error = 'Crash grave nativo reportado en el codigo.';
  return {
    http_status: httpStatus,
    payload,
    context: {
      mode, scenario, domain, outcome,
      mttr_min: mttr,
      blocker_count: blockers,
      handoff_quality: handoff,
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
    m.by_domain[ctx.domain] = (m.by_domain[ctx.domain] || 0) + 1;
    m.mttr_samples.push(ctx.mttr_min);
    m.blocker_samples.push(ctx.blocker_count);
    m.handoff_samples.push(ctx.handoff_quality);
    if (m.mttr_samples.length > 200) m.mttr_samples = m.mttr_samples.slice(-200);
    if (m.blocker_samples.length > 200) m.blocker_samples = m.blocker_samples.slice(-200);
    if (m.handoff_samples.length > 200) m.handoff_samples = m.handoff_samples.slice(-200);
    if (ctx.outcome === 'success') m.successes += 1;
    else m.failures += 1;
    t.modes[ctx.mode] = m;
    t.incidents.push({ ...ctx, status_code: status, elapsed_ms: Number(elapsedMs.toFixed(2)), timestamp_utc: nowIso() });
    if (t.incidents.length > 60) t.incidents = t.incidents.slice(-60);
  }
  writeTelemetry(t);
};

const telemetrySummary = (t) => {
  const samples = t.samples_ms || [];
  const count = samples.length;
  const modes = {};
  for (const [name, m] of Object.entries(t.modes || {})) {
    const ms = m.mttr_samples || [];
    const bs = m.blocker_samples || [];
    const hs = m.handoff_samples || [];
    modes[name] = {
      successes: m.successes || 0,
      failures: m.failures || 0,
      avg_mttr_min: ms.length ? Number((ms.reduce((a, b) => a + b, 0) / ms.length).toFixed(2)) : 0,
      avg_blocker_count: bs.length ? Number((bs.reduce((a, b) => a + b, 0) / bs.length).toFixed(2)) : 0,
      avg_handoff_quality: hs.length ? Number((hs.reduce((a, b) => a + b, 0) / hs.length).toFixed(2)) : 0,
      by_scenario: m.by_scenario || {},
      by_domain: m.by_domain || {},
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
    recent_incidents: [...(t.incidents || [])].reverse(),
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
    out.push(`app_incident_success_total{mode="${lm}"} ${m.successes}`);
    out.push(`app_incident_failure_total{mode="${lm}"} ${m.failures}`);
    out.push(`app_incident_avg_mttr_min{mode="${lm}"} ${m.avg_mttr_min}`);
    out.push(`app_incident_avg_handoff_quality{mode="${lm}"} ${m.avg_handoff_quality}`);
  }
  for (const [domain, cov] of Object.entries(st.coverage)) {
    out.push(`app_domain_coverage{domain="${promLabel(domain)}"} ${cov}`);
  }
  out.push(`app_bus_factor_min ${st.bus_factor_min}`);
  out.push(`app_docs_indexed ${st.docs_indexed}`);
  out.push(`app_pairing_sessions ${st.pairing_sessions}`);
  out.push(`app_drills_completed ${st.drills_completed}`);
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
        goal: 'Comparar incidentes con dependencia fuerte de una sola persona vs. con conocimiento distribuido.',
        node_specific:
          'El runbook esta codificado en el lenguaje: optional chaining (`a?.b?.c ?? default`) en distributed evita el crash que sufre legacy con acceso ciego a estructuras anidadas. Es el equivalente codigo de "leer el runbook antes de tocar producccion".',
        routes: {
          '/health': 'Estado basico del servicio.',
          '/incident-legacy?scenario=owner_absent&domain=deployments': 'Incidente bajo dependencia fuerte.',
          '/incident-distributed?scenario=owner_absent&domain=deployments': 'Mismo incidente con conocimiento distribuido.',
          '/share-knowledge?domain=deployments&activity=runbook': 'Sube madurez del dominio.',
          '/incidents?limit=10': 'Ultimos incidentes observados.',
          '/diagnostics/summary': 'Resumen de bus factor, MTTR y cobertura.',
          '/metrics': 'Metricas JSON.',
          '/metrics-prometheus': 'Metricas en formato Prometheus.',
          '/reset-lab': 'Reinicia estado y metricas.',
        },
        allowed_scenarios: SCENARIOS,
        allowed_domains: ALLOWED_DOMAINS,
        allowed_activities: ALLOWED_ACTIVITIES,
      };
    } else if (uri === '/health') {
      payload = { status: 'ok', stack: APP_STACK };
    } else if (uri === '/incident-legacy' || uri === '/incident-distributed') {
      const mode = uri === '/incident-legacy' ? 'legacy' : 'distributed';
      let scenario = url.searchParams.get('scenario') || 'owner_available';
      if (!SCENARIOS.includes(scenario)) scenario = 'owner_available';
      let domain = url.searchParams.get('domain') || 'deployments';
      if (!ALLOWED_DOMAINS.includes(domain)) domain = 'deployments';
      const result = await runIncidentFlow(mode, scenario, domain);
      status = result.http_status;
      ctx = result.context;
      payload = result.payload;
    } else if (uri === '/share-knowledge') {
      let domain = url.searchParams.get('domain') || 'deployments';
      if (!ALLOWED_DOMAINS.includes(domain)) domain = 'deployments';
      let activity = url.searchParams.get('activity') || 'runbook';
      if (!ALLOWED_ACTIVITIES.includes(activity)) activity = 'runbook';
      payload = { status: 'updated', domain, activity, knowledge_state: shareKnowledge(domain, activity) };
    } else if (uri === '/incidents') {
      const limit = clampInt(url.searchParams.get('limit') || '10', 1, 60);
      payload = { limit, incidents: telemetrySummary(readTelemetry()).recent_incidents.slice(0, limit) };
    } else if (uri === '/diagnostics/summary') {
      payload = {
        case: CASE_NAME,
        stack: APP_STACK,
        knowledge: stateSummary(),
        metrics: telemetrySummary(readTelemetry()),
        interpretation: {
          legacy: 'Legacy expone el riesgo de depender de memoria tribal y de personas unicas para resolver incidentes.',
          distributed: 'Distributed sube runbooks, backups y drills, y baja MTTR / blockers de forma medible.',
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
  if (!skip && uri !== '/metrics' && uri !== '/reset-lab' && uri !== '/share-knowledge') {
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
