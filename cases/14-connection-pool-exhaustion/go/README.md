# 🐹 Caso 14 — Go 1.23

<!-- nav-stack -->
[⬅️ Caso 14](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐹 Perfil de Go](../../../docs/languages/go.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Go del caso 14. Un pool que se achica en silencio contra uno con devolución garantizada.

## El canal bufferizado **es** el pool

```go
type pool struct {
    free chan *conn      // lleva las conexiones Y limita cuántas hay en vuelo
}
```

`<-pool.free` adquiere, `pool.free <- conn` devuelve, y la capacidad del canal es el tamaño máximo. No hace falta semáforo aparte ni contador: una sola estructura hace las dos cosas.

El deadline se agrega envolviendo la recepción en un `select`:

```go
select {
case c := <-p.free:
    return c, nil
case <-timer.C:
    return nil, errNoConn
}
```

Es la **misma primitiva** que el [caso 04](../../04-timeout-chain-and-retry-storms/go/README.md) usa para cancelación, el [08](../../08-critical-module-extraction-without-breaking-operations/go/README.md) para el bus de eventos y el [09](../../09-unstable-external-integration/go/README.md) para la cuota. Cuatro problemas distintos, un solo concepto que aprender.

## El límite honesto de Go en este caso

`defer` es la garantía de devolución — y es **una línea que hay que acordarse de escribir**:

```go
defer p.release(c)     // ← la línea que separa las dos variantes
```

Un `return` temprano antes del `defer` fuga la conexión y **compila igual**. Rust cierra esa puerta con `Drop`; Go la deja abierta y la hace fácil de grepear. Es una diferencia real, y es por lo que este caso es de los pocos donde Rust le gana a Go.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `chan *conn` bufferizado | El pool completo: contenedor y límite a la vez. |
| `select` + `time.NewTimer` | El deadline de adquisición. |
| `defer` | La devolución. Corre en todos los caminos de salida de la función. |
| `sync/atomic` | Contadores de `acquired` / `released` sin lock. |
| `time.Sleep` | El tiempo de retención de la conexión. |

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
docker compose -f compose.go.yml up -d --build
curl "http://127.0.0.1:8600/14/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25"
curl "http://127.0.0.1:8600/14/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25"
curl "http://127.0.0.1:8600/14/pool/state"
```

## Por qué acá el trabajo sí es un `sleep`

En el [caso 13](../../13-cache-stampede-and-thundering-herd/README.md) un `sleep` habría escondido el punto: lo que duele en una estampida es que el origen **hace** el trabajo N veces, así que hubo que quemar CPU de verdad.

Acá es al revés. Una conexión se retiene mientras se **espera a la red**, no mientras se calcula. Dormir es el modelo fiel del tiempo de retención; quemar CPU mediría otra cosa y además competiría con los propios hilos del laboratorio.

La misma decisión, tomada en sentidos opuestos, por la misma razón: modelar el recurso que realmente escasea.

