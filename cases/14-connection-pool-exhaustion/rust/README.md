# 🦀 Caso 14 — Rust 1.83

<!-- nav-stack -->
[⬅️ Caso 14](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🦀 Perfil de Rust](../../../docs/languages/rust.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Rust del caso 14. Un pool que se achica en silencio contra uno con devolución garantizada.

## Por qué este es el caso más incómodo de escribir en Rust de todo el laboratorio

En los otros seis stacks, fugar una conexión es lo que pasa **por defecto** cuando uno se olvida de una línea. Un `finally` que falta, un `defer` que no se escribió, un `Dispose()` que no se llamó.

En Rust no hay línea que olvidar:

```rust
impl Drop for Lease {
    fn drop(&mut self) {
        if let Some(conn) = self.conn.take() {
            self.pool.give_back(conn);
        }
    }
}
```

El `Drop` devuelve la conexión cuando el `Lease` sale de alcance — en el return feliz, en el temprano, y también mientras un `panic` desenrolla la pila. El compilador no lo pide: **simplemente no existe la forma de saltearlo**.

Por eso la variante leaky de este caso tuvo que escribirse a propósito:

```rust
std::mem::forget(lease);   // se queda con el valor y NO corre su Drop
```

`mem::forget` hace exactamente una cosa: perder el recurso. Es la única manera de fugar algo en Rust seguro, y esa es la lección del stack:

> En seis stacks el leak es lo que pasa si te distraes. En Rust hay que pedirlo por su nombre, y el nombre es grepeable.

Vale la aclaración: `mem::forget` **no es `unsafe`**. No puede corromper memoria, solo perder un recurso. Rust considera que perder memoria es seguro; lo que impide es usarla después de liberarla.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `impl Drop for Lease` | La devolución garantizada por el sistema de tipos, sin línea que recordar. |
| `Condvar::wait_timeout` | La adquisición con deadline sin busy-wait. |
| `Arc<Pool>` | Cada `Lease` se lleva su referencia: el pool vive mientras haya préstamos vivos. |
| `std::mem::forget` | La fuga, escrita a propósito. Es el `unwrap()` de este caso: un olor visible en una palabra. |
| `thread::sleep` | El tiempo de retención de la conexión. |

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
docker compose -f compose.rust.yml up -d --build
curl "http://127.0.0.1:8700/14/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25"
curl "http://127.0.0.1:8700/14/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25"
curl "http://127.0.0.1:8700/14/pool/state"
```

## Por qué acá el trabajo sí es un `sleep`

En el [caso 13](../../13-cache-stampede-and-thundering-herd/README.md) un `sleep` habría escondido el punto: lo que duele en una estampida es que el origen **hace** el trabajo N veces, así que hubo que quemar CPU de verdad.

Acá es al revés. Una conexión se retiene mientras se **espera a la red**, no mientras se calcula. Dormir es el modelo fiel del tiempo de retención; quemar CPU mediría otra cosa y además competiría con los propios hilos del laboratorio.

La misma decisión, tomada en sentidos opuestos, por la misma razón: modelar el recurso que realmente escasea.

