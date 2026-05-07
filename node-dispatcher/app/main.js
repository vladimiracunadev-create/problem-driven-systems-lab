'use strict';

/**
 * Node Lab Dispatcher — un solo contenedor, un solo puerto para los 12 casos.
 *
 * Cada caso corre como subproceso interno en un puerto local.
 * El dispatcher escucha en :8300 y enruta por prefijo de path:
 *
 *     GET /01/health          → case 01 server (interno :9101)
 *     GET /02/query?...       → case 02 server (interno :9002)
 *     ...
 *     GET /12/share-knowledge → case 12 server (interno :9012)
 *     GET /                   → indice de todos los casos
 *
 * Los puertos internos nunca se exponen al host — solo :8300 es visible.
 *
 * Nota: el caso 01 usa :9101 en lugar de :9001 porque algunos hosts
 * Windows reservan 9001 (rango excluido del kernel). En Linux Docker
 * cualquier puerto libre funciona; mantener 9101 no rompe nada.
 */

const http = require('http');
const { spawn } = require('child_process');
const { URL } = require('url');

const CASES = {
  '01': { port: 9101, name: 'API lenta bajo carga',               server: '/cases/01/server.js' },
  '02': { port: 9002, name: 'N+1 y cuellos de botella DB',        server: '/cases/02/server.js' },
  '03': { port: 9003, name: 'Observabilidad deficiente',          server: '/cases/03/server.js' },
  '04': { port: 9004, name: 'Timeout chain y retry storms',       server: '/cases/04/server.js' },
  '05': { port: 9005, name: 'Presion de memoria y fugas',         server: '/cases/05/server.js' },
  '06': { port: 9006, name: 'Pipeline roto y delivery fragil',    server: '/cases/06/server.js' },
  '07': { port: 9007, name: 'Modernizacion incremental monolito', server: '/cases/07/server.js' },
  '08': { port: 9008, name: 'Extraccion critica de modulo',       server: '/cases/08/server.js' },
  '09': { port: 9009, name: 'Integracion externa inestable',      server: '/cases/09/server.js' },
  '10': { port: 9010, name: 'Arquitectura cara para algo simple', server: '/cases/10/server.js' },
  '11': { port: 9011, name: 'Reportes que bloquean la operacion', server: '/cases/11/server.js' },
  '12': { port: 9012, name: 'Punto unico de conocimiento',        server: '/cases/12/server.js' },
};

const DISPATCH_PORT = Number.parseInt(process.env.PORT || '8300', 10);
const APP_STACK = process.env.APP_STACK || 'Node.js 20';

// ---------------------------------------------------------------------------
// Startup: spawn each case as an internal subprocess
// ---------------------------------------------------------------------------

const spawnedProcs = [];

const startCaseServers = () => {
  for (const [caseId, info] of Object.entries(CASES)) {
    const env = { ...process.env, PORT: String(info.port), APP_STACK };
    const proc = spawn(process.execPath, [info.server], {
      env,
      stdio: ['ignore', 'ignore', 'ignore'],
    });
    proc.on('exit', (code, signal) => {
      console.error(`[dispatcher] case ${caseId} exited (code=${code} signal=${signal})`);
    });
    spawnedProcs.push(proc);
    console.log(`  case ${caseId} → interno :${info.port} (pid ${proc.pid})`);
  }
};

const probeHealth = (port) =>
  new Promise((resolve) => {
    const req = http.get(`http://127.0.0.1:${port}/health`, { timeout: 1000 }, (res) => {
      res.resume();
      resolve(res.statusCode === 200);
    });
    req.on('error', () => resolve(false));
    req.on('timeout', () => {
      req.destroy();
      resolve(false);
    });
  });

const waitForCases = async (timeoutMs = 20000) => {
  const deadline = Date.now() + timeoutMs;
  const remaining = new Set(Object.keys(CASES));
  while (remaining.size && Date.now() < deadline) {
    for (const caseId of [...remaining]) {
      const ok = await probeHealth(CASES[caseId].port);
      if (ok) remaining.delete(caseId);
    }
    if (remaining.size) await new Promise((r) => setTimeout(r, 300));
  }
  if (remaining.size) {
    console.warn(`  WARNING: cases not ready yet: ${[...remaining].sort().join(', ')}`);
  }
};

