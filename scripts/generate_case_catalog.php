<?php

declare(strict_types=1);

$metadataPath = __DIR__ . '/../shared/catalog/cases.json';
$outputPath = __DIR__ . '/../docs/case-catalog.md';
$checkMode = in_array('--check', $argv, true);

if (!is_file($metadataPath)) {
    fwrite(STDERR, "No se encontro el archivo de metadatos: {$metadataPath}\n");
    exit(1);
}

$raw = file_get_contents($metadataPath);
if ($raw === false) {
    fwrite(STDERR, "No se pudo leer {$metadataPath}\n");
    exit(1);
}

try {
    $catalog = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, "JSON invalido en {$metadataPath}: {$e->getMessage()}\n");
    exit(1);
}

if (!is_array($catalog) || !isset($catalog['cases']) || !is_array($catalog['cases'])) {
    fwrite(STDERR, "El archivo de metadatos no contiene un catalogo valido.\n");
    exit(1);
}

$cases = $catalog['cases'];
usort(
    $cases,
    static fn (array $left, array $right): int => strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''))
);

$lines = [];
$lines[] = '# 🗂️ Catalogo de casos';
$lines[] = '';
$lines[] = '> Lista completa de los 12 casos del laboratorio generada desde `shared/catalog/cases.json`.';
$lines[] = '';
$lines[] = '## 📊 Estado actual';
$lines[] = '';
$lines[] = '| Icono | Caso | Categoria | Estado | Stacks operativos | Nivel actual | Impacto de negocio |';
$lines[] = '| --- | --- | --- | --- | --- | --- | --- |';

foreach ($cases as $case) {
    $id = (string) ($case['id'] ?? '??');
    $icon = (string) ($case['icon'] ?? '•');
    $title = (string) ($case['title'] ?? 'Sin titulo');
    $category = (string) ($case['category'] ?? 'Sin categoria');
    $status = (string) ($case['status'] ?? 'SIN ESTADO');
    $levelDetail = (string) ($case['level_detail'] ?? 'sin detalle');
    $businessOutcome = (string) ($case['business_outcome'] ?? 'Pendiente de detallar.');
    $caseReadmePath = (string) ($case['case_readme_path'] ?? 'README.md');
    $stacks = $case['operational_stacks'] ?? [];

    $stacksMarkdown = '—';
    if (is_array($stacks) && $stacks !== []) {
        $stacksMarkdown = implode(', ', array_map(static fn (string $stack): string => "`{$stack}`", $stacks));
    }

    $lines[] = sprintf(
        '| %s | [%s - %s](../%s) | %s | `%s` | %s | %s | %s |',
        $icon,
        $id,
        $title,
        $caseReadmePath,
        $category,
        $status,
        $stacksMarkdown,
        $levelDetail,
        $businessOutcome
    );
}

$lines[] = '';
$lines[] = '## ✅ Casos operativos hoy';
$lines[] = '';

foreach ($cases as $case) {
    if (($case['status'] ?? '') !== 'OPERATIVO') {
        continue;
    }

    $icon = (string) ($case['icon'] ?? '•');
    $id = (string) ($case['id'] ?? '??');
    $title = (string) ($case['title'] ?? 'Sin titulo');
    $readme = (string) ($case['case_readme_path'] ?? 'README.md');
    $stacks = implode(', ', array_map(static fn (string $stack): string => "`{$stack}`", $case['operational_stacks'] ?? []));
    $proofPoints = $case['proof_points'] ?? [];
    $firstProof = is_array($proofPoints) && $proofPoints !== [] ? (string) $proofPoints[0] : 'Caso operativo con evidencia observable.';

    $lines[] = sprintf('### %s [%s - %s](../%s)', $icon, $id, $title, $readme);
    $lines[] = '';
    $lines[] = '- Stacks operativos: ' . $stacks;
    $lines[] = '- Impacto de negocio: ' . (string) ($case['business_outcome'] ?? 'Pendiente de detallar.');
    $lines[] = '- Que demuestra: ' . $firstProof;
    $lines[] = '';
}

$lines[] = '## 🧭 Rutas de evaluacion';
$lines[] = '';
$lines[] = '| Audiencia | Punto de entrada | Que obtiene |';
$lines[] = '| --- | --- | --- |';

foreach (($catalog['audiences'] ?? []) as $audience) {
    $label = (string) ($audience['label'] ?? 'Audiencia');
    $icon = (string) ($audience['icon'] ?? '•');
    $documentPath = (string) ($audience['document_path'] ?? 'README.md');
    $goal = (string) ($audience['goal'] ?? 'Entender el laboratorio.');
    $lines[] = sprintf('| %s %s | [Abrir](../%s) | %s |', $icon, $label, $documentPath, $goal);
}

$lines[] = '';
$lines[] = '## 🏷️ Leyenda';
$lines[] = '';
$lines[] = '| Estado | Significado |';
$lines[] = '| --- | --- |';
$lines[] = '| `OPERATIVO` | Implementacion real con Docker y evidencia observable |';
$lines[] = '| `DOCUMENTADO / SCAFFOLD` | Caso bien modelado pero aun no profundizado del todo |';
$lines[] = '| `PLANIFICADO` | Futuro del roadmap, todavia no presente en el arbol actual |';

$content = implode(PHP_EOL, $lines) . PHP_EOL;

if ($checkMode) {
    $current = is_file($outputPath) ? file_get_contents($outputPath) : '';
    if ($current !== $content) {
        fwrite(STDERR, "docs/case-catalog.md esta desalineado con shared/catalog/cases.json\n");
        exit(1);
    }

    fwrite(STDOUT, "Catalogo sincronizado.\n");
    exit(0);
}

if (file_put_contents($outputPath, $content) === false) {
    fwrite(STDERR, "No se pudo escribir {$outputPath}\n");
    exit(1);
}

fwrite(STDOUT, "Catalogo regenerado en {$outputPath}\n");
