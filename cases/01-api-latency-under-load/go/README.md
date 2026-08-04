# 🐹 Caso 01 — Go 1.23

<!-- nav-stack -->
[⬅️ Caso 01](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐹 Perfil de Go](../../../docs/languages/go.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Go operativo del caso 01. Filtro no sargable + N+1 real contra SQLite embebido, conviviendo con un worker que refresca una tabla resumen.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `modernc.org/sqlite` | Port de SQLite a Go puro. Sin cgo, asi que `CGO_ENABLED=0` produce un binario estatico. Se elige por sobre `mattn/go-sqlite3`, que exigiria toolchain de C en la imagen final. |
| `journal_mode=WAL` | El worker escribe `customer_summary` mientras los handlers leen, sin bloquearlos. Equivalente embebido del MVCC de PostgreSQL. |
| goroutine + `time.Ticker` | Worker periodico. No hay pool de threads que dimensionar ni shutdown hook que registrar: la goroutine muere con el proceso. |
| `defer` | Cierre de `rows`/`stmt` garantizado incluso en el camino de error. Equivalente del `try-with-resources` de Java y del `using` de C#. |
| `encoding/json` + struct tags | Unico stack del lab que serializa el contrato desde tipos en vez de concatenar strings. |

## Contraste

**Legacy** — filtro no sargable + N+1 real:
```go
// LOWER(region) envuelve la columna → idx_orders_region queda inutilizable.
db.Query(`SELECT id, customer_id, region, amount FROM orders
          WHERE LOWER(region) LIKE 'n%' ORDER BY id LIMIT ?`, limit)

for _, x := range raws {                       // una query dependiente por fila
    db.QueryRow("SELECT name, tier FROM customers WHERE id = ?", x.customerID).Scan(&name, &tier)
    dbHits++                                    // db_hits = 1 + N
}
```

**Optimized** — rango sargable + batches `IN(...)`:
```go
// Mismo predicado reescrito como rango → recupera el indice.
`... WHERE region >= 'n' AND region < 'o' ORDER BY id LIMIT ?`

// Un batch para customers y otro para el resumen. db_hits constante.
fmt.Sprintf("SELECT id, name, tier FROM customers WHERE id IN (%s)", placeholders)
```

Que el primero no use el indice y el segundo si lo dice el planner, no este README:

```text
EXPLAIN QUERY PLAN … WHERE LOWER(region) LIKE 'n%'   →  SCAN orders
EXPLAIN QUERY PLAN … WHERE region >= 'n' AND < 'o'   →  SEARCH orders USING INDEX idx_orders_region
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/report-legacy?limit=20` | `db_hits = 1 + N` sobre SQL real |
| `/report-optimized?limit=20` | `db_hits` constante + `summary_cache_size` |
| `/batch/status` | estado del worker `report-refresh-go` |
| `/job-runs` | historial de corridas del worker |
| `/diagnostics/summary` | contraste legacy vs optimized |
| `/metrics` | avg/p95/p99 por ruta |
| `/reset-lab` | reinicia contadores e historico |

## Hub

```
docker compose -f compose.go.yml up -d --build
curl "http://127.0.0.1:8600/01/report-legacy?limit=20"
curl "http://127.0.0.1:8600/01/report-optimized?limit=20"
```

## Fidelidad

**Substrato real.** `db_hits` cuenta ejecuciones reales contra el motor: `1 + N` en la ruta legacy, constante en la optimizada. Este stack devuelve exactamente las mismas filas que Java y .NET — mismo seed LCG, mismo esquema, mismas queries.

Lo que Go hace distinto no es el resultado, es el arranque: el binario estatico levanta sin JIT que calentar ni runtime que inicializar. En el hub el caso responde en decimas de milisegundo donde Java y .NET pagan varios milisegundos en las primeras requests. Esa diferencia es real y es del modelo de compilacion, no del SQL.

Para ver contencion sobre un recurso externo compartido (pool FPM contra PostgreSQL via socket TCP), ver el stack PHP (`../php/README.md`).
