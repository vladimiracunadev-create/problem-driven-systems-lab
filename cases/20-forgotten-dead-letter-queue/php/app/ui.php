<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caso 20 — La dead letter queue olvidada | Problem-Driven Systems Lab</title>
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
  <div><div class="case-badge">CASO 20</div></div>
  <div class="header-title">
    <h1>🪦 La dead letter queue olvidada</h1>
    <p>Resiliencia · Cierra el arco del caso 15, donde la DLQ nace</p>
  </div>
  <span class="stack-badge">PHP 8.3 · catch union · drenaje por cron</span>
</div>

<div class="container">
  <div class="cards-row">
    <div class="card problem">
      <div class="card-label">🔴 El problema</div>
      <h3>El pipeline se ve sano porque los errores se fueron a otro lado</h3>
      <p>El consumidor falla, manda el mensaje a la DLQ y sigue. Sin clasificar, sin reintentar, sin medir, sin alerta. <strong>Throughput normal, latencia normal, cero errores</strong> — y el 16% de los mensajes nunca se procesó.</p>
    </div>
    <div class="card solution">
      <div class="card-label">🟢 La corrección</div>
      <h3>Clasificar · reintentar · medir · drenar</h3>
      <p>Lo <em>transitorio</em> se reintenta y casi todo se recupera. Solo lo <em>venenoso</em> llega a la DLQ, con su clase de error y una muestra del payload. La profundidad se publica y hay umbral que alerta.</p>
    </div>
  </div>

  <div class="controls">
    <h4>Ejecutar el escenario</h4>
    <div class="params">
      <div class="param-group"><label>mensajes</label><input id="messages" type="number" value="3000" min="10" max="200000" step="100"></div>
      <div class="param-group"><label>transitorios (%)</label><input id="transient_pct" type="number" value="12" min="0" max="100"></div>
      <div class="param-group"><label>venenosos (%)</label><input id="poison_pct" type="number" value="4" min="0" max="100"></div>
      <div class="param-group"><label>umbral de alerta</label><input id="alert_threshold" type="number" value="50" min="0"></div>
    </div>
    <div class="btns">
      <button class="btn btn-legacy" id="btn-naive">Consumidor silencioso</button>
      <button class="btn btn-solution" id="btn-sf">Consumidor observado</button>
      <button class="btn btn-ghost" id="btn-drain">Drenar la DLQ</button>
      <button class="btn btn-ghost" id="btn-reset">Reiniciar lab</button>
    </div>
  </div>

  <div class="compare-panel">
    <div class="compare-card naive">
      <h5>❌ Silencioso</h5>
      <div id="out-naive"><div class="empty">Sin ejecuciones todavía.</div></div>
    </div>
    <div class="compare-card sf">
      <h5>✅ Observado</h5>
      <div id="out-sf"><div class="empty">Sin ejecuciones todavía.</div></div>
    </div>
  </div>

  <div class="note">
    <strong>Transitorio contra venenoso.</strong> Un error <em>transitorio</em> es aquel donde el mismo mensaje funciona en el próximo intento: un timeout, un 503 del downstream, un deadlock. Un error <em>venenoso</em> es aquel donde el mismo mensaje <strong>nunca</strong> va a funcionar: schema roto, campo desconocido, encoding inválido. <strong>Reintentar lo venenoso es quemar CPU; mandar lo transitorio a la DLQ es tirar trabajo que se podía salvar.</strong> El consumidor que no distingue hace las dos cosas mal a la vez.
    <br><br>
    <strong>Lo que aporta este stack.</strong> Los tipos union en <code>catch (A | B $e)</code> dicen «estos dos se tratan igual» sin duplicar el bloque. Y <code>Throwable</code> como raíz común de <code>Exception</code> y <code>Error</code> hace explícito que capturar todo incluye capturar los bugs propios: un <code>TypeError</code> termina en la DLQ como si fuera un mensaje corrupto. <strong>El drenaje como comando de cron</strong> es una ventaja operativa real — se ejecuta a mano en un incidente sin redesplegar nada. En contra: PHP no da exhaustividad de ninguna clase, así que una clase de error nueva simplemente deja de manejarse.
  </div>
</div>

<footer>
  Problem-Driven Systems Lab · Caso 20 ·
  <a href="/diagnostics/summary">/diagnostics/summary</a> ·
  <a href="/dlq/stats">/dlq/stats</a>
</footer>

<script>
const $ = (id) => document.getElementById(id);
const row = (k, v, c) => `<div class="metric-row"><span class="m-k">${k}</span><span class="m-v ${c}">${v}</span></div>`;

function clases(obj) {
  const e = Object.entries(obj || {});
  if (!e.length) return '—';
  return e.map(([k, v]) => `${k}:${v}`).join(' · ');
}

function render(target, d) {
  const good = target === 'out-sf';
  $(target).innerHTML = `
    <div class="hero">
      <div class="big ${d.dead_letter_rate_pct < 5 ? 'val-good' : 'val-bad'}" style="background:none;padding:0">${d.dead_letter_rate_pct}%</div>
      <div class="lbl">mensajes que terminaron en la DLQ</div>
    </div>
    ${row('consumidos', d.consumed, 'val-neutral')}
    ${row('procesados con éxito', d.succeeded, good ? 'val-good' : 'val-bad')}
    ${row('reintentos', d.retried, 'val-neutral')}
    ${row('a la DLQ', d.dead_lettered, d.dead_lettered > 0 && !good ? 'val-bad' : 'val-neutral')}
    ${row('profundidad de la DLQ', d.dlq_depth, d.dlq_depth > d.alert_threshold ? 'val-bad' : 'val-good')}
    ${row('antigüedad del más viejo', d.dlq_oldest_msg_age_ms + ' ms', 'val-neutral')}
    ${row('alertas disparadas', d.alerts_fired, d.alerts_fired > 0 ? 'val-good' : 'val-bad')}
    ${row('payloads muestreados', d.sampled, d.sampled > 0 ? 'val-good' : 'val-bad')}
    ${row('por clase de error', clases(d.by_error_class), 'val-neutral')}
  `;
}

async function run(variant, target, btn) {
  const b = $(btn);
  b.disabled = true;
  try {
    const p = new URLSearchParams({
      messages: $('messages').value || '3000',
      transient_pct: $('transient_pct').value || '12',
      poison_pct: $('poison_pct').value || '4',
      alert_threshold: $('alert_threshold').value || '50',
    });
    const res = await fetch(`/consume-${variant}?${p}`, { headers: { Accept: 'application/json' } });
    render(target, await res.json());
  } catch (e) {
    $(target).innerHTML = `<div class="empty">Error: ${e.message}</div>`;
  } finally {
    b.disabled = false;
  }
}

$('btn-naive').onclick = () => run('silent', 'out-naive', 'btn-naive');
$('btn-sf').onclick = () => run('observed', 'out-sf', 'btn-sf');
$('btn-drain').onclick = async () => {
  const res = await fetch('/dlq/drain?limit=500', { headers: { Accept: 'application/json' } });
  const d = await res.json();
  alert(`Drenaje: ${d.drained_ok} recuperados, ${d.drain_failed} siguen siendo veneno (${d.recovered_pct}% recuperado). Quedan ${d.dlq_depth_after} en la DLQ.`);
};
$('btn-reset').onclick = async () => {
  await fetch('/reset-lab', { headers: { Accept: 'application/json' } });
  $('out-naive').innerHTML = '<div class="empty">Sin ejecuciones todavía.</div>';
  $('out-sf').innerHTML = '<div class="empty">Sin ejecuciones todavía.</div>';
};
</script>
</body>
</html>
