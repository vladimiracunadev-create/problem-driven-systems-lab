'use strict';

// Caso 01 — API lenta bajo carga (stack Node.js 22).
//
// Substrato real: SQLite embebido vía `node:sqlite` (built-in desde Node 22.5,
// sin `npm install` y sin bindings nativos). Las dos rutas ejecutan SQL real
// contra el motor — `db_queries_in_request` cuenta ejecuciones reales, no
// iteraciones de un bucle en memoria.
//
// Particularidad Node que este caso enseña: `DatabaseSync` es **sincrónico**.
// Cada query del N+1 bloquea el event loop mientras corre. Por eso
// `event_loop_lag_ms` deja de ser decorativo y pasa a ser la señal que delata
// el problema: la ruta legacy no solo es lenta para quien la pide, degrada el
// throughput de todo el proceso. La espera de red se modela aparte (`sleep`
// async) porque en un driver cliente-servidor real esa parte sí cede el loop.

const http = require('http');
const { URL } = require('url');
const { performance } = require('perf_hooks');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { DatabaseSync } = require('node:sqlite');

const APP_STACK = 'Node.js 22';
const CASE_NAME = '01 - API lenta bajo carga por cuellos de botella reales';
const WORKER_NAME = 'report-refresh-node';
const STORAGE_DIR = path.join(os.tmpdir(), 'pdsl-case01-node');
const METRICS_PATH = path.join(STORAGE_DIR, 'metrics.json');

// Round-trip artificial. SQLite es embebido: no hay hop de red. Estos ms
// modelan el viaje cliente-servidor de un motor real para que el costo de N+1
// sea visible. El trabajo SQL de abajo es real; esto es solo el transporte.
const ROUNDTRIP_LEGACY_MS = 1.2;
const ROUNDTRIP_DEFAULT_MS = 0.7;

const ensureStorageDir = () => {
  fs.mkdirSync(STORAGE_DIR, { recursive: true });
};

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// SQLite en memoria, por proceso. No persiste — el seed es determinista.
const db = new DatabaseSync(':memory:');

const initSchema = () => {
  db.exec(`
    CREATE TABLE customers (
      id         INTEGER PRIMARY KEY,
      name       TEXT NOT NULL,
      tier       TEXT NOT NULL,
      region     TEXT NOT NULL,
      created_at INTEGER NOT NULL
    );
    CREATE TABLE orders (
      id           INTEGER PRIMARY KEY,
      customer_id  INTEGER NOT NULL,
      status       TEXT NOT NULL,
      total_amount REAL NOT NULL,
      created_at   INTEGER NOT NULL
    );
    CREATE TABLE customer_daily_summary (
      customer_id  INTEGER NOT NULL,
      order_date   INTEGER NOT NULL,
      total_amount REAL NOT NULL,
      order_count  INTEGER NOT NULL,
      refreshed_at INTEGER NOT NULL,
      PRIMARY KEY (customer_id, order_date)
    );
    CREATE TABLE worker_state (
      worker_name      TEXT PRIMARY KEY,
      last_heartbeat   INTEGER,
      last_status      TEXT NOT NULL,
      last_duration_ms REAL,
      last_message     TEXT
    );
    CREATE TABLE job_runs (
      id           INTEGER PRIMARY KEY AUTOINCREMENT,
      worker_name  TEXT NOT NULL,
      status       TEXT NOT NULL,
      started_at   INTEGER NOT NULL,
      finished_at  INTEGER,
      duration_ms  REAL,
      rows_written INTEGER,
      note         TEXT
    );
    CREATE INDEX idx_orders_created_customer      ON orders (created_at, customer_id);
    CREATE INDEX idx_orders_customer_created      ON orders (customer_id, created_at DESC);
    CREATE INDEX idx_orders_status_created        ON orders (status, created_at);
    CREATE INDEX idx_summary_order_date_customer  ON customer_daily_summary (order_date, customer_id);
  `);
};

const REGIONS = ['north', 'south', 'east', 'west'];

