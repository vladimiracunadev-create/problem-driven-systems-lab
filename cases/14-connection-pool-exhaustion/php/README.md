# 🐘 Caso 14 — PHP 8.3

<!-- nav-stack -->
[⬅️ Caso 14](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐘 Perfil de PHP](../../../docs/languages/php.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack PHP del caso 14. Un pool que se achica en silencio contra uno con devolución garantizada.

## La diferencia que aporta este stack

PHP arranca un proceso limpio por request y lo mata al terminar. Eso hace que una conexión fugada **dentro** de una request se recupere sola: el proceso muere y el sistema operativo reclama el socket. Es la razón por la que media industria PHP nunca vio este bug.

Hasta que aparecen las **conexiones persistentes**. `PDO::ATTR_PERSISTENT` hace que la conexión sobreviva al final del script y quede pegada al worker de PHP-FPM. Ahí el modelo de «el proceso limpia por mí» deja de aplicar: una conexión en mal estado, o una transacción sin cerrar, se queda en ese worker y contamina todas las requests que le toquen después.

**La versión PHP del agotamiento de pool no es «el pool se vacía».** Es `max_children` de FPM multiplicado por conexiones persistentes contra el `max_connections` del motor. Con 50 workers y una persistente cada uno, la base ve 50 conexiones abiertas aunque el tráfico sea de 3 req/s.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `finally` | La devolución garantizada. Corre también cuando el `try` hace `continue`, `break` o `return` — no solo cuando lanza. |
| `PDO::ATTR_PERSISTENT` | El mecanismo real que convierte esto en un problema de PHP. No se usa en el caso; se documenta porque es la causa raíz en producción. |
| `usleep()` | El tiempo de retención de la conexión. |

## Contraste

**Leaky** — el bug cabe en lo que *no* está:
```php
try {
    runQuery($conn, $queryMs, fails($i, $failRate));
} catch (RuntimeException) {
    $counts['failed_query']++;
    continue;                    // ← la conexión se fue con la excepción
}
$pool->release($conn);
```

**Managed** — `finally` cubre los tres caminos:
```php
try {
    runQuery($conn, $queryMs, fails($i, $failRate));
    $counts['completed']++;
} catch (RuntimeException) {
    $counts['failed_query']++;
} finally {
    $pool->release($conn);       // corre en éxito, en excepción y en continue
}
```

## Rutas

| Ruta | Qué muestra |
|---|---|
| `/health` | liveness |
| `/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25` | `leaked` > 0 y `hung` creciente: el pool se vacía y no vuelve |
| `/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25` | `leaked` = 0 y `pool_available_after` = `pool_size` |
| `/pool/state` | tamaño, disponibles, adquiridas, devueltas y fugadas |
| `/diagnostics/summary` | acumulado por variante + ley de Little |
| `/reset-lab` | reconstruye el pool y limpia contadores |

**Parámetros:** `requests` (1–200 llamadores), `pool` (1–64 conexiones), `query_ms` (1–500, cuánto retiene cada query), `fail_rate` (0–100 %, porcentaje de queries que lanzan).

## Hub

```bash
docker compose -f compose.root.yml up -d --build
curl "http://127.0.0.1:8100/14/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25"
curl "http://127.0.0.1:8100/14/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25"
curl "http://127.0.0.1:8100/14/pool/state"
```

## Nota de fidelidad

El servidor embebido de PHP es de un solo proceso, así que las N requests se recorren en secuencia y el pool vive dentro de una sola llamada HTTP. Bajo PHP-FPM el que espera es otro proceso, y esa espera sí tiene sentido — por eso la variante corregida cuenta `failed_timeout` donde acá no puede haber espera real.

Lo que sí es idéntico en los dos modelos es `leaked`, que es la métrica del caso.

## Dashboard

Con `Accept: text/html`, la raíz devuelve un panel para lanzar ambas variantes y ver el contraste sin `curl`:

```bash
docker compose -f cases/14-connection-pool-exhaustion/php/compose.yml up -d --build
# abrir http://localhost:8114/
```
