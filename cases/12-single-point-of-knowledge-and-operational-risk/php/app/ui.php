<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caso 12 — Riesgo de Conocimiento | Problem-Driven Systems Lab</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0e1a;--surface:#111827;--surface2:#1a2235;--border:#1e2d45;--accent:#8b5cf6;--red:#ef4444;--green:#22c55e;--amber:#f59e0b;--text:#e2e8f0;--muted:#64748b}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.header{background:linear-gradient(135deg,#0d0a1a,#180f30,#0a0e1a);border-bottom:1px solid #200d40;padding:28px 40px;display:flex;align-items:center;gap:20px}
.case-badge{background:var(--accent);color:#fff;font-weight:800;font-size:11px;padding:4px 10px;border-radius:6px}
.header-title{flex:1}.header-title h1{font-size:22px;font-weight:700;color:#fff}.header-title p{font-size:13px;color:#8070a0;margin-top:4px}
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
.btn-solution{background:var(--accent);color:#fff}.btn-solution:hover{filter:brightness(1.1);transform:translateY(-1px)}
.btn-share{background:rgba(139,92,246,.15);color:#a78bfa;border:1px solid rgba(139,92,246,.3);padding:8px 14px;font-size:12px}.btn-share:hover{background:rgba(139,92,246,.25)}
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
.domains-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:20px}
.domain-card{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:14px}
.domain-card h6{font-size:11px;font-weight:700;color:#a78bfa;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px}
.domain-row{display:flex;justify-content:space-between;font-size:12px;padding:4px 0}
.d-key{color:var(--muted)}.d-val{font-family:'JetBrains Mono',monospace;font-weight:600;color:var(--text)}
.bar-mini{height:4px;background:rgba(255,255,255,.05);border-radius:2px;overflow:hidden;margin-top:3px}
.bar-mini-fill{height:100%;border-radius:2px;background:linear-gradient(90deg,#8b5cf6,#a78bfa);transition:width 1s ease}
.metrics-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:16px}
.metric-card{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px}
.metric-card .m-label{font-size:11px;color:var(--muted);margin-bottom:5px;font-weight:500}.metric-card .m-val{font-size:20px;font-weight:800;font-family:'JetBrains Mono',monospace}
.share-panel{background:rgba(139,92,246,.05);border:1px solid rgba(139,92,246,.2);border-radius:12px;padding:16px;margin-bottom:20px}
.share-panel h5{font-size:12px;font-weight:700;color:#a78bfa;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px}
.share-controls{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
footer{text-align:center;padding:32px;color:var(--muted);font-size:12px;border-top:1px solid var(--border);margin-top:40px}
</style>
</head>
<body>
<div class="header">
  <div><div class="case-badge">CASO 12</div></div>
  <div class="header-title">
    <h1>🧩 Punto único de conocimiento y riesgo operacional</h1>
    <p>Operaciones · Bus factor = 1, sin runbooks, sin backups, MTTR depende de quién está disponible</p>
  </div>
  <span class="stack-badge">PHP 8.3 · Knowledge Management</span>
</div>
<div class="container">
  <div class="cards-row">
    <div class="card problem">
      <div class="card-label">🔴 El Problema</div>
      <h3>Legacy: MTTR de 95 min si la persona clave no está disponible</h3>
      <p>El sistema depende de una sola persona que conoce el procedimiento de deployment, la configuración de la base de datos y el script de rollback. Si esa persona está ausente durante un incidente nocturno, el equipo queda paralizado. Bus factor = 1.</p>
      <span class="tag tag-red">Bus factor 1 · Sin runbooks · MTTR 95min · Memoria tribal</span>
    </div>
    <div class="card solution">
      <div class="card-label">✅ La Solución</div>
      <h3>Distributed: runbooks + pairing + drills bajan el MTTR a 34 min</h3>
      <p>El sistema distribuido invierte en runbooks documentados, sesiones de pairing para transferir contexto y drills de incidente. El MTTR baja de 95 a 34 minutos. Con readiness score alto, puede resolver sin depender de ninguna persona específica.</p>
      <span class="tag tag-green">Bus factor 3+ · Runbooks · Drills · MTTR 34min</span>
    </div>
  </div>

  <div class="action-row">
    <div class="action-panel">
      <h4>🔴 Incidente con dependencia tribal</h4>
      <div class="params">
        <div class="param-group"><label>Escenario</label>
          <select id="sc-leg">
            <option value="owner_available">owner_available – dueño disponible</option>
            <option value="owner_absent" selected>owner_absent – dueño ausente</option>
            <option value="night_shift">night_shift – turno nocturno</option>
            <option value="recent_change">recent_change – cambio reciente</option>
            <option value="tribal_script">tribal_script – script tribal</option>
          </select>
        </div>
        <div class="param-group"><label>Dominio</label>
          <select id="dom-leg"><option value="deployments" selected>deployments</option><option value="database">database</option><option value="payments">payments</option><option value="integrations">integrations</option></select>
        </div>
      </div>
      <button class="btn btn-legacy" id="btn-leg" onclick="runLegacy()">
        <div class="spinner" id="sp-leg"></div>▶ /incident-legacy
      </button>
      <div class="result-box" id="res-leg"><div class="result-empty">Ejecuta owner_absent y mide el MTTR</div></div>
    </div>
    <div class="action-panel">
      <h4>✅ Incidente con conocimiento distribuido</h4>
      <div class="params">
        <div class="param-group"><label>Escenario</label>
          <select id="sc-dis">
            <option value="owner_available">owner_available</option>
            <option value="owner_absent" selected>owner_absent</option>
            <option value="night_shift">night_shift</option>
            <option value="recent_change">recent_change</option>
            <option value="tribal_script">tribal_script</option>
          </select>
        </div>
        <div class="param-group"><label>Dominio</label>
          <select id="dom-dis"><option value="deployments" selected>deployments</option><option value="database">database</option><option value="payments">payments</option><option value="integrations">integrations</option></select>
        </div>
      </div>
      <button class="btn btn-solution" id="btn-dis" onclick="runDistributed()">
        <div class="spinner" id="sp-dis"></div>▶ /incident-distributed
      </button>
      <div class="result-box" id="res-dis"><div class="result-empty">Mejora readiness score con runbooks y pairing</div></div>
    </div>
  </div>

  <div class="metrics-section">
    <h4><span class="pulse"></span> Estado del conocimiento <span style="font-weight:400;color:#475569;font-size:11px;margin-left:8px">Auto-refresh 5s</span></h4>
    <div class="share-panel">
      <h5>📚 Invertir en conocimiento distribuido</h5>
      <div class="share-controls">
        <div class="param-group"><label style="font-size:11px;color:var(--muted)">Dominio</label>
          <select id="sh-dom" style="background:var(--surface);border:1px solid var(--border);color:var(--text);padding:7px 12px;border-radius:8px;font-size:12px">
            <option value="deployments">deployments</option><option value="database">database</option><option value="payments">payments</option><option value="integrations">integrations</option>
          </select>
        </div>
        <div class="param-group"><label style="font-size:11px;color:var(--muted)">Actividad</label>
          <select id="sh-act" style="background:var(--surface);border:1px solid var(--border);color:var(--text);padding:7px 12px;border-radius:8px;font-size:12px">
            <option value="runbook">📝 Runbook (+20 score)</option>
            <option value="pairing">👥 Pairing (+1 backup)</option>
            <option value="drill">🎯 Drill (+18 score)</option>
          </select>
        </div>
        <button class="btn btn-share" onclick="shareKnowledge()">📤 Compartir conocimiento</button>
      </div>
    </div>
    <div class="domains-grid" id="domains-grid"></div>
    <div class="metrics-row">
      <div class="metric-card"><div class="m-label">Bus factor mínimo</div><div class="m-val" id="m-bf" style="color:#a78bfa">—</div></div>
      <div class="metric-card"><div class="m-label">Docs indexados</div><div class="m-val" id="m-docs" style="color:#60a5fa">—</div></div>
      <div class="metric-card"><div class="m-label">Sesiones pairing</div><div class="m-val" id="m-pair" style="color:#4ade80">—</div></div>
      <div class="metric-card"><div class="m-label">Drills completados</div><div class="m-val" id="m-drill">—</div></div>
    </div>
  </div>
</div>
<footer>Problem-Driven Systems Lab · Caso 12 · <a href="/knowledge/state" style="color:var(--accent)">Estado conocimiento</a> · <a href="/reset-lab" style="color:var(--muted)">Reset</a></footer>

<script>
function setLoading(b,s,v){document.getElementById(b).disabled=v;document.getElementById(s).style.display=v?'inline-block':'none';}
function renderResult(boxId,data,isLegacy){
  const ok=data.status==='resolved';
  document.getElementById(boxId).innerHTML=`
    <span class="result-status ${ok?'status-ok':'status-err'}">${ok?'✅ RESUELTO':'❌ BLOQUEADO'}</span>
    <div class="result-row"><span class="result-label">MTTR</span><span class="result-val ${isLegacy?'val-bad':'val-good'}">${data.mttr_min??'—'} min</span></div>
    <div class="result-row"><span class="result-label">Bloqueadores</span><span class="result-val ${(data.blocker_count>0)?'val-bad':'val-good'}">${data.blocker_count??0}</span></div>
    <div class="result-row"><span class="result-label">Calidad handoff</span><span class="result-val ${!isLegacy?'val-good':'val-neutral'}">${data.handoff_quality??'—'}%</span></div>
    <div class="result-row"><span class="result-label">Readiness score</span><span class="result-val ${data.readiness_score>50?'val-good':'val-warn'}">${data.readiness_score??'—'}</span></div>
    <div class="result-row"><span class="result-label">Tiempo</span><span class="result-val val-neutral">${data.elapsed_ms??'—'} ms</span></div>
  `;
}
async function runLegacy(){
  const sc=document.getElementById('sc-leg').value,dom=document.getElementById('dom-leg').value;
  setLoading('btn-leg','sp-leg',true);
  try{const r=await fetch(`/incident-legacy?scenario=${sc}&domain=${dom}`);const d=await r.json();renderResult('res-leg',d,true);}
  catch(e){document.getElementById('res-leg').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`;}
  setLoading('btn-leg','sp-leg',false);loadMetrics();
}
async function runDistributed(){
  const sc=document.getElementById('sc-dis').value,dom=document.getElementById('dom-dis').value;
  setLoading('btn-dis','sp-dis',true);
  try{const r=await fetch(`/incident-distributed?scenario=${sc}&domain=${dom}`);const d=await r.json();renderResult('res-dis',d,false);}
  catch(e){document.getElementById('res-dis').innerHTML=`<div class="result-empty" style="color:var(--red)">${e.message}</div>`;}
  setLoading('btn-dis','sp-dis',false);loadMetrics();
}
async function shareKnowledge(){
  const dom=document.getElementById('sh-dom').value,act=document.getElementById('sh-act').value;
  await fetch(`/share-knowledge?domain=${dom}&activity=${act}`);loadMetrics();
}
async function loadMetrics(){
  try{
    const r=await fetch('/knowledge/state');const d=await r.json();
    document.getElementById('m-bf').textContent=d.bus_factor_min??0;
    document.getElementById('m-docs').textContent=d.docs_indexed??0;
    document.getElementById('m-pair').textContent=d.pairing_sessions??0;
    document.getElementById('m-drill').textContent=d.drills_completed??0;
    const dg=document.getElementById('domains-grid');
    if(d.domains){
      dg.innerHTML=Object.entries(d.domains).map(([name,meta])=>{
        const cov=d.coverage?.[name]??0;
        return `<div class="domain-card">
          <h6>${name}</h6>
          <div class="domain-row"><span class="d-key">Runbook score</span><span class="d-val">${meta.runbook_score??0}%</span></div>
          <div class="bar-mini"><div class="bar-mini-fill" style="width:${meta.runbook_score??0}%"></div></div>
          <div class="domain-row"><span class="d-key">Backups</span><span class="d-val">${meta.backup_people??0} personas</span></div>
          <div class="domain-row"><span class="d-key">Drill score</span><span class="d-val">${meta.drill_score??0}%</span></div>
          <div class="domain-row"><span class="d-key">Cobertura</span><span class="d-val">${cov}%</span></div>
        </div>`;
      }).join('');
    }
  }catch(e){}
}
loadMetrics();setInterval(loadMetrics,5000);
</script>
</body></html>
