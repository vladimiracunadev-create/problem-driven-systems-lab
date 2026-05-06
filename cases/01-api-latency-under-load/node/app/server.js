'use strict';

const http = require('http');
const { URL } = require('url');
const { performance } = require('perf_hooks');
const fs = require('fs');
const os = require('os');
const path = require('path');

const APP_STACK = 'Node.js 20';
const CASE_NAME = '01 - API lenta bajo carga por cuellos de botella reales';
const WORKER_NAME = 'report-refresh-node';
const STORAGE_DIR = path.join(os.tmpdir(), 'pdsl-case01-node');
const METRICS_PATH = path.join(STORAGE_DIR, 'metrics.json');

const ROUNDTRIP_LEGACY_MS = 1.2;
const ROUNDTRIP_BATCH_MS = 0.7;

const ensureStorageDir = () => {
  fs.mkdirSync(STORAGE_DIR, { recursive: true });
};

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const customers = new Map();
const orders = [];
const summaryByCustomer = new Map();
const jobRuns = [];
const workerState = {
  worker_name: WORKER_NAME,
  last_heartbeat: null,
  last_status: 'init',
  last_duration_ms: null,
  last_message: 'worker not started yet',
};

const seedData = () => {
  let seed = 102030;
  const rng = () => {
    seed = (seed * 9301 + 49297) % 233280;
    return seed / 233280;
  };
  const regions = ['north', 'south', 'east', 'west'];
  const now = Math.floor(Date.now() / 1000);

  for (let i = 1; i <= 1600; i += 1) {
    customers.set(i, {
      id: i,
      name: `Customer ${i}`,
      tier: i % 10 === 0 ? 'gold' : i % 3 === 0 ? 'silver' : 'bronze',
      region: regions[i % 4],
      created_at: now - Math.floor(rng() * 365 * 86400),
    });
  }

  for (let i = 1; i <= 36000; i += 1) {
    orders.push({
      id: i,
      customer_id: 1 + Math.floor(rng() * 1600),
      status: rng() < 0.88 ? 'paid' : 'pending',
      total_amount: Number((15 + rng() * 1500).toFixed(2)),
      created_at: now - Math.floor(rng() * 180 * 86400),
    });
  }
};

const dayBucket = (timestampSec) => Math.floor(timestampSec / 86400);

const refreshSummaryOnce = async (note) => {
  const started = performance.now();
  await sleep(8);
  summaryByCustomer.clear();
  for (const order of orders) {
    if (order.status !== 'paid') continue;
    const day = dayBucket(order.created_at);
    let perCustomer = summaryByCustomer.get(order.customer_id);
    if (!perCustomer) {
      perCustomer = new Map();
      summaryByCustomer.set(order.customer_id, perCustomer);
    }
    const dayEntry = perCustomer.get(day) || { total_amount: 0, order_count: 0, refreshed_at: 0 };
    dayEntry.total_amount = Number((dayEntry.total_amount + order.total_amount).toFixed(2));
    dayEntry.order_count += 1;
    perCustomer.set(day, dayEntry);
  }
  const duration_ms = Number((performance.now() - started).toFixed(2));
  const refreshedAt = Math.floor(Date.now() / 1000);
  let rowsWritten = 0;
  for (const perCustomer of summaryByCustomer.values()) {
    for (const entry of perCustomer.values()) {
      entry.refreshed_at = refreshedAt;
      rowsWritten += 1;
    }
  }
  workerState.last_heartbeat = refreshedAt;
  workerState.last_status = 'ok';
  workerState.last_duration_ms = duration_ms;
  workerState.last_message = note;
  jobRuns.push({
    id: jobRuns.length + 1,
    worker_name: WORKER_NAME,
    status: 'ok',
    started_at: refreshedAt,
    finished_at: Math.floor(Date.now() / 1000),
    duration_ms,
    rows_written: rowsWritten,
    note,
  });
  if (jobRuns.length > 200) jobRuns.splice(0, jobRuns.length - 200);
  return { rows_written: rowsWritten, duration_ms };
};

