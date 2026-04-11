<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caso 03 — Observabilidad deficiente | Problem-Driven Systems Lab</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0e1a;--surface:#111827;--surface2:#1a2235;--border:#1e2d45;--accent:#8b5cf6;--accent2:#a78bfa;--red:#ef4444;--green:#22c55e;--text:#e2e8f0;--muted:#64748b}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.header{background:linear-gradient(135deg,#0d0a1a,#1a1030,#0a0e1a);border-bottom:1px solid #2d2050;padding:28px 40px;display:flex;align-items:center;gap:20px}
.case-badge{background:var(--accent);color:#fff;font-weight:800;font-size:11px;padding:4px 10px;border-radius:6px;letter-spacing:.5px}
.header-title{flex:1}.header-title h1{font-size:22px;font-weight:700;color:#fff}.header-title p{font-size:13px;color:#9d88c8;margin-top:4px}
.stack-badge{background:rgba(139,92,246,.15);border:1px solid rgba(139,92,246,.3);color:var(--accent);font-size:11px;padding:4px 12px;border-radius:20px;font-weight:600}
.container{max-width:1300px;margin:0 auto;padding:32px 40px}
.cards-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px}
.card{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:16px;padding:24px}
.card.problem{border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.04)}.card.solution{border-color:rgba(34,197,94,.3);background:rgba(34,197,94,.04)}
.card-label{font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:10px}
.card.problem .card-label{color:var(--red)}.card.solution .card-label{color:var(--green)}
.card h3{font-size:15px;font-weight:600;margin-bottom:10px;color:#fff}.card p{font-size:13px;color:#94a3b8;line-height:1.7}
.tag{display:inline-block;font-size:11px;padding:3px 8px;border-radius:4px;font-weight:600;margin-top:10px}
.tag-red{background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.2)}.tag-green{background:rgba(34,197,94,.15);color:#4ade80;border:1px solid rgba(34,197,94,.2)}
.action-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px}
.action-panel{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:24px}
.action-panel h4{font-size:13px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px}
.params{display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap}.param-group{display:flex;flex-direction:column;gap:4px}
.param-group label{font-size:11px;color:var(--muted);font-weight:500}
.param-group select{background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:7px 12px;border-radius:8px;font-size:13px}
.btn{padding:11px 24px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;border:none;transition:all .2s;display:inline-flex;align-items:center;gap:8px}
.btn-legacy{background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3)}.btn-legacy:hover{background:rgba(239,68,68,.25)}
.btn-solution{background:var(--accent);color:#fff}.btn-solution:hover{background:var(--accent2);transform:translateY(-1px);box-shadow:0 4px 20px rgba(139,92,246,.3)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.spinner{width:14px;height:14px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;display:none}
.btn:disabled .spinner{display:inline-block}@keyframes spin{to{transform:rotate(360deg)}}
.result-box{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:16px;min-height:140px;margin-top:16px;position:relative}
.result-empty{color:var(--muted);font-size:13px;text-align:center;padding:24px 0}
.result-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px}
.result-row:last-child{border:none}.result-label{color:var(--muted);font-weight:500}
.result-val{font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:600;padding:3px 8px;border-radius:5px}
.val-bad{background:rgba(239,68,68,.15);color:#f87171}.val-good{background:rgba(34,197,94,.15);color:#4ade80}.val-neutral{background:rgba(148,163,184,.1);color:#94a3b8}
.result-status{position:absolute;top:12px;right:12px;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px}
.status-ok{background:rgba(34,197,94,.15);color:#4ade80}.status-err{background:rgba(239,68,68,.15);color:#f87171}
.steps-box{margin-top:12px}
.step-item{display:flex;align-items:center;gap:10px;padding:6px 0;font-size:12px;border-bottom:1px solid rgba(255,255,255,.04)}
.step-item:last-child{border:none}.step-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.step-ok{background:#22c55e}.step-err{background:#ef4444}
.step-name{font-family:'JetBrains Mono',monospace;font-weight:500}.step-dep{color:var(--muted)}.step-ms{margin-left:auto;font-family:'JetBrains Mono',monospace;color:#60a5fa}
.metrics-section{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:24px}
.metrics-section h4{font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:20px;display:flex;align-items:center;gap:8px}
.pulse{width:8px;height:8px;background:var(--green);border-radius:50%;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.3)}}
.metrics-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px}
.metric-card{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:16px}
.metric-card .m-label{font-size:11px;color:var(--muted);margin-bottom:6px;font-weight:500}.metric-card .m-val{font-size:22px;font-weight:800;font-family:'JetBrains Mono',monospace}
.answerability{background:rgba(139,92,246,.05);border:1px solid rgba(139,92,246,.2);border-radius:12px;padding:20px;margin-top:20px}
.ans-row{display:grid;grid-template-columns:1fr 80px 80px;gap:8px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05);align-items:center;font-size:12px}
.ans-row:last-child{border:none}.ans-label{color:#94a3b8}.ans-yes{color:#4ade80;text-align:center;font-weight:700}.ans-no{color:#f87171;text-align:center;font-weight:700}
.log-box{background:#0d1117;border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:12px;margin-top:8px;font-family:'JetBrains Mono',monospace;font-size:11px;color:#6b7280;max-height:100px;overflow-y:auto}
.log-line-legacy{color:#fbbf24}.log-line-observable{color:#60a5fa}
footer{text-align:center;padding:32px;color:var(--muted);font-size:12px;border-top:1px solid var(--border);margin-top:40px}
</style>
</head>
<body>
<div class="header">
  <div><div class="case-badge">CASO 03</div></div>
  <div class="header-title">
    <h1>🔭 Observabilidad deficiente y logs inútiles</h1>
    <p>Observabilidad · Logs sin correlación, sin request_id, sin paso que falló, sin dependencia identificada</p>
  </div>
  <span class="stack-badge">PHP 8.3 · Logs estructurados</span>
</div>
<div class="container">
  <div class="cards-row">
    <div class="card problem">
      <div class="card-label">🔴 El Problema</div>
      <h3>Logs planos: "checkout failed" — ¿qué pasó? ¿en qué paso?</h3>
      <p>El modo legacy genera logs como <em>"checkout started"</em> y <em>"checkout failed"</em>. No hay request_id para correlacionar, no se sabe qué dependencia falló, no hay latencia por paso. El incidente se vuelve un rompecabezas sin piezas.</p>
      <span class="tag tag-red">Sin correlación · Sin trace_id · Sin paso identificado</span>
    </div>
    <div class="card solution">
      <div class="card-label">✅ La Solución</div>
      <h3>Logs estructurados con request_id, trace_id y evento exacto</h3>
      <p>El modo observable genera JSON con <code>request_id</code>, <code>trace_id</code>, <code>event</code> (e.g. <em>dependency_failed</em>), el paso exacto (<code>payment.authorize</code>), la dependencia afectada y el tiempo que tardó. El incidente se reconstruye en segundos.</p>
      <span class="tag tag-green">request_id · trace_id · paso exacto · dependency mapeada</span>
    </div>
  </div>

  <div class="action-row">
    <div class="action-panel">
      <h4>🔴 Checkout con logs pobres</h4>
      <div class="params">
        <div class="param-group"><label>Escenario</label>
          <select id="sc-leg">
            <option value="ok">ok – sin falla</option>
            <option value="payment_timeout" selected>payment_timeout</option>
            <option value="inventory_conflict">inventory_conflict</option>
            <option value="notification_down">notification_down</option>
          </select>
        </div>
      </div>
      <button class="btn btn-legacy" id="btn-leg" onclick="runLegacy()">
        <div class="spinner" id="sp-leg"></div>▶ /checkout-legacy
      </button>
      <div class="result-box" id="res-leg"><div class="result-empty">Ejecuta para ver los logs inútiles que genera</div></div>
    </div>
    <div class="action-panel">
      <h4>✅ Checkout observable</h4>
      <div class="params">
        <div class="param-group"><label>Escenario</label>
          <select id="sc-obs">
            <option value="ok">ok – sin falla</option>
            <option value="payment_timeout" selected>payment_timeout</option>
            <option value="inventory_conflict">inventory_conflict</option>
            <option value="notification_down">notification_down</option>
          </select>
        </div>
      </div>
      <button class="btn btn-solution" id="btn-obs" onclick="runObservable()">
        <div class="spinner" id="sp-obs"></div>▶ /checkout-observable
      </button>
      <div class="result-box" id="res-obs"><div class="result-empty">Ejecuta para ver la diferencia con trazabilidad real</div></div>
    </div>
  </div>

  <div class="metrics-section">
    <h4><span class="pulse"></span> Diagnóstico <span style="font-weight:400;color:#475569;font-size:11px;margin-left:8px">Auto-refresh 6s</span></h4>
    <div class="metrics-grid">
      <div class="metric-card"><div class="m-label">Éxitos observable</div><div class="m-val" id="m-suc" style="color:#4ade80">—</div></div>
      <div class="metric-card"><div class="m-label">Fallas legacy</div><div class="m-val" id="m-fail" style="color:#f87171">—</div></div>
      <div class="metric-card"><div class="m-label">Requests totales</div><div class="m-val" id="m-total">—</div></div>
      <div class="metric-card"><div class="m-label">P95 latencia</div><div class="m-val" id="m-p95">—</div><div style="font-size:11px;color:var(--muted);margin-top:4px">ms</div></div>
    </div>
    <div class="answerability" id="answerability"></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:20px">
      <div>
        <div style="font-size:11px;font-weight:700;color:#fbbf24;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">📜 Logs Legacy (últimas 6 líneas)</div>
        <div class="log-box" id="logs-legacy">—</div>
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:#60a5fa;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">📊 Logs Observable (últimas 6 líneas)</div>
        <div class="log-box" id="logs-observable">—</div>
      </div>
    </div>
  </div>
</div>
<footer>Problem-Driven Systems Lab · Caso 03 · <a href="/metrics" style="color:var(--accent)">Métricas JSON</a> · <a href="/reset-observability" style="color:var(--muted)">Reset</a></footer>

<script>
function setLoading(b, s, v) { document.getElementById(b).disabled=v; document.getElementById(s).style.display=v?'inline-block':'none'; }
function renderSteps(events) {
  if (!events?.length) return '';
  return '<div class="steps-box">' + events.map(e=>`
    <div class="step-item">
      <div class="step-dot ${e.status==='ok'?'step-ok':'step-err'}"></div>
      <span class="step-name">${e.step}</span>
      <span class="step-dep">→ ${e.dependency}</span>
      <span class="step-ms">${e.elapsed_ms ?? '—'} ms</span>
    </div>`).join('') + '</div>';
}
function renderResult(boxId, data, isLegacy) {
  const ok = !data.error && data.status === 'completed';
  const steps = renderSteps(data.events);
  let html = `<span class="result-status ${ok?'status-ok':'status-err'}">${ok?'✅':'❌'} ${data.status ?? 'error'}</span>
    <div class="result-row"><span class="result-label">Modo</span><span class="result-val val-neutral">${data.mode}</span></div>
    <div class="result-row"><span class="result-label">Tiempo total</span><span class="result-val val-neutral">${data.elapsed_ms ?? '—'} ms</span></div>`;
  if (!isLegacy && data.request_id) html += `<div class="result-row"><span class="result-label">request_id</span><span class="result-val val-good" style="font-size:10px">${data.request_id}</span></div>`;
  if (!isLegacy && data.trace_id) html += `<div class="result-row"><span class="result-label">trace_id</span><span class="result-val val-good" style="font-size:10px">${data.trace_id}</span></div>`;
  if (data.failed_step) html += `<div class="result-row"><span class="result-label">Paso que falló</span><span class="result-val val-bad">${data.failed_step}</span></div>`;
  if (data.dependency) html += `<div class="result-row"><span class="result-label">Dependencia</span><span class="result-val val-bad">${data.dependency}</span></div>`;
  if (isLegacy && data.error) html += `<div class="result-row"><span class="result-label">Info disponible</span><span class="result-val val-bad">Sólo: "No se pudo completar"</span></div>`;
  document.getElementById(boxId).innerHTML = html + steps;
}
async function runLegacy() {
  const sc = document.getElementById('sc-leg').value;
  setLoading('btn-leg','sp-leg',true);
  try { const r=await fetch(`/checkout-legacy?scenario=${sc}&customer_id=42&cart_items=3`); const d=await r.json(); renderResult('res-leg',d,true); }
  catch(e) { document.getElementById('res-leg').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`; }
  setLoading('btn-leg','sp-leg',false); loadMetrics();
}
async function runObservable() {
  const sc = document.getElementById('sc-obs').value;
  setLoading('btn-obs','sp-obs',true);
  try { const r=await fetch(`/checkout-observable?scenario=${sc}&customer_id=42&cart_items=3`); const d=await r.json(); renderResult('res-obs',d,false); }
  catch(e) { document.getElementById('res-obs').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`; }
  setLoading('btn-obs','sp-obs',false); loadMetrics();
}
async function loadMetrics() {
  try {
    const r=await fetch('/diagnostics/summary'); const d=await r.json();
    document.getElementById('m-total').textContent = d.metrics?.requests_tracked ?? '—';
    document.getElementById('m-p95').textContent = d.metrics?.p95_ms ?? '—';
    document.getElementById('m-suc').textContent = d.metrics?.successes?.observable ?? '—';
    document.getElementById('m-fail').textContent = d.metrics?.failures?.legacy?.total ?? '—';
    const ans = d.answerability;
    if (ans) {
      document.getElementById('answerability').innerHTML = `
        <div style="font-size:12px;font-weight:700;color:#a78bfa;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px">🧠 ¿Puedes responder estas preguntas?</div>
        <div class="ans-row"><span class="ans-label">¿Puedo correlacionar la request completa?</span><span class="ans-no">NO (legacy)</span><span class="ans-yes">SÍ (observable)</span></div>
        <div class="ans-row"><span class="ans-label">¿Sé qué paso falló exactamente?</span><span class="ans-no">NO</span><span class="ans-yes">SÍ</span></div>
        <div class="ans-row"><span class="ans-label">¿Sé qué dependencia está implicada?</span><span class="ans-no">NO</span><span class="ans-yes">SÍ</span></div>
        <div class="ans-row"><span class="ans-label">¿Cuánto tardó cada paso?</span><span class="ans-no">NO</span><span class="ans-yes">SÍ</span></div>
      `;
    }
    const lr=await fetch('/logs/legacy?tail=6'); const ld=await lr.json();
    document.getElementById('logs-legacy').innerHTML = (ld.lines??[]).map(l=>`<div class="log-line-legacy">${l}</div>`).join('') || '(vacío)';
    const or=await fetch('/logs/observable?tail=6'); const od=await or.json();
    document.getElementById('logs-observable').innerHTML = (od.lines??[]).map(l=>{
      try { const j=JSON.parse(l); return `<div class="log-line-observable">${JSON.stringify({event:j.event,step:j.step,error_class:j.error_class}).replace(/null,?/g,'').replace(/[{}]/g,'')}</div>`; } catch{ return `<div class="log-line-observable">${l}</div>`; }
    }).join('') || '(vacío)';
  } catch(e){}
}
loadMetrics(); setInterval(loadMetrics,6000);
</script>
</body></html>
