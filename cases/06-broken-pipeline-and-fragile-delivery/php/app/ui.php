<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caso 06 — Broken Pipeline | Problem-Driven Systems Lab</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0e1a;--surface:#111827;--surface2:#1a2235;--border:#1e2d45;--accent:#22c55e;--red:#ef4444;--green:#22c55e;--amber:#f59e0b;--text:#e2e8f0;--muted:#64748b}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.header{background:linear-gradient(135deg,#051a0a,#0a2d0f,#0a0e1a);border-bottom:1px solid #0f3a18;padding:28px 40px;display:flex;align-items:center;gap:20px}
.case-badge{background:var(--green);color:#000;font-weight:800;font-size:11px;padding:4px 10px;border-radius:6px}
.header-title{flex:1}.header-title h1{font-size:22px;font-weight:700;color:#fff}.header-title p{font-size:13px;color:#6b9e75;margin-top:4px}
.stack-badge{background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.3);color:var(--green);font-size:11px;padding:4px 12px;border-radius:20px;font-weight:600}
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
.status-ok{background:rgba(34,197,94,.15);color:#4ade80}.status-err{background:rgba(239,68,68,.15);color:#f87171}.status-blocked{background:rgba(245,158,11,.15);color:#fbbf24}
.steps-box{margin-top:10px}
.step-item{display:flex;align-items:center;gap:8px;padding:5px 0;font-size:11px;border-bottom:1px solid rgba(255,255,255,.04)}
.step-item:last-child{border:none}.step-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.step-ok-d{background:#22c55e}.step-err-d{background:#ef4444}.step-blocked-d{background:#f59e0b}
.step-name{font-family:'JetBrains Mono',monospace;font-size:11px}.step-ms{margin-left:auto;font-family:'JetBrains Mono',monospace;color:#60a5fa;font-size:11px}
.metrics-section{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:24px}
.metrics-section h4{font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:20px;display:flex;align-items:center;gap:8px}
.pulse{width:8px;height:8px;background:var(--green);border-radius:50%;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.3)}}
.env-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px}
.env-card{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:16px}
.env-card .env-name{font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.env-card .env-health{font-size:14px;font-weight:700;margin-bottom:6px}
.env-health-healthy{color:#4ade80}.env-health-degraded{color:#f87171}.env-health-warming{color:#fbbf24}
.env-release{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted)}
.metrics-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.metric-card{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px}
.metric-card .m-label{font-size:11px;color:var(--muted);margin-bottom:5px;font-weight:500}.metric-card .m-val{font-size:20px;font-weight:800;font-family:'JetBrains Mono',monospace}
footer{text-align:center;padding:32px;color:var(--muted);font-size:12px;border-top:1px solid var(--border);margin-top:40px}
</style>
</head>
<body>
<div class="header">
  <div><div class="case-badge">CASO 06</div></div>
  <div class="header-title">
    <h1>🚚 Pipeline roto y entrega frágil</h1>
    <p>Entrega · Validaciones tardías, sin preflight checks, sin rollback automático, ambiente degradado sin recuperación</p>
  </div>
  <span class="stack-badge">PHP 8.3 · CI/CD · Preflight</span>
</div>
<div class="container">
  <div class="cards-row">
    <div class="card problem">
      <div class="card-label">🔴 El Problema</div>
      <h3>Legacy detecta el problema después de cambiar el tráfico</h3>
      <p>El pipeline legacy empaqueta y despliega antes de verificar secretos, config o migraciones. Si el smoke test falla, el ambiente ya quedó degradado con el nuevo release en producción y sin rollback automático. El equipo tiene que intervenir manualmente.</p>
      <span class="tag tag-red">Detección tardía · Sin preflight · Sin rollback · Ambiente degradado</span>
    </div>
    <div class="card solution">
      <div class="card-label">✅ La Solución</div>
      <h3>Controlled: preflight checks antes de tocar el ambiente</h3>
      <p>El pipeline controlado valida secretos, config y migraciones <em>antes</em> de empezar el deploy. Si el preflight falla, el ambiente no se toca. Hace canary deployment y si el smoke test falla, <em>rollback automático</em> al último release sano.</p>
      <span class="tag tag-green">Preflight · Canary · Rollback automático · Ambiente protegido</span>
    </div>
  </div>

  <div class="action-row">
    <div class="action-panel">
      <h4>🔴 Deploy Legacy — sin guardrails</h4>
      <div class="params">
        <div class="param-group"><label>Escenario</label>
          <select id="sc-leg">
            <option value="ok">ok – deploy limpio</option>
            <option value="missing_secret" selected>missing_secret – falta secreto</option>
            <option value="config_drift">config_drift – config desincronizada</option>
            <option value="failing_smoke">failing_smoke – smoke test falla</option>
            <option value="migration_risk">migration_risk – migración riesgosa</option>
          </select>
        </div>
        <div class="param-group"><label>Ambiente</label>
          <select id="env-leg"><option value="staging" selected>staging</option><option value="prod">prod</option></select>
        </div>
      </div>
      <button class="btn btn-legacy" id="btn-leg" onclick="runLegacy()">
        <div class="spinner" id="sp-leg"></div>▶ /deploy-legacy
      </button>
      <div class="result-box" id="res-leg"><div class="result-empty">Ejecuta missing_secret y observa el daño</div></div>
    </div>
    <div class="action-panel">
      <h4>✅ Deploy Controlled — con preflight y rollback</h4>
      <div class="params">
        <div class="param-group"><label>Escenario</label>
          <select id="sc-ctl">
            <option value="ok">ok – deploy limpio</option>
            <option value="missing_secret" selected>missing_secret – falta secreto</option>
            <option value="config_drift">config_drift – config desincronizada</option>
            <option value="failing_smoke">failing_smoke – smoke test falla</option>
            <option value="migration_risk">migration_risk – migración riesgosa</option>
          </select>
        </div>
        <div class="param-group"><label>Ambiente</label>
          <select id="env-ctl"><option value="staging" selected>staging</option><option value="prod">prod</option></select>
        </div>
      </div>
      <button class="btn btn-solution" id="btn-ctl" onclick="runControlled()">
        <div class="spinner" id="sp-ctl"></div>▶ /deploy-controlled
      </button>
      <div class="result-box" id="res-ctl"><div class="result-empty">Ejecuta el mismo escenario y compara</div></div>
    </div>
  </div>

  <div class="metrics-section">
    <h4><span class="pulse"></span> Estado de ambientes <span style="font-weight:400;color:#475569;font-size:11px;margin-left:8px">Auto-refresh 5s</span></h4>
    <div class="env-grid" id="env-grid">
      <div class="env-card"><div class="env-name">dev</div><div class="env-health" id="env-dev-h">—</div><div class="env-release" id="env-dev-r">—</div></div>
      <div class="env-card"><div class="env-name">staging</div><div class="env-health" id="env-stg-h">—</div><div class="env-release" id="env-stg-r">—</div></div>
      <div class="env-card"><div class="env-name">prod</div><div class="env-health" id="env-prod-h">—</div><div class="env-release" id="env-prod-r">—</div></div>
    </div>
    <div class="metrics-row">
      <div class="metric-card"><div class="m-label">Deploys totales</div><div class="m-val" id="m-tot">—</div></div>
      <div class="metric-card"><div class="m-label">Rollbacks (ctrl)</div><div class="m-val" id="m-rb" style="color:#4ade80">—</div></div>
      <div class="metric-card"><div class="m-label">Preflight blocks</div><div class="m-val" id="m-pf" style="color:#fbbf24">—</div></div>
      <div class="metric-card"><div class="m-label">Fallas legacy</div><div class="m-val" id="m-fl" style="color:#f87171">—</div></div>
    </div>
  </div>
</div>
<footer>Problem-Driven Systems Lab · Caso 06 · <a href="/environments" style="color:var(--accent)">Estado ambientes</a> · <a href="/reset-lab" style="color:var(--muted)">Reset</a></footer>

<script>
function setLoading(b,s,v){document.getElementById(b).disabled=v;document.getElementById(s).style.display=v?'inline-block':'none';}
function renderSteps(steps){
  if(!steps?.length)return '';
  return '<div class="steps-box">'+steps.map(s=>`<div class="step-item">
    <div class="step-dot ${s.status==='ok'?'step-ok-d':s.status==='blocked'?'step-blocked-d':'step-err-d'}"></div>
    <span class="step-name">${s.step}</span>${s.message?`<span style="color:var(--muted);font-size:10px;margin-left:6px">${s.message.substring(0,50)}</span>`:''}
    <span class="step-ms">${s.elapsed_ms??'—'} ms</span></div>`).join('')+'</div>';
}
function renderResult(boxId,data,isLegacy){
  const ok=data.status==='completed',blocked=data.status==='blocked',rb=data.status==='rolled_back';
  const stCls=ok?'status-ok':blocked?'status-blocked':'status-err';
  const stTxt=ok?'✅ COMPLETADO':blocked?'⚠ BLOQUEADO (preflight)':rb?'🔄 ROLLBACK':'❌ FALLIDO';
  document.getElementById(boxId).innerHTML=`
    <span class="result-status ${stCls}">${stTxt}</span>
    <div class="result-row"><span class="result-label">Modo</span><span class="result-val val-neutral">${data.mode}</span></div>
    <div class="result-row"><span class="result-label">Ambiente</span><span class="result-val val-neutral">${data.environment}</span></div>
    <div class="result-row"><span class="result-label">Ambiente salud</span><span class="result-val ${data.environment_after?.health==='healthy'?'val-good':data.environment_after?.health==='degraded'?'val-bad':'val-warn'}">${data.environment_after?.health??'—'}</span></div>
    <div class="result-row"><span class="result-label">Rollback</span><span class="result-val ${data.rollback_performed?'val-good':'val-neutral'}">${data.rollback_performed?'✅ Ejecutado':'No necesario'}</span></div>
    <div class="result-row"><span class="result-label">Tiempo</span><span class="result-val val-neutral">${data.elapsed_ms??'—'} ms</span></div>
  `+renderSteps(data.steps);
}
async function runLegacy(){
  const sc=document.getElementById('sc-leg').value,env=document.getElementById('env-leg').value;
  setLoading('btn-leg','sp-leg',true);
  try{const r=await fetch(`/deploy-legacy?environment=${env}&release=2026.04.1&scenario=${sc}`);const d=await r.json();renderResult('res-leg',d,true);}
  catch(e){document.getElementById('res-leg').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`;}
  setLoading('btn-leg','sp-leg',false);loadMetrics();
}
async function runControlled(){
  const sc=document.getElementById('sc-ctl').value,env=document.getElementById('env-ctl').value;
  setLoading('btn-ctl','sp-ctl',true);
  try{const r=await fetch(`/deploy-controlled?environment=${env}&release=2026.04.2&scenario=${sc}`);const d=await r.json();renderResult('res-ctl',d,false);}
  catch(e){document.getElementById('res-ctl').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`;}
  setLoading('btn-ctl','sp-ctl',false);loadMetrics();
}
async function loadMetrics(){
  try{
    const r=await fetch('/diagnostics/summary');const d=await r.json();
    const envs=d.state?.environments??{};
    ['dev','stg','prod'].forEach((e,i)=>{
      const envKey=['dev','staging','prod'][i];
      const env=envs[envKey]??{};
      const hEl=document.getElementById(`env-${e}-h`);
      const h=env.health??'unknown';
      hEl.className='env-health env-health-'+(h==='healthy'?'healthy':h==='degraded'?'degraded':'warming');
      hEl.textContent=h==='healthy'?'🟢 Saludable':h==='degraded'?'🔴 Degradado':'⚠ Calentando';
      document.getElementById(`env-${e}-r`).textContent=env.current_release??'—';
    });
    const ms=d.metrics?.modes??{};
    const ctl=ms.controlled??{},leg=ms.legacy??{};
    document.getElementById('m-tot').textContent=(d.metrics?.requests_tracked??0);
    document.getElementById('m-rb').textContent=ctl.rollbacks??0;
    document.getElementById('m-pf').textContent=ctl.preflight_blocks??0;
    document.getElementById('m-fl').textContent=leg.failures??0;
  }catch(e){}
}
loadMetrics();setInterval(loadMetrics,5000);
</script>
</body></html>
