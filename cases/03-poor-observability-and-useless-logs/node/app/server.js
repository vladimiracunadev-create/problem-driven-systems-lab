const crypto = require('crypto');
const fs = require('fs');
const http = require('http');
const os = require('os');
const path = require('path');
const { URL } = require('url');

class WorkflowFailure extends Error {
  constructor(message, step, dependency, httpStatus, requestId, traceId, events) {
    super(message);
    this.step = step;
    this.dependency = dependency;
    this.httpStatus = httpStatus;
    this.requestId = requestId;
    this.traceId = traceId;
    this.events = events;
  }
}

const APP_STACK = 'Node.js 20';
const STORAGE_DIR = path.join(os.tmpdir(), 'pdsl-case03-node');
const TELEMETRY_PATH = path.join(STORAGE_DIR, 'telemetry.json');
const LEGACY_LOG_PATH = path.join(STORAGE_DIR, 'legacy.log');
const OBSERVABLE_LOG_PATH = path.join(STORAGE_DIR, 'observable.log');

const ensureStorageDir = () => {
  fs.mkdirSync(STORAGE_DIR, { recursive: true });
};

const initialTelemetry = () => ({
  requests: 0,
  samples_ms: [],
  routes: {},
  last_path: null,
  last_status: 200,
  last_updated: null,
  status_bucket: '2xx',
  successes: {
    legacy: 0,
    observable: 0,
  },
  failures: {
    legacy: { total: 0, by_step: {}, by_scenario: {} },
    observable: { total: 0, by_step: {}, by_scenario: {} },
  },
  traces: [],
});

const readTelemetry = () => {
  ensureStorageDir();
  if (!fs.existsSync(TELEMETRY_PATH)) {
    return initialTelemetry();
  }

  try {
    const parsed = JSON.parse(fs.readFileSync(TELEMETRY_PATH, 'utf8'));
    const seed = initialTelemetry();
    return {
      ...seed,
      ...parsed,
      successes: { ...seed.successes, ...(parsed.successes || {}) },
      failures: {
        legacy: { ...seed.failures.legacy, ...((parsed.failures || {}).legacy || {}) },
        observable: { ...seed.failures.observable, ...((parsed.failures || {}).observable || {}) },
      },
      routes: parsed.routes || {},
      traces: Array.isArray(parsed.traces) ? parsed.traces : [],
      samples_ms: Array.isArray(parsed.samples_ms) ? parsed.samples_ms : [],
    };
  } catch (error) {
    return initialTelemetry();
  }
};

const writeTelemetry = (telemetry) => {
  ensureStorageDir();
  fs.writeFileSync(TELEMETRY_PATH, JSON.stringify(telemetry, null, 2));
};

const resetTelemetryState = () => {
  writeTelemetry(initialTelemetry());
  [LEGACY_LOG_PATH, OBSERVABLE_LOG_PATH].forEach((target) => {
    if (fs.existsSync(target)) {
      fs.unlinkSync(target);
    }
  });
};

const appendLegacyLog = (message) => {
  ensureStorageDir();
  fs.appendFileSync(LEGACY_LOG_PATH, `[${new Date().toISOString()}] ${message}\n`);
};

const appendStructuredLog = (record) => {
  ensureStorageDir();
  fs.appendFileSync(
    OBSERVABLE_LOG_PATH,
    `${JSON.stringify({ ...record, timestamp_utc: new Date().toISOString() })}\n`
  );
};

const tailLines = (targetPath, limit) => {
  if (!fs.existsSync(targetPath)) {
    return [];
  }

  const lines = fs.readFileSync(targetPath, 'utf8').split(/\r?\n/).filter(Boolean);
  return lines.slice(-limit);
};

const percentile = (values, percent) => {
  if (!values.length) {
    return 0;
  }

  const sorted = [...values].sort((a, b) => a - b);
  const index = Math.max(0, Math.min(sorted.length - 1, Math.ceil((percent / 100) * sorted.length) - 1));
  return Number(sorted[index].toFixed(2));
};

const clampInt = (value, min, max) => Math.max(min, Math.min(max, value));

const requestId = (prefix) => `${prefix}-${crypto.randomBytes(4).toString('hex')}`;

const bucketKeyForStatus = (status) => {
  if (status >= 500) return '5xx';
  if (status >= 400) return '4xx';
  return '2xx';
};

