'use strict';

const http = require('http');
const { URL } = require('url');
const { performance } = require('perf_hooks');
const fs = require('fs');
const os = require('os');
const path = require('path');

const APP_STACK = 'Node.js 20';
const CASE_NAME = '02 - N+1 queries y cuellos de botella en base de datos';
const STORAGE_DIR = path.join(os.tmpdir(), 'pdsl-case02-node');
const METRICS_PATH = path.join(STORAGE_DIR, 'metrics.json');

const ROUNDTRIP_LEGACY_MS = 0.8;
const ROUNDTRIP_BATCH_MS = 0.5;

const ensureStorageDir = () => fs.mkdirSync(STORAGE_DIR, { recursive: true });
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const customers = new Map();
const categories = new Map();
const products = new Map();
const orders = [];
const orderItems = new Map();
const ordersIndexById = new Map();

const seedData = () => {
  let seed = 20260427;
  const rng = () => {
    seed = (seed * 9301 + 49297) % 233280;
    return seed / 233280;
  };
  const now = Math.floor(Date.now() / 1000);

  for (let i = 1; i <= 24; i += 1) {
    categories.set(i, { id: i, name: `Category ${i}` });
  }
  for (let i = 1; i <= 900; i += 1) {
    customers.set(i, {
      id: i,
      name: `Customer ${i}`,
      email: `customer${i}@lab.local`,
      segment: i % 12 === 0 ? 'enterprise' : i % 4 === 0 ? 'mid-market' : 'smb',
    });
  }
  for (let i = 1; i <= 360; i += 1) {
    products.set(i, {
      id: i,
      sku: `SKU-${String(i).padStart(4, '0')}`,
      name: `Product ${i}`,
      category_id: 1 + ((i - 1) % 24),
      list_price: Number((15 + rng() * 250).toFixed(2)),
    });
  }
  let itemId = 1;
  for (let orderId = 1; orderId <= 2600; orderId += 1) {
    const created_at = now - Math.floor(rng() * 120 * 86400);
    const status = rng() < 0.55 ? 'paid' : rng() < 0.85 ? 'shipped' : 'pending';
    const customer_id = 1 + Math.floor(rng() * 900);
    const items = [];
    let total = 0;
    const itemCount = 2 + Math.floor(rng() * 5);
    for (let k = 0; k < itemCount; k += 1) {
      const product_id = 1 + Math.floor(rng() * 360);
      const quantity = 1 + Math.floor(rng() * 3);
      const unit_price = Number((10 + rng() * 220).toFixed(2));
      total += quantity * unit_price;
      items.push({ id: itemId, order_id: orderId, product_id, quantity, unit_price });
      itemId += 1;
    }
    const order = {
      id: orderId,
      customer_id,
      status,
      total_amount: Number(total.toFixed(2)),
      created_at,
    };
    orders.push(order);
    ordersIndexById.set(orderId, order);
    orderItems.set(orderId, items);
  }
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
    setImmediate(() => resolve(performance.now() - start));
  });

const timedQuery = async (work, stats, roundtripMs = ROUNDTRIP_LEGACY_MS) => {
  const started = performance.now();
  await sleep(roundtripMs);
  const result = work();
  stats.db_time_ms += performance.now() - started;
  stats.db_queries += 1;
  return result;
};

const recentOrdersLegacy = async (days, limit, stats) => {
  const since = Math.floor(Date.now() / 1000) - days * 86400;
  const baseOrders = await timedQuery(
    () =>
      orders
        .filter((o) => o.created_at >= since && (o.status === 'paid' || o.status === 'shipped'))
        .sort((a, b) => b.created_at - a.created_at)
        .slice(0, limit)
        .map((o) => ({ ...o })),
    stats,
    ROUNDTRIP_LEGACY_MS
  );

  for (const order of baseOrders) {
    order.customer = await timedQuery(() => customers.get(order.customer_id) || null, stats);
    const items = await timedQuery(
      () => (orderItems.get(order.id) || []).map((it) => ({ ...it })),
      stats
    );
    for (const item of items) {
      const product = await timedQuery(() => products.get(item.product_id) || null, stats);
      const category = product
        ? await timedQuery(() => categories.get(product.category_id) || null, stats)
        : null;
      item.product = product;
      item.category = category;
    }
    order.items = items;
  }
  return baseOrders;
};

