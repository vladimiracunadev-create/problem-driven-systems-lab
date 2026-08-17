# ☕ Caso 14 — Java 21

<!-- nav-stack -->
[⬅️ Caso 14](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [☕ Perfil de Java](../../../docs/languages/java.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Java del caso 14. Un pool que se achica en silencio contra uno con devolución garantizada.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `ArrayBlockingQueue<Conn>` | **El pool.** Es la estructura sobre la que están construidos HikariCP y compañía. |
| `poll(timeout, unit)` | La adquisición con deadline. Devuelve `null` al vencer, en vez de lanzar. |
| `Lease implements AutoCloseable` | El recurso que try-with-resources sabe cerrar. |
| `Thread.sleep` | El tiempo de retención de la conexión. |

## Lo decisivo: try-with-resources no depende de la memoria del programador

```java
try (Lease l = lease) {
    runQuery(l.conn, queryMs, fails(idx, failRate));
    return new Outcome("completed", waitMs);
} catch (RuntimeException e) {
    return new Outcome("failed_query", waitMs);
}
```

El compilador **genera** el `finally` que llama a `close()`, y lo genera para todos los caminos de salida — incluida una excepción lanzada dentro del propio bloque. La única forma de fugar una conexión con try-with-resources es no usarlo.

`poll(timeout)` es la otra mitad. Sin él, `take()` espera para siempre: un hilo del pool HTTP bloqueado indefinidamente que en un thread dump aparece como `WAITING (parking)` sobre el `ArrayBlockingQueue` y **no dice por qué**.

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
docker compose -f compose.java.yml up -d --build
curl "http://127.0.0.1:8400/14/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25"
curl "http://127.0.0.1:8400/14/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25"
curl "http://127.0.0.1:8400/14/pool/state"
```

## Por qué acá el trabajo sí es un `sleep`

En el [caso 13](../../13-cache-stampede-and-thundering-herd/README.md) un `sleep` habría escondido el punto: lo que duele en una estampida es que el origen **hace** el trabajo N veces, así que hubo que quemar CPU de verdad.

Acá es al revés. Una conexión se retiene mientras se **espera a la red**, no mientras se calcula. Dormir es el modelo fiel del tiempo de retención; quemar CPU mediría otra cosa y además competiría con los propios hilos del laboratorio.

La misma decisión, tomada en sentidos opuestos, por la misma razón: modelar el recurso que realmente escasea.

## Sobre HikariCP

Este caso implementa a mano lo que HikariCP resuelve: `ArrayBlockingQueue` + timeout de adquisición + `leakDetectionThreshold`. Escribirlo a mano deja ver por qué esa tercera opción de configuración existe — es exactamente el `acquired - released` de `/pool/state`.
