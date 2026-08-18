<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caso 19 — Deriva del índice de búsqueda | Problem-Driven Systems Lab</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0e1a;--surface:#111827;--surface2:#1a2235;--border:#1e2d45;--accent:#38bdf8;--red:#ef4444;--green:#22c55e;--text:#e2e8f0;--muted:#64748b}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.header{background:linear-gradient(135deg,#05141a,#0a2230,#0a0e1a);border-bottom:1px solid #073246;padding:28px 40px;display:flex;align-items:center;gap:20px}
.case-badge{background:var(--accent);color:#04222e;font-weight:800;font-size:11px;padding:4px 10px;border-radius:6px}
.header-title{flex:1}.header-title h1{font-size:22px;font-weight:700;color:#fff}.header-title p{font-size:13px;color:#6ea5bd;margin-top:4px}
.stack-badge{background:rgba(56,189,248,.15);border:1px solid rgba(56,189,248,.3);color:var(--accent);font-size:11px;padding:4px 12px;border-radius:20px;font-weight:600}
.container{max-width:1300px;margin:0 auto;padding:32px 40px}
.cards-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px}
.card{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:16px;padding:24px}
.card.problem{border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.04)}.card.solution{border-color:rgba(34,197,94,.3);background:rgba(34,197,94,.04)}
.card-label{font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:10px}
.card.problem .card-label{color:var(--red)}.card.solution .card-label{color:var(--green)}
.card h3{font-size:15px;font-weight:600;margin-bottom:10px;color:#fff}.card p{font-size:13px;color:#94a3b8;line-height:1.7}
.card code{font-family:'JetBrains Mono',monospace;font-size:12px;background:rgba(255,255,255,.06);padding:1px 5px;border-radius:4px}
.controls{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:24px;margin-bottom:28px}
.controls h4{font-size:13px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px}
.params{display:flex;gap:14px;margin-bottom:18px;flex-wrap:wrap}.param-group{display:flex;flex-direction:column;gap:4px}
.param-group label{font-size:11px;color:var(--muted);font-weight:500}
.param-group input{background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:7px 12px;border-radius:8px;font-size:13px;width:150px;font-family:'JetBrains Mono',monospace}
.btns{display:flex;gap:12px;flex-wrap:wrap}
.btn{padding:11px 22px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;border:none;transition:all .2s}
.btn-legacy{background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3)}.btn-legacy:hover{background:rgba(239,68,68,.25)}
.btn-solution{background:var(--green);color:#04220f}.btn-solution:hover{filter:brightness(1.1)}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}.btn-ghost:hover{color:var(--text)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.compare-panel{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px}
.compare-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:22px}
.compare-card.naive{border-color:rgba(239,68,68,.25)}.compare-card.sf{border-color:rgba(34,197,94,.25)}
.compare-card h5{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px}
.compare-card.naive h5{color:#f87171}.compare-card.sf h5{color:#4ade80}
.metric-row{display:flex;justify-content:space-between;font-size:13px;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.05)}
.metric-row:last-child{border:none}.m-k{color:var(--muted)}
.m-v{font-family:'JetBrains Mono',monospace;font-weight:600;padding:2px 8px;border-radius:5px}
.val-bad{background:rgba(239,68,68,.15);color:#f87171}.val-good{background:rgba(34,197,94,.15);color:#4ade80}.val-neutral{background:rgba(148,163,184,.1);color:#94a3b8}
.empty{color:var(--muted);font-size:13px;text-align:center;padding:28px 0}
.hero{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:16px;text-align:center}
.hero .big{font-family:'JetBrains Mono',monospace;font-size:34px;font-weight:700;line-height:1}
.hero .lbl{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:6px}
.note{background:rgba(56,189,248,.06);border:1px solid rgba(56,189,248,.2);border-radius:12px;padding:16px 18px;font-size:13px;color:#9fd4ea;line-height:1.7}
.note strong{color:#cdeaf7}
footer{text-align:center;padding:32px;color:var(--muted);font-size:12px;border-top:1px solid var(--border);margin-top:40px}
footer a{color:var(--accent);text-decoration:none}
</style>
</head>
<body>
<div class="header">
  <div><div class="case-badge">CASO 19</div></div>
  <div class="header-title">
    <h1>🔎 Deriva del índice de búsqueda y CDC roto</h1>
    <p>Observabilidad · La búsqueda responde 200 y lo que devuelve está mal</p>
  </div>
  <span class="stack-badge">PHP 8.3 · outbox + checkpoint durable</span>
</div>

<div class="container">
  <div class="cards-row">
    <div class="card problem">
      <div class="card-label">🔴 El problema</div>
      <h3>Dual-write: la segunda escritura falla y nadie mira</h3>
      <p>La aplicación escribe en la base y después en el índice. Son <strong>dos sistemas sin transacción común</strong>: cuando la segunda falla, el código sigue. La búsqueda no rompe — le faltan documentos, le sobran borrados, y los que tiene están viejos.</p>
    </div>
    <div class="card solution">
      <div class="card-label">🟢 La corrección</div>
      <h3>Outbox · checkpoint · reconciliación</h3>
      <p>El cambio se anota <em>junto</em> con la escritura a la base. El consumidor aplica en orden y <strong>solo avanza el checkpoint cuando confirma</strong>. Y un barrido compara los dos lados y repara lo que los dos primeros no cubren.</p>
    </div>
  </div>

  <div class="controls">
    <h4>Ejecutar el escenario</h4>
    <div class="params">
      <div class="param-group"><label>escrituras</label><input id="writes" type="number" value="2000" min="10" max="200000" step="100"></div>
      <div class="param-group"><label>fallo del índice (%)</label><input id="fail_rate" type="number" value="8" min="0" max="100"></div>
      <div class="param-group"><label>borrados (%)</label><input id="delete_pct" type="number" value="5" min="0" max="50"></div>
      <div class="param-group"><label>consultas</label><input id="queries" type="number" value="200" min="1" max="5000"></div>
    </div>
    <div class="btns">
      <button class="btn btn-legacy" id="btn-naive">Dual-write</button>
      <button class="btn btn-solution" id="btn-sf">Outbox + reconciliación</button>
      <button class="btn btn-ghost" id="btn-reset">Reiniciar lab</button>
    </div>
  </div>

  <div class="compare-panel">
    <div class="compare-card naive">
      <h5>❌ Dual-write</h5>
      <div id="out-naive"><div class="empty">Sin ejecuciones todavía.</div></div>
    </div>
    <div class="compare-card sf">
      <h5>✅ Outbox + reconciliación</h5>
      <div id="out-sf"><div class="empty">Sin ejecuciones todavía.</div></div>
    </div>
  </div>

  <div class="note">
    <strong>Las tres caras de la deriva.</strong> <code>missing</code> está en la base y no en el índice: la búsqueda <em>no lo encuentra</em>. <code>stale</code> está en los dos con versión vieja: la búsqueda <em>lo encuentra mal</em>. <code>orphan</code> está en el índice y borrado en la base: la búsqueda <em>devuelve fantasmas</em>. Las tres se ven igual desde afuera —«la búsqueda anda rara»— y se arreglan distinto.
    <br><br>
    <strong>Lo que aporta este stack.</strong> En un runtime <em>share-nothing</em> no hay proceso de larga vida donde vivir un consumidor de CDC: el consumidor es un comando de cron, y eso obliga a que <strong>el checkpoint sea durable desde el primer día</strong>. En Java, Go o .NET es tentador dejarlo en memoria hasta el primer reinicio; en PHP no hay «memoria» donde dejarlo. La contracara: PHP es el único de los siete donde <strong>nada ayuda a no ignorar el error</strong> — el <code>@</code> y el <code>catch</code> vacío compilan, corren y callan.
  </div>
</div>

<footer>
  Problem-Driven Systems Lab · Caso 19 ·
  <a href="/diagnostics/summary">/diagnostics/summary</a> ·
  <a href="/index/state">/index/state</a>
</footer>

<script>
const $ = (id) => document.getElementById(id);
const row = (k, v, c) => `<div class="metric-row"><span class="m-k">${k}</span><span class="m-v ${c}">${v}</span></div>`;

function render(target, d) {
  const good = target === 'out-sf';
  const ok = d.drift_count === 0;
  $(target).innerHTML = `
    <div class="hero">
      <div class="big ${ok ? 'val-good' : 'val-bad'}" style="background:none;padding:0">${d.drift_count}</div>
      <div class="lbl">documentos derivados</div>
    </div>
    ${row('faltantes (missing)', d.missing, d.missing > 0 ? 'val-bad' : 'val-good')}
    ${row('viejos (stale)', d.stale, d.stale > 0 ? 'val-bad' : 'val-good')}
    ${row('fantasmas (orphan)', d.orphan, d.orphan > 0 ? 'val-bad' : 'val-good')}
    ${row('fallos silenciosos', d.silent_failures, d.silent_failures > 0 ? 'val-bad' : 'val-good')}
    ${row('recall de búsqueda', d.search_recall_pct + '%', d.search_recall_pct >= 100 ? 'val-good' : 'val-bad')}
    ${row('precisión de búsqueda', d.search_precision_pct + '%', d.search_precision_pct >= 100 ? 'val-good' : 'val-bad')}
    ${row('antigüedad de la deriva', d.drift_age_ms + ' ms', good ? 'val-good' : 'val-bad')}
    ${row('checkpoint', d.last_checkpoint, 'val-neutral')}
    ${row('outbox pendiente', d.outbox_pending, 'val-neutral')}
  `;
}

async function run(variant, target, btn) {
  const b = $(btn);
  b.disabled = true;
  try {
    const p = new URLSearchParams({
      writes: $('writes').value || '2000',
      fail_rate: $('fail_rate').value || '8',
      delete_pct: $('delete_pct').value || '5',
      queries: $('queries').value || '200',
    });
    const res = await fetch(`/search-${variant}?${p}`, { headers: { Accept: 'application/json' } });
    render(target, await res.json());
  } catch (e) {
    $(target).innerHTML = `<div class="empty">Error: ${e.message}</div>`;
  } finally {
    b.disabled = false;
  }
}

$('btn-naive').onclick = () => run('drifted', 'out-naive', 'btn-naive');
$('btn-sf').onclick = () => run('reconciled', 'out-sf', 'btn-sf');
$('btn-reset').onclick = async () => {
  await fetch('/reset-lab', { headers: { Accept: 'application/json' } });
  $('out-naive').innerHTML = '<div class="empty">Sin ejecuciones todavía.</div>';
  $('out-sf').innerHTML = '<div class="empty">Sin ejecuciones todavía.</div>';
};
</script>
</body>
</html>
