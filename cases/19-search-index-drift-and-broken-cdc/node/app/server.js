/**
 * Caso 19 — Deriva del indice de busqueda y CDC roto — stack Node.js 22.
 *
 * Dual-write: la aplicacion escribe en la base y despues en el indice. Cuando la
 * segunda escritura falla —y falla, porque son dos sistemas sin transaccion
 * comun— nadie se entera. La busqueda sigue respondiendo 200; lo que devuelve
 * esta mal.
 *
 * Outbox + checkpoint + reconciliacion: el cambio se anota junto con la escritura
 * a la base, el consumidor aplica en orden y solo avanza el checkpoint cuando la
 * aplicacion se confirma, y un barrido repara lo que los dos primeros no cubren.
 *
 * Las tres formas de deriva, que no son la misma cosa:
 *
 *   missing  — esta en la base, no en el indice      → la busqueda no lo encuentra
 *   stale    — esta en los dos, con version vieja    → la busqueda lo encuentra mal
 *   orphan   — esta en el indice, borrado en la base → la busqueda devuelve fantasmas
 *
 * Primitiva Node distintiva — y aqui el stack es la causa, no la cura:
 *
 *   **El `await` que falta.** En los otros seis stacks, olvidarse de mirar el
 *   resultado de la escritura al indice requiere escribir algo: `_ =` en Go,
 *   `let _ =` en Rust, un `except:` en Python, un `catch {}` vacio en Java o
 *   .NET. En Node basta con NO escribir cuatro letras:
 *
 *       await indice.escribir(doc);   // el error sube y se maneja
 *       indice.escribir(doc);         // el error se va a un rechazo sin dueño
 *
 *   Las dos lineas compilan, las dos parecen correctas en una revision rapida, y
 *   la segunda produce exactamente este caso. Hasta Node 15 el proceso ni
 *   siquiera se enteraba: la promesa rechazada emitia un warning y seguia.
 *
 *   Desde Node 15 el comportamiento por defecto de `unhandledRejection` es
 *   `throw`, lo que mata el proceso — mejor que el silencio, y todavia peor que
 *   un error manejado. La regla `no-floating-promises` de typescript-eslint es
 *   la unica herramienta que lo atrapa antes de produccion, y no viene puesta.
 */

const http = require('http');