const recentOrdersOptimized = async (days, limit, stats) => {
  const since = Math.floor(Date.now() / 1000) - days * 86400;
  const baseOrders = await timedQuery(
    () =>
      orders
        .filter((o) => o.created_at >= since && (o.status === 'paid' || o.status === 'shipped'))
        .sort((a, b) => b.created_at - a.created_at)
        .slice(0, limit)
        .map((o) => {
          const customer = customers.get(o.customer_id);
          return {
            id: o.id,
            customer_id: o.customer_id,
            status: o.status,
            total_amount: o.total_amount,
            created_at: o.created_at,
            customer: customer
              ? {
                  id: customer.id,
                  name: customer.name,
                  email: customer.email,
                  segment: customer.segment,
                }
              : null,
          };
        }),
    stats,
    ROUNDTRIP_BATCH_MS
  );

  if (!baseOrders.length) return [];

  const ids = new Set(baseOrders.map((o) => o.id));
  const itemsByOrder = await timedQuery(
    () => {
      const grouped = new Map();
      for (const orderId of ids) {
        const itemsForOrder = orderItems.get(orderId) || [];
        const enriched = itemsForOrder.map((it) => {
          const product = products.get(it.product_id) || null;
          const category = product ? categories.get(product.category_id) : null;
          return {
            id: it.id,
            quantity: it.quantity,
            unit_price: it.unit_price,
            product: product
              ? {
                  id: product.id,
                  sku: product.sku,
                  name: product.name,
                  list_price: product.list_price,
                }
              : null,
            category: category ? { id: category.id, name: category.name } : null,
          };
        });
        grouped.set(orderId, enriched);
      }
      return grouped;
    },
    stats,
    ROUNDTRIP_BATCH_MS
  );

  for (const order of baseOrders) {
    order.items = itemsByOrder.get(order.id) || [];
  }
  return baseOrders;
};

const databaseDiagnostics = () => {
  const itemsCount = [...orderItems.values()].reduce((acc, list) => acc + list.length, 0);
  const counts = [...orderItems.values()].map((list) => list.length);
  const avgItems = counts.length
    ? Number((counts.reduce((a, b) => a + b, 0) / counts.length).toFixed(2))
    : 0;
  const maxItems = counts.length ? Math.max(...counts) : 0;
  return {
    row_counts: {
      customers: customers.size,
      categories: categories.size,
      products: products.size,
      orders: orders.length,
      order_items: itemsCount,
    },
    relationships: { avg_items_per_order: avgItems, max_items_per_order: maxItems },
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
      note: 'En Node, el N+1 anidado (await dentro de un for-of dentro de otro for-of) penaliza throughput global, no solo la propia request.',
    },
    database: databaseDiagnostics(),
    interpretation: {
      legacy_should_issue_many_queries:
        'La ruta legacy consulta cliente, items, producto y categoria dentro de bucles anidados con await secuencial.',
      optimized_should_be_stable:
        'La ruta optimized hace una lectura base con join en memoria + un solo batch para los detalles agrupados.',
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
        goal: 'Comparar N+1 contra lecturas consolidadas usando el mismo dataset local.',
        routes: {
          '/health': 'Estado basico del servicio.',
          '/orders-legacy?days=30&limit=20':
            'Version con N+1 anidado sobre pedidos, cliente, items, producto y categoria.',
          '/orders-optimized?days=30&limit=20':
            'Version consolidada con join en memoria + batch de detalles.',
          '/diagnostics/summary': 'Resumen entre metricas, densidad relacional y lag del event loop.',
          '/metrics': 'Metricas JSON.',
          '/metrics-prometheus': 'Metricas formato Prometheus.',
          '/reset-metrics': 'Reinicia metricas locales.',
        },
        node_specific:
          'En Node, N+1 anidado se traduce en doble bucle de awaits secuenciales: 1 + N + sum(items_por_order * 2). El optimized colapsa todo a 2 lecturas sin yield al loop entre items.',
      };
    } else if (uri === '/health') {
      payload = { status: 'ok', stack: APP_STACK };
    } else if (uri === '/orders-legacy') {
      const days = clampInt(url.searchParams.get('days') || '30', 1, 180);
      const limit = clampInt(url.searchParams.get('limit') || '20', 1, 60);
      const data = await recentOrdersLegacy(days, limit, stats);
      payload = {
        mode: 'legacy',
        problem: 'N+1 sobre multiples relaciones con round-trips por pedido e item.',
        days,
        limit,
        result_count: data.length,
        db_queries_in_request: stats.db_queries,
        db_time_ms_in_request: Number(stats.db_time_ms.toFixed(2)),
        data,
      };
    } else if (uri === '/orders-optimized') {
      const days = clampInt(url.searchParams.get('days') || '30', 1, 180);
      const limit = clampInt(url.searchParams.get('limit') || '20', 1, 60);
      const data = await recentOrdersOptimized(days, limit, stats);
      payload = {
        mode: 'optimized',
        solution: 'Carga consolidada de pedidos + un solo batch de detalles para evitar round-trips.',
        days,
        limit,
        result_count: data.length,
        db_queries_in_request: stats.db_queries,
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
const PORT = Number.parseInt(process.env.PORT || '8080', 10);
http.createServer(handler).listen(PORT, '0.0.0.0', () => {
  console.log(`Servidor Node escuchando en ${PORT}`);
});
