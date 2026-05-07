<?php
/**
 * PHP Lab Dispatcher — un solo contenedor, un solo puerto para los 12 casos.
 *
 * Cada caso corre como subproceso interno en un puerto local (9001-9012).
 * El dispatcher escucha en :8100 y enruta por prefijo de path:
 *
 *     GET /01/health          -> case 01 server (interno :9001)
 *     GET /02/orders?...      -> case 02 server (interno :9002)
 *     ...
 *     GET /12/share-knowledge -> case 12 server (interno :9012)
 *     GET /                   -> indice de todos los casos
 *
 * Los puertos internos nunca se exponen al host — solo :8100 es visible.
 *
 * Este archivo es el "router script" de `php -S 0.0.0.0:8100 dispatcher.php`,
 * por lo que se RE-EJECUTA en cada request (PHP single-threaded built-in
 * server). Ideal para un lab; no para alta concurrencia productiva.
 */

declare(strict_types=1);

const CASES = [
    '01' => ['port' => 9001, 'name' => 'API lenta bajo carga'],
    '02' => ['port' => 9002, 'name' => 'N+1 y cuellos de botella DB'],
    '03' => ['port' => 9003, 'name' => 'Observabilidad deficiente'],
    '04' => ['port' => 9004, 'name' => 'Timeout chain y retry storms'],
    '05' => ['port' => 9005, 'name' => 'Presion de memoria y fugas'],
    '06' => ['port' => 9006, 'name' => 'Pipeline roto y delivery fragil'],
    '07' => ['port' => 9007, 'name' => 'Modernizacion incremental monolito'],
    '08' => ['port' => 9008, 'name' => 'Extraccion critica de modulo'],
    '09' => ['port' => 9009, 'name' => 'Integracion externa inestable'],
    '10' => ['port' => 9010, 'name' => 'Arquitectura cara para algo simple'],
    '11' => ['port' => 9011, 'name' => 'Reportes que bloquean la operacion'],
    '12' => ['port' => 9012, 'name' => 'Punto unico de conocimiento'],
];

function send_index(): void
{
    header('Content-Type: application/json; charset=utf-8');
    $cases = [];
    foreach (CASES as $id => $info) {
        $cases[$id] = [
            'name'          => $info['name'],
            'health'        => "/{$id}/health",
            'internal_port' => $info['port'],
        ];
    }
    echo json_encode([
        'lab'   => 'Problem-Driven Systems Lab',
        'stack' => 'PHP 8.3',
        'info'  => 'Dispatcher PHP — un contenedor, un puerto, 12 casos.',
        'usage' => 'GET /{caso}/{ruta}  ->  e.g. /01/health, /05/batch-legacy',
        'cases' => $cases,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function send_404(string $path, ?string $requested = null): void
{
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['error' => 'case_not_found', 'path' => $path];
    if ($requested !== null) {
        $payload['requested']   = $requested;
        $payload['valid_cases'] = array_keys(CASES);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function proxy_to_case(string $caseId, string $subPath, string $rawQuery): void
{
    $port = CASES[$caseId]['port'];
    $url  = "http://127.0.0.1:{$port}{$subPath}";
    if ($rawQuery !== '') {
        $url .= '?' . $rawQuery;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? 'application/json';

    $headers = "Accept: {$accept}\r\nConnection: close\r\n";
    $opts = [
        'http' => [
            'method'        => $method,
            'timeout'       => 30,
            'ignore_errors' => true,
            'header'        => $headers,
        ],
    ];

    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $body = file_get_contents('php://input');
        if ($body !== false && $body !== '') {
            $opts['http']['content'] = $body;
            $contentType = $_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream';
            $opts['http']['header'] .= "Content-Type: {$contentType}\r\n";
            $opts['http']['header'] .= 'Content-Length: ' . strlen($body) . "\r\n";
        }
    }

    $ctx  = stream_context_create($opts);
    $body = @file_get_contents($url, false, $ctx);
    $resp = $http_response_header ?? [];

    if ($body === false && $resp === []) {
        http_response_code(502);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error'   => 'dispatcher_proxy_error',
            'case'    => $caseId,
            'message' => 'No response from internal case server',
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Forward status + headers
    $statusCode = 200;
    foreach ($resp as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
            $statusCode = (int) $m[1];
        } else {
            // Filter hop-by-hop headers
            $name = strtolower(strstr($h, ':', true) ?: '');
            if (in_array($name, ['transfer-encoding', 'connection', 'content-length'], true)) {
                continue;
            }
            header($h);
        }
    }
    http_response_code($statusCode);

    if ($body !== false) {
        echo $body;
    }
}

// ---------------------------------------------------------------------------
// Router entry point
// ---------------------------------------------------------------------------

$uri    = $_SERVER['REQUEST_URI'] ?? '/';
$path   = parse_url($uri, PHP_URL_PATH) ?: '/';
$query  = parse_url($uri, PHP_URL_QUERY) ?? '';

if ($path === '/' || $path === '') {
    send_index();
    return true;
}

// Match /XX or /XX/whatever  (XX = 1 a 2 digitos)
if (preg_match('#^/(\d{1,2})(/.*)?$#', $path, $m) === 1) {
    $caseId  = str_pad($m[1], 2, '0', STR_PAD_LEFT);
    $subPath = $m[2] ?? '/';

    if (!array_key_exists($caseId, CASES)) {
        send_404($path, $caseId);
        return true;
    }

    proxy_to_case($caseId, $subPath, $query);
    return true;
}

send_404($path);
return true;
