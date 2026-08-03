# Caso 02 — Go 1.23

Stack Go operativo del caso 02. N+1 real contra SQLite embebido: `1 + N` queries en la ruta legacy, `2` en la optimizada.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `database/sql` | API de acceso a datos de la stdlib. No es un ORM: obliga a escribir el SQL. |
| `modernc.org/sqlite` | Motor SQLite en Go puro, sin cgo. Binario estatico. |
| `file:case02?mode=memory&cache=shared` | DB en memoria compartida entre las conexiones del pool. Sin `cache=shared`, cada conexion abriria su propia base vacia. |
| `defer rows.Close()` | Cierre garantizado del cursor incluso si el scan falla a mitad. |

## Contraste

**Legacy** — 1 SELECT orders + N SELECT items:
```go
orders, _ := selectOrders(limit)       // 1
dbHits++
for _, o := range orders {             // N
    db.Query("SELECT sku, qty FROM order_items WHERE order_id = ? ORDER BY id ASC", o.id)
    dbHits++
}
```

**Optimized** — 1 SELECT orders + 1 SELECT items con `IN(...)`:
```go
placeholders := strings.TrimRight(strings.Repeat("?,", len(ids)), ",")
db.Query(fmt.Sprintf(
    "SELECT order_id, sku, qty FROM order_items WHERE order_id IN (%s) ORDER BY id ASC",
    placeholders), ids...)
dbHits++                                // db_hits = 2, sin importar el limit
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
docker compose -f compose.go.yml up -d --build
curl "http://127.0.0.1:8600/02/orders-legacy?limit=20"
curl "http://127.0.0.1:8600/02/orders-optimized?limit=20"
```

## Lo que este stack enseña y los otros no

Go no tiene ORM en la biblioteca estandar. En Java o .NET el N+1 de este caso es el que **genera un Hibernate o un Entity Framework** al iterar una coleccion lazy sin `JOIN FETCH` ni `Include()` — aparece sin que nadie lo escriba, y por eso cuesta detectarlo.

Aca `database/sql` obliga a escribir cada query. El N+1 no puede colarse por accidente: hay que teclearlo. El caso lo teclea a proposito para medirlo, y ese es el punto — el mismo anti-patron duele igual, pero en Go el diagnostico empieza leyendo el codigo, no el log del ORM.

## Fidelidad

**Substrato real.** El dataset se genera con el mismo LCG y los mismos parametros que el stack Java: 24 categorias, 900 customers, 1.500 orders, 4.500 items. `db_hits` cuenta ejecuciones reales contra el motor.