const seedData = () => {
  let seed = 102030;
  const rng = () => {
    seed = (seed * 9301 + 49297) % 233280;
    return seed / 233280;
  };
  const now = Math.floor(Date.now() / 1000);

  db.exec('BEGIN');

  const insCustomer = db.prepare('INSERT INTO customers VALUES (?, ?, ?, ?, ?)');
  const insOrder = db.prepare('INSERT INTO orders VALUES (?, ?, ?, ?, ?)');

  for (let i = 1; i <= 1600; i += 1) {
    const tier = i % 10 === 0 ? 'gold' : i % 3 === 0 ? 'silver' : 'bronze';
    insCustomer.run(i, `Customer ${i}`, tier, REGIONS[i % 4], now - Math.floor(rng() * 365 * 86400));
  }

  for (let i = 1; i <= 36000; i += 1) {
    insOrder.run(
      i,
      1 + Math.floor(rng() * 1600),
      rng() < 0.88 ? 'paid' : 'pending',
      Number((15 + rng() * 1500).toFixed(2)),
      now - Math.floor(rng() * 180 * 86400)
    );
  }

  db.prepare('INSERT INTO worker_state VALUES (?, ?, ?, ?, ?)').run(
    WORKER_NAME,
    null,
    'init',
    null,
    'worker not started yet'
  );

  db.exec('COMMIT');
};

const dayBucket = (timestampSec) => Math.floor(timestampSec / 86400);

// Refresco del resumen: DELETE + INSERT ... SELECT reales contra el motor.
// Es el proceso batch que convive con la API — el mismo que en PHP corre en un
// contenedor aparte y en Python en un thread.
const refreshSummaryOnce = (note) => {
  const started = performance.now();
  const startedAt = Math.floor(Date.now() / 1000);

  db.exec('BEGIN');
  db.exec('DELETE FROM customer_daily_summary');
  const result = db
    .prepare(
      `INSERT INTO customer_daily_summary (customer_id, order_date, total_amount, order_count, refreshed_at)
       SELECT customer_id,
              CAST(created_at / 86400 AS INTEGER) AS order_date,
              ROUND(SUM(total_amount), 2)         AS total_amount,
              COUNT(*)                            AS order_count,
              ?
       FROM orders
       WHERE status = 'paid'
       GROUP BY customer_id, CAST(created_at / 86400 AS INTEGER)`
    )
    .run(startedAt);
  const rowsWritten = Number(result.changes);
  const durationMs = Number((performance.now() - started).toFixed(2));

  db.prepare(
    `UPDATE worker_state
     SET last_heartbeat = ?, last_status = ?, last_duration_ms = ?, last_message = ?
     WHERE worker_name = ?`
  ).run(Math.floor(Date.now() / 1000), 'ok', durationMs, note, WORKER_NAME);

  db.prepare(
    `INSERT INTO job_runs (worker_name, status, started_at, finished_at, duration_ms, rows_written, note)
     VALUES (?, ?, ?, ?, ?, ?, ?)`
  ).run(WORKER_NAME, 'ok', startedAt, Math.floor(Date.now() / 1000), durationMs, rowsWritten, note);
  db.exec('COMMIT');

  return { rows_written: rowsWritten, duration_ms: durationMs };
};

