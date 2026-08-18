<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const CASE_NAME = '19 - Deriva del indice de busqueda y CDC roto';

$status = 200;

// ---------------------------------------------------------------------------
// Variante dual-write: escribir en la base, escribir en el indice, y rezar
// ---------------------------------------------------------------------------

function runDrifted(array &$s, int $writes, int $failRate, int $deletePct): int
{
    $keyspace = max(1, intdiv($writes, 2));
    $silent = 0;

    for ($i = 0; $i < $writes; $i++) {
        $id = 'doc-' . ($i % $keyspace);
        $term = TERMS[$i % count(TERMS)];
        $deleting = (($i * 53) % 101) < $deletePct;

        $version = isset($s['db'][$id]) ? $s['db'][$id]['version'] + 1 : 1;
        $s['db'][$id] = ['version' => $version, 'term' => $term, 'deleted' => $deleting,
                         'updated_ms' => nowMs()];

        // AQUI ESTA EL BUG. En PHP no hay nada que ayude: ni #[must_use] como
        // Rust, ni la obligacion de escribir `_ =` como Go. El @ o un catch
        // vacio compilan, corren y callan.
        if (indexWriteFails($i, $failRate)) {
            $silent++;
            continue;
        }
        if ($deleting) {
            unset($s['index'][$id]);
        } else {
            $s['index'][$id] = ['version' => $version, 'term' => $term];
        }
    }
    return $silent;
}

// ---------------------------------------------------------------------------
// Variante outbox + checkpoint + reconciliacion
// ---------------------------------------------------------------------------

function runReconciled(array &$s, int $writes, int $failRate, int $deletePct): int
{
    $keyspace = max(1, intdiv($writes, 2));

    for ($i = 0; $i < $writes; $i++) {
        $id = 'doc-' . ($i % $keyspace);
        $term = TERMS[$i % count(TERMS)];
        $deleting = (($i * 53) % 101) < $deletePct;

        $version = isset($s['db'][$id]) ? $s['db'][$id]['version'] + 1 : 1;
        $s['db'][$id] = ['version' => $version, 'term' => $term, 'deleted' => $deleting,
                         'updated_ms' => nowMs()];
        // El cambio se anota JUNTO con la escritura. Si el indice esta caido, el
        // cambio no se pierde: queda escrito.
        $s['seq']++;
        $s['outbox'][(string) $s['seq']] = ['id' => $id, 'version' => $version, 'term' => $term,
                                            'deleted' => $deleting];
    }
    return drainOutbox($s, $failRate, 5);
}

/**
 * Aplica los cambios pendientes al indice, en orden, reintentando.
 *
 * - **En orden**: saltear un cambio dejaria una version vieja pisando a una nueva.
 * - **El checkpoint avanza solo con la confirmacion**: si un cambio no entra
 *   despues de `$maxRetries`, el consumidor se frena. El cambio queda pendiente,
 *   no perdido. Y en PHP el checkpoint es durable por obligacion: no hay proceso
 *   de larga vida donde dejarlo en memoria.
 */
function drainOutbox(array &$s, int $failRate, int $maxRetries): int
{
    $retried = 0;
    $seqs = array_map('intval', array_keys($s['outbox']));
    sort($seqs);

    foreach ($seqs as $seq) {
        if ($seq <= $s['checkpoint']) {
            continue;
        }
        $entry = $s['outbox'][(string) $seq];
        $applied = false;
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            if (indexWriteFails($seq * ($attempt + 1) + $attempt, $failRate)) {
                $retried++;
                continue;
            }
            if ($entry['deleted']) {
                unset($s['index'][$entry['id']]);
            } else {
                $s['index'][$entry['id']] = ['version' => $entry['version'], 'term' => $entry['term']];
            }
            $applied = true;
            break;
        }
        if (!$applied) {
            break;   // el checkpoint se frena: el cambio queda pendiente
        }
        $s['checkpoint'] = $seq;
    }
    return $retried;
}

// ---------------------------------------------------------------------------
// La deriva de tres caras, con array_diff_key / array_intersect_key
// ---------------------------------------------------------------------------

