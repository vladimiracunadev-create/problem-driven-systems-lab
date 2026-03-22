const http = require('http');
const { URL } = require('url');

const state = {
  requests: 0,
  samplesMs: [],
  maxSamples: 3000,
  lastPath: null,
  lastStatus: 200,
  lastUpdated: null,
};

const percentile = (values, percent) => {
  if (!values.length) return 0;
  const sorted = [...values].sort((a, b) => a - b);
  const index = Math.max(0, Math.min(sorted.length - 1, Math.ceil((percent / 100) * sorted.length) - 1));
  return Number(sorted[index].toFixed(2));
};

const payloadOfKb = (kb) => 'x'.repeat(Math.max(0, kb) * 1024);
const cpuWork = (iterations) => {
  let value = 0;
  for (let i = 0; i < iterations; i += 1) {
    value += i % 13;
  }
  return value;
};
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const record = (path, status, elapsedMs) => {
  state.requests += 1;
  state.samplesMs.push(elapsedMs);
  if (state.samplesMs.length > state.maxSamples) {
    state.samplesMs = state.samplesMs.slice(-state.maxSamples);
  }
  state.lastPath = path;
  state.lastStatus = status;
  state.lastUpdated = new Date().toISOString();
};

const json = (res, status, body) => {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(body, null, 2));
};

http.createServer(async (req, res) => {
  const start = process.hrtime.bigint();
  const url = new URL(req.url, 'http://localhost');
  const path = url.pathname;
  let status = 200;
  let body;

  try {
    if (path === '/') {
      body = {
        lab: 'Problem-Driven Systems Lab',
        case: '01 - API lenta bajo carga',
        stack: 'Node.js',
        goal: 'Simular endpoints rápidos y lentos para estudiar latencia, percentiles y comportamiento bajo carga.',
        recommended_flow: [
          'Levantar un solo stack primero para entender el caso.',
          'Usar compose.compare.yml solo cuando quieras comparar comportamientos.',
          'Medir con /metrics antes y después de generar carga.',
        ],
        routes: {
          '/': 'Resumen del caso y rutas disponibles.',
          '/health': 'Chequeo simple.',
          '/fast': 'Respuesta rápida y liviana.',
          '/slow?delay_ms=200&payload_kb=4': 'Simula latencia I/O y payload mayor.',
          '/cpu?iterations=3500000': 'Simula trabajo CPU-bound.',
          '/mixed?delay_ms=120&iterations=1500000&payload_kb=8': 'Combina espera, CPU y payload.',
          '/metrics': 'Métricas acumuladas en memoria.',
          '/reset-metrics': 'Reinicia contadores del caso.',
        },
      };
    } else if (path === '/health') {
      body = { status: 'ok', stack: 'Node.js', case: '01 - API lenta bajo carga' };
    } else if (path === '/fast') {
      body = { endpoint: 'fast', message: 'Respuesta ligera diseñada para contrastar con rutas lentas.' };
    } else if (path === '/slow') {
      const delayMs = Math.max(0, Number(url.searchParams.get('delay_ms') || 250));
      const payloadKb = Math.min(256, Math.max(0, Number(url.searchParams.get('payload_kb') || 8)));
      await sleep(delayMs);
      body = {
        endpoint: 'slow',
        delay_ms: delayMs,
        payload_kb: payloadKb,
        message: 'Esta ruta simula espera de red, I/O o dependencia externa.',
        payload: payloadOfKb(payloadKb),
      };
    } else if (path === '/cpu') {
      const iterations = Math.min(20000000, Math.max(1, Number(url.searchParams.get('iterations') || 3500000)));
      body = {
        endpoint: 'cpu',
        iterations,
        checksum: cpuWork(iterations),
        message: 'Esta ruta simula presión de CPU en una ruta crítica.',
      };
    } else if (path === '/mixed') {
      const delayMs = Math.max(0, Number(url.searchParams.get('delay_ms') || 120));
      const iterations = Math.min(20000000, Math.max(1, Number(url.searchParams.get('iterations') || 1500000)));
      const payloadKb = Math.min(256, Math.max(0, Number(url.searchParams.get('payload_kb') || 12)));
      await sleep(delayMs);
      body = {
        endpoint: 'mixed',
        delay_ms: delayMs,
        iterations,
        checksum: cpuWork(iterations),
        payload_kb: payloadKb,
        message: 'Mezcla espera, trabajo CPU y payload para emular una ruta más realista.',
        payload: payloadOfKb(payloadKb),
      };
    } else if (path === '/metrics') {
      const avg = state.samplesMs.length ? state.samplesMs.reduce((a, b) => a + b, 0) / state.samplesMs.length : 0;
      body = {
        stack: 'Node.js',
        case: '01 - API lenta bajo carga',
        requests_tracked: state.requests,
        sample_count: state.samplesMs.length,
        avg_ms: Number(avg.toFixed(2)),
        p95_ms: percentile(state.samplesMs, 95),
        p99_ms: percentile(state.samplesMs, 99),
        last_path: state.lastPath,
        last_status: state.lastStatus,
        last_updated: state.lastUpdated,
        note: 'Métrica simple, en proceso único, pensada para laboratorio. No reemplaza observabilidad real.',
      };
    } else if (path === '/reset-metrics') {
      state.requests = 0;
      state.samplesMs = [];
      state.lastPath = null;
      state.lastStatus = 200;
      state.lastUpdated = new Date().toISOString();
      body = { status: 'reset', message: 'Métricas reiniciadas para el stack Node.js.' };
    } else {
      status = 404;
      body = { error: 'Ruta no encontrada', path };
    }
  } catch (error) {
    status = 500;
    body = { error: 'Error interno', detail: error.message };
  }

  const elapsedMs = Number((Number(process.hrtime.bigint() - start) / 1_000_000).toFixed(2));
  if (path !== '/metrics' && path !== '/reset-metrics') {
    record(path, status, elapsedMs);
  }

  body.elapsed_ms = elapsedMs;
  body.pid = process.pid;
  body.timestamp_utc = new Date().toISOString();
  json(res, status, body);
}).listen(8080, '0.0.0.0', () => {
  console.log('Servidor Node escuchando en 8080');
});