const startWorker = () => {
  setInterval(() => {
    try {
      refreshSummaryOnce('periodic summary refresh');
    } catch (_error) {
      try {
        db.prepare('UPDATE worker_state SET last_status = ? WHERE worker_name = ?').run(
          'error',
          WORKER_NAME
        );
      } catch (_ignored) {
        /* el worker no debe tumbar el proceso */
      }
    }
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

// El `await sleep` modela el hop de red (async en un driver real). La llamada a
// SQLite es sincrónica y bloquea el loop: eso es lo que mide event_loop_lag_ms.
const timedQuery = async (sql, params, stats, roundtripMs = ROUNDTRIP_DEFAULT_MS) => {
  const started = performance.now();
  if (roundtripMs) await sleep(roundtripMs);
  const rows = db.prepare(sql).all(...params);
  stats.db_time_ms += performance.now() - started;
  stats.db_queries += 1;
  return rows;
};

const topCustomersLegacy = async (days, limit, stats) => {
  const sinceDay = dayBucket(Math.floor(Date.now() / 1000) - days * 86400);

  // Filtro no sargable: CAST(created_at / 86400) impide usar el indice sobre
  // created_at. El motor recorre la tabla entera.
  const rows = await timedQuery(
    `SELECT customer_id, ROUND(SUM(total_amount), 2) AS total_spend, COUNT(*) AS order_count
     FROM orders
     WHERE CAST(created_at / 86400 AS INTEGER) >= ? AND status = 'paid'
     GROUP BY customer_id
     ORDER BY total_spend DESC
     LIMIT ?`,
    [sinceDay, limit],
    stats,
    ROUNDTRIP_LEGACY_MS
  );

  // N+1: dos queries dependientes por cada fila del resultado.
  const enriched = [];
  for (const row of rows) {
    const customer = await timedQuery(
      'SELECT id, name, tier, region FROM customers WHERE id = ?',
      [row.customer_id],
      stats
    );
    const recent = await timedQuery(
      `SELECT id, total_amount, status, created_at
       FROM orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 3`,
      [row.customer_id],
      stats
    );
    enriched.push({ ...row, customer: customer[0] || null, recent_orders: recent });
  }
  return enriched;
};

const topCustomersOptimized = async (days, limit, stats) => {
  const sinceDay = dayBucket(Math.floor(Date.now() / 1000) - days * 86400);

  // Lectura sargable contra la tabla resumen que mantiene el worker.
  const rows = await timedQuery(
    `SELECT c.id AS customer_id, c.name, c.tier, c.region,
            ROUND(SUM(s.total_amount), 2) AS total_spend,
            SUM(s.order_count)            AS order_count
     FROM customer_daily_summary s
     JOIN customers c ON c.id = s.customer_id
     WHERE s.order_date >= ?
     GROUP BY c.id, c.name, c.tier, c.region
     ORDER BY total_spend DESC
     LIMIT ?`,
    [sinceDay, limit],
    stats
  );

  if (!rows.length) return [];

  // Un solo batch con window function reemplaza las 2N queries del N+1.
  const ids = rows.map((row) => row.customer_id);
  const placeholders = ids.map(() => '?').join(',');
  const details = await timedQuery(
    `SELECT customer_id, id, total_amount, status, created_at
     FROM (
       SELECT customer_id, id, total_amount, status, created_at,
              ROW_NUMBER() OVER (PARTITION BY customer_id ORDER BY created_at DESC) AS rn
       FROM orders
       WHERE customer_id IN (${placeholders})
     )
     WHERE rn <= 3
     ORDER BY customer_id, created_at DESC`,
    ids,
    stats
  );

  const detailMap = new Map();
  for (const detail of details) {
    const list = detailMap.get(detail.customer_id) || [];
    list.push({
      id: detail.id,
      total_amount: detail.total_amount,
      status: detail.status,
      created_at: detail.created_at,
    });
    detailMap.set(detail.customer_id, list);
  }
  return rows.map((row) => ({ ...row, recent_orders: detailMap.get(row.customer_id) || [] }));
};

const workerStatusPayload = async (stats) => {
  const state = await timedQuery(
    `SELECT worker_name, last_heartbeat, last_status, last_duration_ms, last_message
     FROM worker_state WHERE worker_name = ?`,
    [WORKER_NAME],
    stats,
    0
  );
  const runs = await timedQuery(
    `SELECT id, status, started_at, finished_at, duration_ms, rows_written, note
     FROM job_runs WHERE worker_name = ? ORDER BY id DESC LIMIT 5`,
    [WORKER_NAME],
    stats,
    0
  );
  return { worker: state[0] || null, recent_runs: runs };
};

const databaseDiagnostics = async (stats) => {
  const counts = (
    await timedQuery(
      `SELECT
         (SELECT COUNT(*) FROM customers)              AS customers_count,
         (SELECT COUNT(*) FROM orders)                 AS orders_count,
         (SELECT COUNT(*) FROM customer_daily_summary) AS summary_count,
         (SELECT COUNT(*) FROM job_runs)               AS job_runs_count`,
      [],
      stats,
      0
    )
  )[0];
  const slowest = await timedQuery(
    `SELECT id, duration_ms, rows_written, started_at, finished_at, note
     FROM job_runs ORDER BY duration_ms DESC, id DESC LIMIT 5`,
    [],
    stats,
    0
  );
  return {
    row_counts: {
      customers: counts.customers_count,
      orders: counts.orders_count,
      customer_daily_summary: counts.summary_count,
      job_runs: counts.job_runs_count,
    },
    slowest_worker_runs: slowest,
  };
};

const diagnosticsSummary = async (stats) => {
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
      note: 'Lag medido entre setImmediate y la callback. node:sqlite es sincronico: cada query del N+1 bloquea el loop mientras corre, no solo espera.',
    },
    worker: await workerStatusPayload(stats),
    database: await databaseDiagnostics(stats),
    interpretation: {
      legacy_route_should_be_higher:
        'La ruta legacy agrega sobre datos transaccionales con un filtro no sargable y luego enriquece con 2N queries dependientes.',
      worker_pressure_note:
        'El worker refresca customer_daily_summary cada 20s con DELETE + INSERT ... SELECT. La ruta optimized lee ese resultado ya agregado.',
      node_specific:
        'DatabaseSync bloquea el event loop por query. En Node el N+1 no penaliza solo a quien lo pide: degrada el throughput de todo el proceso, y eso se ve en event_loop_lag_ms.',
    },
  };
};

const prometheusLabel = (value) =>
  String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, ' ');