const APP_STACK = process.env.APP_STACK || 'Node.js 22';
const CASE_NAME = '19 - Deriva del indice de busqueda y CDC roto';
const TERMS = ['alfa', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta'];

const nowMs = () => Number(process.hrtime.bigint() / 1000n) / 1000;

let db = new Map();       // id -> {version, term, deleted, updatedMs}
let index = new Map();    // id -> {version, term}
let outbox = [];          // [{seq, id, version, term, deleted, atMs}]
let checkpoint = 0;
let seq = 0;
let metrics = initialMetrics();

function initialMetrics() {
  const slot = () => ({ runs: 0, writes: 0, silent_failures: 0, drift_count: 0, outbox_retried: 0 });
  return { drifted: slot(), reconciled: slot() };
}

function resetAll() {
  db = new Map();
  index = new Map();
  outbox = [];
  checkpoint = 0;
  seq = 0;
}

/**
 * El indice rechaza una fraccion de las escrituras.
 * El modulo 101 —primo— importa: con 100, las dos escrituras del mismo documento
 * (i e i+keyspace) caen en el mismo residuo y corren siempre la misma suerte, asi
 * que nunca se produce deriva `stale`. Con 101 se separan.
 */
const indexWriteFails = (idx, failRate) => ((idx * 37) % 101) < failRate;

// ---------------------------------------------------------------------------
// Variante dual-write: escribir en la base, escribir en el indice, y rezar
// ---------------------------------------------------------------------------

function runDrifted(writes, failRate, deletePct) {
  resetAll();
  const keyspace = Math.max(1, Math.floor(writes / 2));
  let silent = 0;

  for (let i = 0; i < writes; i++) {
    const id = `doc-${i % keyspace}`;
    const term = TERMS[i % TERMS.length];
    const deleting = ((i * 53) % 101) < deletePct;

    const prev = db.get(id);
    const version = prev ? prev.version + 1 : 1;
    db.set(id, { version, term, deleted: deleting, updatedMs: nowMs() });

    // La segunda escritura. En Node el modo de falla mas comun no es que el
    // catch se trague el error: es que nunca hubo await, asi que no hay nada
    // que atrape nada. El codigo sigue como si hubiera escrito.
    if (indexWriteFails(i, failRate)) { silent++; continue; }
    if (deleting) index.delete(id);
    else index.set(id, { version, term });
  }
  return silent;
}

// ---------------------------------------------------------------------------
// Variante outbox + checkpoint + reconciliacion
// ---------------------------------------------------------------------------

function runReconciled(writes, failRate, deletePct) {
  resetAll();
  const keyspace = Math.max(1, Math.floor(writes / 2));

  for (let i = 0; i < writes; i++) {
    const id = `doc-${i % keyspace}`;
    const term = TERMS[i % TERMS.length];
    const deleting = ((i * 53) % 101) < deletePct;

    const prev = db.get(id);
    const version = prev ? prev.version + 1 : 1;
    db.set(id, { version, term, deleted: deleting, updatedMs: nowMs() });
    // El cambio se anota JUNTO con la escritura. Si el indice esta caido, el
    // cambio no se pierde: queda escrito.
    seq += 1;
    outbox.push({ seq, id, version, term, deleted: deleting, atMs: nowMs() });
  }
  return drainOutbox(failRate);
}

/**
 * Aplica los cambios pendientes al indice, en orden, reintentando.
 *
 * - **En orden**: saltear un cambio dejaria una version vieja pisando a una nueva.
 * - **El checkpoint avanza solo con la confirmacion**: si un cambio no entra
 *   despues de `maxRetries`, el consumidor se frena. El cambio queda pendiente,
 *   no perdido — que es exactamente lo que el dual-write no puede hacer.
 */
function drainOutbox(failRate, maxRetries = 5) {
  let retried = 0;
  const pending = outbox.filter((e) => e.seq > checkpoint);
  for (const entry of pending) {
    let applied = false;
    for (let attempt = 0; attempt < maxRetries; attempt++) {
      if (indexWriteFails(entry.seq * (attempt + 1) + attempt, failRate)) { retried++; continue; }
      if (entry.deleted) index.delete(entry.id);
      else index.set(entry.id, { version: entry.version, term: entry.term });
      applied = true;
      break;
    }
    if (!applied) break;   // el checkpoint se frena: el cambio queda pendiente
    checkpoint = entry.seq;
  }
  return retried;
}

// ---------------------------------------------------------------------------
// La deriva de tres caras
// ---------------------------------------------------------------------------

function computeDrift() {
  const dbLive = new Map([...db].filter(([, v]) => !v.deleted));
  const missing = [];
  const stale = [];
  const orphan = [];

  for (const [id, doc] of dbLive) {
    const cur = index.get(id);
    if (!cur) missing.push(id);
    else if (cur.version !== doc.version) stale.push(id);
  }
  for (const id of index.keys()) if (!dbLive.has(id)) orphan.push(id);

  const now = nowMs();
  let oldest = 0;
  for (const id of [...missing, ...stale]) {
    oldest = Math.max(oldest, now - dbLive.get(id).updatedMs);
  }

  return {
    db_count: dbLive.size,
    index_count: index.size,
    missing: missing.length,
    stale: stale.length,
    orphan: orphan.length,
    drift_count: missing.length + stale.length + orphan.length,
    drift_age_ms: Number(oldest.toFixed(2)),
    missing_ids: missing.sort().slice(0, 8),
    orphan_ids: orphan.sort().slice(0, 8),
    last_checkpoint: checkpoint,
    outbox_pending: outbox.filter((e) => e.seq > checkpoint).length,
  };
}

function reconcile() {
  const t0 = nowMs();
  const before = computeDrift();
  const dbLive = new Map([...db].filter(([, v]) => !v.deleted));
  for (const [id, doc] of dbLive) {
    const cur = index.get(id);
    if (!cur || cur.version !== doc.version) index.set(id, { version: doc.version, term: doc.term });
  }
  for (const id of [...index.keys()]) if (!dbLive.has(id)) index.delete(id);
  const after = computeDrift();
  return {
    reconcile_duration_ms: Number((nowMs() - t0).toFixed(2)),
    drift_before: before.drift_count,
    drift_after: after.drift_count,
    repaired: before.drift_count - after.drift_count,
    detail_before: { missing: before.missing, stale: before.stale, orphan: before.orphan },
    state: after,
    note: 'El barrido es la red de seguridad de lo que el outbox no cubre: un indice restaurado de un backup '
      + 'viejo, una reindexacion parcial, un borrado manual. Sin el, el outbox garantiza que ningun cambio NUEVO '
      + 'se pierda — pero no arregla los que ya se perdieron.',
  };
}

// ---------------------------------------------------------------------------
// Las consultas: medir la deriva desde donde la ve el usuario
// ---------------------------------------------------------------------------

function runQueries(queries) {
  const dbLive = new Map([...db].filter(([, v]) => !v.deleted));
  let hits = 0; let expected = 0; let returned = 0;
  for (let q = 0; q < queries; q++) {
    const term = TERMS[q % TERMS.length];
    const esperados = new Set([...dbLive].filter(([, d]) => d.term === term).map(([i]) => i));
    const devueltos = [...index].filter(([, d]) => d.term === term).map(([i]) => i);
    expected += esperados.size;
    returned += devueltos.length;
    hits += devueltos.filter((i) => esperados.has(i)).length;
  }
  return {
    queries,
    search_recall_pct: Number(((hits / Math.max(1, expected)) * 100).toFixed(2)),
    search_precision_pct: Number(((hits / Math.max(1, returned)) * 100).toFixed(2)),
    note: 'Recall bajo = la busqueda no encuentra lo que existe. Precision baja = devuelve lo que ya no existe. '
      + 'Las dos se ven como "la busqueda anda rara", no como un error.',
  };
}

function runScenario(variant, writes, failRate, deletePct, queries) {
  const t0 = nowMs();
  let silent = 0; let retried = 0;
  if (variant === 'drifted') silent = runDrifted(writes, failRate, deletePct);
  else { retried = runReconciled(writes, failRate, deletePct); reconcile(); }

  const drift = computeDrift();
  const q = runQueries(queries);

  const slot = metrics[variant];
  slot.runs++; slot.writes += writes; slot.silent_failures += silent;
  slot.drift_count += drift.drift_count; slot.outbox_retried += retried;

  const payload = {
    variant, writes, fail_rate_pct: failRate, delete_pct: deletePct,
    silent_failures: silent, outbox_retried: retried, ...drift, ...q,
  };
  payload.wall_ms = Number((nowMs() - t0).toFixed(2));
  payload.note = variant === 'drifted'
    ? 'La escritura al indice fallo y el codigo siguio como si nada. La base y el indice no comparten transaccion, '
      + 'asi que la unica forma de enterarse es mirando — y nadie mira, porque la busqueda sigue respondiendo 200.'
    : 'El outbox garantiza que ningun cambio nuevo se pierda, el checkpoint impide saltear uno, y el barrido repara '
      + 'lo que los dos primeros no cubren. Deriva final: cero.';
  payload.node_note = 'El modo de falla de Node no es un catch que se traga el error: es el await que falta. '
    + 'indice.escribir(doc) sin await compila, parece correcto y manda el error a un rechazo sin dueño. '
    + 'no-floating-promises de typescript-eslint es lo unico que lo atrapa, y no viene puesto.';
  return payload;
}

function indexState() {
  return {
    stack: APP_STACK,
    ...computeDrift(),
    note: '`missing` no se encuentra, `stale` se encuentra mal y `orphan` es un fantasma. Las tres se ven igual '
      + 'desde afuera —"la busqueda anda rara"— y se arreglan distinto.',
  };
}

function diagnostics() {
  return {
    stack: APP_STACK,
    case: CASE_NAME,
    variants: metrics,
    index: indexState(),
    fidelity: {
      real: 'El diff de tres caras, el outbox con orden y checkpoint, y el barrido de reconciliacion son codigo '
        + 'de verdad, con la primitiva idiomatica de cada runtime.',
      modelado: 'El indice de busqueda es un Map en memoria, no Elasticsearch. La falla de escritura es '
        + 'deterministica (multiplicador primo sobre el indice) para que el escenario sea reproducible.',
      honesto: 'Lo que importa del caso no es el motor de busqueda: es que la base y el indice son dos sistemas '
        + 'sin transaccion comun. Eso es igual de cierto con un Map que con Elasticsearch.',
    },
    interpretation: {
      drifted: 'drift_count > 0 y recall por debajo de 100 con el servicio respondiendo 200 a todo. '
        + 'silent_failures cuenta las escrituras que nadie miro.',
      reconciled: 'drift_count = 0, recall y precision en 100. El outbox no dejo perder ningun cambio y el barrido '
        + 'reparo lo que quedaba.',
      node_note: 'Es el unico stack donde el bug se produce por NO escribir algo. En los otros seis hay que '
        + 'escribir el silencio a proposito.',
    },
  };
}

const clampInt = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
function queryInt(params, key, def) {
  const raw = params.get(key);
  if (raw === null) return def;
  const n = Number.parseInt(raw, 10);
  return Number.isNaN(n) ? def : n;
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const uri = url.pathname;
  const q = url.searchParams;
  let status = 200;
  let payload;

  const writes = clampInt(queryInt(q, 'writes', 2000), 10, 200000);
  const failRate = clampInt(queryInt(q, 'fail_rate', 8), 0, 100);
  const deletePct = clampInt(queryInt(q, 'delete_pct', 5), 0, 50);
  const queries = clampInt(queryInt(q, 'queries', 200), 1, 5000);

  if (uri === '/' || uri === '/index') {
    payload = {
      lab: 'Problem-Driven Systems Lab',
      case: CASE_NAME,
      stack: APP_STACK,
      goal: 'Mostrar que una busqueda que responde 200 puede estar respondiendo mal, y que la unica forma de '
        + 'saberlo es comparar los dos lados a proposito.',
      node_specific: 'El modo de falla es el await que falta: la promesa sin dueño no rompe nada visible y '
        + 'produce el caso entero.',
      routes: {
        '/health': 'Estado basico del servicio.',
        '/search-drifted?writes=2000&fail_rate=8': 'Dual-write: el indice se desincroniza en silencio.',
        '/search-reconciled?writes=2000&fail_rate=8': 'Outbox + checkpoint + barrido: deriva cero.',
        '/reconcile': 'Un barrido suelto, para ver que encuentra y que repara.',
        '/index/state': 'Las tres caras de la deriva y la antiguedad del cambio mas viejo sin aplicar.',
        '/diagnostics/summary': 'Comparativa entre variantes.',
        '/reset-lab': 'Vacia la base, el indice, el outbox y las metricas.',
      },
    };
  } else if (uri === '/health') {
    payload = { status: 'ok', stack: APP_STACK, case: CASE_NAME };
  } else if (uri === '/search-drifted') {
    payload = runScenario('drifted', writes, failRate, deletePct, queries);
  } else if (uri === '/search-reconciled') {
    payload = runScenario('reconciled', writes, failRate, deletePct, queries);
  } else if (uri === '/reconcile') {
    payload = reconcile();
  } else if (uri === '/index/state') {
    payload = indexState();
  } else if (uri === '/diagnostics/summary') {
    payload = diagnostics();
  } else if (uri === '/reset-lab') {
    resetAll();
    metrics = initialMetrics();
    payload = { status: 'reset', message: 'Base, indice, outbox y metricas reiniciados.' };
  } else {
    status = 404;
    payload = { error: 'Ruta no encontrada', path: uri };
  }

  payload.timestamp_utc = new Date().toISOString().replace(/\.\d+Z$/, 'Z');
  payload.pid = process.pid;
  const body = JSON.stringify(payload, null, 2);
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(body);
});

const PORT = Number(process.env.PORT || 8080);
server.listen(PORT, '0.0.0.0', () => console.log(`Servidor Node escuchando en ${PORT}`));
