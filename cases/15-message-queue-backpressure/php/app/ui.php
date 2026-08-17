<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caso 15 — Backpressure en colas | Problem-Driven Systems Lab</title>
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
  <div><div class="case-badge">CASO 15</div></div>
  <div class="header-title">
    <h1>🌊 Backpressure en colas de mensajes</h1>
    <p>Rendimiento · La clave caliente expira y los N llamadores pegan al origen a la vez</p>
  </div>
  <span class="stack-badge">PHP 8.3 · el freno vive en el transporte</span>
</div>

<div class="container">
  <div class="cards-row">
    <div class="card problem">
      <div class="card-label">🔴 El problema</div>
      <h3>La cola absorbe todo hasta que la memoria dice basta</h3>
      <p>Sin capacidad, el productor <strong>nunca se entera</strong> de que el consumidor no da abasto. El throughput se ve sano, no se pierde ningún mensaje, y mientras tanto el más viejo espera por todos los que llegaron después. El primer síntoma real suele ser el OOM killer.</p>
    </div>
    <div class="card solution">
      <div class="card-label">🟢 La corrección</div>
      <h3>Capacidad fija y una política elegida a propósito</h3>
      <p>Con límite hay que decidir qué pasa cuando se llena: <strong>frenar</strong> al productor, <strong>descartar</strong> datos, o mandarlos a una <strong>DLQ</strong>. Ninguna es gratis — y esa es la lección. La cola sin límite parece una cuarta opción sin costo, pero es la primera con el freno roto.</p>
    </div>
  </div>

  <div class="controls">
    <h4>Ejecutar la carga</h4>
    <div class="params">
      <div class="param-group"><label>mensajes</label><input id="messages" type="number" value="120" min="1" max="2000"></div>
      <div class="param-group"><label>capacidad</label><input id="capacity" type="number" value="32" min="1" max="1000"></div>
      <div class="param-group"><label>ms por consumo</label><input id="consume_ms" type="number" value="2" min="0" max="100"></div>
      <div class="param-group"><label>política</label><select id="policy"><option value="block">block</option><option value="drop_oldest">drop_oldest</option><option value="dead_letter">dead_letter</option></select></div>
    </div>
    <div class="btns">
      <button class="btn btn-legacy" id="btn-naive">Cola sin límite</button>
      <button class="btn btn-solution" id="btn-sf">Cola acotada</button>
      <button class="btn btn-ghost" id="btn-reset">Reiniciar lab</button>
    </div>
  </div>

  <div class="compare-panel">
    <div class="compare-card naive">
      <h5>❌ Unbounded</h5>
      <div id="out-naive"><div class="empty">Sin ejecuciones todavía.</div></div>
    </div>
    <div class="compare-card sf">
      <h5>✅ Bounded</h5>
      <div id="out-sf"><div class="empty">Sin ejecuciones todavía.</div></div>
    </div>
  </div>

  <div class="note">
    <strong>Nota de fidelidad.</strong> PHP <strong>no tiene cola en proceso</strong>: no hay <code>queue.Queue</code>, ni <code>chan</code>, ni <code>BlockingQueue</code>. Acá el productor y el consumidor son pasos del mismo bucle. Las tres políticas existen igual en producción, pero viven en otra capa: <code>listen.backlog</code> de FPM para frenar, <code>pm.max_children</code> agotado para descartar (502), y la DLQ del broker real. Es el stack que mejor enseña que <strong>el backpressure es una propiedad del sistema, no de la cola</strong>.
  </div>
</div>

<footer>
  Problem-Driven Systems Lab · Caso 15 ·
  <a href="/diagnostics/summary">/diagnostics/summary</a> ·
  <a href="/queue/state">/queue/state</a> · <a href="/dlq">/dlq</a>
</footer>

<script>
const $ = (id) => document.getElementById(id);
const row = (k, v, c) => `<div class="metric-row"><span class="m-k">${k}</span><span class="m-v ${c}">${v}</span></div>`;

function render(target, d) {
  const good = target === 'out-sf';
  const kb = (d.queue_bytes_peak / 1024).toFixed(0);
  $(target).innerHTML = `
    <div class="hero">
      <div class="big ${good ? 'val-good' : 'val-bad'}" style="background:none;padding:0">${d.queue_depth_peak}</div>
      <div class="lbl">profundidad máxima de cola (${kb} KB)</div>
    </div>
    ${row('política', d.policy || '— sin límite', d.policy ? 'val-good' : 'val-bad')}
    ${row('mensaje más viejo', d.oldest_msg_age_ms_peak + ' ms', good ? 'val-good' : 'val-bad')}
    ${row('productor frenado', d.producer_blocked_ms + ' ms', 'val-neutral')}
    ${row('descartados', d.dropped, d.dropped > 0 ? 'val-bad' : 'val-neutral')}
    ${row('a la DLQ', d.dead_lettered, d.dead_lettered > 0 ? 'val-bad' : 'val-neutral')}
    ${row('producidos / consumidos', d.produced + ' / ' + d.consumed, 'val-neutral')}
    ${row('señales de backpressure', d.backpressure_signals, 'val-neutral')}
    ${row('wall total', d.wall_ms + ' ms', 'val-neutral')}
  `;
}

async function run(variant, target, btn) {
  const b = $(btn);
  b.disabled = true;
  try {
    const p = new URLSearchParams({
      messages: $('messages').value || '120',
      capacity: $('capacity').value || '32',
      consume_ms: $('consume_ms').value || '2',
    });
    if (variant === 'bounded') p.set('policy', $('policy').value);
    const res = await fetch(`/produce-${variant}?${p}`, { headers: { Accept: 'application/json' } });
    render(target, await res.json());
  } catch (e) {
    $(target).innerHTML = `<div class="empty">Error: ${e.message}</div>`;
  } finally {
    b.disabled = false;
  }
}

$('btn-naive').onclick = () => run('unbounded', 'out-naive', 'btn-naive');
$('btn-sf').onclick = () => run('bounded', 'out-sf', 'btn-sf');
$('btn-reset').onclick = async () => {
  await fetch('/reset-lab', { headers: { Accept: 'application/json' } });
  $('out-naive').innerHTML = '<div class="empty">Sin ejecuciones todavía.</div>';
  $('out-sf').innerHTML = '<div class="empty">Sin ejecuciones todavía.</div>';
};
</script>
</body>
</html>
