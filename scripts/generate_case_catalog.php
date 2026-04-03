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
    $cases = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, "JSON invalido en {$metadataPath}: {$e->getMessage()}\n");
    exit(1);
}

if (!is_array($cases)) {
    fwrite(STDERR, "El archivo de metadatos no contiene una lista valida de casos.\n");
    exit(1);
}

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
$lines[] = '| Icono | Caso | Categoria | Estado | Stacks operativos | Nivel actual |';
$lines[] = '| --- | --- | --- | --- | --- | --- |';

foreach ($cases as $case) {
    $id = (string) ($case['id'] ?? '??');
    $slug = (string) ($case['slug'] ?? '');
    $icon = (string) ($case['icon'] ?? '•');
    $title = (string) ($case['title'] ?? 'Sin titulo');
    $category = (string) ($case['category'] ?? 'Sin categoria');
    $status = (string) ($case['status'] ?? 'SIN ESTADO');
    $levelDetail = (string) ($case['level_detail'] ?? 'sin detalle');
    $stacks = $case['operational_stacks'] ?? [];

    $stacksMarkdown = '—';
    if (is_array($stacks) && $stacks !== []) {
        $stacksMarkdown = implode(', ', array_map(static fn (string $stack): string => "`{$stack}`", $stacks));
    }

    $lines[] = sprintf(
        '| %s | [%s - %s](../cases/%s-%s/README.md) | %s | `%s` | %s | %s |',
        $icon,
        $id,
        $title,
        $id,
        $slug,
        $category,
        $status,
        $stacksMarkdown,
        $levelDetail
    );
}

$lines[] = '';
$lines[] = '## 🧭 Ruta recomendada';
$lines[] = '';
$lines[] = 'Si quieres evaluar el repositorio rapido, empieza por:';
$lines[] = '';
$lines[] = '1. Caso `01` para rendimiento + observabilidad.';
$lines[] = '2. Caso `02` para modelado serio de acceso a datos.';
$lines[] = '3. Caso `03` para diagnostico, logs estructurados y trazabilidad.';
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
