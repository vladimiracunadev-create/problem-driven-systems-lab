<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caso 09 — Integración Externa Inestable | Problem-Driven Systems Lab</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0e1a;--surface:#111827;--surface2:#1a2235;--border:#1e2d45;--accent:#ef4444;--red:#ef4444;--green:#22c55e;--amber:#f59e0b;--text:#e2e8f0;--muted:#64748b}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.header{background:linear-gradient(135deg,#1a0505,#2a0808,#0a0e1a);border-bottom:1px solid #3d0d0d;padding:28px 40px;display:flex;align-items:center;gap:20px}
.case-badge{background:var(--red);color:#fff;font-weight:800;font-size:11px;padding:4px 10px;border-radius:6px}
.header-title{flex:1}.header-title h1{font-size:22px;font-weight:700;color:#fff}.header-title p{font-size:13px;color:#c09090;margin-top:4px}
.stack-badge{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);color:var(--red);font-size:11px;padding:4px 12px;border-radius:20px;font-weight:600}
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
.param-group select,.param-group input{background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:7px 12px;border-radius:8px;font-size:13px}
.btn{padding:11px 24px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;border:none;transition:all .2s;display:inline-flex;align-items:center;gap:8px}
.btn-legacy{background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3)}.btn-legacy:hover{background:rgba(239,68,68,.25)}
.btn-solution{background:var(--green);color:#000}.btn-solution:hover{filter:brightness(1.1);transform:translateY(-1px)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.spinner{width:14px;height:14px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;display:none}
.btn:disabled .spinner{display:inline-block}@keyframes spin{to{transform:rotate(360deg)}}
.result-box{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:16px;min-height:130px;margin-top:16px;position:relative}
.result-empty{color:var(--muted);font-size:13px;text-align:center;padding:24px 0}
.result-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px}
.result-row:last-child{border:none}.result-label{color:var(--muted);font-weight:500}
.result-val{font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:600;padding:3px 8px;border-radius:5px}
.val-bad{background:rgba(239,68,68,.15);color:#f87171}.val-good{background:rgba(34,197,94,.15);color:#4ade80}.val-neutral{background:rgba(148,163,184,.1);color:#94a3b8}.val-warn{background:rgba(245,158,11,.15);color:#fbbf24}
.result-status{position:absolute;top:12px;right:12px;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px}
.status-ok{background:rgba(34,197,94,.15);color:#4ade80}.status-err{background:rgba(239,68,68,.15);color:#f87171}
.metrics-section{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:24px}
.metrics-section h4{font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:20px;display:flex;align-items:center;gap:8px}
.pulse{width:8px;height:8px;background:var(--green);border-radius:50%;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.3)}}
.provider-panel{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px}
.prov-card{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:14px;text-align:center}
.prov-card .p-label{font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.prov-card .p-val{font-size:20px;font-weight:800;font-family:'JetBrains Mono',monospace}
.budget-bar{height:10px;background:rgba(255,255,255,.05);border-radius:5px;overflow:hidden;margin-top:8px}
.budget-fill{height:100%;border-radius:5px;transition:width 1s ease}
.metrics-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.metric-card{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px}
.metric-card .m-label{font-size:11px;color:var(--muted);margin-bottom:5px;font-weight:500}.metric-card .m-val{font-size:20px;font-weight:800;font-family:'JetBrains Mono',monospace}
footer{text-align:center;padding:32px;color:var(--muted);font-size:12px;border-top:1px solid var(--border);margin-top:40px}
</style>
</head>
<body>
<div class="header">
  <div><div class="case-badge">CASO 09</div></div>
  <div class="header-title">
    <h1>🛡️ Integración externa inestable</h1>
    <p>Resiliencia · Schema drift, rate limiting, payloads parciales, ventanas de mantenimiento del proveedor</p>
  </div>
  <span class="stack-badge">PHP 8.3 · Adapter · Cache</span>
</div>
<div class="container">
  <div class="cards-row">
    <div class="card problem">
      <div class="card-label">🔴 El Problema</div>
      <h3>Legacy depende del proveedor directamente — cualquier cambio rompe</h3>
      <p>La integración legacy llama al proveedor sin adaptador, sin cache y consume 3 unidades de cuota por request. Si el proveedor cambia su schema, aplica rate limit, devuelve datos parciales o entra en mantenimiento, la operación de negocio falla completamente.</p>
      <span class="tag tag-red">Sin adaptador · 3 cuota/request · Sin cache · Sin fallback local</span>
    </div>
    <div class="card solution">
      <div class="card-label">✅ La Solución</div>
      <h3>Hardened: adaptador + cache de snapshot + protección de schema</h3>
      <p>El adaptador traduce entre versiones del contrato del proveedor. El cache guarda un snapshot válido y lo sirve cuando el proveedor no está disponible. Solo consume 1 unidad de cuota. Los cambios de schema se mapean automáticamente.</p>
      <span class="tag tag-green">Adaptador · 1 cuota/request · Snapshot cache · Schema mapping</span>
    </div>
  </div>

  <div class="action-row">
    <div class="action-panel">
      <h4>🔴 Integración sin protección</h4>
      <div class="params">
        <div class="param-group"><label>Escenario</label>
          <select id="sc-leg">
            <option value="ok">ok – proveedor estable</option>
            <option value="schema_drift" selected>schema_drift – schema cambia</option>
            <option value="rate_limited">rate_limited – cuota agotada</option>
            <option value="partial_payload">partial_payload – payload incompleto</option>
            <option value="maintenance_window">maintenance_window – en mantenimiento</option>
          </select>
        </div>
        <div class="param-group"><label>SKU</label>
          <input id="sku-leg" type="text" value="SKU-100" style="width:100px">
        </div>
      </div>
      <button class="btn btn-legacy" id="btn-leg" onclick="runLegacy()">
        <div class="spinner" id="sp-leg"></div>▶ /catalog-legacy
      </button>
      <div class="result-box" id="res-leg"><div class="result-empty">Ejecuta schema_drift y observa el 502</div></div>
    </div>
    <div class="action-panel">
      <h4>✅ Integración endurecida</h4>
      <div class="params">
        <div class="param-group"><label>Escenario</label>
          <select id="sc-hrd">
            <option value="ok">ok</option>
            <option value="schema_drift" selected>schema_drift</option>
            <option value="rate_limited">rate_limited</option>
            <option value="partial_payload">partial_payload</option>
            <option value="maintenance_window">maintenance_window</option>
          </select>
        </div>
        <div class="param-group"><label>SKU</label>
          <input id="sku-hrd" type="text" value="SKU-100" style="width:100px">
        </div>
      </div>
      <button class="btn btn-solution" id="btn-hrd" onclick="runHardened()">
        <div class="spinner" id="sp-hrd"></div>▶ /catalog-hardened
      </button>
      <div class="result-box" id="res-hrd"><div class="result-empty">El mismo escenario — mantiene continuidad</div></div>
    </div>
  </div>

  <div class="metrics-section">
    <h4><span class="pulse"></span> Estado del proveedor e integración <span style="font-weight:400;color:#475569;font-size:11px;margin-left:8px">Auto-refresh 5s</span></h4>
    <div class="provider-panel">
      <div class="prov-card">
        <div class="p-label">Rate limit budget</div>
        <div class="p-val" id="pv-budget" style="color:#fbbf24">—</div>
        <div class="budget-bar"><div class="budget-fill" id="budget-bar" style="width:0%;background:linear-gradient(90deg,#ef4444,#fbbf24)"></div></div>
      </div>
      <div class="prov-card">
        <div class="p-label">Cache age</div>
        <div class="p-val" id="pv-cache" style="color:#60a5fa">—</div>
        <div style="font-size:11px;color:var(--muted);margin-top:4px">segundos</div>
      </div>
      <div class="prov-card">
        <div class="p-label">Eventos en cuarentena</div>
        <div class="p-val" id="pv-quar" style="color:#f87171">—</div>
      </div>
    </div>
    <div class="metrics-grid">
      <div class="metric-card"><div class="m-label">Fallas legacy</div><div class="m-val" id="m-fl" style="color:#f87171">—</div></div>
      <div class="metric-card"><div class="m-label">Éxitos hardened</div><div class="m-val" id="m-sh" style="color:#4ade80">—</div></div>
      <div class="metric-card"><div class="m-label">Schema mappings</div><div class="m-val" id="m-sm" style="color:#60a5fa">—</div></div>
      <div class="metric-card"><div class="m-label">Cuota ahorrada</div><div class="m-val" id="m-qs" style="color:#4ade80">—</div></div>
    </div>
  </div>
</div>
<footer>Problem-Driven Systems Lab · Caso 09 · <a href="/integration/state" style="color:var(--accent)">Estado integración</a> · <a href="/reset-lab" style="color:var(--muted)">Reset</a></footer>

<script>
function setLoading(b,s,v){document.getElementById(b).disabled=v;document.getElementById(s).style.display=v?'inline-block':'none';}
function renderResult(boxId,data,isLegacy){
  const ok=data.status==='completed';
  document.getElementById(boxId).innerHTML=`
    <span class="result-status ${ok?'status-ok':'status-err'}">${ok?'✅ OK':'❌ FALLIDO'}</span>
    <div class="result-row"><span class="result-label">Tiempo</span><span class="result-val val-neutral">${data.elapsed_ms??'—'} ms</span></div>
    <div class="result-row"><span class="result-label">Fuente respuesta</span><span class="result-val ${data.product?.source==='cached_snapshot'?'val-warn':data.product?.source==='live_provider'?'val-good':'val-neutral'}">${data.product?.source??'—'}</span></div>
    <div class="result-row"><span class="result-label">Schema mapeado</span><span class="result-val ${data.schema_protected?'val-good':'val-neutral'}">${data.schema_protected?'✅ Sí':'No'}</span></div>
    <div class="result-row"><span class="result-label">Cache usado</span><span class="result-val ${data.cached_response?'val-warn':'val-neutral'}">${data.cached_response?'Sí (snapshot)':'No'}</span></div>
    <div class="result-row"><span class="result-label">Cuota consumida</span><span class="result-val ${isLegacy?'val-bad':'val-good'}">${isLegacy?'3 unidades':'1 unidad'}</span></div>
    ${data.error?`<div class="result-row"><span class="result-label">Error</span><span class="result-val val-bad" style="font-size:10px">${data.error.substring(0,60)}</span></div>`:''}
  `;
}
async function runLegacy(){
  const sc=document.getElementById('sc-leg').value,sku=document.getElementById('sku-leg').value.toUpperCase();
  setLoading('btn-leg','sp-leg',true);
  try{const r=await fetch(`/catalog-legacy?scenario=${sc}&sku=${sku}`);const d=await r.json();renderResult('res-leg',d,true);}
  catch(e){document.getElementById('res-leg').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`;}
  setLoading('btn-leg','sp-leg',false);loadMetrics();
}
async function runHardened(){
  const sc=document.getElementById('sc-hrd').value,sku=document.getElementById('sku-hrd').value.toUpperCase();
  setLoading('btn-hrd','sp-hrd',true);
  try{const r=await fetch(`/catalog-hardened?scenario=${sc}&sku=${sku}`);const d=await r.json();renderResult('res-hrd',d,false);}
  catch(e){document.getElementById('res-hrd').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`;}
  setLoading('btn-hrd','sp-hrd',false);loadMetrics();
}
async function loadMetrics(){
  try{
    const r=await fetch('/diagnostics/summary');const d=await r.json();
    const intg=d.integration??{},ms=d.metrics?.modes??{};
    const budget=intg.rate_limit_budget??0;
    document.getElementById('pv-budget').textContent=budget;
    document.getElementById('budget-bar').style.width=`${(budget/12)*100}%`;
    document.getElementById('pv-cache').textContent=intg.cache?.age_seconds??'—';
    document.getElementById('pv-quar').textContent=intg.quarantine_events??0;
    document.getElementById('m-fl').textContent=ms.legacy?.failures??0;
    document.getElementById('m-sh').textContent=ms.hardened?.successes??0;
    document.getElementById('m-sm').textContent=intg.contract?.schema_mappings??0;
    const qs=(ms.hardened?.avg_quota_saved??0)*(ms.hardened?.successes??0);
    document.getElementById('m-qs').textContent=qs.toFixed(0);
  }catch(e){}
}
loadMetrics();setInterval(loadMetrics,5000);
</script>
</body></html>
