<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$metadataPath = '/workspace/shared/catalog/cases.json';
if (!is_file($metadataPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'No se encontro el catalogo compartido.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents($metadataPath);
if ($raw === false) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo leer el catalogo compartido.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$cases = json_decode($raw, true);
if (!is_array($cases)) {
    http_response_code(500);
    echo json_encode(['error' => 'El catalogo compartido no es un JSON valido.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

usort(
    $cases,
    static fn (array $left, array $right): int => strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''))
);

$repoBaseUrl = 'https://github.com/vladimiracunadev-create/problem-driven-systems-lab/blob/main/';
$hostHeader = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
$host = parse_url('http://' . $hostHeader, PHP_URL_HOST) ?: 'localhost';

$aboutText = 'Problem-driven systems lab with Docker-first cases for performance, observability, resilience, architecture, and operational risk across PHP, Node.js, Python, Java, and .NET.';
$topics = [
    'problem-driven',
    'docker',
    'docker-compose',
    'php',
    'nodejs',
    'python',
    'java',
    'dotnet',
    'observability',
    'performance',
    'resilience',
    'systems-design',
    'software-architecture',
    'legacy-modernization',
    'technical-portfolio',
    'postgresql',
    'prometheus',
    'grafana',
];

$stackMeta = [
    'php' => [
        'label' => 'PHP',
        'headline' => 'Stack principal hoy para profundidad funcional.',
        'note' => 'Muestra los casos mas maduros y mejor instrumentados del laboratorio.',
    ],
    'node' => [
        'label' => 'Node.js',
        'headline' => 'Stack operativo real en observabilidad comparada.',
        'note' => 'Ideal para mostrar transferibilidad del criterio fuera de PHP.',
    ],
    'python' => [
        'label' => 'Python',
        'headline' => 'Stack operativo real en observabilidad comparada.',
        'note' => 'Permite contrastar la misma narrativa operacional en otro runtime.',
    ],
    'java' => [
        'label' => 'Java',
        'headline' => 'Aun sin casos operativos profundizados.',
        'note' => 'La estructura existe, pero la madurez real todavia esta en evolucion.',
    ],
    'dotnet' => [
        'label' => '.NET',
        'headline' => 'Aun sin casos operativos profundizados.',
        'note' => 'La estructura existe, pero la madurez real todavia esta en evolucion.',
    ],
];

$businessOutcomes = [
    '01' => 'Reduce latencia visible y evita sobredimensionar infraestructura a ciegas.',
    '02' => 'Reduce round-trips, costo por request y desgaste innecesario sobre la base de datos.',
    '03' => 'Reduce MTTR y convierte incidentes vagos en fallas diagnosticables con evidencia.',
];

$stackLinks = [
    '01' => [
        'php' => [
            'port' => 811,
            'compose_path' => 'cases/01-api-latency-under-load/php/compose.yml',
            'readme_path' => 'cases/01-api-latency-under-load/php/README.md',
            'health_path' => '/health',
            'root_path' => '/',
        ],
    ],
    '02' => [
        'php' => [
            'port' => 812,
            'compose_path' => 'cases/02-n-plus-one-and-db-bottlenecks/php/compose.yml',
            'readme_path' => 'cases/02-n-plus-one-and-db-bottlenecks/php/README.md',
            'health_path' => '/health',
            'root_path' => '/',
        ],
    ],
    '03' => [
        'php' => [
            'port' => 813,
            'compose_path' => 'cases/03-poor-observability-and-useless-logs/php/compose.yml',
            'readme_path' => 'cases/03-poor-observability-and-useless-logs/php/README.md',
            'health_path' => '/health',
            'root_path' => '/',
        ],
        'node' => [
            'port' => 823,
            'compose_path' => 'cases/03-poor-observability-and-useless-logs/node/compose.yml',
            'readme_path' => 'cases/03-poor-observability-and-useless-logs/node/README.md',
            'health_path' => '/health',
            'root_path' => '/',
        ],
        'python' => [
            'port' => 833,
            'compose_path' => 'cases/03-poor-observability-and-useless-logs/python/compose.yml',
            'readme_path' => 'cases/03-poor-observability-and-useless-logs/python/README.md',
            'health_path' => '/health',
            'root_path' => '/',
        ],
    ],
];

foreach ($cases as &$case) {
    $caseId = (string) ($case['id'] ?? '');
    $case['business_outcome'] = $businessOutcomes[$caseId] ?? 'Este caso sigue documentado para crecimiento futuro.';
    $case['runtime_entries'] = [];

    foreach (($case['operational_stacks'] ?? []) as $stack) {
        $entry = $stackLinks[$caseId][$stack] ?? null;
        if ($entry === null) {
            continue;
        }

        $baseUrl = sprintf('http://%s:%d', $host, $entry['port']);
        $case['runtime_entries'][$stack] = [
            'stack' => $stack,
            'label' => $stackMeta[$stack]['label'] ?? strtoupper((string) $stack),
            'base_url' => $baseUrl . $entry['root_path'],
            'health_url' => $baseUrl . $entry['health_path'],
            'compose_path' => $entry['compose_path'],
            'compose_url' => $repoBaseUrl . $entry['compose_path'],
            'readme_path' => $entry['readme_path'],
            'readme_url' => $repoBaseUrl . $entry['readme_path'],
            'up_command' => 'docker compose -f ' . $entry['compose_path'] . ' up -d --build',
        ];
    }
}
unset($case);

$languages = [];
foreach ($stackMeta as $key => $meta) {
    $languageCases = [];
    foreach ($cases as $case) {
        if (!isset($case['runtime_entries'][$key])) {
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
            'business_outcome' => $case['business_outcome'],
            'runtime' => $case['runtime_entries'][$key],
        ];
    }

    $languages[] = [
        'key' => $key,
        'label' => $meta['label'],
        'headline' => $meta['headline'],
        'note' => $meta['note'],
        'available' => $languageCases !== [],
        'cases' => $languageCases,
    ];
}

$response = [
    'lab' => [
        'name' => 'Problem-Driven Systems Lab',
        'tagline' => 'Laboratorio Docker-first orientado a rendimiento, observabilidad, resiliencia, arquitectura y operacion.',
        'audience_message' => 'Pensado para reclutadores, lideres tecnicos y personas que necesitan entender rapidamente que hace el producto y por que importa.',
    ],
    'recommended_github_about' => $aboutText,
    'recommended_github_topics' => $topics,
    'stats' => [
        'cases_total' => count($cases),
        'cases_operational' => count(array_filter($cases, static fn (array $case): bool => ($case['status'] ?? '') === 'OPERATIVO')),
        'stacks_operational' => array_sum(array_map(static fn (array $case): int => count($case['operational_stacks'] ?? []), $cases)),
    ],
    'documents' => [
        ['icon' => '👔', 'title' => 'RECRUITER.md', 'url' => $repoBaseUrl . 'RECRUITER.md'],
        ['icon' => '🏗️', 'title' => 'ARCHITECTURE.md', 'url' => $repoBaseUrl . 'ARCHITECTURE.md'],
        ['icon' => '🚀', 'title' => 'INSTALL.md', 'url' => $repoBaseUrl . 'INSTALL.md'],
        ['icon' => '🛠️', 'title' => 'RUNBOOK.md', 'url' => $repoBaseUrl . 'RUNBOOK.md'],
        ['icon' => '🗂️', 'title' => 'docs/case-catalog.md', 'url' => $repoBaseUrl . 'docs/case-catalog.md'],
    ],
    'languages' => $languages,
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
