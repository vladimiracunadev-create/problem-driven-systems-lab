<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caso 11 — Reporting Pesado | Problem-Driven Systems Lab</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0e1a;--surface:#111827;--surface2:#1a2235;--border:#1e2d45;--accent:#f97316;--red:#ef4444;--green:#22c55e;--amber:#f59e0b;--text:#e2e8f0;--muted:#64748b}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.header{background:linear-gradient(135deg,#1a0c05,#2d1500,#0a0e1a);border-bottom:1px solid #3a1500;padding:28px 40px;display:flex;align-items:center;gap:20px}
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
.btn-write{background:rgba(249,115,22,.15);color:#fb923c;border:1px solid rgba(249,115,22,.3);padding:9px 18px;font-size:12px;font-weight:600}.btn-write:hover{background:rgba(249,115,22,.25)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.spinner{width:14px;height:14px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;display:none}
.btn:disabled .spinner{display:inline-block}@keyframes spin{to{transform:rotate(360deg)}}
.result-box{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:16px;min-height:120px;margin-top:16px;position:relative}
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
.pressure-gauges{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:14px;margin-bottom:20px}
.gauge-card{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:14px}
.gauge-card .g-label{font-size:11px;color:var(--muted);font-weight:500;margin-bottom:8px}
.gauge-track{height:8px;background:rgba(255,255,255,.05);border-radius:4px;overflow:hidden;margin-bottom:6px}
.gauge-fill{height:100%;border-radius:4px;transition:width 1s ease}
.gauge-val{font-family:'JetBrains Mono',monospace;font-size:14px;font-weight:700}
.pressure-badge{display:inline-block;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;margin-top:8px}
.pressure-healthy{background:rgba(34,197,94,.15);color:#4ade80}.pressure-warning{background:rgba(245,158,11,.15);color:#fbbf24}.pressure-critical{background:rgba(239,68,68,.15);color:#f87171}
footer{text-align:center;padding:32px;color:var(--muted);font-size:12px;border-top:1px solid var(--border);margin-top:40px}
</style>
</head>
<body>
<div class="header">
  <div><div class="case-badge">CASO 11</div></div>
  <div class="header-title">
    <h1>📊 Reporting pesado bloquea operaciones</h1>
    <p>Operaciones · Analitica y transaccional compitiendo sobre el mismo primario — locks y degradación</p>
  </div>
  <span class="stack-badge">PHP 8.3 · DB Isolation · Replica</span>
</div>
<div class="container">
  <div class="cards-row">
    <div class="card problem">
      <div class="card-label">🔴 El Problema</div>
      <h3>Legacy: el reporte ejecuta sobre el primario y degrada las escrituras</h3>
      <p>Cuando el reporte de fin de mes se ejecuta directamente sobre la base de datos transaccional, la carga del primario sube +52%, los locks crecen +34%, y las escrituras de operación (nuevos pedidos) se degradan. En escenario crítico, el servicio falla.</p>
      <span class="tag tag-red">+52% carga primario · +34% lock pressure · Degrada escrituras</span>
    </div>
    <div class="card solution">
      <div class="card-label">✅ La Solución</div>
      <h3>Isolated: cola de reporte + réplica — el primario conserva su capacidad</h3>
      <p>El modo aislado envía el reporte a una cola y lo ejecuta sobre una réplica de lectura o un snapshot. La carga al primario es mínima (+8% vs +52%). Las escrituras transaccionales no se ven afectadas aunque el reporte tome 10 minutos.</p>
      <span class="tag tag-green">+8% carga primario · Réplica/cola · Escrituras protegidas</span>
    </div>
  </div>

  <div class="action-row">
    <div class="action-panel">
      <h4>🔴 Reporte directo sobre primario</h4>
      <div class="params">
        <div class="param-group"><label>Escenario</label>
          <select id="sc-leg">
            <option value="end_of_month" selected>end_of_month – fin de mes</option>
            <option value="finance_audit">finance_audit – auditoría</option>
            <option value="ad_hoc_export">ad_hoc_export – export ad-hoc</option>
            <option value="mixed_peak">mixed_peak – pico mixto</option>
          </select>
        </div>
        <div class="param-group"><label>Filas</label>
          <select id="rows-leg">
            <option value="200000">200K</option>
            <option value="600000" selected>600K</option>
            <option value="1200000">1.2M</option>
          </select>
        </div>
      </div>
      <button class="btn btn-legacy" id="btn-leg" onclick="runLegacy()">
        <div class="spinner" id="sp-leg"></div>▶ /report-legacy
      </button>
      <div class="result-box" id="res-leg"><div class="result-empty">Ejecuta y observa cómo sube la presión</div></div>
    </div>
    <div class="action-panel">
      <h4>✅ Reporte aislado con réplica</h4>
      <div class="params">
        <div class="param-group"><label>Escenario</label>
          <select id="sc-iso">
            <option value="end_of_month" selected>end_of_month</option>
            <option value="finance_audit">finance_audit</option>
            <option value="ad_hoc_export">ad_hoc_export</option>
            <option value="mixed_peak">mixed_peak</option>
          </select>
        </div>
        <div class="param-group"><label>Filas</label>
          <select id="rows-iso">
            <option value="200000">200K</option>
            <option value="600000" selected>600K</option>
            <option value="1200000">1.2M</option>
          </select>
        </div>
      </div>
      <button class="btn btn-solution" id="btn-iso" onclick="runIsolated()">
        <div class="spinner" id="sp-iso"></div>▶ /report-isolated
      </button>
      <div class="result-box" id="res-iso"><div class="result-empty">Observa cómo el primario queda protegido</div></div>
    </div>
  </div>

  <div class="metrics-section">
    <h4><span class="pulse"></span> Presión del sistema en tiempo real <span style="font-weight:400;color:#475569;font-size:11px;margin-left:8px">Auto-refresh 4s</span></h4>
    <div class="pressure-gauges">
      <div class="gauge-card">
        <div class="g-label">Carga primario</div>
        <div class="gauge-track"><div class="gauge-fill" id="g-load" style="width:0%;background:linear-gradient(90deg,#22c55e,#ef4444)"></div></div>
        <div class="gauge-val" id="g-load-v">—<span style="font-size:12px">%</span></div>
      </div>
      <div class="gauge-card">
        <div class="g-label">Lock pressure</div>
        <div class="gauge-track"><div class="gauge-fill" id="g-lock" style="width:0%;background:linear-gradient(90deg,#fbbf24,#ef4444)"></div></div>
        <div class="gauge-val" id="g-lock-v">—<span style="font-size:12px">%</span></div>
      </div>
      <div class="gauge-card">
        <div class="g-label">Réplica lag</div>
        <div class="gauge-track"><div class="gauge-fill" id="g-lag" style="width:0%;background:linear-gradient(90deg,#3b82f6,#8b5cf6)"></div></div>
        <div class="gauge-val" id="g-lag-v">—<span style="font-size:12px">s</span></div>
      </div>
      <div class="gauge-card">
        <div class="g-label">Cola de reporte</div>
        <div class="gauge-track"><div class="gauge-fill" id="g-queue" style="width:0%;background:linear-gradient(90deg,#22c55e,#fbbf24)"></div></div>
        <div class="gauge-val" id="g-queue-v">—</div>
      </div>
    </div>
    <div style="margin-bottom:12px;display:flex;align-items:center;gap:12px">
      <div id="pressure-badge"></div>
      <button class="btn btn-write" onclick="runWrite()"><div class="spinner" id="sp-wr"></div>Simular escritura transaccional (25 órdenes)</button>
    </div>
    <div id="write-result"></div>
  </div>
</div>
<footer>Problem-Driven Systems Lab · Caso 11 · <a href="/reporting/state" style="color:var(--accent)">Estado reporting</a> · <a href="/reset-lab" style="color:var(--muted)">Reset</a></footer>

<script>
function setLoading(b,s,v){document.getElementById(b).disabled=v;document.getElementById(s).style.display=v?'inline-block':'none';}
function pressureClass(l){return 'pressure-'+(l==='critical'?'critical':l==='warning'?'warning':'healthy');}
function renderResult(boxId,data,isLegacy){
  const ok=data.status==='completed';
  document.getElementById(boxId).innerHTML=`
    <span class="result-status ${ok?'status-ok':'status-err'}">${ok?'✅ OK':'❌ PRIMARIO DEGRADADO'}</span>
    <div class="result-row"><span class="result-label">Carga antes → después</span><span class="result-val ${isLegacy?'val-bad':'val-good'}">${data.primary_load_before??'—'} → ${data.primary_load_after??'—'}%</span></div>
    <div class="result-row"><span class="result-label">Lock antes → después</span><span class="result-val ${isLegacy?'val-bad':'val-neutral'}">${data.lock_pressure_before??'—'} → ${data.lock_pressure_after??'—'}%</span></div>
    <div class="result-row"><span class="result-label">Impacto en escrituras</span><span class="result-val ${isLegacy?'val-bad':'val-good'}">${data.ops_latency_impact_ms??'—'} ms</span></div>
    <div class="result-row"><span class="result-label">Tiempo total</span><span class="result-val val-neutral">${data.elapsed_ms??'—'} ms</span></div>
  `;
}
async function runLegacy(){
  setLoading('btn-leg','sp-leg',true);
  const sc=document.getElementById('sc-leg').value,rows=document.getElementById('rows-leg').value;
  try{const r=await fetch(`/report-legacy?scenario=${sc}&rows=${rows}`);const d=await r.json();renderResult('res-leg',d,true);}
  catch(e){document.getElementById('res-leg').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`;}
  setLoading('btn-leg','sp-leg',false);loadMetrics();
}
async function runIsolated(){
  setLoading('btn-iso','sp-iso',true);
  const sc=document.getElementById('sc-iso').value,rows=document.getElementById('rows-iso').value;
  try{const r=await fetch(`/report-isolated?scenario=${sc}&rows=${rows}`);const d=await r.json();renderResult('res-iso',d,false);}
  catch(e){document.getElementById('res-iso').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`;}
  setLoading('btn-iso','sp-iso',false);loadMetrics();
}
async function runWrite(){
  setLoading('btn-write','sp-wr',true);
  try{
    const r=await fetch('/order-write?orders=25');const d=await r.json();
    const el=document.getElementById('write-result');
    el.innerHTML=`<div style="background:${d.status==='completed'?'rgba(34,197,94,.08)':'rgba(239,68,68,.08)'};border:1px solid ${d.status==='completed'?'rgba(34,197,94,.2)':'rgba(239,68,68,.2)'};border-radius:8px;padding:10px 14px;font-size:12px;font-family:'JetBrains Mono',monospace;margin-top:8px">
      ✍️ Escritura: ${d.status==='completed'?'✅ OK':'❌ DEGRADADA'} · latencia: ${d.write_latency_ms??'—'} ms</div>`;
  }catch(e){}
  setLoading('btn-write','sp-wr',false);loadMetrics();
}
async function loadMetrics(){
  try{
    const r=await fetch('/reporting/state');const d=await r.json();
    const load=d.primary_load??0,lock=d.lock_pressure??0,lag=d.replica_lag_s??0,queue=d.queue_depth??0;
    document.getElementById('g-load').style.width=`${load}%`;
    document.getElementById('g-load-v').innerHTML=`${load}<span style="font-size:12px">%</span>`;
    document.getElementById('g-lock').style.width=`${lock}%`;
    document.getElementById('g-lock-v').innerHTML=`${lock}<span style="font-size:12px">%</span>`;
    document.getElementById('g-lag').style.width=`${Math.min(100,(lag/180)*100)}%`;
    document.getElementById('g-lag-v').innerHTML=`${lag}<span style="font-size:12px">s</span>`;
    document.getElementById('g-queue').style.width=`${Math.min(100,(queue/120)*100)}%`;
    document.getElementById('g-queue-v').textContent=queue;
    const pl=d.pressure_level??'healthy';
    document.getElementById('pressure-badge').innerHTML=`<span class="pressure-badge ${pressureClass(pl)}">${pl==='critical'?'🔴 PRESIÓN CRÍTICA':pl==='warning'?'⚠ ADVERTENCIA':'🟢 SALUDABLE'}</span>`;
  }catch(e){}
}
loadMetrics();setInterval(loadMetrics,4000);
</script>
</body></html>