const routeMetricsSummary = (telemetry) => {
  const routes = {};
  Object.entries(telemetry.routes || {}).forEach(([route, samples]) => {
    const values = Array.isArray(samples) ? samples : [];
    const count = values.length;
    routes[route] = {
      count,
      avg_ms: count ? Number((values.reduce((sum, item) => sum + item, 0) / count).toFixed(2)) : 0,
      p95_ms: percentile(values, 95),
      p99_ms: percentile(values, 99),
      max_ms: count ? Number(Math.max(...values).toFixed(2)) : 0,
    };
  });

  return Object.fromEntries(Object.entries(routes).sort(([left], [right]) => left.localeCompare(right)));
};

const telemetrySummary = (telemetry) => {
  const samples = telemetry.samples_ms || [];
  const count = samples.length;

  return {
    requests_tracked: telemetry.requests || 0,
    sample_count: count,
    avg_ms: count ? Number((samples.reduce((sum, item) => sum + item, 0) / count).toFixed(2)) : 0,
    p95_ms: percentile(samples, 95),
    p99_ms: percentile(samples, 99),
    max_ms: count ? Number(Math.max(...samples).toFixed(2)) : 0,
    last_path: telemetry.last_path || null,
    last_status: telemetry.last_status || 200,
    last_updated: telemetry.last_updated || null,
    successes: telemetry.successes || { legacy: 0, observable: 0 },
    failures: telemetry.failures || initialTelemetry().failures,
    routes: routeMetricsSummary(telemetry),
    recent_traces: [...(telemetry.traces || [])].reverse(),
  };
};

const recordRequestTelemetry = (uri, status, elapsedMs, workflowContext) => {
  const telemetry = readTelemetry();
  telemetry.requests += 1;
  telemetry.samples_ms.push(Number(elapsedMs.toFixed(2)));
  if (telemetry.samples_ms.length > 3000) {
    telemetry.samples_ms = telemetry.samples_ms.slice(-3000);
  }

  telemetry.routes[uri] = telemetry.routes[uri] || [];
  telemetry.routes[uri].push(Number(elapsedMs.toFixed(2)));
  if (telemetry.routes[uri].length > 500) {
    telemetry.routes[uri] = telemetry.routes[uri].slice(-500);
  }

  telemetry.last_path = uri;
  telemetry.last_status = status;
  telemetry.last_updated = new Date().toISOString();
  telemetry.status_bucket = bucketKeyForStatus(status);

  if (workflowContext) {
    const { mode, scenario } = workflowContext;
    if (workflowContext.outcome === 'success') {
      telemetry.successes[mode] = (telemetry.successes[mode] || 0) + 1;
    } else {
      telemetry.failures[mode].total = (telemetry.failures[mode].total || 0) + 1;
      const step = workflowContext.failing_step || 'unknown';
      telemetry.failures[mode].by_step[step] = (telemetry.failures[mode].by_step[step] || 0) + 1;
      telemetry.failures[mode].by_scenario[scenario] = (telemetry.failures[mode].by_scenario[scenario] || 0) + 1;
    }

    telemetry.traces.push({
      ...workflowContext,
      status_code: status,
      elapsed_ms: Number(elapsedMs.toFixed(2)),
      timestamp_utc: new Date().toISOString(),
    });

    if (telemetry.traces.length > 40) {
      telemetry.traces = telemetry.traces.slice(-40);
    }
  }

  writeTelemetry(telemetry);
};

const scenarioCatalog = () => ({
  ok: { step: null, dependency: null, http_status: 200, error_class: null, hint: null },
  inventory_conflict: {
    step: 'inventory.reserve',
    dependency: 'inventory-service',
    http_status: 503,
    error_class: 'inventory_conflict',
    hint: 'Revisar disponibilidad y bloqueos del stock.',
  },
  payment_timeout: {
    step: 'payment.authorize',
    dependency: 'payment-gateway',
    http_status: 504,
    error_class: 'payment_timeout',
    hint: 'Inspeccionar latencia del gateway y politicas de timeout.',
  },
  notification_down: {
    step: 'notification.dispatch',
    dependency: 'notification-provider',
    http_status: 502,
    error_class: 'notification_dependency_failure',
    hint: 'Validar el proveedor de notificaciones y su cola de salida.',
  },
});

