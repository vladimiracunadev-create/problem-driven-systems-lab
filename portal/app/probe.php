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

function normalizePath(string $path): string
{
    return '/' . ltrim($path === '' ? '/' : $path, '/');
}

function parseStatusCode(array $headers): int
{
    if ($headers === []) {
        return 0;
    }

    if (preg_match('/HTTP\/\S+\s+(\d{3})/', (string) $headers[0], $matches) === 1) {
        return (int) $matches[1];
    }

    return 0;
}

function jsonExit(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$caseId = (string) ($_GET['case_id'] ?? '');
$stack = (string) ($_GET['stack'] ?? '');
if ($caseId === '' || $stack === '') {
    jsonExit(400, ['error' => 'Debes indicar case_id y stack.']);
}

$raw = @file_get_contents(metadataPath());
if ($raw === false) {
    jsonExit(500, ['error' => 'No se pudo leer el catalogo compartido.']);
}

try {
    $catalog = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    jsonExit(500, ['error' => 'El catalogo compartido no es un JSON valido.']);
}

$cases = $catalog['cases'] ?? [];
$targetCase = null;
foreach ($cases as $case) {
    if (($case['id'] ?? '') === $caseId) {
        $targetCase = $case;
        break;
    }
}

if (!is_array($targetCase)) {
    jsonExit(404, ['error' => 'No se encontro el caso solicitado.', 'case_id' => $caseId]);
}

$runtimeEntry = $targetCase['runtime_entries'][$stack] ?? null;
if (!is_array($runtimeEntry)) {
    jsonExit(404, ['error' => 'No se encontro el stack solicitado para este caso.', 'case_id' => $caseId, 'stack' => $stack]);
}

$probeHost = envOr('PDSL_PROBE_HOST', 'host.docker.internal');
$publicHost = envOr('PDSL_PUBLIC_HOST', parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8080'), PHP_URL_HOST) ?: 'localhost');
$port = (int) ($runtimeEntry['port'] ?? 0);
$healthPath = normalizePath((string) ($runtimeEntry['health_path'] ?? '/health'));
$publicTargetUrl = sprintf('http://%s:%d%s', $publicHost, $port, $healthPath);
$probeTargetUrl = sprintf('http://%s:%d%s', $probeHost, $port, $healthPath);

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 3,
        'ignore_errors' => true,
        'header' => "Accept: application/json\r\nConnection: close\r\n",
    ],
]);

$started = microtime(true);
$body = @file_get_contents($probeTargetUrl, false, $context);
$elapsedMs = round((microtime(true) - $started) * 1000, 2);
$headers = $http_response_header ?? [];
$statusCode = parseStatusCode($headers);
$isUp = $statusCode >= 200 && $statusCode < 400;

$responsePreview = null;
if (is_string($body) && $body !== '') {
    $decoded = json_decode($body, true);
    if (is_array($decoded)) {
        $responsePreview = array_slice($decoded, 0, 5, true);
    } else {
        $responsePreview = substr($body, 0, 220);
    }
}

$payload = [
    'case_id' => $caseId,
    'case_title' => $targetCase['title'] ?? 'Sin titulo',
    'stack' => $stack,
    'status' => $isUp ? 'up' : 'down',
    'status_code' => $statusCode,
    'elapsed_ms' => $elapsedMs,
    'checked_at_utc' => gmdate('c'),
    'target_url' => $publicTargetUrl,
    'probe_target_url' => $probeTargetUrl,
    'summary' => $isUp
        ? sprintf('HTTP %d en %s ms', $statusCode, $elapsedMs)
        : 'El servicio no respondio correctamente desde el portal.',
    'response_preview' => $responsePreview,
];

if (!$isUp) {
    $payload['headers'] = $headers;
    jsonExit(502, $payload);
}

jsonExit(200, $payload);
