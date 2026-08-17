<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caso 14 — Pool de conexiones | Problem-Driven Systems Lab</title>
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
  <div><div class="case-badge">CASO 14</div></div>
  <div class="header-title">
    <h1>🚰 Agotamiento del pool de conexiones</h1>
    <p>Rendimiento · La clave caliente expira y los N llamadores pegan al origen a la vez</p>
  </div>
  <span class="stack-badge">PHP 8.3 · finally garantizado</span>
</div>

<div class="container">
  <div class="cards-row">
    <div class="card problem">
      <div class="card-label">🔴 El problema</div>
      <h3>Cada excepción se lleva una conexión que no vuelve</h3>
      <p>El <code>release()</code> está solo en el camino feliz. Cuando la query falla, la línea nunca se ejecuta y la conexión se pierde. <strong>Nada en los logs dice «se fugó una conexión»</strong>: el pool simplemente se achica, y un día no queda ninguna.</p>
    </div>
    <div class="card solution">
      <div class="card-label">🟢 La corrección</div>
      <h3>finally garantizado y fallo rápido</h3>
      <p><code>finally</code> corre en todos los caminos de salida — incluido el <code>continue</code> del <code>catch</code>. Y cuando no hay conexión, se falla rápido con un código que el llamador puede interpretar, en vez de colgarse esperando algo que ya no existe.</p>
    </div>
  </div>

  <div class="controls">
    <h4>Ejecutar la carga</h4>
    <div class="params">
      <div class="param-group"><label>requests</label><input id="requests" type="number" value="24" min="1" max="200"></div>
      <div class="param-group"><label>tamaño del pool</label><input id="pool" type="number" value="4" min="1" max="64"></div>
      <div class="param-group"><label>duración de la query (ms)</label><input id="query_ms" type="number" value="25" min="1" max="500"></div>
      <div class="param-group"><label>tasa de fallo (%)</label><input id="fail_rate" type="number" value="25" min="0" max="100"></div>
    </div>
    <div class="btns">
      <button class="btn btn-legacy" id="btn-naive">Sin finally (fuga)</button>
      <button class="btn btn-solution" id="btn-sf">Con finally + fallo rápido</button>
      <button class="btn btn-ghost" id="btn-reset">Reiniciar lab</button>
    </div>
  </div>

  <div class="compare-panel">
    <div class="compare-card naive">
      <h5>❌ Leaky</h5>
      <div id="out-naive"><div class="empty">Sin ejecuciones todavía.</div></div>
    </div>
    <div class="compare-card sf">
      <h5>✅ Managed</h5>
      <div id="out-sf"><div class="empty">Sin ejecuciones todavía.</div></div>
    </div>
  </div>

  <div class="note">
    <strong>Nota de fidelidad.</strong> El servidor embebido de PHP es de un solo proceso, así que las N requests se recorren en secuencia y el pool vive dentro de una sola llamada HTTP. Y hay algo más honesto que decir: <strong>el proceso por request tapa este bug</strong> — la conexión fugada se recupera sola cuando el proceso muere. La versión PHP real del agotamiento es <code>max_children</code> de FPM multiplicado por conexiones <code>PDO::ATTR_PERSISTENT</code> contra el <code>max_connections</code> del motor.
  </div>
</div>

<footer>
  Problem-Driven Systems Lab · Caso 14 ·
  <a href="/diagnostics/summary">/diagnostics/summary</a> ·
  <a href="/pool/state">/pool/state</a>
</footer>

<script>
const $ = (id) => document.getElementById(id);
const row = (k, v, c) => `<div class="metric-row"><span class="m-k">${k}</span><span class="m-v ${c}">${v}</span></div>`;

function render(target, d) {
  const good = target === 'out-sf';
  const leakCls = d.leaked > 0 ? 'val-bad' : 'val-good';
  $(target).innerHTML = `
    <div class="hero">
      <div class="big ${leakCls}" style="background:none;padding:0">${d.leaked}</div>
      <div class="lbl">conexiones fugadas</div>
    </div>
    ${row('completadas', d.completed, good ? 'val-good' : 'val-neutral')}
    ${row('fallo la query', d.failed_query, 'val-neutral')}
    ${row('sin conexión (rápido)', d.failed_timeout, 'val-neutral')}
    ${row('colgadas', d.hung, d.hung > 0 ? 'val-bad' : 'val-good')}
    ${row('adquiridas / devueltas', d.acquired + ' / ' + d.released, 'val-neutral')}
    ${row('pool disponible al final', d.pool_available_after + ' de ' + d.pool_size, d.pool_available_after === d.pool_size ? 'val-good' : 'val-bad')}
    ${row('wall total', d.wall_ms + ' ms', good ? 'val-good' : 'val-bad')}
    ${row('pool sugerido (Little)', d.littles_law.recommended_pool_size, 'val-neutral')}
  `;
}

async function run(variant, target, btn) {
  const b = $(btn);
  b.disabled = true;
  try {
    const qs = new URLSearchParams({
      requests: $('requests').value || '24',
      pool: $('pool').value || '4',
      query_ms: $('query_ms').value || '25',
      fail_rate: $('fail_rate').value || '25',
    });
    const res = await fetch(`/pool-${variant}?${qs}`, { headers: { Accept: 'application/json' } });
    render(target, await res.json());
  } catch (e) {
    $(target).innerHTML = `<div class="empty">Error: ${e.message}</div>`;
  } finally {
    b.disabled = false;
  }
}

$('btn-naive').onclick = () => run('leaky', 'out-naive', 'btn-naive');
$('btn-sf').onclick = () => run('managed', 'out-sf', 'btn-sf');
$('btn-reset').onclick = async () => {
  await fetch('/reset-lab', { headers: { Accept: 'application/json' } });
  $('out-naive').innerHTML = '<div class="empty">Sin ejecuciones todavía.</div>';
  $('out-sf').innerHTML = '<div class="empty">Sin ejecuciones todavía.</div>';
};
</script>
</body>
</html>
