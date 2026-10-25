<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$checks = 0;

function check(bool $condition, string $message): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function readFileStrict(string $path): string
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException("No se pudo leer {$path}");
    }
    return $content;
}

$caseDockerfiles = glob($root . '/cases/*/php/Dockerfile') ?: [];
sort($caseDockerfiles);
check(count($caseDockerfiles) === 20, 'deben existir exactamente 20 Dockerfiles de casos PHP');

$cliDockerfiles = array_merge($caseDockerfiles, [
    $root . '/docker/php/Dockerfile',
    $root . '/php-dispatcher/Dockerfile',
]);

foreach ($cliDockerfiles as $dockerfile) {
    $content = readFileStrict($dockerfile);
    $relative = str_replace('\\', '/', substr($dockerfile, strlen($root) + 1));
    check(str_contains($content, 'FROM php:8.4.27-cli-alpine3.24'), "{$relative} fija PHP CLI 8.4.27");
    check(str_contains($content, "'session.upload_progress.enabled=0'"), "{$relative} mitiga CVE-2026-115560");
    check(str_contains($content, "'file_uploads=0'"), "{$relative} desactiva uploads no usados");
}

$portalDockerfile = readFileStrict($root . '/portal/Dockerfile');
check(str_contains($portalDockerfile, 'FROM php:8.4.27-apache-trixie'), 'portal fija PHP Apache 8.4.27');
check(str_contains($portalDockerfile, "'session.upload_progress.enabled=0'"), 'portal mitiga CVE-2026-115560');
check(str_contains($portalDockerfile, "'file_uploads=0'"), 'portal desactiva uploads no usados');

$allDockerfiles = array_merge($cliDockerfiles, [$root . '/portal/Dockerfile']);
foreach ($allDockerfiles as $dockerfile) {
    $content = readFileStrict($dockerfile);
    check(!preg_match('/^FROM php:(?:8\.3|8\.4-(?:cli|apache)|latest)/m', $content), basename(dirname($dockerfile)) . ' no usa una etiqueta PHP vulnerable o flotante');
}

$phpFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php' && !str_contains(str_replace('\\', '/', $file->getPathname()), '/tests/')) {
        $phpFiles[] = $file->getPathname();
    }
}

$forbidden = [
    '/\bunserialize\s*\(/i' => 'deserializacion PHP insegura',
    '/\beval\s*\(/i' => 'eval',
    '/\b(?:shell_exec|passthru|system)\s*\(/i' => 'ejecucion de shell',
    '/\bmove_uploaded_file\s*\(/i' => 'subida de archivos',
    '/\$_FILES\b/' => 'entrada de archivos',
];

foreach ($phpFiles as $phpFile) {
    $content = readFileStrict($phpFile);
    foreach ($forbidden as $pattern => $label) {
        check(preg_match($pattern, $content) !== 1, str_replace('\\', '/', substr($phpFile, strlen($root) + 1)) . " no contiene {$label}");
    }
}

$dispatcher = readFileStrict($root . '/php-dispatcher/app/dispatcher.php');
check(str_contains($dispatcher, "'follow_location' => 0"), 'dispatcher impide redirects desde backends loopback');
check(str_contains($dispatcher, 'MAX_PROXY_BODY_BYTES'), 'dispatcher limita cuerpos reenviados');
check(str_contains($dispatcher, 'safeForwardHeader'), 'dispatcher filtra CR/LF en headers reenviados');

$catalog = readFileStrict($root . '/portal/app/catalog.php');
$probe = readFileStrict($root . '/portal/app/probe.php');
check(str_contains($catalog, 'validatedPublicHost'), 'catalog.php valida Host/PDSL_PUBLIC_HOST');
check(str_contains($probe, 'validatedPublicHost'), 'probe.php valida Host/PDSL_PUBLIC_HOST');

$case13 = readFileStrict($root . '/cases/13-cache-stampede-and-thundering-herd/php/app/index.php');
$case16 = readFileStrict($root . '/cases/16-idempotency-and-duplicate-effects/php/app/index.php');
check(str_contains($case13, 'safeCacheKey'), 'caso 13 restringe claves libres');
check(str_contains($case16, 'safeBusinessToken'), 'caso 16 restringe clave y cuenta');

if ($failures !== []) {
    fwrite(STDERR, sprintf("%d/%d comprobaciones fallaron.\n", count($failures), $checks));
    exit(1);
}

fwrite(STDOUT, "OK: {$checks} comprobaciones estaticas de seguridad PHP.\n");
