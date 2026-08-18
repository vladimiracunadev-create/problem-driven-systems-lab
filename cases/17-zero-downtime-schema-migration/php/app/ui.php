<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caso 17 — Migración sin downtime | Problem-Driven Systems Lab</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0e1a;--surface:#111827;--surface2:#1a2235;--border:#1e2d45;--accent:#38bdf8;--red:#ef4444;--green:#22c55e;--text:#e2e8f0;--muted:#64748b}
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.header{background:linear-gradient(135deg,#05141a,#0a2230,#0a0e1a);border-bottom:1px solid #073246;padding:28px 40px;display:flex;align-items:center;gap:20px}
.case-badge{background:var(--accent);color:#04222e;font-weight:800;font-size:11px;padding:4px 10px;border-radius:6px}
.header-title{flex:1}.header-title h1{font-size:22px;font-weight:700;color:#fff}.header-title p{font-size:13px;color:#6ea5bd;margin-top:4px}
.stack-badge{background:rgba(56,189,248,.15);border:1px solid rgba(56,189,248,.3);color:var(--accent);font-size:11px;padding:4px 12px;border-radius:20px;font-weight:600}
.container{max-width:1300px;margin:0 auto;padding:32px 40px}
.cards-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px}
.card{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:16px;padding:24px}
.card.problem{border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.04)}.card.solution{border-color:rgba(34,197,94,.3);background:rgba(34,197,94,.04)}
.card-label{font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:10px}
.card.problem .card-label{color:var(--red)}.card.solution .card-label{color:var(--green)}
.card h3{font-size:15px;font-weight:600;margin-bottom:10px;color:#fff}.card p{font-size:13px;color:#94a3b8;line-height:1.7}
.card code{font-family:'JetBrains Mono',monospace;font-size:12px;background:rgba(255,255,255,.06);padding:1px 5px;border-radius:4px}
.controls{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:24px;margin-bottom:28px}
.controls h4{font-size:13px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px}
.params{display:flex;gap:14px;margin-bottom:18px;flex-wrap:wrap}.param-group{display:flex;flex-direction:column;gap:4px}
.param-group label{font-size:11px;color:var(--muted);font-weight:500}
.param-group input{background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:7px 12px;border-radius:8px;font-size:13px;width:150px;font-family:'JetBrains Mono',monospace}
.btns{display:flex;gap:12px;flex-wrap:wrap}
.btn{padding:11px 22px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;border:none;transition:all .2s}
.btn-legacy{background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3)}.btn-legacy:hover{background:rgba(239,68,68,.25)}
.btn-solution{background:var(--green);color:#04220f}.btn-solution:hover{filter:brightness(1.1)}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}.btn-ghost:hover{color:var(--text)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.compare-panel{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px}
.compare-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:22px}
.compare-card.naive{border-color:rgba(239,68,68,.25)}.compare-card.sf{border-color:rgba(34,197,94,.25)}
.compare-card h5{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px}
.compare-card.naive h5{color:#f87171}.compare-card.sf h5{color:#4ade80}
.metric-row{display:flex;justify-content:space-between;font-size:13px;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.05)}
.metric-row:last-child{border:none}.m-k{color:var(--muted)}
.m-v{font-family:'JetBrains Mono',monospace;font-weight:600;padding:2px 8px;border-radius:5px}
.val-bad{background:rgba(239,68,68,.15);color:#f87171}.val-good{background:rgba(34,197,94,.15);color:#4ade80}.val-neutral{background:rgba(148,163,184,.1);color:#94a3b8}
.empty{color:var(--muted);font-size:13px;text-align:center;padding:28px 0}
.hero{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:16px;text-align:center}
.hero .big{font-family:'JetBrains Mono',monospace;font-size:34px;font-weight:700;line-height:1}
.hero .lbl{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:6px}
.note{background:rgba(56,189,248,.06);border:1px solid rgba(56,189,248,.2);border-radius:12px;padding:16px 18px;font-size:13px;color:#9fd4ea;line-height:1.7}
.note strong{color:#cdeaf7}
footer{text-align:center;padding:32px;color:var(--muted);font-size:12px;border-top:1px solid var(--border);margin-top:40px}
footer a{color:var(--accent);text-decoration:none}
</style>
</head>
<body>
<div class="header">
  <div><div class="case-badge">CASO 17</div></div>
  <div class="header-title">
    <h1>🧬 Migración de esquema sin downtime</h1>
    <p>Rendimiento · La clave caliente expira y los N llamadores pegan al origen a la vez</p>
  </div>
  <span class="stack-badge">PHP 8.3 · flock LOCK_SH / LOCK_EX</span>
</div>

<div class="container">
  <div class="cards-row">
    <div class="card problem">
      <div class="card-label">🔴 El problema</div>
      <h3>Veinte minutos de 503 por una columna nueva</h3>
      <p>El <code>ALTER TABLE</code> toma el lock exclusivo y no lo suelta hasta terminar. Los lectores no pueden entrar: <code>LOCK_SH</code> es incompatible con <code>LOCK_EX</code>. La aplicación devuelve 503 todo ese tiempo — <strong>y el proceso sigue vivo</strong>, así que el healthcheck dice que todo está bien.</p>
    </div>
    <div class="card solution">
      <div class="card-label">🟢 La corrección</div>
      <h3>Expand · backfill · switch · contract</h3>
      <p>La columna nueva se agrega <em>nullable</em> (metadata, instantáneo), se rellena por lotes soltando el lock entre cada uno, un feature flag cambia las lecturas, y recién después se borra la vieja. <strong>El trabajo total es idéntico</strong>: lo que cambia es que ningún lector espera más que un lote.</p>
    </div>
  </div>

  <div class="controls">
    <h4>Ejecutar la migración</h4>
    <div class="params">
      <div class="param-group"><label>filas</label><input id="rows" type="number" value="20000" min="1000" max="500000" step="1000"></div>
      <div class="param-group"><label>lectores</label><input id="readers" type="number" value="8" min="1" max="64"></div>
      <div class="param-group"><label>ms por cada 1k filas</label><input id="ms_per_1k" type="number" value="20" min="1" max="200"></div>
      <div class="param-group"><label>tamaño de lote</label><input id="batch" type="number" value="2000" min="100" step="500"></div>
    </div>
    <div class="btns">
      <button class="btn btn-legacy" id="btn-naive">ALTER TABLE bloqueante</button>
      <button class="btn btn-solution" id="btn-sf">Expand-contract</button>
      <button class="btn btn-ghost" id="btn-reset">Reiniciar lab</button>
    </div>
  </div>

  <div class="compare-panel">
    <div class="compare-card naive">
      <h5>❌ Bloqueante</h5>
      <div id="out-naive"><div class="empty">Sin ejecuciones todavía.</div></div>
    </div>
    <div class="compare-card sf">
      <h5>✅ Expand-contract</h5>
      <div id="out-sf"><div class="empty">Sin ejecuciones todavía.</div></div>
    </div>
  </div>

  <div class="note">
    <strong>Lo que aporta este stack.</strong> <code>flock</code> es un read-write lock <strong>del sistema operativo</strong>, no una estructura en memoria: <code>LOCK_SH</code> para lectores, <code>LOCK_EX</code> para el escritor, <code>LOCK_NB</code> para el intento con deadline. Los otros seis stacks coordinan hilos de un mismo proceso; este coordina <strong>procesos distintos</strong> — que es exactamente lo que hace un motor de base de datos. <em>Nota de fidelidad:</em> el servidor embebido de PHP es de un solo proceso, así que los lectores se recorren en secuencia; el lock es real, lo que no es concurrente es el laboratorio.
  </div>
</div>

<footer>
  Problem-Driven Systems Lab · Caso 17 ·
  <a href="/diagnostics/summary">/diagnostics/summary</a> ·
  <a href="/migration/state">/migration/state</a>
</footer>

<script>
const $ = (id) => document.getElementById(id);
const row = (k, v, c) => `<div class="metric-row"><span class="m-k">${k}</span><span class="m-v ${c}">${v}</span></div>`;

function render(target, d) {
  const good = target === 'out-sf';
  const okAvail = d.availability_pct >= 99.99;
  $(target).innerHTML = `
    <div class="hero">
      <div class="big ${okAvail ? 'val-good' : 'val-bad'}" style="background:none;padding:0">${d.availability_pct}%</div>
      <div class="lbl">disponibilidad durante la migración</div>
    </div>
    ${row('lectores servidos', d.readers_served, 'val-neutral')}
    ${row('lectores rechazados', d.readers_failed, d.readers_failed > 0 ? 'val-bad' : 'val-good')}
    ${row('lock más largo', d.longest_single_lock_ms + ' ms', good ? 'val-good' : 'val-bad')}
    ${row('espera máxima de lectura', d.max_read_wait_ms + ' ms', good ? 'val-good' : 'val-bad')}
    ${row('lock total', d.lock_held_ms + ' ms', 'val-neutral')}
    ${row('lotes', d.backfill_batches, 'val-neutral')}
    ${row('fase final', d.phase, 'val-neutral')}
    ${row('backfill', d.backfill_progress_pct + '%', 'val-neutral')}
  `;
}

async function run(variant, target, btn) {
  const b = $(btn);
  b.disabled = true;
  try {
    const p = new URLSearchParams({
      rows: $('rows').value || '20000',
      readers: $('readers').value || '8',
      ms_per_1k: $('ms_per_1k').value || '20',
      batch: $('batch').value || '2000',
    });
    const res = await fetch(`/migrate-${variant}?${p}`, { headers: { Accept: 'application/json' } });
    render(target, await res.json());
  } catch (e) {
    $(target).innerHTML = `<div class="empty">Error: ${e.message}</div>`;
  } finally {
    b.disabled = false;
  }
}

$('btn-naive').onclick = () => run('blocking', 'out-naive', 'btn-naive');
$('btn-sf').onclick = () => run('expand-contract', 'out-sf', 'btn-sf');
$('btn-reset').onclick = async () => {
  await fetch('/reset-lab', { headers: { Accept: 'application/json' } });
  $('out-naive').innerHTML = '<div class="empty">Sin ejecuciones todavía.</div>';
  $('out-sf').innerHTML = '<div class="empty">Sin ejecuciones todavía.</div>';
};
</script>
</body>
</html>
