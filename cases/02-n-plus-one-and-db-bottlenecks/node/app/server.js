'use strict';

// Caso 02 — N+1 queries y cuellos de botella en base de datos (stack Node.js).
//
// Substrato real: SQLite embebido vía `node:sqlite` (built-in en Node 22.x con
// flag --experimental-sqlite). Sin contenedor extra, sin puerto extra: el
// motor corre en proceso. Esto cierra la asimetría con PHP/Python (que ya
// usaban DB real) — antes este caso simulaba N+1 con `Map`/`Dictionary` en
// memoria, lo que pierde el sentido pedagógico: N+1 *es* un problema de
// round-trips contra una base relacional.
//
// Schema (unificado entre Node/Java/.NET para que las comparaciones sean justas):
//   categories(id, name)                              24 filas
//   customers(id, name, region, category_id)          900 filas
//   orders(id, customer_id, total, created_at)        1500 filas
//   order_items(id, order_id, sku, qty, price)        ~5000 filas

const http = require('http');
const { URL } = require('url');
const { performance } = require('perf_hooks');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { DatabaseSync } = require('node:sqlite');

const APP_STACK = 'Node.js 22';
const CASE_NAME = '02 - N+1 queries y cuellos de botella en base de datos';
const STORAGE_DIR = path.join(os.tmpdir(), 'pdsl-case02-node');
const METRICS_PATH = path.join(STORAGE_DIR, 'metrics.json');

const ensureStorageDir = () => fs.mkdirSync(STORAGE_DIR, { recursive: true });

// SQLite en memoria, por proceso. No persiste — el seed es determinista.
const db = new DatabaseSync(':memory:');

const initSchema = () => {
  db.exec(`
    CREATE TABLE categories (
      id   INTEGER PRIMARY KEY,
      name TEXT NOT NULL
    );
    CREATE TABLE customers (
      id          INTEGER PRIMARY KEY,
      name        TEXT NOT NULL,
      region      TEXT NOT NULL,
      category_id INTEGER NOT NULL
    );
    CREATE TABLE orders (
      id          INTEGER PRIMARY KEY,
      customer_id INTEGER NOT NULL,
      total       REAL NOT NULL,
      created_at  INTEGER NOT NULL
    );
    CREATE TABLE order_items (
      id       INTEGER PRIMARY KEY,
      order_id INTEGER NOT NULL,
      sku      TEXT NOT NULL,
      qty      INTEGER NOT NULL,
      price    REAL NOT NULL
    );
    CREATE INDEX idx_orders_created  ON orders (created_at DESC);
    CREATE INDEX idx_items_order_id  ON order_items (order_id);
  `);
};

const REGIONS = ['LATAM', 'NA', 'EMEA', 'APAC'];

const seedData = () => {
  let seed = 20260427;
  const rng = () => {
    seed = (seed * 9301 + 49297) % 233280;
    return seed / 233280;
  };
  const now = Math.floor(Date.now() / 1000);

  db.exec('BEGIN');

  const insCat  = db.prepare('INSERT INTO categories VALUES (?, ?)');
  const insCust = db.prepare('INSERT INTO customers VALUES (?, ?, ?, ?)');
  const insOrd  = db.prepare('INSERT INTO orders VALUES (?, ?, ?, ?)');
  const insItem = db.prepare('INSERT INTO order_items VALUES (?, ?, ?, ?, ?)');

  for (let i = 1; i <= 24; i += 1) insCat.run(i, `Category ${i}`);
  for (let i = 1; i <= 900; i += 1) {
    const region = REGIONS[Math.floor(rng() * REGIONS.length)];
    insCust.run(i, `Customer ${i}`, region, 1 + ((i - 1) % 24));
  }

  let itemId = 1;
  for (let orderId = 1; orderId <= 1500; orderId += 1) {
    const created_at  = now - Math.floor(rng() * 120 * 86400);
    const customer_id = 1 + Math.floor(rng() * 900);
    const itemCount   = 2 + Math.floor(rng() * 4); // 2..5 → promedio 3.5 → ~5250 items
    let total = 0;
    const items = [];
    for (let k = 0; k < itemCount; k += 1) {
      const sku   = `SKU-${String(1000 + Math.floor(rng() * 9000)).padStart(4, '0')}`;
      const qty   = 1 + Math.floor(rng() * 3);
      const price = Number((10 + rng() * 220).toFixed(2));
      total += qty * price;
      items.push([itemId, orderId, sku, qty, price]);
      itemId += 1;
    }
    insOrd.run(orderId, customer_id, Number(total.toFixed(2)), created_at);
    for (const it of items) insItem.run(...it);
  }

  db.exec('COMMIT');
};

