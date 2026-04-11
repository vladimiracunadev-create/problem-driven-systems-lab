<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caso 08 — Extracción de Módulo Crítico | Problem-Driven Systems Lab</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0e1a;--surface:#111827;--surface2:#1a2235;--border:#1e2d45;--accent:#3b82f6;--red:#ef4444;--green:#22c55e;--text:#e2e8f0;--muted:#64748b}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.header{background:linear-gradient(135deg,#05101a,#0a1e30,#0a0e1a);border-bottom:1px solid #0f2540;padding:28px 40px;display:flex;align-items:center;gap:20px}
.case-badge{background:var(--accent);color:#fff;font-weight:800;font-size:11px;padding:4px 10px;border-radius:6px}
.header-title{flex:1}.header-title h1{font-size:22px;font-weight:700;color:#fff}.header-title p{font-size:13px;color:#5a8aac;margin-top:4px}
.stack-badge{background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.3);color:var(--accent);font-size:11px;padding:4px 12px;border-radius:20px;font-weight:600}
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
.btn-solution{background:var(--accent);color:#fff}.btn-solution:hover{filter:brightness(1.1);transform:translateY(-1px)}
.btn-advance{background:rgba(59,130,246,.15);color:#60a5fa;border:1px solid rgba(59,130,246,.3);padding:8px 16px;font-size:12px}.btn-advance:hover{background:rgba(59,130,246,.25)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.spinner{width:14px;height:14px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;display:none}
.btn:disabled .spinner{display:inline-block}@keyframes spin{to{transform:rotate(360deg)}}
.result-box{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:16px;min-height:115px;margin-top:16px;position:relative}
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
.consumers-grid{margin-bottom:20px}
.consumer-row{display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:12px}
.consumer-row:last-child{border:none}.consumer-name{color:var(--muted);width:110px;flex-shrink:0;font-weight:600}
.progress-track{flex:1;height:8px;background:rgba(255,255,255,.05);border-radius:4px;overflow:hidden}
.progress-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,#3b82f6,#60a5fa);transition:width 1s ease}
.progress-val{width:40px;text-align:right;font-family:'JetBrains Mono',monospace;font-weight:700;color:#93c5fd;font-size:11px}
.metrics-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.metric-card{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px}
.metric-card .m-label{font-size:11px;color:var(--muted);margin-bottom:5px;font-weight:500}.metric-card .m-val{font-size:20px;font-weight:800;font-family:'JetBrains Mono',monospace}
footer{text-align:center;padding:32px;color:var(--muted);font-size:12px;border-top:1px solid var(--border);margin-top:40px}
</style>
</head>
<body>
<div class="header">
  <div><div class="case-badge">CASO 08</div></div>
  <div class="header-title">
    <h1>🔧 Extracción de módulo crítico sin romper operaciones</h1>
    <p>Arquitectura · Big bang vs. cutover gradual con proxy de compatibilidad y contratos</p>
  </div>
  <span class="stack-badge">PHP 8.3 · Proxy · Contratos</span>
</div>
<div class="container">
  <div class="cards-row">
    <div class="card problem">
      <div class="card-label">🔴 El Problema</div>
      <h3>Big bang: cortar el módulo de golpe amplifica incompatibilidades</h3>
      <p>La extracción big bang intenta mover todo el módulo de pricing de una sola vez. Solo funciona si todos los consumidores (checkout, app, backoffice, partners) tienen exactamente las mismas expectativas. Cualquier drift rompe producción.</p>
      <span class="tag tag-red">Alto blast radius · Sin proxy · Sin contratos · Roturas silenciosas</span>
    </div>
    <div class="card solution">
      <div class="card-label">✅ La Solución</div>
      <h3>Compatible: proxy de compatibilidad + cutover progresivo por consumidor</h3>
      <p>El modo compatible mueve consumidores de a 25% por vez, mantiene un proxy de compatibilidad que traduce entre versiones de contrato, y ejecuta sombra de tráfico para validar. Cada consumidor migra a su ritmo sin romper a los demás.</p>
      <span class="tag tag-green">Proxy de compatibilidad · Cutover gradual · Shadow traffic · Contratos</span>
    </div>
  </div>

  <div class="action-row">
    <div class="action-panel">
      <h4>🔴 Extracción Big Bang</h4>
      <div class="params">
        <div class="param-group"><label>Escenario</label>
          <select id="sc-bb">
            <option value="stable">stable – cambio estable</option>
            <option value="rule_drift" selected>rule_drift – regla cambia</option>
            <option value="shared_write">shared_write – escritura compartida</option>
            <option value="peak_sale">peak_sale – pico de ventas</option>
            <option value="partner_contract">partner_contract – contrato partner</option>
          </select>
        </div>
        <div class="param-group"><label>Consumidor</label>
          <select id="cs-bb"><option value="checkout" selected>checkout</option><option value="app">app</option><option value="backoffice">backoffice</option><option value="partner_api">partner_api</option></select>
        </div>
      </div>
      <button class="btn btn-legacy" id="btn-bb" onclick="runBigBang()">
        <div class="spinner" id="sp-bb"></div>▶ /pricing-bigbang
      </button>
      <div class="result-box" id="res-bb"><div class="result-empty">Ejecuta rule_drift y observa el error</div></div>
    </div>
    <div class="action-panel">
      <h4>✅ Extracción Compatible (gradual)</h4>
      <div class="params">
        <div class="param-group"><label>Escenario</label>
          <select id="sc-cp">
            <option value="stable">stable</option>
            <option value="rule_drift" selected>rule_drift</option>
            <option value="shared_write">shared_write</option>
            <option value="peak_sale">peak_sale</option>
            <option value="partner_contract">partner_contract</option>
          </select>
        </div>
        <div class="param-group"><label>Consumidor</label>
          <select id="cs-cp"><option value="checkout" selected>checkout</option><option value="app">app</option><option value="backoffice">backoffice</option><option value="partner_api">partner_api</option></select>
        </div>
      </div>
      <button class="btn btn-solution" id="btn-cp" onclick="runCompatible()">
        <div class="spinner" id="sp-cp"></div>▶ /pricing-compatible
      </button>
      <div class="result-box" id="res-cp"><div class="result-empty">Ejecuta varias veces para avanzar el cutover</div></div>
    </div>
  </div>

  <div class="metrics-section">
    <h4><span class="pulse"></span> Estado de extracción <span style="font-weight:400;color:#475569;font-size:11px;margin-left:8px">Auto-refresh 5s</span></h4>
    <div style="font-size:11px;font-weight:700;color:#60a5fa;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px">Progreso de cutover por consumidor</div>
    <div class="consumers-grid" id="consumers-list"></div>
    <div style="margin-bottom:12px">
      <button class="btn btn-advance" onclick="advanceCutover()">⟳ Forzar avance de cutover (checkout)</button>
    </div>
    <div class="metrics-row">
      <div class="metric-card"><div class="m-label">Proxy hits totales</div><div class="m-val" id="m-ph" style="color:#60a5fa">—</div></div>
      <div class="metric-card"><div class="m-label">Shadow traffic %</div><div class="m-val" id="m-st">—<span style="font-size:12px">%</span></div></div>
      <div class="metric-card"><div class="m-label">Tests contrato</div><div class="m-val" id="m-ct" style="color:#4ade80">—</div></div>
      <div class="metric-card"><div class="m-label">Cutover events</div><div class="m-val" id="m-ce">—</div></div>
    </div>
  </div>
</div>
<footer>Problem-Driven Systems Lab · Caso 08 · <a href="/extraction/state" style="color:var(--accent)">Estado extracción</a> · <a href="/reset-lab" style="color:var(--muted)">Reset</a></footer>

<script>
function setLoading(b,s,v){document.getElementById(b).disabled=v;document.getElementById(s).style.display=v?'inline-block':'none';}
function renderResult(boxId,data,isBigBang){
  const ok=data.status==='completed';
  document.getElementById(boxId).innerHTML=`
    <span class="result-status ${ok?'status-ok':'status-err'}">${ok?'✅ OK':'❌ ROTO'}</span>
    <div class="result-row"><span class="result-label">Blast radius</span><span class="result-val ${isBigBang?'val-bad':'val-good'}">${data.blast_radius_score??'—'}</span></div>
    <div class="result-row"><span class="result-label">Proxy hits</span><span class="result-val ${data.compatibility_proxy_hits>0?'val-good':'val-neutral'}">${data.compatibility_proxy_hits??0}</span></div>
    <div class="result-row"><span class="result-label">Progreso consumidor</span><span class="result-val val-neutral">${data.consumer_progress_after??'—'}%</span></div>
    <div class="result-row"><span class="result-label">Tiempo</span><span class="result-val val-neutral">${data.elapsed_ms??'—'} ms</span></div>
  `;
}
async function runBigBang(){
  const sc=document.getElementById('sc-bb').value,cs=document.getElementById('cs-bb').value;
  setLoading('btn-bb','sp-bb',true);
  try{const r=await fetch(`/pricing-bigbang?scenario=${sc}&consumer=${cs}`);const d=await r.json();renderResult('res-bb',d,true);}
  catch(e){document.getElementById('res-bb').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`;}
  setLoading('btn-bb','sp-bb',false);loadMetrics();
}
async function runCompatible(){
  const sc=document.getElementById('sc-cp').value,cs=document.getElementById('cs-cp').value;
  setLoading('btn-cp','sp-cp',true);
  try{const r=await fetch(`/pricing-compatible?scenario=${sc}&consumer=${cs}`);const d=await r.json();renderResult('res-cp',d,false);}
  catch(e){document.getElementById('res-cp').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`;}
  setLoading('btn-cp','sp-cp',false);loadMetrics();
}
async function advanceCutover(){
  await fetch('/cutover/advance?consumer=checkout');loadMetrics();
}
async function loadMetrics(){
  try{
    const r=await fetch('/extraction/state');const d=await r.json();
    const cl=document.getElementById('consumers-list');
    if(d.consumers&&Object.keys(d.consumers).length>0){
      cl.innerHTML=Object.entries(d.consumers).map(([name,pct])=>`<div class="consumer-row"><span class="consumer-name">${name}</span><div class="progress-track"><div class="progress-fill" style="width:${pct}%"></div></div><span class="progress-val">${pct}%</span></div>`).join('');
    }else{cl.innerHTML='<div style="color:var(--muted);font-size:13px">Ejecuta /pricing-compatible para iniciar</div>';}
    document.getElementById('m-ph').textContent=d.compatibility_proxy_hits??0;
    document.getElementById('m-st').textContent=d.shadow_traffic_percent??0;
    document.getElementById('m-ct').textContent=d.contract_tests??0;
    document.getElementById('m-ce').textContent=d.cutover_events??0;
  }catch(e){}
}
loadMetrics();setInterval(loadMetrics,5000);
</script>
</body></html>
