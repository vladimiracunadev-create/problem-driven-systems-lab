<?php

/**
 * Caso 20 — La dead letter queue olvidada — stack PHP 8.3.
 *
 * Cierra el arco que abrio el caso 15: alli la DLQ **nace**, como la politica de
 * rechazo que salva al productor de bloquearse. Aca se ve que pasa cuando nadie
 * vuelve a mirarla.
 *
 * Silencioso: el consumidor falla, manda el mensaje a la DLQ y sigue. Sin
 * clasificar, sin reintentar, sin medir, sin alerta. La cola crece durante meses
 * y el pipeline se ve sano: throughput normal, cero errores — porque los errores
 * se fueron a otro lado.
 *
 * Observado: el error se clasifica antes de decidir. Lo transitorio se reintenta
 * y casi todo se recupera; lo venenoso va a la DLQ con su clase y una muestra del
 * payload; la profundidad y la antiguedad se publican; hay umbral.
 *
 * La distincion que ordena el caso:
 *
 *   transitorio  — el mismo mensaje funciona en el proximo intento
 *   venenoso     — el mismo mensaje NUNCA va a funcionar
 *
 *   Reintentar lo venenoso es quemar CPU. Mandar lo transitorio a la DLQ es
 *   tirar trabajo que se podia salvar.
 *
 * Lo que este stack aporta:
 *
 *   **Los tipos union en `catch` (PHP 8.0)**, que dicen «estos dos se tratan
 *   igual» sin duplicar el bloque ni inventar una clase base artificial:
 *
 *       catch (ErrorTransitorio | ErrorDeRed $e) { reintentar(); }
 *       catch (ErrorVenenoso $e)                 { aDlq($msg, $e->clase); }
 *
 *   Y `Throwable` como raiz comun de `Exception` y `Error`, que hace explicito
 *   —a diferencia de Java, donde `Error` esta fuera de `Exception`— que
 *   **capturar todo incluye capturar los bugs propios**.
 *
 *   **Y el drenaje como comando de cron es la forma nativa de PHP**, que es
 *   tambien la que mas se parece a como se opera de verdad. Un `bin/dlq:drain`
 *   ejecutable a mano en un incidente vale mas que un consumidor embebido que
 *   hay que redesplegar para tocar.
 *
 * Lo que hay que decir en contra: PHP no tiene exhaustividad de ninguna clase.
 * Una clase de error nueva cae en el `catch (Throwable)` de mas abajo y termina
 * en la DLQ como `unclassified`, sin que nada avise. Rust rompe la compilacion;
 * PHP ni siquiera emite un warning.
 *
 * Nota de fidelidad: la DLQ vive en un archivo JSON bajo `flock`, no en SQS ni
 * en RabbitMQ. Lo que define el caso no es el broker: es que un mensaje que
 * falla tiene que ir a algun lado, y que ese lado necesita profundidad,
 * antiguedad, clasificacion y una salida.
 */

declare(strict_types=1);

const POISON_CLASSES = ['schema_mismatch', 'unknown_field', 'null_required', 'invalid_encoding'];

/** El mismo mensaje funciona en el proximo intento. */
class ErrorTransitorio extends RuntimeException
{
}

/** El mismo mensaje NUNCA va a funcionar. */
class ErrorVenenoso extends RuntimeException
{
    public function __construct(public readonly string $clase)
    {
        parent::__construct('mensaje venenoso: ' . $clase);
    }
}

function envOr(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function storageDir(): string
{
    $dir = sys_get_temp_dir() . '/pdsl-case20';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function statePath(): string
{
    return storageDir() . '/lab.json';
}

function nowMs(): float
{
    return microtime(true) * 1000.0;
}

function emptyState(): array
{
    $slot = ['runs' => 0, 'consumed' => 0, 'succeeded' => 0, 'retried' => 0,
             'dead_lettered' => 0, 'alerts_fired' => 0];
    return [
        'dlq' => [],
        'alerts_fired' => 0,
        'metrics' => ['silent' => $slot, 'observed' => $slot],
    ];
}

function loadState(): array
{
    $path = statePath();
    if (!is_file($path)) {
        return emptyState();
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return emptyState();
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : emptyState();
}

function saveState(array $state): void
{
    file_put_contents(statePath(), json_encode($state), LOCK_EX);
}

/**
 * Procesa un mensaje. Lanza transitorio o venenoso segun el mensaje.
 *
 * El transitorio falla solo en el primer intento: es la definicion de
 * transitorio, y es lo que hace que reintentarlo tenga sentido. El venenoso
 * falla siempre, por mas veces que se lo intente.
 */
function procesar(int $idx, int $transientPct, int $poisonPct, int $attempt): void
{
    if ((($idx * 53) % 101) < $poisonPct) {
        throw new ErrorVenenoso(POISON_CLASSES[$idx % count(POISON_CLASSES)]);
    }
    if ((($idx * 37) % 101) < $transientPct && $attempt === 0) {
        throw new ErrorTransitorio('timeout del downstream');
    }
}

function clampInt(int $v, int $lo, int $hi): int
{
    return max($lo, min($hi, $v));
}

function queryInt(array $q, string $key, int $default): int
{
    if (!isset($q[$key]) || !is_numeric($q[$key])) {
        return $default;
    }
    return (int) $q[$key];
}