// Bootstrap del schema + seed determinista ANTES de preparar statements —
// si no, los `db.prepare(...)` top-level fallarian con "no such table".
initSchema();
seedData();

// Statements preparados — reutilizados para que el coste medido sea el del
// round-trip a SQLite (parse+plan+execute), no del parse.
const stmtOrdersLimit  = db.prepare(
  'SELECT id, customer_id, total, created_at FROM orders ORDER BY id ASC LIMIT ?'
);
const stmtItemsByOrder = db.prepare(
  'SELECT id, order_id, sku, qty, price FROM order_items WHERE order_id = ? ORDER BY id ASC'
);
const stmtCounts = db.prepare(`
  SELECT
    (SELECT COUNT(*) FROM customers)   AS customers,
    (SELECT COUNT(*) FROM categories)  AS categories,
    (SELECT COUNT(*) FROM orders)      AS orders,
    (SELECT COUNT(*) FROM order_items) AS order_items
`);

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
    setImmediate(() => resolve(performance.now() - start));
  });

// Cada `runQuery` representa un round-trip real al motor SQLite. Antes el
// contador era ficticio (Map.get); ahora cuenta invocaciones a stmt.all/get,
// que parsea/planifica/ejecuta — exactamente lo que paga un cliente JDBC,
// libpq o cualquier driver real.
const runQuery = (work, stats) => {
  const started = performance.now();
  const result = work();
  stats.db_time_ms += performance.now() - started;
  stats.db_queries += 1;
  return result;
};

const recentOrdersLegacy = (limit, stats) => {
  // 1: SELECT orders LIMIT N
  const baseOrders = runQuery(() => stmtOrdersLimit.all(limit), stats);
  // N: por cada order, un SELECT items individual — el anti-patrón clásico.
  for (const order of baseOrders) {
    order.items = runQuery(() => stmtItemsByOrder.all(order.id), stats);
  }
  return baseOrders;
};

const recentOrdersOptimized = (limit, stats) => {
  // 1: SELECT orders LIMIT N
  const baseOrders = runQuery(() => stmtOrdersLimit.all(limit), stats);
  if (!baseOrders.length) return [];

  // 2: SELECT items WHERE order_id IN (?, ?, ...) — un solo batch.
  // Placeholders dinámicos: SQLite los reusa con plan estable; cada request
  // con el mismo N reaprovecha el cache de query plans.
  const ids = baseOrders.map((o) => o.id);
  const placeholders = ids.map(() => '?').join(',');
  const itemRows = runQuery(() => {
    const stmt = db.prepare(
      `SELECT id, order_id, sku, qty, price FROM order_items WHERE order_id IN (${placeholders}) ORDER BY id ASC`
    );
    return stmt.all(...ids);
  }, stats);

  const itemsByOrder = new Map();
  for (const row of itemRows) {
    if (!itemsByOrder.has(row.order_id)) itemsByOrder.set(row.order_id, []);
    itemsByOrder.get(row.order_id).push(row);
  }
  for (const order of baseOrders) {
    order.items = itemsByOrder.get(order.id) || [];
  }
  return baseOrders;
};

const databaseDiagnostics = () => {
  const counts = stmtCounts.get();
  const agg = db
    .prepare(
      'SELECT AVG(c) AS avg_items, MAX(c) AS max_items FROM (SELECT COUNT(*) AS c FROM order_items GROUP BY order_id)'
    )
    .get();
  return {
    row_counts: {
      customers:   counts.customers,
      categories:  counts.categories,
      orders:      counts.orders,
      order_items: counts.order_items,
    },
    relationships: {
      avg_items_per_order: Number((agg.avg_items || 0).toFixed(2)),
      max_items_per_order: agg.max_items || 0,
    },
  };
};

