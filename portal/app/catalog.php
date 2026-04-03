<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function envOr(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function metadataPath(): string
{
    return '/workspace/shared/catalog/cases.json';
}

function jsonError(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function loadCatalog(): array
{
    $path = metadataPath();
    if (!is_file($path)) {
        jsonError(500, 'No se encontro el catalogo compartido.');
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        jsonError(500, 'No se pudo leer el catalogo compartido.');
    }

    try {
        $catalog = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        jsonError(500, 'El catalogo compartido no es un JSON valido.');
    }

    if (!is_array($catalog) || !isset($catalog['cases']) || !is_array($catalog['cases'])) {
        jsonError(500, 'El catalogo compartido no tiene la estructura esperada.');
    }

    return $catalog;
}

function normalizePath(string $path): string
{
    return '/' . ltrim($path === '' ? '/' : $path, '/');
}

function buildBrowserUrl(string $host, int $port, string $path): string
{
    return sprintf('http://%s:%d%s', $host, $port, normalizePath($path));
}

function repoUrl(string $base, string $path): string
{
    return $base . ltrim($path, '/');
}

$catalog = loadCatalog();
$repoBaseUrl = 'https://github.com/vladimiracunadev-create/problem-driven-systems-lab/blob/main/';
$hostHeader = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
$browserHost = parse_url('http://' . $hostHeader, PHP_URL_HOST) ?: 'localhost';
$publicHost = envOr('PDSL_PUBLIC_HOST', $browserHost);

$cases = $catalog['cases'];
usort(
    $cases,
    static fn (array $left, array $right): int => strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''))
);

$languageMeta = [];
foreach (($catalog['languages'] ?? []) as $language) {
    if (!is_array($language) || !isset($language['key'])) {
        continue;
    }
    $languageMeta[(string) $language['key']] = $language;
}

foreach ($cases as &$case) {
    $caseId = (string) ($case['id'] ?? '');
    $case['case_readme_url'] = repoUrl($repoBaseUrl, (string) ($case['case_readme_path'] ?? 'README.md'));
    $runtimeEntries = is_array($case['runtime_entries'] ?? null) ? $case['runtime_entries'] : [];
    $case['runtime_entries'] = [];

    foreach ($runtimeEntries as $stack => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $port = (int) ($entry['port'] ?? 0);
        if ($port <= 0) {
            continue;
        }

        $stackKey = (string) $stack;
        $rootPath = normalizePath((string) ($entry['root_path'] ?? '/'));
        $healthPath = normalizePath((string) ($entry['health_path'] ?? '/health'));
        $composePath = (string) ($entry['compose_path'] ?? '');
        $readmePath = (string) ($entry['readme_path'] ?? '');
        $stackLabel = (string) (($languageMeta[$stackKey]['label'] ?? strtoupper($stackKey)));

        $case['runtime_entries'][$stackKey] = [
            'stack' => $stackKey,
            'label' => $stackLabel,
            'port' => $port,
            'base_url' => buildBrowserUrl($publicHost, $port, $rootPath),
            'health_url' => buildBrowserUrl($publicHost, $port, $healthPath),
            'compose_path' => $composePath,
            'compose_url' => repoUrl($repoBaseUrl, $composePath),
            'readme_path' => $readmePath,
            'readme_url' => repoUrl($repoBaseUrl, $readmePath),
            'up_command' => 'docker compose -f ' . $composePath . ' up -d --build',
            'probe_url' => '/probe.php?case_id=' . rawurlencode($caseId) . '&stack=' . rawurlencode($stackKey),
        ];
    }
}
unset($case);

$languages = [];
foreach (($catalog['languages'] ?? []) as $language) {
    if (!is_array($language)) {
        continue;
    }

    $languageKey = (string) ($language['key'] ?? '');
    if ($languageKey === '') {
        continue;
    }

    $languageCases = [];
    foreach ($cases as $case) {
        if (!isset($case['runtime_entries'][$languageKey])) {
            continue;
        }

        $languageCases[] = [
            'id' => $case['id'],
            'icon' => $case['icon'],
            'slug' => $case['slug'],
            'title' => $case['title'],
            'category' => $case['category'],
            'status' => $case['status'],
            'summary' => $case['summary'],
            'business_outcome' => $case['business_outcome'] ?? 'Pendiente de detallar.',
            'recruiter_pitch' => $case['recruiter_pitch'] ?? '',
            'proof_points' => $case['proof_points'] ?? [],
            'look_for' => $case['look_for'] ?? [],
            'honesty_note' => $case['honesty_note'] ?? 'La madurez se declara de forma honesta y gradual.',
            'case_readme_url' => $case['case_readme_url'],
            'runtime' => $case['runtime_entries'][$languageKey],
        ];
    }

    $languages[] = [
        'key' => $languageKey,
        'label' => $language['label'] ?? strtoupper($languageKey),
        'headline' => $language['headline'] ?? '',
        'note' => $language['note'] ?? '',
        'available' => $languageCases !== [],
        'cases' => $languageCases,
    ];
}

$documents = [];
foreach (($catalog['documents'] ?? []) as $document) {
    if (!is_array($document)) {
        continue;
    }

    $path = (string) ($document['path'] ?? 'README.md');
    $documents[] = [
        'icon' => $document['icon'] ?? '📄',
        'title' => $document['title'] ?? $path,
        'description' => $document['description'] ?? 'Documento del laboratorio.',
        'path' => $path,
        'url' => repoUrl($repoBaseUrl, $path),
    ];
}

$audiences = [];
foreach (($catalog['audiences'] ?? []) as $audience) {
    if (!is_array($audience)) {
        continue;
    }

    $path = (string) ($audience['document_path'] ?? 'README.md');
    $audiences[] = [
        'key' => $audience['key'] ?? 'audience',
        'icon' => $audience['icon'] ?? '🧭',
        'label' => $audience['label'] ?? 'Audience',
        'headline' => $audience['headline'] ?? '',
        'description' => $audience['description'] ?? '',
        'goal' => $audience['goal'] ?? '',
        'document_path' => $path,
        'document_url' => repoUrl($repoBaseUrl, $path),
    ];
}

$response = [
    'lab' => $catalog['lab'] ?? [],
    'recommended_github_about' => $catalog['recommended_github_about'] ?? '',
    'recommended_github_topics' => $catalog['recommended_github_topics'] ?? [],
    'documents' => $documents,
    'audiences' => $audiences,
    'languages' => $languages,
    'stats' => [
        'cases_total' => count($cases),
        'cases_operational' => count(array_filter($cases, static fn (array $case): bool => ($case['status'] ?? '') === 'OPERATIVO')),
        'stacks_operational' => array_sum(array_map(static fn (array $case): int => count($case['runtime_entries'] ?? []), $cases)),
    ],
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
