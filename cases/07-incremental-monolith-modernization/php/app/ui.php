<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caso 07 — Modernización Incremental | Problem-Driven Systems Lab</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0e1a;--surface:#111827;--surface2:#1a2235;--border:#1e2d45;--accent:#3b82f6;--red:#ef4444;--green:#22c55e;--blue:#3b82f6;--text:#e2e8f0;--muted:#64748b}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.header{background:linear-gradient(135deg,#05101a,#0a1e30,#0a0e1a);border-bottom:1px solid #0f2540;padding:28px 40px;display:flex;align-items:center;gap:20px}
.case-badge{background:var(--blue);color:#fff;font-weight:800;font-size:11px;padding:4px 10px;border-radius:6px}
.header-title{flex:1}.header-title h1{font-size:22px;font-weight:700;color:#fff}.header-title p{font-size:13px;color:#5a8aac;margin-top:4px}
.stack-badge{background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.3);color:var(--blue);font-size:11px;padding:4px 12px;border-radius:20px;font-weight:600}
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
.btn-solution{background:var(--blue);color:#fff}.btn-solution:hover{filter:brightness(1.1);transform:translateY(-1px)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.spinner{width:14px;height:14px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;display:none}
.btn:disabled .spinner{display:inline-block}@keyframes spin{to{transform:rotate(360deg)}}
.result-box{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:16px;min-height:130px;margin-top:16px;position:relative}
.result-empty{color:var(--muted);font-size:13px;text-align:center;padding:24px 0}
.result-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px}
.result-row:last-child{border:none}.result-label{color:var(--muted);font-weight:500}
.result-val{font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:600;padding:3px 8px;border-radius:5px}
.val-bad{background:rgba(239,68,68,.15);color:#f87171}.val-good{background:rgba(34,197,94,.15);color:#4ade80}.val-neutral{background:rgba(148,163,184,.1);color:#94a3b8}
.result-status{position:absolute;top:12px;right:12px;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px}
.status-ok{background:rgba(34,197,94,.15);color:#4ade80}.status-err{background:rgba(239,68,68,.15);color:#f87171}
.metrics-section{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:24px}
.metrics-section h4{font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:20px;display:flex;align-items:center;gap:8px}
.pulse{width:8px;height:8px;background:var(--green);border-radius:50%;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.3)}}
.migration-panel{background:rgba(59,130,246,.05);border:1px solid rgba(59,130,246,.2);border-radius:12px;padding:20px;margin-bottom:20px}
.migration-panel h5{font-size:12px;font-weight:700;color:#60a5fa;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px}
.consumer-row{display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:12px}
.consumer-row:last-child{border:none}.consumer-name{color:var(--muted);width:100px;flex-shrink:0;font-weight:600}
.progress-track{flex:1;height:8px;background:rgba(255,255,255,.05);border-radius:4px;overflow:hidden}
.progress-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,#3b82f6,#60a5fa);transition:width 1s ease}
.progress-val{width:45px;text-align:right;font-family:'JetBrains Mono',monospace;font-weight:700;color:#93c5fd;font-size:11px}
.metrics-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.metric-card{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px}
.metric-card .m-label{font-size:11px;color:var(--muted);margin-bottom:5px;font-weight:500}.metric-card .m-val{font-size:20px;font-weight:800;font-family:'JetBrains Mono',monospace}
footer{text-align:center;padding:32px;color:var(--muted);font-size:12px;border-top:1px solid var(--border);margin-top:40px}
</style>
</head>
<body>
<div class="header">
  <div><div class="case-badge">CASO 07</div></div>
  <div class="header-title">
    <h1>🏗️ Modernización incremental del monolito</h1>
    <p>Arquitectura · Alto acoplamiento, alto radio de impacto por cambio, riesgo de regresión en cada release</p>
  </div>
  <span class="stack-badge">PHP 8.3 · Strangler Fig Pattern</span>
</div>
<div class="container">
  <div class="cards-row">
    <div class="card problem">
      <div class="card-label">🔴 El Problema</div>
      <h3>Legacy: cambiar billing toca 6 módulos y tiene 82% de riesgo</h3>
      <p>En el monolito acoplado, un cambio en facturación tiene dependencias con inventario, precios, reportes, auditoría y notificaciones. El blast radius es enorme. Cada cambio es una operación de alto riesgo que requiere coordinación entre equipos.</p>
      <span class="tag tag-red">6 módulos afectados · 82% riesgo · Alto blast radius</span>
    </div>
    <div class="card solution">
      <div class="card-label">✅ La Solución</div>
      <h3>Strangler Fig: extraer servicios, mover consumidores gradualmente</h3>
      <p>El patrón strangler fig extrae módulos con una Anti-Corruption Layer y mueve consumidores de a 25% por vez. La cobertura de contratos sube con cada corte. El blast radius baja a 2 módulos y el riesgo cae al 28%.</p>
      <span class="tag tag-green">2 módulos · ACL · Contratos · Migración gradual</span>
    </div>
  </div>

  <div class="action-row">
    <div class="action-panel">
      <h4>🔴 Cambio sobre monolito acoplado</h4>
      <div class="params">
        <div class="param-group"><label>Escenario</label>
          <select id="sc-leg">
            <option value="billing_change" selected>billing_change – cambio de facturación</option>
            <option value="shared_schema">shared_schema – esquema compartido</option>
            <option value="parallel_work">parallel_work – trabajo paralelo</option>
          </select>
        </div>
        <div class="param-group"><label>Consumidor</label>
          <select id="cs-leg"><option value="web" selected>web</option><option value="app">app</option><option value="backoffice">backoffice</option></select>
        </div>
      </div>
      <button class="btn btn-legacy" id="btn-leg" onclick="runLegacy()">
        <div class="spinner" id="sp-leg"></div>▶ /change-legacy
      </button>
      <div class="result-box" id="res-leg"><div class="result-empty">Ejecuta y observa el blast radius</div></div>
    </div>
    <div class="action-panel">
      <h4>✅ Cambio con Strangler Fig</h4>
      <div class="params">
        <div class="param-group"><label>Escenario</label>
          <select id="sc-str">
            <option value="billing_change" selected>billing_change – cambio de facturación</option>
            <option value="shared_schema">shared_schema – esquema compartido</option>
            <option value="parallel_work">parallel_work – trabajo paralelo</option>
          </select>
        </div>
        <div class="param-group"><label>Consumidor</label>
          <select id="cs-str"><option value="web" selected>web</option><option value="app">app</option><option value="backoffice">backoffice</option></select>
        </div>
      </div>
      <button class="btn btn-solution" id="btn-str" onclick="runStrangler()">
        <div class="spinner" id="sp-str"></div>▶ /change-strangler
      </button>
      <div class="result-box" id="res-str"><div class="result-empty">Ejecuta varias veces para ver el progreso de migración</div></div>
    </div>
  </div>

  <div class="metrics-section">
    <h4><span class="pulse"></span> Progreso de modernización <span style="font-weight:400;color:#475569;font-size:11px;margin-left:8px">Auto-refresh 5s</span></h4>
    <div class="migration-panel">
      <h5>📊 Progreso de migración por consumidor</h5>
      <div id="consumers-list"><div style="color:var(--muted);font-size:13px">Ejecuta /change-strangler para ver el progreso</div></div>
    </div>
    <div class="metrics-row">
      <div class="metric-card"><div class="m-label">Tests de contrato</div><div class="m-val" id="m-ct" style="color:#60a5fa">—</div></div>
      <div class="metric-card"><div class="m-label">Cobertura módulo</div><div class="m-val" id="m-cov">—<span style="font-size:12px">%</span></div></div>
      <div class="metric-card"><div class="m-label">Exitos (strangler)</div><div class="m-val" id="m-ss" style="color:#4ade80">—</div></div>
      <div class="metric-card"><div class="m-label">Fallas (legacy)</div><div class="m-val" id="m-lf" style="color:#f87171">—</div></div>
    </div>
  </div>
</div>
<footer>Problem-Driven Systems Lab · Caso 07 · <a href="/migration/state" style="color:var(--accent)">Estado migración</a> · <a href="/reset-lab" style="color:var(--muted)">Reset</a></footer>

<script>
function setLoading(b,s,v){document.getElementById(b).disabled=v;document.getElementById(s).style.display=v?'inline-block':'none';}
function renderResult(boxId,data,isLegacy){
  const ok=data.status==='completed';
  document.getElementById(boxId).innerHTML=`
    <span class="result-status ${ok?'status-ok':'status-err'}">${ok?'✅ OK':'❌ FALLIDO'}</span>
    <div class="result-row"><span class="result-label">Módulos tocados</span><span class="result-val ${isLegacy?'val-bad':'val-good'}">${data.modules_touched??'—'}</span></div>
    <div class="result-row"><span class="result-label">Blast radius score</span><span class="result-val ${isLegacy?'val-bad':'val-good'}">${data.blast_radius_score??'—'}</span></div>
    <div class="result-row"><span class="result-label">Riesgo score</span><span class="result-val ${isLegacy?'val-bad':'val-good'}">${data.risk_score??'—'}%</span></div>
    ${!isLegacy&&data.migration_state?`<div class="result-row"><span class="result-label">Progreso consumidor</span><span class="result-val val-good">${data.migration_state.consumers?.[document.getElementById('cs-str').value]??0}%</span></div>`:''}
    <div class="result-row"><span class="result-label">Tiempo</span><span class="result-val val-neutral">${data.elapsed_ms??'—'} ms</span></div>
  `;
}
async function runLegacy(){
  const sc=document.getElementById('sc-leg').value,cs=document.getElementById('cs-leg').value;
  setLoading('btn-leg','sp-leg',true);
  try{const r=await fetch(`/change-legacy?scenario=${sc}&consumer=${cs}`);const d=await r.json();renderResult('res-leg',d,true);}
  catch(e){document.getElementById('res-leg').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`;}
  setLoading('btn-leg','sp-leg',false);loadMetrics();
}
async function runStrangler(){
  const sc=document.getElementById('sc-str').value,cs=document.getElementById('cs-str').value;
  setLoading('btn-str','sp-str',true);
  try{const r=await fetch(`/change-strangler?scenario=${sc}&consumer=${cs}`);const d=await r.json();renderResult('res-str',d,false);}
  catch(e){document.getElementById('res-str').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`;}
  setLoading('btn-str','sp-str',false);loadMetrics();
}
async function loadMetrics(){
  try{
    const r=await fetch('/diagnostics/summary');const d=await r.json();
    const mg=d.migration??{},ms=d.metrics?.modes??{};
    document.getElementById('m-ct').textContent=mg.contract_tests??0;
    document.getElementById('m-cov').textContent=mg.extracted_module_coverage??0;
    document.getElementById('m-ss').textContent=ms.strangler?.successes??0;
    document.getElementById('m-lf').textContent=ms.legacy?.failures??0;
    const cl=document.getElementById('consumers-list');
    if(mg.consumers&&Object.keys(mg.consumers).length>0){
      cl.innerHTML=Object.entries(mg.consumers).map(([name,pct])=>`
        <div class="consumer-row">
          <span class="consumer-name">${name}</span>
          <div class="progress-track"><div class="progress-fill" style="width:${pct}%"></div></div>
          <span class="progress-val">${pct}%</span>
        </div>`).join('');
    }
  }catch(e){}
}
loadMetrics();setInterval(loadMetrics,5000);
</script>
</body></html>