const workflowDefinition = () => ([
  { name: 'cart.validate', dependency: 'internal', base_ms: 18 },
  { name: 'inventory.reserve', dependency: 'inventory-service', base_ms: 52 },
  { name: 'payment.authorize', dependency: 'payment-gateway', base_ms: 145 },
  { name: 'notification.dispatch', dependency: 'notification-provider', base_ms: 36 },
]);

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const runCheckout = async (mode, scenario, customerId, cartItems) => {
  const catalog = scenarioCatalog();
  const scenarioMeta = catalog[scenario] || catalog.ok;
  const traceId = requestId('trace');
  const reqId = requestId('req');
  const orderRef = `ORD-${crypto.randomBytes(3).toString('hex').toUpperCase()}`;
  const events = [];

  if (mode === 'legacy') {
    appendLegacyLog('checkout started');
    appendLegacyLog(`processing customer=${customerId}`);
  } else {
    appendStructuredLog({
      level: 'info',
      event: 'checkout_started',
      request_id: reqId,
      trace_id: traceId,
      customer_id: customerId,
      cart_items: cartItems,
      scenario,
      order_ref: orderRef,
    });
  }

  for (const step of workflowDefinition()) {
    const stepStarted = process.hrtime.bigint();
    await sleep(step.base_ms + Math.floor(Math.random() * 14) + 4);
    const elapsedMs = Number((Number(process.hrtime.bigint() - stepStarted) / 1_000_000).toFixed(2));

    if (scenarioMeta.step === step.name) {
      events.push({
        step: step.name,
        dependency: step.dependency,
        status: 'error',
        elapsed_ms: elapsedMs,
      });

      if (mode === 'legacy') {
        appendLegacyLog('checkout failed');
        appendLegacyLog('external dependency issue');
      } else {
        appendStructuredLog({
          level: 'error',
          event: 'dependency_failed',
          request_id: reqId,
          trace_id: traceId,
          customer_id: customerId,
          cart_items: cartItems,
          scenario,
          step: step.name,
          dependency: step.dependency,
          elapsed_ms: elapsedMs,
          error_class: scenarioMeta.error_class,
          hint: scenarioMeta.hint,
        });
      }

      throw new WorkflowFailure(
        'No se pudo completar el checkout.',
        step.name,
        step.dependency,
        scenarioMeta.http_status,
        reqId,
        traceId,
        events
      );
    }

    events.push({
      step: step.name,
      dependency: step.dependency,
      status: 'ok',
      elapsed_ms: elapsedMs,
    });

    if (mode === 'legacy') {
      if (step.name === 'payment.authorize') {
        appendLegacyLog('payment step completed');
      }
    } else {
      appendStructuredLog({
        level: 'info',
        event: 'step_completed',
        request_id: reqId,
        trace_id: traceId,
        customer_id: customerId,
        cart_items: cartItems,
        scenario,
        step: step.name,
        dependency: step.dependency,
        elapsed_ms: elapsedMs,
      });
    }
  }

  if (mode === 'legacy') {
    appendLegacyLog('checkout completed');
  } else {
    appendStructuredLog({
      level: 'info',
      event: 'checkout_completed',
      request_id: reqId,
      trace_id: traceId,
      customer_id: customerId,
      cart_items: cartItems,
      scenario,
      order_ref: orderRef,
      step_count: events.length,
    });
  }

  return {
    request_id: reqId,
    trace_id: traceId,
    order_ref: orderRef,
    events,
  };
};

const diagnosticsSummary = () => {
  const telemetry = telemetrySummary(readTelemetry());
  return {
    case: '03 - Observabilidad deficiente y logs inutiles',
    stack: APP_STACK,
    metrics: telemetry,
    answerability: {
      legacy: {
        request_correlation: false,
        failing_step_identified: false,
        dependency_identified: false,
        latency_breakdown_by_step: false,
      },
      observable: {
        request_correlation: true,
        failing_step_identified: true,
        dependency_identified: true,
        latency_breakdown_by_step: true,
      },
    },
    recent_legacy_logs: tailLines(LEGACY_LOG_PATH, 6),
    recent_observable_logs: tailLines(OBSERVABLE_LOG_PATH, 6),
  };
};

const prometheusLabel = (value) => value.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, ' ');

