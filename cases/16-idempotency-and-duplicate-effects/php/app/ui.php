<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caso 16 — Idempotencia | Problem-Driven Systems Lab</title>
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
  <div><div class="case-badge">CASO 16</div></div>
  <div class="header-title">
    <h1>🔁 Idempotencia y efectos duplicados</h1>
    <p>Rendimiento · La clave caliente expira y los N llamadores pegan al origen a la vez</p>
  </div>
  <span class="stack-badge">PHP 8.3 · reserva atómica en almacenamiento</span>
</div>

<div class="container">
  <div class="cards-row">
    <div class="card problem">
      <div class="card-label">🔴 El problema</div>
      <h3>El cliente reintentó por un timeout, no porque quisiera pagar de nuevo</h3>
      <p>El primer intento sí llegó al servidor — lo que se perdió fue la <strong>respuesta</strong>. El cliente no puede distinguir «no llegó» de «llegó y no me enteré», así que reintenta. Sin clave de idempotencia el servidor tampoco puede distinguirlo, y cobra otra vez. Con cinco reintentos son cinco cobros.</p>
    </div>
    <div class="card solution">
      <div class="card-label">🟢 La corrección</div>
      <h3>Reserva atómica de la clave + outbox</h3>
      <p>Una <code>Idempotency-Key</code> reservada de forma atómica distingue «es la primera vez que veo esto» de «ya lo procesé». Y el <strong>outbox</strong> resuelve la otra mitad: el cargo y el aviso se escriben <em>juntos</em>, así que no pueden quedar desincronizados.</p>
    </div>
  </div>

  <div class="controls">
    <h4>Ejecutar los reintentos</h4>
    <div class="params">
      <div class="param-group"><label>Idempotency-Key</label><input id="key" value="order-4711"></div>
      <div class="param-group"><label>cuenta</label><input id="account" value="acct-1"></div>
      <div class="param-group"><label>reintentos</label><input id="attempts" type="number" value="5" min="1" max="64"></div>
      <div class="param-group"><label>monto (centavos)</label><input id="amount" type="number" value="2500" min="1"></div>
    </div>
    <div class="btns">
      <button class="btn btn-legacy" id="btn-naive">Sin idempotencia</button>
      <button class="btn btn-solution" id="btn-sf">Con clave + outbox</button>
      <button class="btn btn-ghost" id="btn-reset">Reiniciar lab</button>
    </div>
  </div>

  <div class="compare-panel">
    <div class="compare-card naive">
      <h5>❌ Unsafe</h5>
      <div id="out-naive"><div class="empty">Sin ejecuciones todavía.</div></div>
    </div>
    <div class="compare-card sf">
      <h5>✅ Idempotent</h5>
      <div id="out-sf"><div class="empty">Sin ejecuciones todavía.</div></div>
    </div>
  </div>

  <div class="note">
    <strong>Lo incómodo del caso.</strong> PHP no comparte heap entre requests, así que la clave <em>tiene</em> que vivir en el almacenamiento y la atomicidad la aporta el motor — <code>INSERT ... ON CONFLICT DO NOTHING</code>, o <code>flock()</code> acá. Y por eso <strong>es la única de las siete versiones que sigue siendo correcta con veinte réplicas</strong>: las otras seis resuelven la carrera dentro de su proceso, y con dos pods el mismo pago se cobra dos veces, una por pod. El stack que peor puntúa en fit de primitivas es el que tiene la respuesta que escala.
  </div>
</div>

<footer>
  Problem-Driven Systems Lab · Caso 16 ·
  <a href="/diagnostics/summary">/diagnostics/summary</a> ·
  <a href="/idempotency/state">/idempotency/state</a> · <a href="/outbox">/outbox</a>
</footer>

<script>
const $ = (id) => document.getElementById(id);
const row = (k, v, c) => `<div class="metric-row"><span class="m-k">${k}</span><span class="m-v ${c}">${v}</span></div>`;
const money = (c) => '$' + (c / 100).toFixed(2);

function render(target, d) {
  const good = target === 'out-sf';
  const over = d.overcharged_cents > 0;
  $(target).innerHTML = `
    <div class="hero">
      <div class="big ${over ? 'val-bad' : 'val-good'}" style="background:none;padding:0">${d.charges_applied}</div>
      <div class="lbl">cargos aplicados sobre ${d.attempts} reintentos</div>
    </div>
    ${row('cobrado de más', money(d.overcharged_cents), over ? 'val-bad' : 'val-good')}
    ${row('duplicados evitados', d.duplicates_prevented, good ? 'val-good' : 'val-neutral')}
    ${row('duplicados aplicados', d.duplicates_applied, d.duplicates_applied > 0 ? 'val-bad' : 'val-good')}
    ${row('saldo de la cuenta', money(d.balance_cents), 'val-neutral')}
    ${row('efectos emitidos', d.side_effects_emitted, d.side_effects_emitted > 1 ? 'val-bad' : 'val-good')}
    ${row('transporte del efecto', d.side_effect_transport, good ? 'val-good' : 'val-bad')}
    ${row('outbox pendiente', d.outbox_pending, 'val-neutral')}
    ${row('overhead del lookup', d.lookup_overhead_ms + ' ms', 'val-neutral')}
  `;
}

async function run(variant, target, btn) {
  const b = $(btn);
  b.disabled = true;
  try {
    const p = new URLSearchParams({
      key: $('key').value || 'order-4711',
      account: $('account').value || 'acct-1',
      attempts: $('attempts').value || '5',
      amount: $('amount').value || '2500',
    });
    const res = await fetch(`/charge-${variant}?${p}`, { headers: { Accept: 'application/json' } });
    render(target, await res.json());
  } catch (e) {
    $(target).innerHTML = `<div class="empty">Error: ${e.message}</div>`;
  } finally {
    b.disabled = false;
  }
}

$('btn-naive').onclick = () => run('unsafe', 'out-naive', 'btn-naive');
$('btn-sf').onclick = () => run('idempotent', 'out-sf', 'btn-sf');
$('btn-reset').onclick = async () => {
  await fetch('/reset-lab', { headers: { Accept: 'application/json' } });
  $('out-naive').innerHTML = '<div class="empty">Sin ejecuciones todavía.</div>';
  $('out-sf').innerHTML = '<div class="empty">Sin ejecuciones todavía.</div>';
};
</script>
</body>
</html>
