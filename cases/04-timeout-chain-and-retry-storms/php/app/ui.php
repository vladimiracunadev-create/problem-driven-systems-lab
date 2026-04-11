<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caso 04 — Timeout Chain y Retry Storms | Problem-Driven Systems Lab</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0e1a;--surface:#111827;--surface2:#1a2235;--border:#1e2d45;--accent:#ef4444;--accent2:#f87171;--red:#ef4444;--green:#22c55e;--amber:#f59e0b;--text:#e2e8f0;--muted:#64748b}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.header{background:linear-gradient(135deg,#1a0505,#300a0a,#0a0e1a);border-bottom:1px solid #400d0d;padding:28px 40px;display:flex;align-items:center;gap:20px}
.case-badge{background:var(--red);color:#fff;font-weight:800;font-size:11px;padding:4px 10px;border-radius:6px;letter-spacing:.5px}
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
.param-group select{background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:7px 12px;border-radius:8px;font-size:13px}
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
.status-ok{background:rgba(34,197,94,.15);color:#4ade80}.status-err{background:rgba(239,68,68,.15);color:#f87171}.status-deg{background:rgba(245,158,11,.15);color:#fbbf24}
.steps-box{margin-top:10px}
.step-item{display:flex;align-items:center;gap:8px;padding:5px 0;font-size:11px;border-bottom:1px solid rgba(255,255,255,.04)}
.step-item:last-child{border:none}.step-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.step-ok{background:#22c55e}.step-err{background:#ef4444}.step-wait{background:#f59e0b}
.step-name{font-family:'JetBrains Mono',monospace}.step-meta{color:var(--muted)}.step-latency{margin-left:auto;font-family:'JetBrains Mono',monospace;color:#60a5fa;font-size:11px}
.metrics-section{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:24px}
.metrics-section h4{font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:20px;display:flex;align-items:center;gap:8px}
.pulse{width:8px;height:8px;background:var(--green);border-radius:50%;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.3)}}
.metrics-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:16px}
.metric-card{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:16px}
.metric-card .m-label{font-size:11px;color:var(--muted);margin-bottom:6px;font-weight:500}.metric-card .m-val{font-size:20px;font-weight:800;font-family:'JetBrains Mono',monospace}
.circuit-panel{background:rgba(239,68,68,.05);border:1px solid rgba(239,68,68,.2);border-radius:12px;padding:16px;margin-top:20px}
.circuit-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;font-size:13px;border-bottom:1px solid rgba(255,255,255,.05)}
.circuit-row:last-child{border:none}
footer{text-align:center;padding:32px;color:var(--muted);font-size:12px;border-top:1px solid var(--border);margin-top:40px}
</style>
</head>
<body>
<div class="header">
  <div><div class="case-badge">CASO 04</div></div>
  <div class="header-title">
    <h1>⏱️ Cadena de timeouts y tormentas de reintentos</h1>
    <p>Resiliencia · Reintentos sin control, timeouts mal calibrados, sin circuit breaker, sin fallback</p>
  </div>
  <span class="stack-badge">PHP 8.3 · Circuit Breaker</span>