const renderPrometheusMetrics = () => {
  const summary = telemetrySummary(readTelemetry());
  const lines = [];
  lines.push('# HELP app_requests_total Total de requests observados por el laboratorio.');
  lines.push('# TYPE app_requests_total counter');
  lines.push(`app_requests_total ${summary.requests_tracked || 0}`);
  lines.push('# HELP app_request_latency_ms Latencia agregada de requests en milisegundos.');
  lines.push('# TYPE app_request_latency_ms gauge');
  lines.push(`app_request_latency_ms{stat="avg"} ${summary.avg_ms || 0}`);
  lines.push(`app_request_latency_ms{stat="p95"} ${summary.p95_ms || 0}`);
  lines.push(`app_request_latency_ms{stat="p99"} ${summary.p99_ms || 0}`);

  Object.entries(summary.successes || {}).forEach(([mode, count]) => {
    lines.push(`app_workflow_success_total{mode="${prometheusLabel(mode)}"} ${count}`);
  });

  Object.entries(summary.failures || {}).forEach(([mode, failureData]) => {
    lines.push(`app_workflow_failures_total{mode="${prometheusLabel(mode)}"} ${failureData.total || 0}`);
    Object.entries(failureData.by_step || {}).forEach(([step, count]) => {
      lines.push(`app_workflow_failures_by_step_total{mode="${prometheusLabel(mode)}",step="${prometheusLabel(step)}"} ${count}`);
    });
    Object.entries(failureData.by_scenario || {}).forEach(([scenario, count]) => {
      lines.push(`app_workflow_failures_by_scenario_total{mode="${prometheusLabel(mode)}",scenario="${prometheusLabel(scenario)}"} ${count}`);
    });
  });

  Object.entries(summary.routes || {}).forEach(([route, stats]) => {
    const label = prometheusLabel(route);
    lines.push(`app_route_latency_ms{route="${label}",stat="avg"} ${stats.avg_ms || 0}`);
    lines.push(`app_route_latency_ms{route="${label}",stat="p95"} ${stats.p95_ms || 0}`);
    lines.push(`app_route_requests_total{route="${label}"} ${stats.count || 0}`);
  });

  return `${lines.join('\n')}\n`;
};

const jsonResponse = (res, statusCode, body) => {
  res.writeHead(statusCode, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(body, null, 2));
};

