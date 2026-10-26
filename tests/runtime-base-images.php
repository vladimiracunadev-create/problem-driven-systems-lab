<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$expected = [
    'php' => 'php:8.4.27-cli-alpine3.24',
    'node' => 'node:22.23.4-alpine3.24',
    'python' => 'python:3.13.17-alpine3.24',
    'java' => 'amazoncorretto:25.0.5-alpine3.24',
    'dotnet' => 'mcr.microsoft.com/dotnet/sdk:10.0.403',
    'go' => 'golang:1.27.3-alpine3.24',
];

$dispatcherDockerfiles = [
    'php' => 'php-dispatcher/Dockerfile',
    'node' => 'node-dispatcher/Dockerfile',
    'python' => 'python-dispatcher/Dockerfile',
    'java' => 'java-dispatcher/Dockerfile',
    'dotnet' => 'dotnet-dispatcher/Dockerfile',
    'go' => 'go-dispatcher/Dockerfile',
];

$javaDistrustedAliases = [
    'emsignrootcag1 [jdk]',
    'emsignrootcag2 [jdk]',
    'emsigneccrootcag3 [jdk]',
    'comodoaaaca [jdk]',
];

$assertJavaMitigation = static function (string $content, string $label) use (&$failures, $javaDistrustedAliases): void {
    if (!str_contains($content, 'CVE-2026-91403')) {
        $failures[] = "{$label}: no documenta CVE-2026-91403";
    }
    if (!str_contains($content, '! keytool -list -cacerts')) {
        $failures[] = "{$label}: no verifica que los certificados hayan sido eliminados";
    }
    foreach ($javaDistrustedAliases as $alias) {
        if (!str_contains($content, $alias)) {
            $failures[] = "{$label}: falta eliminar el alias {$alias}";
        }
    }
};

$failures = [];
foreach ($expected as $stack => $image) {
    $files = glob("{$root}/cases/*/{$stack}/Dockerfile") ?: [];
    if (count($files) !== 20) {
        $failures[] = "{$stack}: se esperaban 20 Dockerfiles y se encontraron " . count($files);
    }

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        if (!preg_match('/^FROM\s+' . preg_quote($image, '/') . '(?:\s|$)/m', $content)) {
            $failures[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file) . ": no fija {$image}";
        }
        if ($stack === 'java') {
            $assertJavaMitigation($content, str_replace($root . DIRECTORY_SEPARATOR, '', $file));
        }
    }

    $dispatcher = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dispatcherDockerfiles[$stack]);
    $content = (string) file_get_contents($dispatcher);
    if (!preg_match('/^FROM\s+' . preg_quote($image, '/') . '(?:\s|$)/m', $content)) {
        $failures[] = "{$dispatcherDockerfiles[$stack]}: no fija {$image}";
    }
    if ($stack === 'java') {
        $assertJavaMitigation($content, $dispatcherDockerfiles[$stack]);
    }
}

$dotnetRuntimeImage = 'mcr.microsoft.com/dotnet/aspnet:10.0.14';
$dotnetDockerfiles = glob("{$root}/cases/*/dotnet/Dockerfile") ?: [];
$dotnetDockerfiles[] = $root . '/dotnet-dispatcher/Dockerfile';
foreach ($dotnetDockerfiles as $file) {
    $content = (string) file_get_contents($file);
    if (!preg_match('/^FROM\s+' . preg_quote($dotnetRuntimeImage, '/') . '(?:\s|$)/m', $content)) {
        $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
        $failures[] = "{$relative}: no fija el runtime {$dotnetRuntimeImage}";
    }
}

$portal = (string) file_get_contents($root . '/portal/Dockerfile');
if (!str_contains($portal, 'FROM php:8.4.27-apache-trixie')) {
    $failures[] = 'portal/Dockerfile: no fija php:8.4.27-apache-trixie';
}

$staleRuntimeClaims = [
    '/PHP 8\.3/' => 'PHP 8.3',
    '/Python 3\.12/' => 'Python 3.12',
    '/Node\.js 22(?!\.)/' => 'Node.js 22 sin parche',
    '/Java 25(?!\.)/' => 'Java 25 sin parche',
    '/Go 1\.27(?!\.)/' => 'Go 1.27 sin parche',
    '/\.NET 10(?![\d.])/' => '.NET 10 sin parche',
    '/\.NET 10\.0(?![\d.])/' => '.NET 10.0 sin parche',
    '/python:3\.12-alpine/' => 'imagen Python 3.12 flotante',
    '/mcr\.microsoft\.com\/dotnet\/(?:sdk|aspnet):10\.0(?!\.)/' => 'imagen .NET 10.0 flotante',
];
$claimFiles = [];
$claimRoots = array_merge([$root . '/cases'], array_map(
    static fn(string $path): string => $root . '/' . dirname($path),
    array_values($dispatcherDockerfiles)
));
foreach ($claimRoots as $claimRoot) {
    if (is_file($claimRoot)) {
        $claimFiles[] = $claimRoot;
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($claimRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && ($file->getBasename() === 'Dockerfile' || in_array(strtolower($file->getExtension()), ['md', 'php', 'py', 'js', 'java', 'go', 'rs', 'cs', 'yml', 'yaml'], true))) {
            $claimFiles[] = $file->getPathname();
        }
    }
}
foreach (glob($root . '/compose.*.yml') ?: [] as $composeFile) {
    $claimFiles[] = $composeFile;
}
foreach ($claimFiles as $claimFile) {
    $content = (string) file_get_contents($claimFile);
    foreach ($staleRuntimeClaims as $pattern => $label) {
        if (preg_match($pattern, $content) === 1) {
            $relative = str_replace('\\', '/', substr($claimFile, strlen($root) + 1));
            $failures[] = "{$relative}: conserva {$label}";
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Runtime pins inseguros o incoherentes:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: 20 casos + dispatcher fijan las bases auditadas; portal Apache fijado y mitigaciones presentes.\n";
