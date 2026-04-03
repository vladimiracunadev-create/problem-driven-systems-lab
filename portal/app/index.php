<?php

declare(strict_types=1);

$metadataPath = '/workspace/shared/catalog/cases.json';
$cases = [];
$metadataWarning = null;

if (is_file($metadataPath)) {
    $raw = file_get_contents($metadataPath);
    if ($raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $cases = $decoded;
        } else {
            $metadataWarning = 'No se pudo interpretar el catalogo compartido.';
        }
    } else {
        $metadataWarning = 'No se pudo leer el catalogo compartido.';
    }
} else {
    $metadataWarning = 'No se encontro el archivo de metadatos compartidos.';
}

usort(
    $cases,
    static fn (array $left, array $right): int => strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''))
);

$operationalCases = array_values(array_filter(
    $cases,
    static fn (array $case): bool => ($case['status'] ?? '') === 'OPERATIVO'
));
$documentedCases = array_values(array_filter(
    $cases,
    static fn (array $case): bool => ($case['status'] ?? '') === 'DOCUMENTADO / SCAFFOLD'
));
$operationalStacks = 0;
foreach ($cases as $case) {
    $operationalStacks += count($case['operational_stacks'] ?? []);
}

$docCards = [
    ['icon' => '📘', 'title' => 'README.md', 'summary' => 'Entrada principal del laboratorio.', 'path' => 'README.md'],
    ['icon' => '🏗️', 'title' => 'ARCHITECTURE.md', 'summary' => 'Arquitectura actual del sistema y del repositorio.', 'path' => 'ARCHITECTURE.md'],
    ['icon' => '👔', 'title' => 'RECRUITER.md', 'summary' => 'Ruta ejecutiva para reclutadores y hiring managers.', 'path' => 'RECRUITER.md'],
    ['icon' => '🚀', 'title' => 'INSTALL.md', 'summary' => 'Puesta en marcha con Docker Compose.', 'path' => 'INSTALL.md'],
    ['icon' => '🛠️', 'title' => 'RUNBOOK.md', 'summary' => 'Operacion, diagnostico y respuesta inicial.', 'path' => 'RUNBOOK.md'],
    ['icon' => '🗂️', 'title' => 'docs/case-catalog.md', 'summary' => 'Catalogo generado automaticamente desde metadatos.', 'path' => 'docs/case-catalog.md'],
];