const renderPrometheusMetrics = () => {
  const summary = metricsSummary(readMetrics());
  const workerRow =
    db
      .prepare('SELECT last_status, last_duration_ms FROM worker_state WHERE worker_name = ?')
      .get(WORKER_NAME) || null;
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
  lines.push('# HELP app_db_time_ms Tiempo agregado de DB por request en milisegundos.');
  lines.push('# TYPE app_db_time_ms gauge');
  lines.push(`app_db_time_ms{stat="avg"} ${summary.avg_db_time_ms}`);
  lines.push(`app_db_time_ms{stat="p95"} ${summary.p95_db_time_ms}`);
  lines.push('# HELP app_db_queries Cantidad de queries reales por request.');
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
  if (workerRow && workerRow.last_duration_ms !== null) {
    lines.push('# HELP app_worker_last_duration_ms Ultima duracion reportada por el worker.');
    lines.push('# TYPE app_worker_last_duration_ms gauge');
    lines.push(
      `app_worker_last_duration_ms{worker="${prometheusLabel(WORKER_NAME)}"} ${workerRow.last_duration_ms}`
    );
    lines.push('# HELP app_worker_status Estado logico del worker. 1=ok, 0=otro estado.');
    lines.push('# TYPE app_worker_status gauge');
    lines.push(
      `app_worker_status{worker="${prometheusLabel(WORKER_NAME)}",status="${prometheusLabel(workerRow.last_status)}"} ${workerRow.last_status === 'ok' ? 1 : 0}`
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
          'Comparar una ruta legacy con filtro no sargable y N+1 sobre SQL real contra una ruta optimizada con tabla resumen y batch.',
        routes: {
          '/health': 'Estado basico del servicio.',
          '/report-legacy?days=30&limit=20':
            'Consulta defectuosa: filtro no sargable sobre la tabla transaccional + N+1 real contra SQLite.',
          '/report-optimized?days=30&limit=20':
            'Consulta mejorada: lectura sobre customer_daily_summary + un solo batch con window function.',
          '/batch/status': 'Estado del worker embebido.',
          '/diagnostics/summary':
            'Resumen correlacionado entre metricas, worker, base local y lag del event loop.',
          '/job-runs?limit=10': 'Ultimas ejecuciones del worker.',
          '/metrics': 'Metricas JSON.',
          '/metrics-prometheus': 'Metricas en formato Prometheus.',
          '/reset-metrics': 'Reinicia metricas locales.',
        },
        node_specific:
          'node:sqlite (DatabaseSync) es sincronico: cada query del N+1 bloquea el event loop. El costo no es solo de la request que lo dispara, es del proceso entero.',
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
          'Filtro no sargable (CAST sobre created_at) + patron N+1 real: 2 queries dependientes por fila.',
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
          'Tabla resumen mantenida por el worker + un solo batch con ROW_NUMBER(). Menos queries, menos bloqueo del loop.',
        days,
        limit,
        result_count: rows.length,
        db_queries_in_request: stats.db_queries,
        db_time_ms_in_request: Number(stats.db_time_ms.toFixed(2)),
        data: rows,
      };
    } else if (uri === '/batch/status') {
      payload = await workerStatusPayload(stats);
    } else if (uri === '/job-runs') {
      const limit = clampInt(url.searchParams.get('limit') || '10', 1, 50);
      payload = {
        limit,
        runs: await timedQuery(
          `SELECT id, worker_name, status, started_at, finished_at, duration_ms, rows_written, note
           FROM job_runs ORDER BY id DESC LIMIT ?`,
          [limit],
          stats,
          0
        ),
      };
    } else if (uri === '/diagnostics/summary') {
      payload = await diagnosticsSummary(stats);
    } else if (uri === '/metrics') {
      payload = {
        case: CASE_NAME,
        stack: APP_STACK,
        ...metricsSummary(readMetrics()),
        note:
          'Metrica util de laboratorio. event_loop_lag_ms es la senal Node-especifica: node:sqlite es sincronico y el N+1 bloquea el loop.',
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

initSchema();
seedData();
refreshSummaryOnce('initial seed');
startWorker();

const PORT = Number.parseInt(process.env.PORT || '8080', 10);
http.createServer(handler).listen(PORT, '0.0.0.0', () => {
  console.log(`Servidor Node escuchando en ${PORT}`);
});