// ---------------------------------------------------------------------------
// Proxy helper
// ---------------------------------------------------------------------------

const proxyToCase = (caseId, subPath, rawQuery) =>
  new Promise((resolve) => {
    const port = CASES[caseId].port;
    const path = rawQuery ? `${subPath}?${rawQuery}` : subPath;
    const req = http.get(
      { host: '127.0.0.1', port, path, timeout: 30000 },
      (res) => {
        const chunks = [];
        res.on('data', (c) => chunks.push(c));
        res.on('end', () => {
          resolve({ status: res.statusCode, headers: res.headers, body: Buffer.concat(chunks) });
        });
      }
    );
    req.on('error', (err) => {
      const body = Buffer.from(
        JSON.stringify({ error: 'dispatcher_proxy_error', case: caseId, message: err.message })
      );
      resolve({ status: 502, headers: { 'content-type': 'application/json; charset=utf-8' }, body });
    });
    req.on('timeout', () => {
      req.destroy();
      const body = Buffer.from(
        JSON.stringify({ error: 'dispatcher_proxy_timeout', case: caseId })
      );
      resolve({ status: 504, headers: { 'content-type': 'application/json; charset=utf-8' }, body });
    });
  });

// ---------------------------------------------------------------------------
// Request handler
// ---------------------------------------------------------------------------

const sendJson = (res, status, payload) => {
  const body = JSON.stringify(payload, null, 2);
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(body),
  });
  res.end(body);
};

const handler = async (req, res) => {
  const parsed = new URL(req.url || '/', 'http://127.0.0.1');
  const parts = parsed.pathname.replace(/^\/+/, '').split('/');
  const head = parts[0] || '';
  const caseId = head ? head.padStart(2, '0') : '';
  const subPath = parts.length > 1 ? '/' + parts.slice(1).join('/') : '/';
  const rawQuery = parsed.search.startsWith('?') ? parsed.search.slice(1) : parsed.search;

  if (!caseId || caseId === '00') {
    const payload = {
      lab: 'Problem-Driven Systems Lab',
      stack: APP_STACK,
      info: 'Dispatcher Node.js — un contenedor, un puerto, 12 casos.',
      usage: 'GET /{caso}/{ruta}  →  e.g. /01/health, /05/batch-legacy',
      cases: Object.fromEntries(
        Object.entries(CASES).map(([cid, info]) => [
          cid,
          { name: info.name, health: `/${cid}/health`, internal_port: info.port },
        ])
      ),
    };
    sendJson(res, 200, payload);
    return;
  }

  if (!Object.prototype.hasOwnProperty.call(CASES, caseId)) {
    sendJson(res, 404, {
      error: 'case_not_found',
      requested: caseId,
      valid_cases: Object.keys(CASES).sort(),
    });
    return;
  }

  const result = await proxyToCase(caseId, subPath, rawQuery);
  const ct = result.headers['content-type'] || 'application/json; charset=utf-8';
  res.writeHead(result.status, {
    'Content-Type': ct,
    'Content-Length': result.body.length,
  });
  res.end(result.body);
};

// ---------------------------------------------------------------------------
// Entry point + graceful shutdown
// ---------------------------------------------------------------------------

const shutdown = (signal) => {
  console.log(`[dispatcher] ${signal} recibido, cerrando subprocesos...`);
  for (const proc of spawnedProcs) {
    try { proc.kill('SIGTERM'); } catch (_e) { /* ignore */ }
  }
  setTimeout(() => process.exit(0), 1500);
};
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT',  () => shutdown('SIGINT'));

(async () => {
  console.log('[dispatcher] Iniciando casos internos...');
  startCaseServers();

  console.log('[dispatcher] Esperando que los casos levanten...');
  await waitForCases(20000);

  console.log(`[dispatcher] Listo. Escuchando en :${DISPATCH_PORT}`);
  console.log('[dispatcher] Rutas: /01/ → :9101, /02/.../12/ → :9002...:9012');

  http.createServer(handler).listen(DISPATCH_PORT, '0.0.0.0');
})();