const server = http.createServer(async (req, res) => {
  const started = process.hrtime.bigint();
  const url = new URL(req.url, 'http://127.0.0.1');
  const uri = url.pathname || '/';
  let statusCode = 200;
  let payload = {};
  let skipStoreMetrics = false;
  let workflowContext = null;

  try {
    if (uri === '/' || uri === '') {
      payload = {
        lab: 'Problem-Driven Systems Lab',
        case: '03 - Observabilidad deficiente y logs inutiles',
        stack: APP_STACK,
        goal: 'Comparar un flujo con logs pobres contra el mismo flujo con telemetria util, correlation IDs y trazas locales.',
        routes: {
          '/health': 'Estado basico del servicio.',
          '/checkout-legacy?scenario=payment_timeout&customer_id=42&cart_items=3': 'Ejecuta el flujo con evidencia pobre y poco accionable.',
          '/checkout-observable?scenario=payment_timeout&customer_id=42&cart_items=3': 'Ejecuta el flujo con logs estructurados, request_id y trazabilidad.',
          '/logs/legacy?tail=20': 'Ultimas lineas del log legacy.',
          '/logs/observable?tail=20': 'Ultimas lineas del log estructurado.',
          '/traces?limit=10': 'Ultimos rastros locales del laboratorio.',
          '/diagnostics/summary': 'Resumen de telemetria y capacidad de diagnostico.',
          '/metrics': 'Metricas JSON del proceso.',
          '/metrics-prometheus': 'Metricas en formato Prometheus.',
          '/reset-observability': 'Reinicia logs y telemetria local.',
        },
        allowed_scenarios: Object.keys(scenarioCatalog()),
      };
    } else if (uri === '/health') {
      payload = { status: 'ok', stack: APP_STACK };
    } else if (uri === '/checkout-legacy' || uri === '/checkout-observable') {
      const mode = uri === '/checkout-legacy' ? 'legacy' : 'observable';
      const catalog = scenarioCatalog();
      const scenarioParam = url.searchParams.get('scenario') || 'ok';
      const scenario = Object.prototype.hasOwnProperty.call(catalog, scenarioParam) ? scenarioParam : 'ok';
      const customerId = clampInt(Number(url.searchParams.get('customer_id') || 42), 1, 5000);
      const cartItems = clampInt(Number(url.searchParams.get('cart_items') || 3), 1, 25);

      try {
        const result = await runCheckout(mode, scenario, customerId, cartItems);
        workflowContext = {
          mode,
          scenario,
          outcome: 'success',
          failing_step: null,
          dependency: null,
          request_id: result.request_id,
          trace_id: result.trace_id,
          customer_id: customerId,
          cart_items: cartItems,
          events: result.events,
        };
        payload = {
          mode,
          scenario,
          status: 'completed',
          customer_id: customerId,
          cart_items: cartItems,
          order_ref: result.order_ref,
          events: result.events,
        };

        if (mode === 'observable') {
          payload.request_id = result.request_id;
          payload.trace_id = result.trace_id;
        }
      } catch (error) {
        if (!(error instanceof WorkflowFailure)) {
          throw error;
        }

        statusCode = error.httpStatus;
        workflowContext = {
          mode,
          scenario,
          outcome: 'failure',
          failing_step: error.step,
          dependency: error.dependency,
          request_id: mode === 'observable' ? error.requestId : null,
          trace_id: mode === 'observable' ? error.traceId : null,
          customer_id: customerId,
          cart_items: cartItems,
          events: error.events,
        };
        payload = {
          mode,
          scenario,
          error: 'Checkout fallido',
          message: mode === 'observable'
            ? 'Fallo el checkout. Usa request_id y trace_id para correlacionar el incidente.'
            : 'No se pudo completar la operacion.',
        };

        if (mode === 'observable') {
          payload.request_id = workflowContext.request_id;
          payload.trace_id = workflowContext.trace_id;
          payload.failed_step = error.step;
          payload.dependency = error.dependency;
        }
      }
    } else if (uri === '/logs/legacy') {
      const tail = clampInt(Number(url.searchParams.get('tail') || 20), 1, 200);
      payload = { mode: 'legacy', tail, lines: tailLines(LEGACY_LOG_PATH, tail) };
    } else if (uri === '/logs/observable') {
      const tail = clampInt(Number(url.searchParams.get('tail') || 20), 1, 200);
      payload = { mode: 'observable', tail, lines: tailLines(OBSERVABLE_LOG_PATH, tail) };
    } else if (uri === '/traces') {
      const limit = clampInt(Number(url.searchParams.get('limit') || 10), 1, 50);
      payload = { limit, traces: telemetrySummary(readTelemetry()).recent_traces.slice(0, limit) };
    } else if (uri === '/diagnostics/summary') {
      payload = diagnosticsSummary();
    } else if (uri === '/metrics') {
      payload = {
        case: '03 - Observabilidad deficiente y logs inutiles',
        stack: APP_STACK,
        ...telemetrySummary(readTelemetry()),
      };
    } else if (uri === '/metrics-prometheus') {
      skipStoreMetrics = true;
      res.writeHead(200, { 'Content-Type': 'text/plain; version=0.0.4; charset=utf-8' });
      res.end(renderPrometheusMetrics());
      return;
    } else if (uri === '/reset-observability') {
      resetTelemetryState();
      payload = { status: 'reset', message: 'Logs y telemetria reiniciados.' };
    } else {
      statusCode = 404;
      payload = { error: 'Ruta no encontrada', path: uri };
    }
  } catch (error) {
    statusCode = 500;
    payload = {
      error: 'Fallo al procesar la solicitud',
      message: error.message,
      path: uri,
    };
  }

  const elapsedMs = Number((Number(process.hrtime.bigint() - started) / 1_000_000).toFixed(2));
  if (!skipStoreMetrics && uri !== '/metrics' && uri !== '/reset-observability') {
    recordRequestTelemetry(uri, statusCode, elapsedMs, workflowContext);
  }

  payload.elapsed_ms = elapsedMs;
  payload.timestamp_utc = new Date().toISOString();
  payload.pid = process.pid;
  jsonResponse(res, statusCode, payload);
});

const PORT = Number.parseInt(process.env.PORT || '8080', 10);
server.listen(PORT, '0.0.0.0', () => {
  console.log(`Servidor Node escuchando en ${PORT}`);
});