const startWorker = () => {
  setInterval(() => {
    refreshSummaryOnce('periodic summary refresh').catch(() => {
      workerState.last_status = 'error';
    });
  }, 20000).unref();
};

const initialMetrics = () => ({
  requests: 0,
  samples_ms: [],
  routes: {},
  last_path: null,
  last_status: 200,
  last_updated: null,
  last_db_time_ms: 0,
  last_db_queries: 0,
  db_time_samples_ms: [],
  db_query_samples: [],
  status_counts: { '2xx': 0, '4xx': 0, '5xx': 0 },
  event_loop_lag_samples_ms: [],
});

const readMetrics = () => {
  ensureStorageDir();
  if (!fs.existsSync(METRICS_PATH)) return initialMetrics();
  try {
    const parsed = JSON.parse(fs.readFileSync(METRICS_PATH, 'utf8'));
    const seed = initialMetrics();
    return {
      ...seed,
      ...parsed,
      status_counts: { ...seed.status_counts, ...(parsed.status_counts || {}) },
      routes: parsed.routes || {},
      samples_ms: Array.isArray(parsed.samples_ms) ? parsed.samples_ms : [],
      db_time_samples_ms: Array.isArray(parsed.db_time_samples_ms) ? parsed.db_time_samples_ms : [],
      db_query_samples: Array.isArray(parsed.db_query_samples) ? parsed.db_query_samples : [],
      event_loop_lag_samples_ms: Array.isArray(parsed.event_loop_lag_samples_ms)
        ? parsed.event_loop_lag_samples_ms
        : [],
    };
  } catch (_error) {
    return initialMetrics();
  }
};

const writeMetrics = (metrics) => {
  ensureStorageDir();
  fs.writeFileSync(METRICS_PATH, JSON.stringify(metrics, null, 2));
};

const percentile = (values, percent) => {
  if (!values.length) return 0;
  const sorted = [...values].sort((a, b) => a - b);
  const idx = Math.max(0, Math.min(sorted.length - 1, Math.ceil((percent / 100) * sorted.length) - 1));
  return Number(sorted[idx].toFixed(2));
};