function computeDrift(array $s): array
{
    $dbLive = array_filter($s['db'], static fn(array $d): bool => !$d['deleted']);
    $index = $s['index'];

    $missing = array_keys(array_diff_key($dbLive, $index));
    $orphan = array_keys(array_diff_key($index, $dbLive));
    $comunes = array_intersect_key($dbLive, $index);

    $stale = [];
    foreach ($comunes as $id => $doc) {
        if ($index[$id]['version'] !== $doc['version']) {
            $stale[] = $id;
        }
    }

    $now = nowMs();
    $oldest = 0.0;
    foreach (array_merge($missing, $stale) as $id) {
        $oldest = max($oldest, $now - $dbLive[$id]['updated_ms']);
    }

    $pending = 0;
    foreach (array_keys($s['outbox']) as $k) {
        if ((int) $k > $s['checkpoint']) {
            $pending++;
        }
    }

    sort($missing);
    sort($orphan);
    return [
        'db_count' => count($dbLive),
        'index_count' => count($index),
        'missing' => count($missing),
        'stale' => count($stale),
        'orphan' => count($orphan),
        'drift_count' => count($missing) + count($stale) + count($orphan),
        'drift_age_ms' => round($oldest, 2),
        'missing_ids' => array_slice($missing, 0, 8),
        'orphan_ids' => array_slice($orphan, 0, 8),
        'last_checkpoint' => $s['checkpoint'],
        'outbox_pending' => $pending,
    ];
}

function reconcileState(array &$s): array
{
    $t0 = nowMs();
    $before = computeDrift($s);

    $dbLive = array_filter($s['db'], static fn(array $d): bool => !$d['deleted']);
    foreach ($dbLive as $id => $doc) {
        $cur = $s['index'][$id] ?? null;
        if ($cur === null || $cur['version'] !== $doc['version']) {
            $s['index'][$id] = ['version' => $doc['version'], 'term' => $doc['term']];
        }
    }
    foreach (array_keys($s['index']) as $id) {
        if (!isset($dbLive[$id])) {
            unset($s['index'][$id]);
        }
    }

    $after = computeDrift($s);
    return [
        'reconcile_duration_ms' => round(nowMs() - $t0, 2),
        'drift_before' => $before['drift_count'],
        'drift_after' => $after['drift_count'],
        'repaired' => $before['drift_count'] - $after['drift_count'],
        'detail_before' => ['missing' => $before['missing'], 'stale' => $before['stale'],
                            'orphan' => $before['orphan']],
        'state' => $after,
        'note' => 'El barrido es la red de seguridad de lo que el outbox no cubre: un indice restaurado de un '
            . 'backup viejo, una reindexacion parcial, un borrado manual. Sin el, el outbox garantiza que ningun '
            . 'cambio NUEVO se pierda — pero no arregla los que ya se perdieron. En PHP este barrido es un '
            . 'comando de cron, que es exactamente lo que es en produccion.',
    ];
}

// ---------------------------------------------------------------------------
// Las consultas: medir la deriva desde donde la ve el usuario
// ---------------------------------------------------------------------------

function runQueries(array $s, int $queries): array
{
    $dbLive = array_filter($s['db'], static fn(array $d): bool => !$d['deleted']);
    $hits = $expected = $returned = 0;

    for ($q = 0; $q < $queries; $q++) {
        $term = TERMS[$q % count(TERMS)];
        $esperados = array_filter($dbLive, static fn(array $d): bool => $d['term'] === $term);
        $devueltos = array_filter($s['index'], static fn(array $d): bool => $d['term'] === $term);
        $expected += count($esperados);
        $returned += count($devueltos);
        $hits += count(array_intersect_key($devueltos, $esperados));
    }

    return [
        'queries' => $queries,
        'search_recall_pct' => round($hits * 100 / max(1, $expected), 2),
        'search_precision_pct' => round($hits * 100 / max(1, $returned), 2),
        'note' => 'Recall bajo = la busqueda no encuentra lo que existe. Precision baja = devuelve lo que ya no '
            . 'existe. Las dos se ven como «la busqueda anda rara», no como un error.',
    ];
}

function runScenario(string $variant, int $writes, int $failRate, int $deletePct, int $queries): array
{
    $t0 = nowMs();
    $s = loadState();
    $metrics = $s['metrics'];
    $fresh = emptyState();
    $fresh['metrics'] = $metrics;
    $s = $fresh;

    $silent = 0;
    $retried = 0;
    if ($variant === 'drifted') {
        $silent = runDrifted($s, $writes, $failRate, $deletePct);
    } else {
        $retried = runReconciled($s, $writes, $failRate, $deletePct);
        reconcileState($s);
    }

    $drift = computeDrift($s);
    $q = runQueries($s, $queries);

    $s['metrics'][$variant]['runs']++;
    $s['metrics'][$variant]['writes'] += $writes;
    $s['metrics'][$variant]['silent_failures'] += $silent;
    $s['metrics'][$variant]['drift_count'] += $drift['drift_count'];
    $s['metrics'][$variant]['outbox_retried'] += $retried;
    saveState($s);

    $payload = array_merge([
        'variant' => $variant,
        'writes' => $writes,
        'fail_rate_pct' => $failRate,
        'delete_pct' => $deletePct,
        'silent_failures' => $silent,
        'outbox_retried' => $retried,
    ], $drift, $q);
    $payload['wall_ms'] = round(nowMs() - $t0, 2);
    $payload['note'] = $variant === 'drifted'
        ? 'La escritura al indice fallo y el codigo siguio como si nada. La base y el indice no comparten '
          . 'transaccion, asi que la unica forma de enterarse es mirando — y nadie mira, porque la busqueda sigue '
          . 'respondiendo 200.'
        : 'El outbox garantiza que ningun cambio nuevo se pierda, el checkpoint impide saltear uno, y el barrido '
          . 'repara lo que los dos primeros no cubren. Deriva final: cero.';
    $payload['php_note'] = 'En un runtime share-nothing no hay proceso de larga vida donde vivir un consumidor de '
        . 'CDC: el consumidor es un comando de cron, y eso obliga a que el checkpoint sea durable desde el primer '
        . 'dia. La contracara es que PHP es el unico de los siete donde nada ayuda a no ignorar el error: el @ y '
        . 'el catch vacio compilan, corren y callan.';
    return $payload;
}

