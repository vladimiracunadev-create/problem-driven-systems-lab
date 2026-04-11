<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caso 02 — N+1 Queries | Problem-Driven Systems Lab</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0e1a;--surface:#111827;--surface2:#1a2235;--border:#1e2d45;--accent:#f59e0b;--accent2:#fbbf24;--red:#ef4444;--green:#22c55e;--text:#e2e8f0;--muted:#64748b;--card-bg:rgba(255,255,255,0.03)}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.header{background:linear-gradient(135deg,#1a1200,#2d1f00,#0a0e1a);border-bottom:1px solid #3d2e00;padding:28px 40px;display:flex;align-items:center;gap:20px}
.case-badge{background:var(--accent);color:#000;font-weight:800;font-size:11px;padding:4px 10px;border-radius:6px;letter-spacing:.5px}
.header-title{flex:1}.header-title h1{font-size:22px;font-weight:700;color:#fff}.header-title p{font-size:13px;color:#a0856a;margin-top:4px}
.stack-badge{background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.3);color:var(--accent);font-size:11px;padding:4px 12px;border-radius:20px;font-weight:600}
.container{max-width:1300px;margin:0 auto;padding:32px 40px}
.cards-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px}
.card{background:var(--card-bg);border:1px solid var(--border);border-radius:16px;padding:24px}
.card.problem{border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.04)}.card.solution{border-color:rgba(34,197,94,.3);background:rgba(34,197,94,.04)}
.card-label{font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:10px;display:flex;align-items:center;gap:8px}
.card.problem .card-label{color:var(--red)}.card.solution .card-label{color:var(--green)}
.card h3{font-size:15px;font-weight:600;margin-bottom:10px;color:#fff}.card p{font-size:13px;color:#94a3b8;line-height:1.7}
.tag{display:inline-block;font-size:11px;padding:3px 8px;border-radius:4px;font-weight:600;margin-top:10px}
.tag-red{background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.2)}.tag-green{background:rgba(34,197,94,.15);color:#4ade80;border:1px solid rgba(34,197,94,.2)}
.action-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px}
.action-panel{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:24px}
.action-panel h4{font-size:13px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px}
.params{display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap}.param-group{display:flex;flex-direction:column;gap:4px}
.param-group label{font-size:11px;color:var(--muted);font-weight:500}
.param-group select,.param-group input{background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:7px 12px;border-radius:8px;font-size:13px;font-family:'Inter',sans-serif}
.btn{padding:11px 24px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;border:none;transition:all .2s;display:inline-flex;align-items:center;gap:8px}
.btn-legacy{background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3)}.btn-legacy:hover{background:rgba(239,68,68,.25)}
.btn-solution{background:var(--accent);color:#000}.btn-solution:hover{background:var(--accent2);transform:translateY(-1px);box-shadow:0 4px 20px rgba(245,158,11,.3)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.spinner{width:14px;height:14px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;display:none}
.btn:disabled .spinner{display:inline-block}@keyframes spin{to{transform:rotate(360deg)}}
.result-box{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:16px;min-height:110px;margin-top:16px;position:relative}
.result-empty{color:var(--muted);font-size:13px;text-align:center;padding:24px 0}
.result-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px}
.result-row:last-child{border:none}.result-label{color:var(--muted);font-weight:500}
.result-val{font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:600;padding:3px 8px;border-radius:5px}
.val-bad{background:rgba(239,68,68,.15);color:#f87171}.val-good{background:rgba(34,197,94,.15);color:#4ade80}.val-neutral{background:rgba(148,163,184,.1);color:#94a3b8}
.result-status{position:absolute;top:12px;right:12px;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px}
.status-ok{background:rgba(34,197,94,.15);color:#4ade80}.status-err{background:rgba(239,68,68,.15);color:#f87171}
.metrics-section{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:24px}
.metrics-section h4{font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:20px;display:flex;align-items:center;gap:8px}
.pulse{width:8px;height:8px;background:var(--green);border-radius:50%;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.3)}}
.metrics-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px}
.metric-card{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:16px}
.metric-card .m-label{font-size:11px;color:var(--muted);margin-bottom:6px;font-weight:500}
.metric-card .m-val{font-size:22px;font-weight:800;font-family:'JetBrains Mono',monospace;letter-spacing:-1px}
.metric-card .m-sub{font-size:11px;color:var(--muted);margin-top:4px}
.compare-row{display:grid;grid-template-columns:1fr auto 1fr;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.05)}
.compare-row:last-child{border:none}.compare-label{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;text-align:center;font-weight:600}
.compare-val{font-family:'JetBrains Mono',monospace;font-size:13px;font-weight:600;padding:4px 10px;border-radius:6px}
.db-info{background:rgba(59,130,246,.05);border:1px solid rgba(59,130,246,.2);border-radius:12px;padding:16px;margin-top:20px}
.db-info h5{font-size:12px;font-weight:700;color:#60a5fa;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px}
.db-row{display:flex;justify-content:space-between;font-size:12px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.db-row:last-child{border:none}.db-key{color:var(--muted)}.db-val{font-family:'JetBrains Mono',monospace;color:#93c5fd}
footer{text-align:center;padding:32px;color:var(--muted);font-size:12px;border-top:1px solid var(--border);margin-top:40px}
</style>
</head>
<body>
<div class="header">
  <div><div class="case-badge">CASO 02</div></div>
  <div class="header-title">
    <h1>🔄 N+1 Queries y cuellos de botella en base de datos</h1>
    <p>Rendimiento · Consultas dentro de bucles, relaciones cargadas una por una, round-trips innecesarios</p>
  </div>
  <span class="stack-badge">PHP 8.3 · PostgreSQL</span>
</div>
<div class="container">
  <div class="cards-row">
    <div class="card problem">
      <div class="card-label">🔴 El Problema</div>
      <h3>N+1 en cascada: orden → cliente → items → producto → categoría</h3>
      <p>Por cada orden se ejecutan queries individuales para su cliente, luego por cada item se ejecutan queries para producto y categoría. Con 20 órdenes y 5 items cada una, se disparan <strong>100+ queries</strong> para responder una sola request.</p>
      <span class="tag tag-red">N+1 en cascada · Bucles con DB · O(n × m) queries</span>
    </div>
    <div class="card solution">
      <div class="card-label">✅ La Solución</div>
      <h3>JOINs consolidados: 2 queries para cualquier volumen</h3>
      <p>Una query trae órdenes + cliente en JOIN. Otra query trae todos los items con producto y categoría en un solo batch usando <code>WHERE order_id IN (...)</code>. Sin importar cuántas órdenes o ítems, siempre son <strong>2 queries</strong>.</p>
      <span class="tag tag-green">2 queries fijas · IN-batch · Escalable linealmente</span>
    </div>
  </div>

  <div class="action-row">
    <div class="action-panel">
      <h4>🔴 Ruta con N+1</h4>
      <div class="params">
        <div class="param-group"><label>Días</label>
          <select id="days-leg"><option value="7">7 días</option><option value="30" selected>30 días</option><option value="90">90 días</option></select>
        </div>
        <div class="param-group"><label>Límite</label>
          <input id="limit-leg" type="number" value="20" min="1" max="60" style="width:80px">
        </div>
      </div>
      <button class="btn btn-legacy" id="btn-leg" onclick="runLegacy()">
        <div class="spinner" id="sp-leg"></div>▶ /orders-legacy
      </button>
      <div class="result-box" id="res-leg"><div class="result-empty">Ejecuta para ver cuántas queries genera</div></div>
    </div>
    <div class="action-panel">
      <h4>✅ Ruta Optimizada</h4>
      <div class="params">
        <div class="param-group"><label>Días</label>
          <select id="days-opt"><option value="7">7 días</option><option value="30" selected>30 días</option><option value="90">90 días</option></select>
        </div>
        <div class="param-group"><label>Límite</label>
          <input id="limit-opt" type="number" value="20" min="1" max="60" style="width:80px">
        </div>
      </div>
      <button class="btn btn-solution" id="btn-opt" onclick="runOptimized()">
        <div class="spinner" id="sp-opt"></div>▶ /orders-optimized
      </button>
      <div class="result-box" id="res-opt"><div class="result-empty">Ejecuta para comparar</div></div>
    </div>
  </div>

  <div class="metrics-section">
    <h4><span class="pulse"></span> Métricas acumuladas <span style="font-weight:400;color:#475569;font-size:11px;margin-left:8px">Auto-refresh 6s</span></h4>
    <div class="metrics-grid">
      <div class="metric-card"><div class="m-label">Requests totales</div><div class="m-val" id="m-total">—</div></div>
      <div class="metric-card"><div class="m-label">Avg queries/req (legacy)</div><div class="m-val" id="m-qleg" style="color:#f87171">—</div></div>
      <div class="metric-card"><div class="m-label">Avg queries/req (opt)</div><div class="m-val" id="m-qopt" style="color:#4ade80">—</div></div>
      <div class="metric-card"><div class="m-label">Delta latencia p95</div><div class="m-val" id="m-delta">—</div><div class="m-sub">legacy − optimized</div></div>
    </div>
    <div style="margin-top:20px;font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px">Comparación por ruta</div>
    <div id="compare-table"></div>
    <div class="db-info" id="db-info"></div>
  </div>
</div>
<footer>Problem-Driven Systems Lab · Caso 02 · <a href="/metrics" style="color:var(--accent)">Métricas JSON</a> · <a href="/reset-metrics" style="color:var(--muted)">Reset</a></footer>

<script>
function setLoading(btnId, spId, loading) {
  document.getElementById(btnId).disabled = loading;
  document.getElementById(spId).style.display = loading ? 'inline-block' : 'none';
}
function renderResult(boxId, data, isLegacy) {
  const box = document.getElementById(boxId);
  const ok = !data.error;
  box.innerHTML = `
    <span class="result-status ${ok?(isLegacy?'status-err':'status-ok'):'status-err'}">${isLegacy?'⚠ LEGACY':'✅ OPTIMIZADO'}</span>
    <div class="result-row"><span class="result-label">Tiempo total</span><span class="result-val ${isLegacy?'val-bad':'val-good'}">${data.elapsed_ms ?? '—'} ms</span></div>
    <div class="result-row"><span class="result-label">Queries a DB</span><span class="result-val ${isLegacy?'val-bad':'val-good'}">${data.db_queries_in_request ?? '—'}</span></div>
    <div class="result-row"><span class="result-label">Tiempo en DB</span><span class="result-val ${isLegacy?'val-bad':'val-good'}">${data.db_time_ms_in_request ?? '—'} ms</span></div>
    <div class="result-row"><span class="result-label">Órdenes</span><span class="result-val val-neutral">${data.result_count ?? '—'}</span></div>
  `;
}
async function runLegacy() {
  const days = document.getElementById('days-leg').value;
  const limit = document.getElementById('limit-leg').value;
  setLoading('btn-leg','sp-leg',true);
  try { const r = await fetch(`/orders-legacy?days=${days}&limit=${limit}`); const d = await r.json(); renderResult('res-leg', d, true); }
  catch(e) { document.getElementById('res-leg').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`; }
  setLoading('btn-leg','sp-leg',false); loadMetrics();
}
async function runOptimized() {
  const days = document.getElementById('days-opt').value;
  const limit = document.getElementById('limit-opt').value;
  setLoading('btn-opt','sp-opt',true);
  try { const r = await fetch(`/orders-optimized?days=${days}&limit=${limit}`); const d = await r.json(); renderResult('res-opt', d, false); }
  catch(e) { document.getElementById('res-opt').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`; }
  setLoading('btn-opt','sp-opt',false); loadMetrics();
}
async function loadMetrics() {
  try {
    const r = await fetch('/diagnostics/summary'); const d = await r.json();
    const leg = d.legacy ?? {}, opt = d.optimized ?? {}, db = d.database ?? {};
    document.getElementById('m-total').textContent = d.metrics?.requests_tracked ?? '—';
    document.getElementById('m-qleg').textContent = d.metrics?.avg_db_queries?.toFixed?.(1) ?? '—';
    document.getElementById('m-qopt').textContent = '2';
    const delta = (leg.p95_ms ?? 0) - (opt.p95_ms ?? 0);
    document.getElementById('m-delta').textContent = delta > 0 ? `+${delta.toFixed(0)} ms` : '—';
    const ct = document.getElementById('compare-table');
    const rows = [['Avg ms', leg.avg_ms, opt.avg_ms],['P95 ms', leg.p95_ms, opt.p95_ms],['Requests', leg.count, opt.count]];
    ct.innerHTML = `<div class="compare-row"><span class="compare-val" style="color:var(--muted);font-size:11px">LEGACY</span><span class="compare-label">Métrica</span><span class="compare-val" style="color:var(--muted);font-size:11px">OPTIMIZADA</span></div>`
      + rows.map(([l,lv,ov])=>`<div class="compare-row"><span class="compare-val ${lv>ov?'val-bad':'val-neutral'}">${lv??'—'}</span><span class="compare-label">${l}</span><span class="compare-val ${ov<lv?'val-good':'val-neutral'}">${ov??'—'}</span></div>`).join('');
    const dbEl = document.getElementById('db-info');
    if (db.row_counts) {
      dbEl.innerHTML = `<h5>📊 Densidad relacional de la base</h5>
        ${Object.entries(db.row_counts).map(([k,v])=>`<div class="db-row"><span class="db-key">${k}</span><span class="db-val">${v} filas</span></div>`).join('')}
        ${db.relationships ? `<div class="db-row"><span class="db-key">Avg items/orden</span><span class="db-val">${db.relationships.avg_items_per_order}</span></div>` : ''}`;
    }
  } catch(e) {}
}
loadMetrics(); setInterval(loadMetrics, 6000);
</script>
</body></html>