const routeSummary = (routes) => {
  const out = {};
  for (const [route, samples] of Object.entries(routes)) {
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

const metricsSummary = (metrics) => {
  const samples = metrics.samples_ms || [];
  const dbTimes = metrics.db_time_samples_ms || [];
  const dbQueries = metrics.db_query_samples || [];
  const lagSamples = metrics.event_loop_lag_samples_ms || [];
  const count = samples.length;
  return {
    requests_tracked: metrics.requests || 0,
    sample_count: count,
    avg_ms: count ? Number((samples.reduce((a, b) => a + b, 0) / count).toFixed(2)) : 0,
    p95_ms: percentile(samples, 95),
    p99_ms: percentile(samples, 99),
    max_ms: count ? Number(Math.max(...samples).toFixed(2)) : 0,
    last_path: metrics.last_path,
    last_status: metrics.last_status,
    last_updated: metrics.last_updated,
    last_db_time_ms: metrics.last_db_time_ms || 0,
    last_db_queries: metrics.last_db_queries || 0,
    avg_db_time_ms: dbTimes.length
      ? Number((dbTimes.reduce((a, b) => a + b, 0) / dbTimes.length).toFixed(2))
      : 0,
    p95_db_time_ms: percentile(dbTimes, 95),
    avg_db_queries: dbQueries.length
      ? Number((dbQueries.reduce((a, b) => a + b, 0) / dbQueries.length).toFixed(2))
      : 0,
    p95_db_queries: percentile(dbQueries, 95),
    avg_event_loop_lag_ms: lagSamples.length
      ? Number((lagSamples.reduce((a, b) => a + b, 0) / lagSamples.length).toFixed(2))
      : 0,
    p95_event_loop_lag_ms: percentile(lagSamples, 95),
    status_counts: metrics.status_counts || { '2xx': 0, '4xx': 0, '5xx': 0 },
    routes: routeSummary(metrics.routes || {}),
  };
};

const statusBucket = (status) => (status >= 500 ? '5xx' : status >= 400 ? '4xx' : '2xx');

const storeRequestMetrics = (route, status, elapsedMs, dbTimeMs, dbQueriesCount, lagMs) => {
  const metrics = readMetrics();
  metrics.requests += 1;
  metrics.samples_ms.push(Number(elapsedMs.toFixed(2)));
  if (metrics.samples_ms.length > 3000) metrics.samples_ms = metrics.samples_ms.slice(-3000);
  metrics.db_time_samples_ms.push(Number(dbTimeMs.toFixed(2)));
  if (metrics.db_time_samples_ms.length > 3000) {
    metrics.db_time_samples_ms = metrics.db_time_samples_ms.slice(-3000);
  }
  metrics.db_query_samples.push(dbQueriesCount);
  if (metrics.db_query_samples.length > 3000) {
    metrics.db_query_samples = metrics.db_query_samples.slice(-3000);
  }
  metrics.event_loop_lag_samples_ms.push(Number(lagMs.toFixed(2)));
  if (metrics.event_loop_lag_samples_ms.length > 3000) {
    metrics.event_loop_lag_samples_ms = metrics.event_loop_lag_samples_ms.slice(-3000);
  }
  metrics.routes[route] = metrics.routes[route] || [];
  metrics.routes[route].push(Number(elapsedMs.toFixed(2)));
  if (metrics.routes[route].length > 500) {
    metrics.routes[route] = metrics.routes[route].slice(-500);
  }
  metrics.status_counts[statusBucket(status)] =
    (metrics.status_counts[statusBucket(status)] || 0) + 1;
  metrics.last_path = route;
  metrics.last_status = status;
  metrics.last_updated = new Date().toISOString();
  metrics.last_db_time_ms = Number(dbTimeMs.toFixed(2));
  metrics.last_db_queries = dbQueriesCount;
  writeMetrics(metrics);
};

const clampInt = (value, min, max) => {
  const parsed = Number.parseInt(value, 10);
  if (Number.isNaN(parsed)) return min;
  return Math.max(min, Math.min(max, parsed));
};

const measureEventLoopLag = () =>
  new Promise((resolve) => {
    const start = performance.now();
    setImmediate(() => {
      resolve(performance.now() - start);
    });
  });

const timedQuery = async (work, stats, roundtripMs = ROUNDTRIP_LEGACY_MS) => {
  const started = performance.now();
  await sleep(roundtripMs);
  const result = work();
  stats.db_time_ms += performance.now() - started;
  stats.db_queries += 1;
  return result;
};

const topCustomersLegacy = async (days, limit, stats) => {
  const sinceDay = dayBucket(Math.floor(Date.now() / 1000) - days * 86400);
  const aggregated = await timedQuery(
    () => {
      const sums = new Map();
      for (const order of orders) {
        if (order.status !== 'paid') continue;
        if (dayBucket(order.created_at) < sinceDay) continue;
        const current = sums.get(order.customer_id) || { total_spend: 0, order_count: 0 };
        current.total_spend = Number((current.total_spend + order.total_amount).toFixed(2));
        current.order_count += 1;
        sums.set(order.customer_id, current);
      }
      return [...sums.entries()]
        .map(([customer_id, value]) => ({ customer_id, ...value }))
        .sort((a, b) => b.total_spend - a.total_spend)
        .slice(0, limit);
    },
    stats,
    ROUNDTRIP_LEGACY_MS
  );

  const enriched = [];
  for (const row of aggregated) {
    const customer = await timedQuery(() => customers.get(row.customer_id) || null, stats);
    const recent = await timedQuery(
      () =>
        orders
          .filter((o) => o.customer_id === row.customer_id)
          .sort((a, b) => b.created_at - a.created_at)
          .slice(0, 3),
      stats
    );
    enriched.push({ ...row, customer, recent_orders: recent });
  }
  return enriched;
};

const topCustomersOptimized = async (days, limit, stats) => {
  const sinceDay = dayBucket(Math.floor(Date.now() / 1000) - days * 86400);

  const aggregated = await timedQuery(
    () => {
      const sums = new Map();
      for (const [customerId, byDay] of summaryByCustomer.entries()) {
        for (const [day, entry] of byDay.entries()) {
          if (day < sinceDay) continue;
          const current = sums.get(customerId) || { total_spend: 0, order_count: 0 };
          current.total_spend = Number((current.total_spend + entry.total_amount).toFixed(2));
          current.order_count += entry.order_count;
          sums.set(customerId, current);
        }
      }
      return [...sums.entries()]
        .map(([customer_id, value]) => {
          const customer = customers.get(customer_id);
          return {
            customer_id,
            name: customer ? customer.name : null,
            tier: customer ? customer.tier : null,
            region: customer ? customer.region : null,
            ...value,
          };
        })
        .sort((a, b) => b.total_spend - a.total_spend)
        .slice(0, limit);
    },
    stats,
    ROUNDTRIP_BATCH_MS
  );

  if (!aggregated.length) return [];

  const idSet = new Set(aggregated.map((row) => row.customer_id));
  const recentMap = await timedQuery(
    () => {
      const grouped = new Map();
      for (const order of orders) {
        if (!idSet.has(order.customer_id)) continue;
        const list = grouped.get(order.customer_id) || [];
        list.push(order);
        grouped.set(order.customer_id, list);
      }
      const out = new Map();
      for (const [cid, list] of grouped.entries()) {
        out.set(
          cid,
          list
            .sort((a, b) => b.created_at - a.created_at)
            .slice(0, 3)
            .map((o) => ({
              id: o.id,
              total_amount: o.total_amount,
              status: o.status,
              created_at: o.created_at,
            }))
        );
      }
      return out;
    },
    stats,
    ROUNDTRIP_BATCH_MS
  );

  return aggregated.map((row) => ({ ...row, recent_orders: recentMap.get(row.customer_id) || [] }));
};

const workerStatusPayload = () => ({
  worker: { ...workerState },
  recent_runs: jobRuns.slice(-5).reverse(),
});

const databaseDiagnostics = () => ({
  row_counts: {
    customers: customers.size,
    orders: orders.length,
    customer_daily_summary: [...summaryByCustomer.values()].reduce((acc, m) => acc + m.size, 0),
    job_runs: jobRuns.length,
  },
  slowest_worker_runs: [...jobRuns]
    .sort((a, b) => (b.duration_ms || 0) - (a.duration_ms || 0))
    .slice(0, 5),
});

const diagnosticsSummary = () => {
  const summary = metricsSummary(readMetrics());
  const legacy = summary.routes['/report-legacy'] || {};
  const optimized = summary.routes['/report-optimized'] || {};
  return {
    case: CASE_NAME,
    stack: APP_STACK,
    legacy,
    optimized,
    delta: {
      avg_ms: Number(((legacy.avg_ms || 0) - (optimized.avg_ms || 0)).toFixed(2)),
      p95_ms: Number(((legacy.p95_ms || 0) - (optimized.p95_ms || 0)).toFixed(2)),
    },
    event_loop: {
      avg_lag_ms: summary.avg_event_loop_lag_ms,
      p95_lag_ms: summary.p95_event_loop_lag_ms,
      note: 'Lag medido entre setImmediate y la callback. En Node, await secuencial dentro de un bucle bloquea throughput global, no solo la propia request.',
    },
    worker: workerStatusPayload(),
    database: databaseDiagnostics(),
    interpretation: {
      legacy_route_should_be_higher:
        'La ruta legacy agrega sobre datos transaccionales y luego enriquece con N consultas dependientes.',
      worker_pressure_note:
        'El worker refresca el resumen cada 20s. La ruta optimized se apoya en ese resultado y deja libre el event loop.',
      node_specific:
        'En Node el costo no es solo la latencia de la request: cada await secuencial cede el loop pero el costo agregado degrada throughput global del proceso.',
    },
  };
};

const prometheusLabel = (value) =>
  String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, ' ');

const renderPrometheusMetrics = () => {
  const summary = metricsSummary(readMetrics());
  const lines = [];
  lines.push('# HELP app_requests_total Total de requests observados por el laboratorio.');
  lines.push('# TYPE app_requests_total counter');
  lines.push(`app_requests_total ${summary.requests_tracked}`);
  lines.push('# HELP app_request_latency_ms Latencia agregada de requests en milisegundos.');
  lines.push('# TYPE app_request_latency_ms gauge');
  lines.push(`app_request_latency_ms{stat="avg"} ${summary.avg_ms}`);
  lines.push(`app_request_latency_ms{stat="p95"} ${summary.p95_ms}`);
  lines.push(`app_request_latency_ms{stat="p99"} ${summary.p99_ms}`);
  lines.push(`app_request_latency_ms{stat="max"} ${summary.max_ms}`);
  lines.push('# HELP app_db_time_ms Tiempo agregado de DB simulada por request en milisegundos.');
  lines.push('# TYPE app_db_time_ms gauge');
  lines.push(`app_db_time_ms{stat="avg"} ${summary.avg_db_time_ms}`);
  lines.push(`app_db_time_ms{stat="p95"} ${summary.p95_db_time_ms}`);
  lines.push('# HELP app_db_queries Cantidad de queries por request.');
  lines.push('# TYPE app_db_queries gauge');
  lines.push(`app_db_queries{stat="avg"} ${summary.avg_db_queries}`);
  lines.push(`app_db_queries{stat="p95"} ${summary.p95_db_queries}`);
  lines.push('# HELP app_event_loop_lag_ms Lag del event loop medido por request.');
  lines.push('# TYPE app_event_loop_lag_ms gauge');
  lines.push(`app_event_loop_lag_ms{stat="avg"} ${summary.avg_event_loop_lag_ms}`);
  lines.push(`app_event_loop_lag_ms{stat="p95"} ${summary.p95_event_loop_lag_ms}`);

  for (const [bucket, count] of Object.entries(summary.status_counts || {})) {
    lines.push(`app_status_total{bucket="${prometheusLabel(bucket)}"} ${count}`);
  }
  for (const [route, stats] of Object.entries(summary.routes || {})) {
    const label = prometheusLabel(route);
    lines.push(`app_route_latency_ms{route="${label}",stat="avg"} ${stats.avg_ms}`);
    lines.push(`app_route_latency_ms{route="${label}",stat="p95"} ${stats.p95_ms}`);
    lines.push(`app_route_latency_ms{route="${label}",stat="p99"} ${stats.p99_ms}`);
    lines.push(`app_route_requests_total{route="${label}"} ${stats.count}`);
  }
  if (workerState.last_duration_ms !== null) {
    lines.push('# HELP app_worker_last_duration_ms Ultima duracion reportada por el worker.');
    lines.push('# TYPE app_worker_last_duration_ms gauge');
    lines.push(
      `app_worker_last_duration_ms{worker="${prometheusLabel(WORKER_NAME)}"} ${workerState.last_duration_ms}`
    );
    lines.push('# HELP app_worker_status Estado logico del worker. 1=ok, 0=otro estado.');
    lines.push('# TYPE app_worker_status gauge');
    lines.push(
      `app_worker_status{worker="${prometheusLabel(WORKER_NAME)}",status="${prometheusLabel(workerState.last_status)}"} ${workerState.last_status === 'ok' ? 1 : 0}`
    );
  }
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
  const stats = { db_time_ms: 0, db_queries: 0 };
  let status = 200;
  let payload = {};
  let skipStoreMetrics = false;

  try {
    if (uri === '/' || uri === '') {
      payload = {
        lab: 'Problem-Driven Systems Lab',
        case: CASE_NAME,
        stack: APP_STACK,
        goal:
          'Comparar una ruta legacy con N+1 secuencial (await en bucle) versus una ruta optimizada con resumen pre-calculado y batch.',
        routes: {
          '/health': 'Estado basico del servicio.',
          '/report-legacy?days=30&limit=20':
            'Consulta defectuosa: agregacion sobre datos transaccionales + N+1 con await secuencial. Bloquea el throughput.',
          '/report-optimized?days=30&limit=20':
            'Consulta mejorada: lectura sobre resumen pre-calculado + un solo batch para detalles.',
          '/batch/status': 'Estado del worker embebido.',
          '/diagnostics/summary':
            'Resumen correlacionado entre metricas, worker, base local y lag del event loop.',
          '/job-runs?limit=10': 'Ultimas ejecuciones del worker.',
          '/metrics': 'Metricas JSON.',
          '/metrics-prometheus': 'Metricas en formato Prometheus.',
          '/reset-metrics': 'Reinicia metricas locales.',
        },
        node_specific:
          'En Node, el patron N+1 con await secuencial no solo penaliza la propia request: cede el event loop entre cada query y degrada el throughput global del proceso.',
      };
    } else if (uri === '/health') {
      payload = { status: 'ok', stack: APP_STACK };
    } else if (uri === '/report-legacy') {
      const days = clampInt(url.searchParams.get('days') || '30', 1, 180);
      const limit = clampInt(url.searchParams.get('limit') || '20', 1, 50);
      const rows = await topCustomersLegacy(days, limit, stats);
      payload = {
        mode: 'legacy',
        problem:
          'Filtro no sargable + patron N+1 con await secuencial + lectura directa desde transaccion.',
        days,
        limit,
        result_count: rows.length,
        db_queries_in_request: stats.db_queries,
        db_time_ms_in_request: Number(stats.db_time_ms.toFixed(2)),
        data: rows,
      };
    } else if (uri === '/report-optimized') {
      const days = clampInt(url.searchParams.get('days') || '30', 1, 180);
      const limit = clampInt(url.searchParams.get('limit') || '20', 1, 50);
      const rows = await topCustomersOptimized(days, limit, stats);
      payload = {
        mode: 'optimized',
        solution:
          'Resumen pre-calculado por worker + un solo batch para detalles. Mantiene el event loop libre.',
        days,
        limit,
        result_count: rows.length,
        db_queries_in_request: stats.db_queries,
        db_time_ms_in_request: Number(stats.db_time_ms.toFixed(2)),
        data: rows,
      };
    } else if (uri === '/batch/status') {
      payload = workerStatusPayload();
    } else if (uri === '/job-runs') {
      const limit = clampInt(url.searchParams.get('limit') || '10', 1, 50);
      payload = { limit, runs: [...jobRuns].reverse().slice(0, limit) };
    } else if (uri === '/diagnostics/summary') {
      payload = diagnosticsSummary();
    } else if (uri === '/metrics') {
      payload = {
        case: CASE_NAME,
        stack: APP_STACK,
        ...metricsSummary(readMetrics()),
        note:
          'Metrica util de laboratorio. event_loop_lag_ms es la senal Node-especifica que delata await secuencial bloqueante.',
      };
    } else if (uri === '/metrics-prometheus') {
      skipStoreMetrics = true;
      const text = renderPrometheusMetrics();
      res.writeHead(200, { 'Content-Type': 'text/plain; version=0.0.4; charset=utf-8' });
      res.end(text);
      return;
    } else if (uri === '/reset-metrics') {
      writeMetrics(initialMetrics());
      payload = { status: 'reset', message: 'Metricas locales reiniciadas.' };
    } else {
      status = 404;
      payload = { error: 'Ruta no encontrada', path: uri };
    }
  } catch (error) {
    status = 500;
    payload = { error: 'Fallo al procesar la solicitud', message: error.message, path: uri };
  }

  const elapsedMs = performance.now() - started;
  const lagMs = await measureEventLoopLag();
  if (!skipStoreMetrics && uri !== '/metrics' && uri !== '/reset-metrics') {
    storeRequestMetrics(uri, status, elapsedMs, stats.db_time_ms, stats.db_queries, lagMs);
  }
  payload.elapsed_ms = Number(elapsedMs.toFixed(2));
  payload.event_loop_lag_ms = Number(lagMs.toFixed(2));
  payload.timestamp_utc = new Date().toISOString();
  payload.pid = process.pid;
  sendJson(res, status, payload);
};

seedData();
refreshSummaryOnce('initial seed').catch(() => {});
startWorker();

const PORT = Number.parseInt(process.env.PORT || '8080', 10);
http.createServer(handler).listen(PORT, '0.0.0.0', () => {
  console.log(`Servidor Node escuchando en ${PORT}`);
});