function indexState(string $stack): array
{
    $s = loadState();
    $d = computeDrift($s);
    $d['stack'] = $stack;
    $d['note'] = '`missing` no se encuentra, `stale` se encuentra mal y `orphan` es un fantasma. Las tres se ven '
        . 'igual desde afuera —«la busqueda anda rara»— y se arreglan distinto.';
    return $d;
}

function diagnostics(string $stack): array
{
    $s = loadState();
    return [
        'stack' => $stack,
        'case' => CASE_NAME,
        'variants' => $s['metrics'],
        'index' => indexState($stack),
        'fidelity' => [
            'real' => 'El diff de tres caras, el outbox ordenado con checkpoint durable y el barrido de '
                . 'reconciliacion son codigo de verdad, con la primitiva idiomatica de cada runtime.',
            'modelado' => 'El indice de busqueda es un array en un archivo JSON bajo flock, no Elasticsearch. La '
                . 'falla de escritura es deterministica para que el escenario sea reproducible.',
            'honesto' => 'Lo que importa del caso no es el motor de busqueda: es que la base y el indice son dos '
                . 'sistemas sin transaccion comun. Eso es igual de cierto con un array que con Elasticsearch.',
        ],
        'interpretation' => [
            'drifted' => 'drift_count > 0 y recall por debajo de 100 con el servicio respondiendo 200 a todo. '
                . 'silent_failures cuenta las escrituras que nadie miro.',
            'reconciled' => 'drift_count = 0, recall y precision en 100. El outbox no dejo perder ningun cambio y '
                . 'el barrido reparo lo que quedaba.',
            'php_note' => 'El checkpoint durable no es una buena practica en PHP: es la unica opcion. Eso convierte '
                . 'en obligatorio lo que en otros stacks es tentador dejar en memoria hasta el primer reinicio.',
        ],
    ];
}

// ---------------------------------------------------------------------------
// Enrutado
// ---------------------------------------------------------------------------

$stack = envOr('APP_STACK', 'PHP 8.3');
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$q = $_GET;

$writes = clampInt(queryInt($q, 'writes', 2000), 10, 200000);
$failRate = clampInt(queryInt($q, 'fail_rate', 8), 0, 100);
$deletePct = clampInt(queryInt($q, 'delete_pct', 5), 0, 50);
$queries = clampInt(queryInt($q, 'queries', 200), 1, 5000);

if ($uri === '/' || $uri === '/index' || $uri === '/index.php') {
    require __DIR__ . '/ui.php';
    exit;
}

switch ($uri) {
    case '/health':
        $payload = ['status' => 'ok', 'stack' => $stack, 'case' => CASE_NAME];
        break;
    case '/search-drifted':
        $payload = runScenario('drifted', $writes, $failRate, $deletePct, $queries);
        break;
    case '/search-reconciled':
        $payload = runScenario('reconciled', $writes, $failRate, $deletePct, $queries);
        break;
    case '/reconcile':
        $s = loadState();
        $payload = reconcileState($s);
        saveState($s);
        break;
    case '/index/state':
        $payload = indexState($stack);
        break;
    case '/diagnostics/summary':
        $payload = diagnostics($stack);
        break;
    case '/reset-lab':
        saveState(emptyState());
        $payload = ['status' => 'reset', 'message' => 'Base, indice, outbox y metricas reiniciados.'];
        break;
    default:
        $status = 404;
        $payload = ['error' => 'Ruta no encontrada', 'path' => $uri];
}

$payload['timestamp_utc'] = gmdate('Y-m-d\TH:i:s\Z');
$payload['pid'] = getmypid();

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
