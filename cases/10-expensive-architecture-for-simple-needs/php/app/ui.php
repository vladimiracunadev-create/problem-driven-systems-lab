<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caso 10 — Arquitectura Costosa | Problem-Driven Systems Lab</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0e1a;--surface:#111827;--surface2:#1a2235;--border:#1e2d45;--accent:#f97316;--red:#ef4444;--green:#22c55e;--amber:#f59e0b;--text:#e2e8f0;--muted:#64748b}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.header{background:linear-gradient(135deg,#1a0c05,#2d1a0a,#0a0e1a);border-bottom:1px solid #3a1e00;padding:28px 40px;display:flex;align-items:center;gap:20px}
.case-badge{background:var(--accent);color:#fff;font-weight:800;font-size:11px;padding:4px 10px;border-radius:6px}
.header-title{flex:1}.header-title h1{font-size:22px;font-weight:700;color:#fff}.header-title p{font-size:13px;color:#a07060;margin-top:4px}
.stack-badge{background:rgba(249,115,22,.15);border:1px solid rgba(249,115,22,.3);color:var(--accent);font-size:11px;padding:4px 12px;border-radius:20px;font-weight:600}
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
.val-bad{background:rgba(239,68,68,.15);color:#f87171}.val-good{background:rgba(34,197,94,.15);color:#4ade80}.val-neutral{background:rgba(148,163,184,.1);color:#94a3b8}
.result-status{position:absolute;top:12px;right:12px;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px}
.status-ok{background:rgba(34,197,94,.15);color:#4ade80}.status-err{background:rgba(239,68,68,.15);color:#f87171}
.metrics-section{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:24px}
.metrics-section h4{font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:20px;display:flex;align-items:center;gap:8px}
.pulse{width:8px;height:8px;background:var(--green);border-radius:50%;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.3)}}
.compare-panel{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}
.compare-card{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:16px}
.compare-card.complex-c{border-color:rgba(239,68,68,.2)}.compare-card.right-c{border-color:rgba(34,197,94,.2)}
.compare-card h5{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px}
.compare-card.complex-c h5{color:#f87171}.compare-card.right-c h5{color:#4ade80}
.metric-row{display:flex;justify-content:space-between;font-size:12px;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.metric-row:last-child{border:none}.m-k{color:var(--muted)}.m-v{font-family:'JetBrains Mono',monospace;font-weight:600}
footer{text-align:center;padding:32px;color:var(--muted);font-size:12px;border-top:1px solid var(--border);margin-top:40px}
</style>
</head>
<body>
<div class="header">
  <div><div class="case-badge">CASO 10</div></div>
  <div class="header-title">
    <h1>⚙️ Arquitectura costosa para necesidades simples</h1>
    <p>Operaciones · Sobrearquitectura, demasiados servicios, alto costo operativo, coordinación excesiva</p>
  </div>
  <span class="stack-badge">PHP 8.3 · Architecture Decision</span>
</div>
<div class="container">
  <div class="cards-row">
    <div class="card problem">
      <div class="card-label">🔴 El Problema</div>
      <h3>Complex: 8 servicios, $5,400/mes y 11 días lead time para un CRUD simple</h3>
      <p>Aplicar una arquitectura de microservicios completa a una necesidad simple de gestión de cuentas: event bus, service mesh, orquestador, 3 databases separadas, 8 equipos coordinados. El overhead operativo supera enormemente el valor entregado.</p>
      <span class="tag tag-red">8 servicios · $5400/mes · 11 días · 7 puntos de coordinación</span>
    </div>
    <div class="card solution">
      <div class="card-label">✅ La Solución</div>
      <h3>Right-sized: 2 servicios, $850/mes y 3 días lead time</h3>
      <p>La arquitectura right-sized resuelve la misma necesidad con 2 servicios, una base de datos y 2 equipos. El problema fit score sube del 18% al 88%. La proporcionalidad entre el problema y la solución es la clave de la eficiencia.</p>
      <span class="tag tag-green">2 servicios · $850/mes · 3 días · 88% fit score</span>
    </div>
  </div>

  <div class="action-row">
    <div class="action-panel">
      <h4>🔴 Solución sobrearquitectada</h4>
      <div class="params">
        <div class="param-group"><label>Escenario</label>
          <select id="sc-cx">
            <option value="basic_crud" selected>basic_crud – CRUD simple</option>
            <option value="small_campaign">small_campaign – campaña pequeña</option>
            <option value="audit_needed">audit_needed – con auditoría</option>
            <option value="seasonal_peak">seasonal_peak – pico estacional</option>
          </select>
        </div>
        <div class="param-group"><label>Cuentas</label>
          <input id="acc-cx" type="number" value="120" min="10" max="2000" style="width:90px">
        </div>
      </div>
      <button class="btn btn-legacy" id="btn-cx" onclick="runComplex()">
        <div class="spinner" id="sp-cx"></div>▶ /feature-complex
      </button>
      <div class="result-box" id="res-cx"><div class="result-empty">Ejecuta y observa el costo real</div></div>
    </div>
    <div class="action-panel">
      <h4>✅ Solución proporcional al problema</h4>
      <div class="params">
        <div class="param-group"><label>Escenario</label>
          <select id="sc-rs">
            <option value="basic_crud" selected>basic_crud</option>
            <option value="small_campaign">small_campaign</option>
            <option value="audit_needed">audit_needed</option>
            <option value="seasonal_peak">seasonal_peak</option>
          </select>
        </div>
        <div class="param-group"><label>Cuentas</label>
          <input id="acc-rs" type="number" value="120" min="10" max="2000" style="width:90px">
        </div>
      </div>
      <button class="btn btn-solution" id="btn-rs" onclick="runRightSized()">
        <div class="spinner" id="sp-rs"></div>▶ /feature-right-sized
      </button>
      <div class="result-box" id="res-rs"><div class="result-empty">Compara el costo mensual y lead time</div></div>
    </div>
  </div>

  <div class="metrics-section">
    <h4><span class="pulse"></span> Comparativo de arquitectura <span style="font-weight:400;color:#475569;font-size:11px;margin-left:8px">Auto-refresh 5s</span></h4>
    <div class="compare-panel">
      <div class="compare-card complex-c">
        <h5>🔴 Complex (promedio acumulado)</h5>
        <div class="metric-row"><span class="m-k">Costo mensual</span><span class="m-v" id="cx-cost" style="color:#f87171">—</span></div>
        <div class="metric-row"><span class="m-k">Lead time</span><span class="m-v" id="cx-lead" style="color:#f87171">—</span></div>
        <div class="metric-row"><span class="m-k">Servicios tocados</span><span class="m-v" id="cx-svc">—</span></div>
        <div class="metric-row"><span class="m-k">Coordinación</span><span class="m-v" id="cx-coord">—</span></div>
        <div class="metric-row"><span class="m-k">Fallas</span><span class="m-v" id="cx-fail" style="color:#f87171">—</span></div>
      </div>
      <div class="compare-card right-c">
        <h5>✅ Right-sized (promedio acumulado)</h5>
        <div class="metric-row"><span class="m-k">Costo mensual</span><span class="m-v" id="rs-cost" style="color:#4ade80">—</span></div>
        <div class="metric-row"><span class="m-k">Lead time</span><span class="m-v" id="rs-lead" style="color:#4ade80">—</span></div>
        <div class="metric-row"><span class="m-k">Servicios tocados</span><span class="m-v" id="rs-svc">—</span></div>
        <div class="metric-row"><span class="m-k">Coordinación</span><span class="m-v" id="rs-coord">—</span></div>
        <div class="metric-row"><span class="m-k">Éxitos</span><span class="m-v" id="rs-ok" style="color:#4ade80">—</span></div>
      </div>
    </div>
  </div>
</div>
<footer>Problem-Driven Systems Lab · Caso 10 · <a href="/architecture/state" style="color:var(--accent)">Estado arquitectura</a> · <a href="/reset-lab" style="color:var(--muted)">Reset</a></footer>

<script>
function setLoading(b,s,v){document.getElementById(b).disabled=v;document.getElementById(s).style.display=v?'inline-block':'none';}
function renderResult(boxId,data,isComplex){
  const ok=data.status==='completed';
  document.getElementById(boxId).innerHTML=`
    <span class="result-status ${ok?'status-ok':'status-err'}">${ok?'✅ OK':'❌ FALLÓ (sobrecarga)'}</span>
    <div class="result-row"><span class="result-label">Costo mensual</span><span class="result-val ${isComplex?'val-bad':'val-good'}">$${data.monthly_cost_usd??'—'} USD</span></div>
    <div class="result-row"><span class="result-label">Lead time</span><span class="result-val ${isComplex?'val-bad':'val-good'}">${data.lead_time_days??'—'} días</span></div>
    <div class="result-row"><span class="result-label">Servicios tocados</span><span class="result-val ${isComplex?'val-bad':'val-good'}">${data.services_touched??'—'}</span></div>
    <div class="result-row"><span class="result-label">Problem fit</span><span class="result-val ${isComplex?'val-bad':'val-good'}">${data.problem_fit_score??'—'}%</span></div>
    <div class="result-row"><span class="result-label">Coordinación</span><span class="result-val ${isComplex?'val-bad':'val-good'}">${data.coordination_points??'—'} puntos</span></div>
  `;
}
async function runComplex(){
  const sc=document.getElementById('sc-cx').value,acc=document.getElementById('acc-cx').value;
  setLoading('btn-cx','sp-cx',true);
  try{const r=await fetch(`/feature-complex?scenario=${sc}&accounts=${acc}`);const d=await r.json();renderResult('res-cx',d,true);}
  catch(e){document.getElementById('res-cx').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`;}
  setLoading('btn-cx','sp-cx',false);loadMetrics();
}
async function runRightSized(){
  const sc=document.getElementById('sc-rs').value,acc=document.getElementById('acc-rs').value;
  setLoading('btn-rs','sp-rs',true);
  try{const r=await fetch(`/feature-right-sized?scenario=${sc}&accounts=${acc}`);const d=await r.json();renderResult('res-rs',d,false);}
  catch(e){document.getElementById('res-rs').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`;}
  setLoading('btn-rs','sp-rs',false);loadMetrics();
}
async function loadMetrics(){
  try{
    const r=await fetch('/diagnostics/summary');const d=await r.json();
    const ms=d.metrics?.modes??{};
    const cx=ms.complex??{},rs=ms.right_sized??{};
    document.getElementById('cx-cost').textContent=cx.avg_monthly_cost_usd?`$${cx.avg_monthly_cost_usd.toFixed(0)}`:'—';
    document.getElementById('cx-lead').textContent=cx.avg_lead_time_days?`${cx.avg_lead_time_days}d`:'—';
    document.getElementById('cx-svc').textContent=cx.avg_services_touched?.toFixed(1)??'—';
    document.getElementById('cx-coord').textContent=cx.avg_coordination_points?.toFixed(1)??'—';
    document.getElementById('cx-fail').textContent=cx.failures??0;
    document.getElementById('rs-cost').textContent=rs.avg_monthly_cost_usd?`$${rs.avg_monthly_cost_usd.toFixed(0)}`:'—';
    document.getElementById('rs-lead').textContent=rs.avg_lead_time_days?`${rs.avg_lead_time_days}d`:'—';
    document.getElementById('rs-svc').textContent=rs.avg_services_touched?.toFixed(1)??'—';
    document.getElementById('rs-coord').textContent=rs.avg_coordination_points?.toFixed(1)??'—';
    document.getElementById('rs-ok').textContent=rs.successes??0;
  }catch(e){}
}
loadMetrics();setInterval(loadMetrics,5000);
</script>
</body></html>
