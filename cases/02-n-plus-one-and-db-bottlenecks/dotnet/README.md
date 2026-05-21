# Caso 02 — .NET 8 + SQLite (Microsoft.Data.Sqlite)

Stack .NET operativo del caso 02. Patron N+1 reproducido contra **SQLite real via ADO.NET**, contraste con batch `IN(@id0, @id1, ...)` consolidado.

## Motor de datos

SQLite embebido via `Microsoft.Data.Sqlite` — paquete oficial Microsoft, API ADO.NET-style. DB en `:memory:` por instancia o `/tmp/case02.db` segun env. `SqliteConnection` + `SqliteCommand` con bindings `@named` (convencion ADO.NET; el motor acepta posicional pero la API expone named).

## Primitivas .NET nativas

| Primitiva | Rol |
|---|---|
| `SqliteConnection` | Conexion al motor SQLite embebido (`Microsoft.Data.Sqlite`). |
| `SqliteCommand` + `Parameters.AddWithValue` | Prepared statement con bindings named. |
| `SqliteDataReader` + `using` | Cleanup garantizado por `IDisposable`. |
| `record` types | `Order`, `Item` inmutables sin boilerplate. |
| `Interlocked.Increment` | Contadores por ruta lock-free. |
| `HttpListener` (BCL) | Sin frameworks externos. |

## Contraste

**Legacy** — N+1 dentro del bucle, un `ExecuteReader()` por order:
```csharp
using var cmd = conn.CreateCommand();
cmd.CommandText = "SELECT * FROM order_items WHERE order_id = @id";
var idParam = cmd.Parameters.Add("@id", SqliteType.Integer);

for (int i = 0; i < take; i++) {
    idParam.Value = orders[i].Id;
    using var rdr = cmd.ExecuteReader();   // 1 query por order → N+1
    while (rdr.Read()) ...
}
```

**Optimized** — batch `IN(@id0, @id1, ...)` + ensamblado O(N):
```csharp
var ph = string.Join(",", ids.Select((_, i) => $"@id{i}"));
using var cmd = conn.CreateCommand();
cmd.CommandText = $@"
    SELECT oi.*, p.name AS product_name, c.name AS category_name
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    JOIN categories c ON c.id = p.category_id
    WHERE oi.order_id IN ({ph})";
for (int i = 0; i < ids.Count; i++)
    cmd.Parameters.AddWithValue($"@id{i}", ids[i]);

var grouped = new Dictionary<int, List<Item>>();
using var rdr = cmd.ExecuteReader();
while (rdr.Read()) {
    var oid = rdr.GetInt32(rdr.GetOrdinal("order_id"));
    if (!grouped.TryGetValue(oid, out var list))
        grouped[oid] = list = new();
    list.Add(MapItem(rdr));
}
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/orders-legacy?limit=20` | 1 query orders + N queries items reales contra SQLite |
| `/orders-optimized?limit=20` | 1 query orders + 1 batch `IN(...)` consolidado |
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

Los cinco stacks ejecutan SQL real. PHP usa PostgreSQL via PDO (cliente/servidor); Python usa `sqlite3` stdlib; Node usa `node:sqlite` built-in; Java usa `sqlite-jdbc`; .NET usa `Microsoft.Data.Sqlite`. Cinco APIs idiomaticas sobre el mismo patron `prepared statement + IN(...)`. La unica diferencia notable es que `Microsoft.Data.Sqlite` expone bindings named (`@id`) mientras los otros 4 usan posicional (`?`) — convencion historica de ADO.NET, no limitacion del motor.