const diagnosticsSummary = () => {
  const summary = metricsSummary(readMetrics());
  const legacy = summary.routes['/orders-legacy'] || {};
  const optimized = summary.routes['/orders-optimized'] || {};
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
      note: 'En Node, el N+1 sobre SQLite síncrono bloquea el event loop por la duración del bucle entero.',
    },
    database: databaseDiagnostics(),
    interpretation: {
      legacy_should_issue_many_queries:
        'La ruta legacy ejecuta 1 SELECT orders + N SELECT items (uno por order). db_hits = 1 + N.',
      optimized_should_be_stable:
        'La ruta optimized ejecuta 1 SELECT orders + 1 SELECT items con IN(...) batch. db_hits = 2 sin importar N.',
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
  lines.push('# HELP app_db_queries Cantidad de queries por request.');
  lines.push('# TYPE app_db_queries gauge');
  lines.push(`app_db_queries{stat="avg"} ${summary.avg_db_queries}`);
  lines.push(`app_db_queries{stat="p95"} ${summary.p95_db_queries}`);
  lines.push('# HELP app_event_loop_lag_ms Lag del event loop por request.');
  lines.push('# TYPE app_event_loop_lag_ms gauge');
  lines.push(`app_event_loop_lag_ms{stat="avg"} ${summary.avg_event_loop_lag_ms}`);
  lines.push(`app_event_loop_lag_ms{stat="p95"} ${summary.p95_event_loop_lag_ms}`);
  for (const [route, stats] of Object.entries(summary.routes || {})) {
    const label = prometheusLabel(route);
    lines.push(`app_route_latency_ms{route="${label}",stat="avg"} ${stats.avg_ms}`);
    lines.push(`app_route_latency_ms{route="${label}",stat="p95"} ${stats.p95_ms}`);
    lines.push(`app_route_requests_total{route="${label}"} ${stats.count}`);
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
        goal: 'Comparar N+1 contra lecturas consolidadas sobre SQLite embebido.',
        routes: {
          '/health': 'Estado basico del servicio.',
          '/orders-legacy?limit=20':
            'Version con N+1: 1 SELECT orders + N SELECT items.',
          '/orders-optimized?limit=20':
            'Version consolidada: 1 SELECT orders + 1 SELECT items con IN(...) batch.',
          '/diagnostics/summary': 'Resumen entre metricas, densidad relacional y lag del event loop.',
          '/metrics': 'Metricas JSON.',
          '/metrics-prometheus': 'Metricas formato Prometheus.',
          '/reset-metrics': 'Reinicia metricas locales.',
        },
        node_specific:
          'En Node, N+1 contra SQLite síncrono bloquea el event loop por la duración del bucle. El optimized colapsa todo a 2 queries.',
      };
    } else if (uri === '/health') {
      payload = { status: 'ok', stack: APP_STACK };
    } else if (uri === '/orders-legacy') {
      const limit = clampInt(url.searchParams.get('limit') || '20', 1, 200);
      const data = recentOrdersLegacy(limit, stats);
      payload = {
        mode: 'legacy',
        problem: '1 SELECT orders + N SELECT items (uno por order).',
        limit,
        result_count: data.length,
        db_hits: stats.db_queries,
        db_queries_in_request: stats.db_queries, // alias retrocompatible
        db_time_ms_in_request: Number(stats.db_time_ms.toFixed(2)),
        data,
      };
    } else if (uri === '/orders-optimized') {
      const limit = clampInt(url.searchParams.get('limit') || '20', 1, 200);
      const data = recentOrdersOptimized(limit, stats);
      payload = {
        mode: 'optimized',
        solution: '1 SELECT orders + 1 SELECT items con IN(...) batch.',
        limit,
        result_count: data.length,
        db_hits: stats.db_queries,
        db_queries_in_request: stats.db_queries, // alias retrocompatible
        db_time_ms_in_request: Number(stats.db_time_ms.toFixed(2)),
        data,
      };
    } else if (uri === '/diagnostics/summary') {
      payload = diagnosticsSummary();
    } else if (uri === '/metrics') {
      payload = { case: CASE_NAME, stack: APP_STACK, ...metricsSummary(readMetrics()) };
    } else if (uri === '/metrics-prometheus') {
      skipStoreMetrics = true;
      res.writeHead(200, { 'Content-Type': 'text/plain; version=0.0.4; charset=utf-8' });
      res.end(renderPrometheusMetrics());
      return;
    } else if (uri === '/reset-metrics' || uri === '/reset-lab') {
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
  if (!skipStoreMetrics && uri !== '/metrics' && uri !== '/reset-metrics' && uri !== '/reset-lab') {
    storeRequestMetrics(uri, status, elapsedMs, stats.db_time_ms, stats.db_queries, lagMs);
  }
  payload.elapsed_ms = Number(elapsedMs.toFixed(2));
  payload.event_loop_lag_ms = Number(lagMs.toFixed(2));
  payload.timestamp_utc = new Date().toISOString();
  payload.pid = process.pid;
  sendJson(res, status, payload);
};

const PORT = Number.parseInt(process.env.PORT || '8080', 10);
http.createServer(handler).listen(PORT, '0.0.0.0', () => {
  console.log(`Servidor Node escuchando en ${PORT}`);
});