</div>
<div class="container">
  <div class="cards-row">
    <div class="card problem">
      <div class="card-label">🔴 El Problema</div>
      <h3>Legacy: 4 reintentos × 360ms timeout = 1440ms mínimo por falla</h3>
      <p>Cuando el proveedor falla, el sistema reintenta 4 veces sin backoff, cada reintento espera 360ms completos. Si 100 usuarios hacen esto simultáneamente, el proveedor recibe 400 llamadas adicionales. El sistema amplifica la falla en vez de contenerla.</p>
      <span class="tag tag-red">4 reintentos · Sin backoff · Sin circuit · 1440ms mínimo</span>
    </div>
    <div class="card solution">
      <div class="card-label">✅ La Solución</div>
      <h3>Resilient: timeout corto + backoff exponencial + circuit breaker + fallback</h3>
      <p>Timeout de 220ms, máximo 2 reintentos con backoff exponencial con jitter. Después de 2 fallas consecutivas el circuit breaker se abre por 30 segundos. Si hay fallback disponible (cotización cacheada), se usa automáticamente. La degradación queda contenida.</p>
      <span class="tag tag-green">220ms timeout · 2 reintentos · Circuit breaker · Fallback</span>
    </div>
  </div>

  <div class="action-row">
    <div class="action-panel">
      <h4>🔴 Ruta Legacy con retries agresivos</h4>
      <div class="params">
        <div class="param-group"><label>Escenario</label>
          <select id="sc-leg">
            <option value="ok">ok – proveedor estable</option>
            <option value="slow_provider">slow_provider – lento</option>
            <option value="flaky_provider">flaky_provider – inestable</option>
            <option value="provider_down" selected>provider_down – caído</option>
            <option value="burst_then_recover">burst_then_recover – pico y recupera</option>
          </select>
        </div>
      </div>
      <button class="btn btn-legacy" id="btn-leg" onclick="runLegacy()">
        <div class="spinner" id="sp-leg"></div>▶ /quote-legacy
      </button>
      <div class="result-box" id="res-leg"><div class="result-empty">Ejecuta provider_down y mide el costo total</div></div>
    </div>
    <div class="action-panel">
      <h4>✅ Ruta Resilient con circuit breaker</h4>
      <div class="params">
        <div class="param-group"><label>Escenario</label>
          <select id="sc-res">
            <option value="ok">ok – proveedor estable</option>
            <option value="slow_provider">slow_provider – lento</option>
            <option value="flaky_provider">flaky_provider – inestable</option>
            <option value="provider_down" selected>provider_down – caído</option>
            <option value="burst_then_recover">burst_then_recover – pico y recupera</option>
          </select>
        </div>
      </div>
      <button class="btn btn-solution" id="btn-res" onclick="runResilient()">
        <div class="spinner" id="sp-res"></div>▶ /quote-resilient
      </button>
      <div class="result-box" id="res-res"><div class="result-empty">Ejecuta y observa circuit breaker + fallback</div></div>
    </div>
  </div>

  <div class="metrics-section">
    <h4><span class="pulse"></span> Circuit Breaker y Métricas <span style="font-weight:400;color:#475569;font-size:11px;margin-left:8px">Auto-refresh 5s</span></h4>
    <div class="metrics-grid">
      <div class="metric-card"><div class="m-label">Requests totales</div><div class="m-val" id="m-total">—</div></div>
      <div class="metric-card"><div class="m-label">Avg intentos (legacy)</div><div class="m-val" id="m-att" style="color:#f87171">—</div></div>
      <div class="metric-card"><div class="m-label">Fallbacks usados</div><div class="m-val" id="m-fb" style="color:#fbbf24">—</div></div>
      <div class="metric-card"><div class="m-label">Circuit opens</div><div class="m-val" id="m-co" style="color:#ef4444">—</div></div>
    </div>
    <div class="circuit-panel" id="circuit-panel">
      <div style="font-size:12px;font-weight:700;color:#f87171;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px">⚡ Estado del Circuit Breaker</div>
      <div class="circuit-row"><span style="color:var(--muted)">Estado actual</span><span id="cb-status" style="font-weight:700">—</span></div>
      <div class="circuit-row"><span style="color:var(--muted)">Fallas consecutivas</span><span id="cb-fails" style="font-family:'JetBrains Mono',monospace;color:#f87171">—</span></div>
      <div class="circuit-row"><span style="color:var(--muted)">Abierto hasta</span><span id="cb-until" style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#fbbf24">—</span></div>
      <div class="circuit-row"><span style="color:var(--muted)">Short-circuits</span><span id="cb-sc" style="font-family:'JetBrains Mono',monospace;color:#fb923c">—</span></div>
      <div class="circuit-row"><span style="color:var(--muted)">Fallback disponible</span><span id="cb-fb" style="font-weight:700">—</span></div>
    </div>
  </div>
</div>
<footer>Problem-Driven Systems Lab · Caso 04 · <a href="/dependency/state" style="color:var(--accent)">Estado dependencia</a> · <a href="/reset-lab" style="color:var(--muted)">Reset</a></footer>