function statusClass(string $status): string
{
    return match ($status) {
        'OPERATIVO' => 'status status-live',
        'DOCUMENTADO / SCAFFOLD' => 'status status-docs',
        default => 'status status-plan',
    };
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Problem-Driven Systems Lab</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      --bg: #09111f;
      --bg-soft: #0f1b31;
      --panel: rgba(11, 20, 38, 0.88);
      --panel-2: rgba(18, 31, 58, 0.92);
      --line: rgba(148, 163, 184, 0.18);
      --text: #e5edf9;
      --muted: #9fb0ca;
      --accent: #5eead4;
      --accent-2: #f59e0b;
      --ok: #22c55e;
      --warn: #f59e0b;
      --plan: #94a3b8;
      --shadow: 0 24px 70px rgba(2, 8, 23, 0.45);
    }

    * { box-sizing: border-box; }
    body {
      margin: 0;
      color: var(--text);
      background:
        radial-gradient(circle at top left, rgba(94, 234, 212, 0.12), transparent 28%),
        radial-gradient(circle at right, rgba(245, 158, 11, 0.12), transparent 24%),
        linear-gradient(180deg, #07101d 0%, #09111f 50%, #0b1322 100%);
      font-family: "Aptos", "Trebuchet MS", "Segoe UI", sans-serif;
    }
    .wrap {
      max-width: 1240px;
      margin: 0 auto;
      padding: 32px 20px 72px;
    }
    .hero {
      position: relative;
      overflow: hidden;
      padding: 34px;
      border-radius: 28px;
      border: 1px solid var(--line);
      background:
        linear-gradient(135deg, rgba(14, 24, 43, 0.96), rgba(8, 16, 31, 0.96)),
        linear-gradient(120deg, rgba(94, 234, 212, 0.15), transparent 40%);
      box-shadow: var(--shadow);
    }
    .hero::after {
      content: "";
      position: absolute;
      inset: auto -120px -120px auto;
      width: 280px;
      height: 280px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(94, 234, 212, 0.18), transparent 70%);
      pointer-events: none;
    }
    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 14px;
      border-radius: 999px;
      border: 1px solid rgba(94, 234, 212, 0.22);
      background: rgba(94, 234, 212, 0.08);
      color: #bafaf0;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      font-size: 12px;
      font-weight: 700;
    }
    h1, h2, h3, h4 { margin: 0; }
    h1 {
      margin-top: 18px;
      font-size: clamp(2.4rem, 4vw, 4.3rem);
      line-height: 0.96;
      letter-spacing: -0.05em;
    }
    p {
      margin: 0;
      line-height: 1.7;
      color: var(--muted);
    }
    .hero-copy {
      max-width: 760px;
      margin-top: 16px;
      font-size: 1.05rem;
    }
    .hero-grid,
    .stats,
    .docs-grid,
    .case-grid {
      display: grid;
      gap: 16px;
    }
    .hero-grid {
      grid-template-columns: 1.7fr 1fr;
      margin-top: 30px;
    }
    .stats {
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      margin-top: 22px;
    }
    .stat,
    .doc-card,
    .case-card,
    .system-card {
      border-radius: 22px;
      border: 1px solid var(--line);
      background: var(--panel);
      box-shadow: var(--shadow);
    }
    .stat {
      padding: 18px 20px;
    }
    .stat small {
      display: block;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-size: 11px;
      margin-bottom: 8px;
    }
    .stat strong {
      display: block;
      font-size: 2rem;
      line-height: 1;
    }
    .hero-side,
    .system-card,
    .doc-card,
    .case-card {
      padding: 22px;
    }
    .hero-side ul {
      margin: 14px 0 0;
      padding-left: 18px;
      color: var(--muted);
      line-height: 1.75;
    }
    .section-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin: 32px 0 16px;
    }
    .section-head p {
      max-width: 720px;
    }
    .docs-grid {
      grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    }
    .doc-card h3,
    .system-card h3 {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 1.04rem;
    }
    .doc-card p,
    .system-card p {
      margin-top: 12px;
    }
    .path {
      display: inline-flex;
      margin-top: 14px;
      padding: 7px 11px;
      border-radius: 999px;
      border: 1px solid rgba(148, 163, 184, 0.2);
      background: rgba(15, 23, 42, 0.8);
      color: #d6e3f8;
      font-family: "Cascadia Code", "Consolas", monospace;
      font-size: 12px;
    }
    .case-grid {
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    }
    .case-card {
      background:
        linear-gradient(180deg, rgba(13, 23, 42, 0.96), rgba(10, 18, 33, 0.96)),
        var(--panel);
    }
    .case-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 14px;
    }
    .case-title {
      display: flex;
      gap: 12px;
      align-items: center;
    }
    .case-icon {
      display: inline-flex;
      width: 48px;
      height: 48px;
      align-items: center;
      justify-content: center;
      border-radius: 16px;
      background: linear-gradient(135deg, rgba(94, 234, 212, 0.14), rgba(56, 189, 248, 0.08));
      border: 1px solid rgba(94, 234, 212, 0.22);
      font-size: 1.3rem;
    }
    .case-title strong {
      display: block;
      font-size: 1rem;
      line-height: 1.2;
    }
    .case-title span {
      display: block;
      color: var(--muted);
      font-size: 0.88rem;
      margin-top: 4px;
    }
    .status {
      display: inline-flex;
      align-items: center;
      padding: 7px 12px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      white-space: nowrap;
    }
    .status-live {
      color: #d2ffe0;
      background: rgba(34, 197, 94, 0.16);
      border: 1px solid rgba(34, 197, 94, 0.22);
    }
    .status-docs {
      color: #fff2d3;
      background: rgba(245, 158, 11, 0.16);
      border: 1px solid rgba(245, 158, 11, 0.22);
    }
    .status-plan {
      color: #d9e2ef;
      background: rgba(148, 163, 184, 0.14);
      border: 1px solid rgba(148, 163, 184, 0.22);
    }
    .chips {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 16px;
    }
    .chip {
      display: inline-flex;
      align-items: center;
      padding: 6px 10px;
      border-radius: 999px;
      background: rgba(30, 41, 59, 0.95);
      border: 1px solid rgba(148, 163, 184, 0.18);
      color: #d6e3f8;
      font-size: 12px;
    }
    .warning {
      margin-top: 16px;
      padding: 14px 16px;
      border-radius: 18px;
      border: 1px solid rgba(245, 158, 11, 0.24);
      background: rgba(245, 158, 11, 0.1);
      color: #ffe8b3;
    }
    .system-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 16px;
    }
    @media (max-width: 920px) {
      .hero-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <section class="hero">
      <span class="eyebrow">🧪 Problem-Driven • Docker-First • Catalogo unificado</span>
      <h1>Problem-Driven<br>Systems Lab</h1>
      <p class="hero-copy">
        Laboratorio multi-stack orientado a problemas reales de software. El objetivo no es coleccionar sintaxis,
        sino documentar como se analizan, justifican y resuelven incidentes y decisiones de arquitectura con evidencia reproducible.
      </p>

      <?php if ($metadataWarning !== null): ?>
        <div class="warning">⚠️ <?= htmlspecialchars($metadataWarning) ?></div>
      <?php endif; ?>

      <div class="stats">
        <article class="stat">
          <small>Casos definidos</small>
          <strong><?= count($cases) ?></strong>
        </article>
        <article class="stat">
          <small>Casos operativos</small>
          <strong><?= count($operationalCases) ?></strong>
        </article>
        <article class="stat">
          <small>Stacks operativos</small>
          <strong><?= $operationalStacks ?></strong>
        </article>
        <article class="stat">
          <small>Scaffold / docs</small>
          <strong><?= count($documentedCases) ?></strong>
        </article>
      </div>

      <div class="hero-grid">
        <article class="system-card">
          <h3>🏗️ Sistema actual</h3>
          <p>
            El portal local no hardcodea el catalogo: lo consume desde <code>shared/catalog/cases.json</code>.
            La documentacion del catalogo se genera desde el mismo archivo, reduciendo drift entre portal y reportes.
          </p>
          <div class="chips">
            <span class="chip">📘 README.md</span>
            <span class="chip">🏗️ ARCHITECTURE.md</span>
            <span class="chip">🗂️ docs/case-catalog.md</span>
            <span class="chip">🛠️ scripts/generate_case_catalog.php</span>
          </div>
        </article>

        <aside class="hero-side">
          <h3>🚀 Ruta recomendada</h3>
          <ul>
            <li>Levanta este portal con <code>docker compose -f compose.root.yml up -d --build</code>.</li>
            <li>Revisa <code>INSTALL.md</code> y <code>RUNBOOK.md</code> para los casos operativos.</li>
            <li>Empieza por los casos 01, 02 o 03 para evaluar profundidad real.</li>
            <li>Usa <code>compose.compare.yml</code> solo cuando quieras contrastar stacks.</li>
          </ul>
        </aside>
      </div>
    </section>

    <section>
      <div class="section-head">
        <div>
          <h2>📚 Reportes y rutas de lectura</h2>
          <p>La capa documental ahora esta separada por audiencia: ejecutiva, tecnica, operativa y de soporte.</p>
        </div>
      </div>

      <div class="docs-grid">
        <?php foreach ($docCards as $doc): ?>
          <article class="doc-card">
            <h3><?= htmlspecialchars($doc['icon'] . ' ' . $doc['title']) ?></h3>
            <p><?= htmlspecialchars($doc['summary']) ?></p>
            <span class="path"><?= htmlspecialchars($doc['path']) ?></span>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section>
      <div class="section-head">
        <div>
          <h2>🗂️ Casos del laboratorio</h2>
          <p>Estados, categorias e implementaciones reales a partir del catalogo compartido.</p>
        </div>
      </div>

      <div class="case-grid">
        <?php foreach ($cases as $case): ?>
          <?php $status = (string) ($case['status'] ?? 'SIN ESTADO'); ?>
          <article class="case-card">
            <div class="case-top">
              <div class="case-title">
                <span class="case-icon"><?= htmlspecialchars((string) ($case['icon'] ?? '•')) ?></span>
                <div>
                  <strong><?= htmlspecialchars((string) ($case['id'] ?? '??') . ' - ' . (string) ($case['title'] ?? 'Sin titulo')) ?></strong>
                  <span><?= htmlspecialchars((string) ($case['category'] ?? 'Sin categoria')) ?></span>
                </div>
              </div>
              <span class="<?= htmlspecialchars(statusClass($status)) ?>"><?= htmlspecialchars($status) ?></span>
            </div>

            <p><?= htmlspecialchars((string) ($case['summary'] ?? 'Sin resumen.')) ?></p>

            <div class="chips">
              <span class="chip">📁 cases/<?= htmlspecialchars((string) ($case['id'] ?? '??') . '-' . (string) ($case['slug'] ?? 'sin-slug')) ?>/</span>
              <span class="chip">🧭 <?= htmlspecialchars((string) ($case['level_detail'] ?? 'Sin detalle')) ?></span>
            </div>

            <?php if (!empty($case['operational_stacks']) && is_array($case['operational_stacks'])): ?>
              <div class="chips">
                <?php foreach ($case['operational_stacks'] as $stack): ?>
                  <span class="chip">✅ <?= htmlspecialchars((string) $stack) ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
</body>
</html>
