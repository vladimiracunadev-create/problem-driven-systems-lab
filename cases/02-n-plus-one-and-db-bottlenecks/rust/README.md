# 🦀 Caso 02 — Rust 1.83

<!-- nav-stack -->
[⬅️ Caso 02](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🦀 Perfil de Rust](../../../docs/languages/rust.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Rust operativo del caso 02. N+1 real contra SQLite embebido: `1 + N` queries en la ruta legacy, `2` en la optimizada.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `rusqlite` con feature `bundled` | Motor SQLite compilado dentro del binario. |
| `stmt.query_map(...).collect::<Result<Vec<_>>>()` | Materializa las filas **propagando el error de cualquiera de ellas**. |
| `rusqlite::params_from_iter` | Arma el `IN (?,?,…)` desde un iterador de ids. |
| `Drop` sobre `Connection` | Cierre al salir de scope, sin `defer` ni `finally`. |

## Contraste

**Legacy** — 1 SELECT orders + N SELECT items:
```rust
let orders = select_orders(&conn, limit)?;   // 1
db_hits += 1;
for (oid, cid) in orders.iter() {            // N
    conn.prepare("SELECT sku, qty FROM order_items WHERE order_id = ?1 ORDER BY id ASC")?;
    db_hits += 1;
}
```

**Optimized** — 1 SELECT orders + 1 SELECT items con `IN(...)`:
```rust
let placeholders = vec!["?"; ids.len()].join(",");
let sql = format!(
    "SELECT order_id, sku, qty FROM order_items WHERE order_id IN ({placeholders}) ORDER BY id ASC"
);
stmt.query_map(rusqlite::params_from_iter(ids.iter()), ...)?;
db_hits += 1;                                 // db_hits = 2, sin importar el limit
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/orders-legacy?limit=20` | `db_hits = 21` con `limit=20` |
| `/orders-optimized?limit=20` | `db_hits = 2` con el mismo resultado |
| `/diagnostics/summary` | totales del dataset + metricas por variante |
| `/metrics` | avg/p95/p99 por ruta |
| `/reset-lab` | reinicia contadores |

## Hub

```
docker compose -f compose.rust.yml up -d --build
curl "http://127.0.0.1:8700/02/orders-legacy?limit=20"
curl "http://127.0.0.1:8700/02/orders-optimized?limit=20"
```

## El error que no se puede ignorar

Go y Rust comparten que el SQL se escribe a mano: ninguno trae ORM en la stdlib, asi que el N+1 de este caso **no puede aparecer por accidente** como lo genera un Hibernate o un Entity Framework al iterar una coleccion lazy. Hay que teclearlo.

Donde Rust se separa de Go es en el manejo del cursor:

```rust
// El tipo es Iterator<Item = Result<T>>. Este collect obliga a decidir
// que pasa si una fila falla a mitad del recorrido.
mapped.collect::<rusqlite::Result<Vec<_>>>()?
```

En Go, el equivalente es recorrer `rows.Next()` y **acordarse** de chequear `rows.Err()` despues del bucle. Olvidarlo compila y silencia fallos parciales del cursor — la query devuelve menos filas de las que debia y nadie se entera. Aca ese olvido no tiene forma de expresarse: el `Result` esta en el tipo del iterador.

## Fidelidad

**Substrato real.** Mismo LCG y mismos parametros que Java, .NET y Go: 24 categorias, 900 customers, 1.500 orders, 4.500 items. Verificado: `/orders-legacy?limit=5` devuelve `order_id 1, customer_id 276` con items `SKU-2369 qty 2` y `SKU-2863 qty 8` — identico al stack Go.
