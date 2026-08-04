# 🦀 Caso 01 — Rust 1.83

<!-- nav-stack -->
[⬅️ Caso 01](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🦀 Perfil de Rust](../../../docs/languages/rust.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Rust operativo del caso 01. Filtro no sargable + N+1 real contra SQLite embebido, conviviendo con un worker que refresca una tabla resumen.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `rusqlite` con feature `bundled` | Compila SQLite desde fuente **dentro del binario**. No depende de `libsqlite3` del sistema ni de que la imagen final traiga el `.so`. |
| `journal_mode=WAL` | El worker escribe `customer_summary` mientras los handlers leen, sin bloquearlos. Equivalente embebido del MVCC de PostgreSQL. |
| Ownership + `Drop` | La `Connection` se cierra al salir de scope. **No hay cierre que escribir**: ni `try-with-resources`, ni `using`, ni `defer`. |
| `std::thread` | Un thread por conexion y otro para el worker. Sin runtime asincronico. |
| `AtomicI64` + `Mutex<Vec<f64>>` | Contadores y buffer de muestras para p95/p99. |

## Contraste

**Legacy** — filtro no sargable + N+1 real:
```rust
// LOWER(region) envuelve la columna → idx_orders_region queda inutilizable.
"SELECT id, customer_id, region, amount FROM orders \
 WHERE LOWER(region) LIKE 'n%' ORDER BY id LIMIT ?1"

for (id, cid, region, amount) in rows.iter() {          // una query por fila
    conn.query_row("SELECT name, tier FROM customers WHERE id = ?1", params![cid], ...)
    db_hits += 1;                                        // db_hits = 1 + N
}
```

**Optimized** — rango sargable + batches `IN(...)`:
```rust
"SELECT id, customer_id, region, amount FROM orders \
 WHERE region >= 'n' AND region < 'o' ORDER BY id LIMIT ?1"

// params_from_iter arma el IN(...) con los ids sin construir SQL a mano por elemento.
stmt.query_map(rusqlite::params_from_iter(ids.iter()), |r| ...)
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/report-legacy?limit=20` | `db_hits = 1 + N` sobre SQL real |
| `/report-optimized?limit=20` | `db_hits` constante + `summary_cache_size` |
| `/batch/status` | estado del worker `report-refresh-rust` |
| `/job-runs` | historial de corridas del worker |
| `/diagnostics/summary` | contraste legacy vs optimized |
| `/metrics` | avg/p95/p99 por ruta |
| `/reset-lab` | reinicia contadores e historico |

## Hub

```
docker compose -f compose.rust.yml up -d --build
curl "http://127.0.0.1:8700/01/report-legacy?limit=20"
curl "http://127.0.0.1:8700/01/report-optimized?limit=20"
```

## Fidelidad

**Substrato real.** El seed usa el mismo LCG y los mismos parametros que Java, .NET y Go, asi que el dataset es identico. Verificado: `/report-legacy?limit=5` devuelve `order_id 12, Customer 1315, silver, north, 934` con `db_hits 6`, y el worker refresca **1.531** filas — los mismos numeros que los otros tres stacks compilados.

## Lo que `std` no trae, y por que importa

Este es el unico stack del lab donde **la biblioteca estandar no incluye servidor HTTP**. Java tiene `com.sun.net.httpserver`, .NET tiene `HttpListener`, Go tiene `net/http`, Node y Python los traen de fabrica.

Aca el servidor se escribe sobre `TcpListener` en unas 60 lineas: leer la request line, drenar cabeceras, despachar, responder. Es deliberado — meter `axum` o `actix` habria traido ~200 crates transitivos para un caso cuyo tema es SQL, no HTTP.

La contrapartida es honesta: en produccion nadie escribe su propio servidor HTTP en Rust. Se usa `axum` sobre `tokio`. Lo que este stack demuestra es el costo real de la eleccion "cero dependencias", no una recomendacion de arquitectura.