<script>
function setLoading(b,s,v){document.getElementById(b).disabled=v;document.getElementById(s).style.display=v?'inline-block':'none';}
function renderSteps(events){
  if(!events?.length)return '';
  return '<div class="steps-box">'+events.map(e=>{
    const isWait=e.step==='retry.wait';
    return `<div class="step-item"><div class="step-dot ${e.status==='ok'?'step-ok':isWait?'step-wait':'step-err'}"></div>
      <span class="step-name">${e.step}</span>${e.attempt?`<span class="step-meta">#${e.attempt}</span>`:''}
      ${e.waited_ms?`<span class="step-latency">${e.waited_ms}ms waited</span>`:e.backoff_ms?`<span class="step-latency">backoff ${e.backoff_ms}ms</span>`:''}
    </div>`;
  }).join('')+'</div>';
}
function renderResult(boxId,data,isLegacy){
  const degraded=data.status==='degraded';
  const ok=data.status==='completed'||degraded;
  const stCls=degraded?'status-deg':ok?'status-ok':'status-err';
  const stTxt=degraded?'⚠ DEGRADADO':ok?'✅ OK':'❌ FALLIDO';
  let html=`<span class="result-status ${stCls}">${stTxt}</span>
    <div class="result-row"><span class="result-label">Tiempo total</span><span class="result-val ${isLegacy?'val-bad':'val-neutral'}">${data.elapsed_ms??'—'} ms</span></div>
    <div class="result-row"><span class="result-label">Intentos</span><span class="result-val ${(data.attempts>2)?'val-bad':'val-neutral'}">${data.attempts??'—'}</span></div>
    <div class="result-row"><span class="result-label">Timeouts</span><span class="result-val ${data.timeout_count>0?'val-bad':'val-good'}">${data.timeout_count??0}</span></div>`;
  if(data.dependency?.circuit_status) html+=`<div class="result-row"><span class="result-label">Circuit</span><span class="result-val ${data.dependency.circuit_status==='open'?'val-bad':'val-good'}">${data.dependency.circuit_status}</span></div>`;
  if(data.quote) {
    const src=data.quote.source;
    html+=`<div class="result-row"><span class="result-label">Cotización fuente</span><span class="result-val ${src==='fallback'?'val-warn':src==='live'?'val-good':'val-neutral'}">${src}</span></div>`;
    html+=`<div class="result-row"><span class="result-label">Monto (USD)</span><span class="result-val val-neutral">$${data.quote.amount??'—'}</span></div>`;
  }
  document.getElementById(boxId).innerHTML=html+renderSteps(data.events);
}
async function runLegacy(){
  const sc=document.getElementById('sc-leg').value;
  setLoading('btn-leg','sp-leg',true);
  try{const r=await fetch(`/quote-legacy?scenario=${sc}&customer_id=42&items=3`);const d=await r.json();renderResult('res-leg',d,true);}
  catch(e){document.getElementById('res-leg').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`;}
  setLoading('btn-leg','sp-leg',false);loadMetrics();
}
async function runResilient(){
  const sc=document.getElementById('sc-res').value;
  setLoading('btn-res','sp-res',true);
  try{const r=await fetch(`/quote-resilient?scenario=${sc}&customer_id=42&items=3`);const d=await r.json();renderResult('res-res',d,false);}
  catch(e){document.getElementById('res-res').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`;}
  setLoading('btn-res','sp-res',false);loadMetrics();
}
async function loadMetrics(){
  try{
    const r=await fetch('/diagnostics/summary');const d=await r.json();
    document.getElementById('m-total').textContent=d.metrics?.requests_tracked??'—';
    const leg=d.metrics?.modes?.legacy??{};
    document.getElementById('m-att').textContent=leg.avg_attempts_per_flow?.toFixed?.(1)??'—';
    document.getElementById('m-fb').textContent=leg.fallbacks_used??'—';
    document.getElementById('m-co').textContent=leg.circuit_opens??'—';
    const dep=d.dependency??{};
    const open=dep.circuit_status==='open';
    document.getElementById('cb-status').innerHTML=`<span style="color:${open?'#f87171':'#4ade80'};font-weight:700">${open?'🔴 ABIERTO':'🟢 CERRADO'}</span>`;
    document.getElementById('cb-fails').textContent=dep.consecutive_failures??0;
    document.getElementById('cb-until').textContent=dep.opened_until??'—';
    document.getElementById('cb-sc').textContent=dep.short_circuit_count??0;
    document.getElementById('cb-fb').innerHTML=`<span style="color:${dep.fallback_quote?'#4ade80':'#64748b'}">${dep.fallback_quote?'✅ Disponible':'○ Sin cache'}</span>`;
  }catch(e){}
}
loadMetrics();setInterval(loadMetrics,5000);
</script>
</body></html>
