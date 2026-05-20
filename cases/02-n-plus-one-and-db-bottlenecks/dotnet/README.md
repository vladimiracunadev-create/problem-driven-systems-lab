# Caso 02 — .NET 8

Stack .NET operativo del caso 02. Patron N+1 reproducido en memoria, contraste con batch `IN(...)` simulado.

## Primitivas .NET nativas

| Primitiva | Rol |
|---|---|
| `Dictionary<int, List<Item>>` | `itemsByOrderId` precomputado actua como tabla relacional indexada. |
| `record` types | `Order`, `Item` inmutables sin boilerplate. |
| `Interlocked.Increment` | Contadores por ruta lock-free. |
| `HttpListener` (BCL) | Sin frameworks. Build single-file, runtime minimo. |

## Contraste

**Legacy** — N+1 dentro del bucle:
```csharp
for (int i = 0; i < take; i++) {
    var o = orders[i];
    var items = LookupItemsOneByOne(o.Id);   // 1 query por order
    SleepMicros(900);                         // costo de roundtrip
}
```

**Optimized** — batch `IN(...)` + ensamblado O(1):
```csharp
var ids = CollectIds(orders, take);
var batch = new Dictionary<int, List<Item>>();
foreach (var id in ids) batch[id] = itemsByOrderId.GetValueOrDefault(id, new());
SleepMicros(700);   // un solo roundtrip
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/orders-legacy?limit=20` | 1 query orders + N queries items |
| `/orders-optimized?limit=20` | 1 query orders + 1 batch IN |
| `/diagnostics/summary` | totales + contraste avg/p95/p99 |
| `/metrics` | avg/p95/p99 por ruta |
| `/reset-lab` | reinicia contadores |

## Hub

```
docker compose -f compose.dotnet.yml up -d --build
curl "http://127.0.0.1:8500/02/orders-optimized?limit=10"
```

## Modo aislado

```
docker compose -f cases/02-n-plus-one-and-db-bottlenecks/dotnet/compose.yml up -d --build
curl http://127.0.0.1:852/health
```

## Diferencia con PHP/Python/Node/Java

PHP usa PDO + PostgreSQL real. Python usa sqlite3 + `DB_LOCK`. Node usa `Map`+`Set` en memoria. Java usa `HashMap` en memoria. La version .NET tambien se queda en memoria y enfoca el contraste en el patron de carga, no en EF Core vs Dapper. Mismo problema, idioma distinto.
