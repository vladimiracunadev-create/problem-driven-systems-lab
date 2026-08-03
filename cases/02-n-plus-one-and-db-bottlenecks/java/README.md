# Caso 02 — Java 21 + SQLite (sqlite-jdbc)

Stack Java operativo del caso 02. Patron N+1 reproducido contra **SQLite real via JDBC**, contraste con batch `IN(...)` consolidado.

## Motor de datos

SQLite embebido via `sqlite-jdbc` — single jar agregado al classpath en build-time, sin Maven. La DB vive en `:memory:` por instancia o en `/tmp/case02.db` segun env. `Connection` + `PreparedStatement` con `?` posicional siguen el patron JDBC clasico.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `Connection` (`org.sqlite.JDBC`) | Conexion al motor SQLite empaquetado en `sqlite-jdbc`. |
| `PreparedStatement` | Plan cacheado por la libreria; bindings via `setInt(i, v)`. |
| `ResultSet` + `try-with-resources` | Cleanup garantizado incluso bajo excepcion. |
| `record` types | `Order` e `Item` inmutables. |
| `LongAdder` | Contadores por ruta lock-free para p95/p99. |

## Contraste

**Legacy** — N+1 dentro del bucle, una `executeQuery()` por order:
```java
try (PreparedStatement ps = conn.prepareStatement(
        "SELECT * FROM order_items WHERE order_id = ?")) {
    for (int i = 0; i < take; i++) {
        ps.setInt(1, orders.get(i).id);
        try (ResultSet rs = ps.executeQuery()) {
            while (rs.next()) ...  // 1 query por order → N+1
        }
    }
}
```

**Optimized** — batch `IN(...)` + ensamblado O(N):
```java
String ph = String.join(",", Collections.nCopies(ids.size(), "?"));
String sql = "SELECT oi.*, p.name AS product_name, ... " +
             "FROM order_items oi " +
             "JOIN products p ON p.id = oi.product_id " +
             "JOIN categories c ON c.id = p.category_id " +
             "WHERE oi.order_id IN (" + ph + ")";

try (PreparedStatement ps = conn.prepareStatement(sql)) {
    for (int i = 0; i < ids.size(); i++) ps.setInt(i + 1, ids.get(i));
    try (ResultSet rs = ps.executeQuery()) {
        Map<Integer, List<Item>> grouped = new HashMap<>();
        while (rs.next())
            grouped.computeIfAbsent(rs.getInt("order_id"), k -> new ArrayList<>())
                   .add(mapItem(rs));
        return grouped;
    }
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
docker compose -f compose.java.yml up -d --build
curl "http://127.0.0.1:8400/02/orders-optimized?limit=10"
```

## Diferencia con PHP/Python/Node/.NET

Los siete stacks ejecutan SQL real. PHP usa PostgreSQL via PDO (cliente/servidor); Python usa `sqlite3` stdlib; Node usa `node:sqlite` built-in; .NET usa `Microsoft.Data.Sqlite`; Go usa `modernc.org/sqlite` (Go puro, sin cgo); Rust usa `rusqlite` bundled; Java usa `sqlite-jdbc`. Siete APIs idiomaticas, mismo patron `prepared statement + IN(?, ?, ?, ...)`. La diferencia esta en la primitiva, no en la fidelidad del contraste.
