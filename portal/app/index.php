<?php
$cases = [
        ['id' => '01', 'slug' => 'api-latency-under-load', 'title' => 'API lenta bajo carga', 'summary' => 'La aplicación responde bien con pocos usuarios, pero degrada su latencia y estabilidad al aumentar la concurrencia.'],
        ['id' => '02', 'slug' => 'n-plus-one-and-db-bottlenecks', 'title' => 'N+1 queries y cuellos de botella en base de datos', 'summary' => 'La aplicación ejecuta demasiadas consultas por solicitud o usa el ORM de forma ineficiente, generando saturación de base de datos.'],
        ['id' => '03', 'slug' => 'poor-observability-and-useless-logs', 'title' => 'Observabilidad deficiente y logs inútiles', 'summary' => 'Existen errores e incidentes, pero no hay trazabilidad suficiente para identificar causa raíz de forma rápida y confiable.'],
        ['id' => '04', 'slug' => 'timeout-chain-and-retry-storms', 'title' => 'Cadena de timeouts y tormentas de reintentos', 'summary' => 'Una integración lenta o inestable dispara reintentos, bloqueos y cascadas de fallas entre servicios.'],
        ['id' => '05', 'slug' => 'memory-pressure-and-resource-leaks', 'title' => 'Presión de memoria y fugas de recursos', 'summary' => 'El sistema consume memoria, descriptores o conexiones de forma progresiva hasta degradar o caerse.'],
        ['id' => '06', 'slug' => 'broken-pipeline-and-fragile-delivery', 'title' => 'Pipeline roto y entrega frágil', 'summary' => 'El software funciona en desarrollo, pero falla al desplegar, promover cambios o revertir incidentes con seguridad.'],
        ['id' => '07', 'slug' => 'incremental-monolith-modernization', 'title' => 'Modernización incremental de monolito', 'summary' => 'El sistema legacy sigue siendo crítico, pero su evolución se vuelve lenta, riesgosa y costosa.'],
        ['id' => '08', 'slug' => 'critical-module-extraction-without-breaking-operations', 'title' => 'Extracción de módulo crítico sin romper operación', 'summary' => 'Se necesita desacoplar una parte clave del sistema, pero esa parte participa en flujos sensibles y no admite quiebres.'],
        ['id' => '09', 'slug' => 'unstable-external-integration', 'title' => 'Integración externa inestable', 'summary' => 'Una API, servicio o proveedor externo introduce latencia, errores intermitentes o reglas cambiantes que afectan el sistema propio.'],
        ['id' => '10', 'slug' => 'expensive-architecture-for-simple-needs', 'title' => 'Arquitectura cara para un problema simple', 'summary' => 'La solución técnica consume más servicios, complejidad y costo del que el problema de negocio realmente necesita.'],
        ['id' => '11', 'slug' => 'heavy-reporting-blocks-operations', 'title' => 'Reportes pesados que bloquean la operación', 'summary' => 'Consultas y procesos de reporting compiten con la operación transaccional y degradan el sistema completo.'],
        ['id' => '12', 'slug' => 'single-point-of-knowledge-and-operational-risk', 'title' => 'Punto único de conocimiento y riesgo operacional', 'summary' => 'Una persona, módulo o procedimiento concentra tanto conocimiento que el sistema se vuelve frágil ante ausencias o rotación.'],
];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Problem-Driven Systems Lab</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: Arial, Helvetica, sans-serif; margin: 0; background: #0f172a; color: #e2e8f0; }
    .wrap { max-width: 1200px; margin: 0 auto; padding: 32px 20px 64px; }
    h1, h2 { margin: 0 0 16px; }
    h3 { margin-top: 0; }
    p { line-height: 1.6; }
    .hero { background: linear-gradient(135deg, #111827, #1e293b); border: 1px solid #334155; border-radius: 16px; padding: 28px; margin-bottom: 24px; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
    .card { background: #111827; border: 1px solid #334155; border-radius: 14px; padding: 18px; }
    .muted { color: #94a3b8; }
    code { background: #1e293b; padding: 2px 6px; border-radius: 6px; }
  </style>
</head>
<body>
  <div class="wrap">
    <section class="hero">
      <h1>Problem-Driven Systems Lab</h1>
      <p>Laboratorio multi-stack, dockerizado y orientado a problemas reales de software. El objetivo no es coleccionar sintaxis, sino documentar cómo se analizan, justifican y resuelven problemas grandes de sistemas en producción.</p>
      <ul>
        <li>Portal raíz en PHP 8 para navegación local</li>
        <li>Casos aislados por problema y por stack</li>
        <li><code>compose.root.yml</code> solo para la landing</li>
        <li><code>compose.yml</code> por stack y <code>compose.compare.yml</code> por caso</li>
      </ul>
      <p class="muted">Revisa el README y la carpeta <code>docs/</code> para el detalle arquitectónico y documental completo.</p>
    </section>

    <section class="hero">
      <h2>Casos incluidos</h2>
      <div class="grid">
        <?php foreach ($cases as $case): ?>
          <article class="card">
            <h3><?= htmlspecialchars($case['id'] . ' - ' . $case['title']) ?></h3>
            <p><?= htmlspecialchars($case['summary']) ?></p>
            <p class="muted">Ruta sugerida: <code>cases/<?= htmlspecialchars($case['id'] . '-' . $case['slug']) ?>/</code></p>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
</body>
</html>
