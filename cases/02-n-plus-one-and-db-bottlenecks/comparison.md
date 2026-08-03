# Caso 02 — Comparativa multi-stack: N+1 y cuellos de botella en base de datos (PHP · Python · Node.js · Java · .NET · Go · Rust)

## El problema que los siete resuelven

Un feed de pedidos que necesita devolver, por cada pedido, el cliente, los items, y por cada item el producto y su categoria. La variante legacy construye ese grafo con queries anidadas dentro de bucles. La variante optimizada lo construye con joins consolidados y ensamblado en memoria.

## Fidelidad del substrato — los 7 stacks corren sobre SQL real

**Caso 02 ejecuta N+1 real sobre una base relacional embebida en los siete stacks** — igual que el caso 01 desde que se cerro su deuda de fidelidad. El contraste deja de ser "DB vs memoria" y pasa a ser **N+1 sobre el mismo problema, primitivas idiomaticas distintas por lenguaje**:

| Stack | Motor | Primitiva idiomatica |
|---|---|---|
| PHP | PostgreSQL 16 (externo) | `PDO` + prepared statements |
| Python | SQLite (stdlib) | `sqlite3` + cursor compartido |
| Node.js | SQLite (`node:sqlite` built-in en Node 22.5+) | `Database` + `db.prepare()` |
| Java 21 | SQLite (`sqlite-jdbc`) | `Connection` + `PreparedStatement` |
| .NET 8 | SQLite (`Microsoft.Data.Sqlite`) | `SqliteConnection` + `SqliteCommand` |

`db_hits` ahora es un contador real, no una metrica derivada. La diferencia entre `orders-legacy` y `orders-optimized` es observable a nivel motor en los siete runtimes. El contrato JSON externo no cambio — lo que cambio es que el numero ahora corresponde a `prepare()` + `execute()` reales contra una DB.

---

## PHP: PostgreSQL, cursores PDO, agrupacion con array_column

**Runtime:** PHP-FPM. Cada request es un proceso efimero. No hay estructuras de datos que sobrevivan entre requests.

**El fallo legacy en PHP:**
```php
foreach ($orders as &$order) {
    $order['customer'] = $db->timedQuery(
        "SELECT * FROM customers WHERE id = ?", [$order['customer_id']]
    )->fetch();
    $items = $db->timedQuery(
        "SELECT * FROM order_items WHERE order_id = ?", [$order['id']]
    )->fetchAll();
    foreach ($items as &$item) {
        $item['product'] = $db->timedQuery(
            "SELECT p.*, c.name FROM products p
             JOIN categories c ON c.id = p.category_id
             WHERE p.id = ?", [$item['product_id']]
        )->fetch();
    }
    $order['items'] = $items;
}
```
Patron: `1 + N + N*M + N*M`. Para 20 pedidos con 3 items promedio: mas de 120 llamadas PDO. PostgreSQL abre un cursor transaccional por cada `prepare()`. El pool de conexiones se agota.

**La correccion en PHP:**
```php
$orderIds = array_map(fn($o) => $o['id'], $orders);
$ph = implode(',', array_fill(0, count($orderIds), '?'));

$items = $db->timedQuery(
    "SELECT oi.*, p.name product_name, p.list_price,
            c.name category_name
     FROM order_items oi
     JOIN products p ON p.id = oi.product_id
     JOIN categories c ON c.id = p.category_id
     WHERE oi.order_id IN ($ph)", $orderIds
)->fetchAll();

$grouped = [];
foreach ($items as $item) {
    $grouped[$item['order_id']][] = $item;
}
foreach ($orders as &$order) {
    $order['items'] = $grouped[$order['id']] ?? [];
}
```
PHP usa `array_column()` e iteracion asociativa. El FPM recupera su rol: procesamiento CPU sin cruzar la frontera de red.

**Observabilidad adicional PHP:** `pg_stat_statements` via postgres-exporter. Se pueden ver las queries reales ejecutadas contra PostgreSQL desde Grafana.

---

## Python: SQLite, sqlite3 stdlib, dict comprehensions

**Runtime:** `ThreadingHTTPServer`. Los hilos comparten la conexion SQLite protegida por `threading.RLock`.

**Motor de datos:** SQLite via `sqlite3` de stdlib. Conexion unica, file I/O real, prepared statements via `?` placeholders.

**El fallo legacy en Python:**
```python
for order in orders:
    cur.execute("SELECT * FROM customers WHERE id = ?", (order["customer_id"],))
    order["customer"] = dict(cur.fetchone())
    cur.execute("SELECT * FROM order_items WHERE order_id = ?", (order["id"],))
    items = [dict(r) for r in cur.fetchall()]
    for item in items:
        cur.execute(
            "SELECT p.*, c.name cat FROM products p "
            "JOIN categories c ON c.id = p.category_id WHERE p.id = ?",
            (item["product_id"],)
        )
        item["product"] = dict(cur.fetchone())
    order["items"] = items
```
Mismo patron `1 + N + N*M`. SQLite bloquea el hilo en cada `execute()`. El GIL se libera durante el I/O pero el acceso secuencial acumula tiempo de todas formas.

**La correccion en Python:**
```python
order_ids = [o["id"] for o in orders]
ph = ",".join("?" * len(order_ids))

cur.execute(
    f"SELECT oi.*, p.name product_name, p.list_price, c.name category_name "
    f"FROM order_items oi "
    f"JOIN products p ON p.id = oi.product_id "
    f"JOIN categories c ON c.id = p.category_id "
    f"WHERE oi.order_id IN ({ph})", order_ids
)
items_all = [dict(r) for r in cur.fetchall()]

grouped = {}
for item in items_all:
    grouped.setdefault(item["order_id"], []).append(item)

for order in orders:
    order["items"] = grouped.get(order["id"], [])
```
Python usa `dict.setdefault()` y list comprehensions. El resultado es funcionalmente identico al PHP con `array_column()`.

---

## Node.js: SQLite via `node:sqlite` built-in, `db.prepare()`, single-thread

**Runtime:** Node.js 22 single-thread con event loop. El N+1 anidado se traduce en `for (const order of baseOrders) { const c = stmt.get(order.customer_id); ... }` — el costo es `1 + N + sum(items_por_order * 2)` `prepare()`/`get()` reales contra SQLite.

**Motor de datos:** `node:sqlite` (modulo built-in desde Node 22.5, sin npm install, sin bindings nativos a compilar). DB en `:memory:` por instancia o `/tmp/case02.db`. `Database` + `db.prepare(sql).get(...)` / `db.prepare(sql).all(...)` con prepared statements verdaderos.

**El fallo legacy en Node.js:**
```javascript
const getCustomer = db.prepare("SELECT * FROM customers WHERE id = ?");
const getItems    = db.prepare("SELECT * FROM order_items WHERE order_id = ?");
const getProduct  = db.prepare(`
  SELECT p.*, c.name AS category_name FROM products p
  JOIN categories c ON c.id = p.category_id WHERE p.id = ?`);

for (const order of baseOrders) {
  order.customer = timedQuery(() => getCustomer.get(order.customer_id), stats);
  const items    = timedQuery(() => getItems.all(order.id), stats);
  for (const item of items) {
    item.product = timedQuery(() => getProduct.get(item.product_id), stats);
  }
  order.items = items;
}
```
Con `limit=20` y ~3.7 items/order: ~190 `Statement.get()` secuenciales contra SQLite. Cada uno es file I/O sincronico — bloquea el event loop mientras dura. Bajo concurrencia, `event_loop_lag_ms` se dispara y el throughput del proceso cae.

**La correccion en Node.js:**
```javascript
const ids = baseOrders.map(o => o.id);
const ph  = ids.map(() => "?").join(",");

const rows = timedQuery(() =>
  db.prepare(`
    SELECT oi.*, p.name AS product_name, p.list_price,
           c.name AS category_name
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    JOIN categories c ON c.id = p.category_id
    WHERE oi.order_id IN (${ph})
  `).all(...ids), stats);

const grouped = new Map();
for (const row of rows) {
  const list = grouped.get(row.order_id) || [];
  list.push(row);
  grouped.set(row.order_id, list);
}
for (const order of baseOrders) order.items = grouped.get(order.id) || [];
```
Dos `prepare().all()` reales. Joins resueltos por SQLite, agrupacion en JS con `Map.get()` O(1). La ausencia de un ORM hace explicita la decision — no hay magia que la oculte.

---

## Java 21: sqlite-jdbc, `PreparedStatement`, batch `IN(...)`

**Runtime:** JVM con thread pool. Cada handler corre en thread propio. La `Connection` SQLite es compartida y serializada por SQLite mismo (la libreria `sqlite-jdbc` empaqueta el motor nativo).

**Motor de datos:** SQLite via `sqlite-jdbc` (single jar, sin Maven — se descarga en build-time y se agrega al classpath). DB en `:memory:` por instancia o `/tmp/case02.db`. `Connection` + `PreparedStatement` con `?` parametrizado.

**El fallo legacy en Java:**
```java
try (PreparedStatement ps = conn.prepareStatement(
        "SELECT * FROM order_items WHERE order_id = ?")) {
    for (int i = 0; i < take; i++) {
        Order o = orders.get(i);
        ps.setInt(1, o.id);
        try (ResultSet rs = ps.executeQuery()) {   // N+1 clasico
            while (rs.next()) ...
        }
    }
}
```
N+1 clasico contra el motor real. Cada `executeQuery()` cruza la frontera JNI hasta el motor nativo de SQLite, lee del file, retorna. `db_hits` incrementa una vez por iteracion — el contador refleja el numero verdadero de queries.

**La correccion en Java:**
```java
String ph = String.join(",", Collections.nCopies(ids.size(), "?"));
String sql = """
    SELECT oi.*, p.name AS product_name, p.list_price,
           c.name AS category_name
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    JOIN categories c ON c.id = p.category_id
    WHERE oi.order_id IN (%s)
""".formatted(ph);

try (PreparedStatement ps = conn.prepareStatement(sql)) {
    for (int i = 0; i < ids.size(); i++) ps.setInt(i + 1, ids.get(i));
    try (ResultSet rs = ps.executeQuery()) {
        Map<Integer, List<Item>> grouped = new HashMap<>();
        while (rs.next()) {
            grouped.computeIfAbsent(rs.getInt("order_id"), k -> new ArrayList<>())
                   .add(mapItem(rs));
        }
        return grouped;
    }
}
```
Un solo `executeQuery()` con `IN(?, ?, ?, ...)` construido dinamicamente — el patron canonico cuando JDBC no expone `setArray()` para SQLite. `try-with-resources` garantiza el cleanup del `ResultSet` y `PreparedStatement` incluso bajo excepcion.

---

## .NET 8: Microsoft.Data.Sqlite, `SqliteCommand`, parameter binding

**Runtime:** .NET 8 sobre `HttpListener`. El CLR despacha al `ThreadPool`. Cada request puede tomar una `SqliteConnection` (la libreria es thread-safe a nivel de connection, no de command compartido).

**Motor de datos:** SQLite via `Microsoft.Data.Sqlite` (paquete oficial Microsoft, ADO.NET-style). DB en `:memory:` por instancia o `/tmp/case02.db`. `SqliteConnection` + `SqliteCommand` con `@parametro` named bindings.

**El fallo legacy en C#:**
```csharp
using var cmd = conn.CreateCommand();
cmd.CommandText = "SELECT * FROM order_items WHERE order_id = @id";
var idParam = cmd.Parameters.Add("@id", SqliteType.Integer);

for (int i = 0; i < take; i++) {
    var o = orders[i];
    idParam.Value = o.Id;
    using var rdr = cmd.ExecuteReader();   // N+1 clasico
    while (rdr.Read()) ...
}
```
Mismo patron `1 + N` contra el motor real. Cada `ExecuteReader()` invoca el motor nativo de SQLite. El contador `db_hits` cuenta cada ejecucion — la metrica es honesta.

**La correccion en C#:**
```csharp
var ph = string.Join(",", ids.Select((_, i) => $"@id{i}"));
using var cmd = conn.CreateCommand();
cmd.CommandText = $@"
    SELECT oi.*, p.name AS product_name, p.list_price,
           c.name AS category_name
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    JOIN categories c ON c.id = p.category_id
    WHERE oi.order_id IN ({ph})";

for (int i = 0; i < ids.Count; i++)
    cmd.Parameters.AddWithValue($"@id{i}", ids[i]);

var grouped = new Dictionary<int, List<Item>>();
using var rdr = cmd.ExecuteReader();
while (rdr.Read()) {
    var orderId = rdr.GetInt32(rdr.GetOrdinal("order_id"));
    if (!grouped.TryGetValue(orderId, out var list))
        grouped[orderId] = list = new();
    list.Add(MapItem(rdr));
}
```
Un solo `ExecuteReader()` con `IN(@id0, @id1, ...)` parametrizado. `using` garantiza el `Dispose` del `SqliteCommand` y del `SqliteDataReader`. Con EF Core real esto seria `.Where(x => ids.Contains(x.OrderId)).ToList()` traducido a `IN(...)` automaticamente — aqui se ve el SQL crudo para que el patron quede explicito.

**Notas idiomaticas vs los otros stacks:**
- `Microsoft.Data.Sqlite` cumple el rol de `sqlite-jdbc` Java o `node:sqlite` Node — el motor empaquetado en libreria nativa, accesible via API ADO.NET.
- `Interlocked.Increment(ref counter)` reemplaza el `LongAdder` Java o `AtomicInteger`.
- A diferencia de PHP, no hay pool de conexiones externo — la `SqliteConnection` es del proceso.

---

---

## Go 1.23: `database/sql` + `modernc.org/sqlite`, sin ORM que culpar

**Motor:** SQLite en memoria compartida (`file:case02?mode=memory&cache=shared`). Sin `cache=shared`, cada conexion del pool abriria su propia base vacia — es el detalle que hace que el caso funcione con `database/sql`.

**Legacy vs optimized:**
```go
orders, _ := selectOrders(limit)                  // 1
for _, o := range orders {                        // N
    db.Query("SELECT sku, qty FROM order_items WHERE order_id = ? ORDER BY id ASC", o.id)
}
// vs
placeholders := strings.TrimRight(strings.Repeat("?,", len(ids)), ",")
db.Query(fmt.Sprintf("SELECT order_id, sku, qty FROM order_items WHERE order_id IN (%s) ORDER BY id ASC", placeholders), ids...)
```

**Lo que este stack enseña y los otros no:** Go no tiene ORM en la biblioteca estandar. En Java o .NET, el N+1 de este caso es el que **genera Hibernate o Entity Framework** al iterar una coleccion lazy sin `JOIN FETCH` ni `Include()` — aparece sin que nadie lo escriba, y por eso cuesta detectarlo. Aca hay que teclearlo. El caso lo teclea a proposito para medirlo, y el diagnostico empieza leyendo el codigo en vez del log del ORM.

---

## Rust 1.83: `rusqlite` bundled y el error de cursor que no se puede ignorar

**Motor:** SQLite embebido via `rusqlite` feature `bundled`, archivo con `journal_mode=WAL`.

**Donde Rust se separa de Go —que comparte con el la ausencia de ORM— es en el manejo del cursor:**
```rust
// query_map devuelve Iterator<Item = Result<T>>.
// Este collect obliga a decidir que pasa si una fila falla a mitad del recorrido.
mapped.collect::<rusqlite::Result<Vec<_>>>()?
```

En Go el equivalente es recorrer `rows.Next()` y **acordarse** de chequear `rows.Err()` despues del bucle. Olvidarlo compila y silencia fallos parciales del cursor: la query devuelve menos filas de las que debia y nadie se entera. En Rust ese olvido no tiene forma de expresarse, porque el `Result` esta en el tipo del iterador.

**Verificacion cruzada:** Go y Rust generan el dataset con el mismo LCG. `/orders-legacy?limit=5` devuelve `order_id 1, customer_id 276` con items `SKU-2369 qty 2` y `SKU-2863 qty 8` en ambos.

## Diferencias de decision, no de correccion — PHP · Python · Node · Java · .NET

> Los stacks Go y Rust tienen su seccion propia arriba; el contraste de los **siete** esta en "Primitiva central por stack" al final.

| Aspecto | PHP | Python | Node.js | Java | .NET | Razon |
|---|---|---|---|---|---|---|
| Motor DB | PostgreSQL 16 (externo) | SQLite stdlib | SQLite `node:sqlite` built-in | SQLite `sqlite-jdbc` | SQLite `Microsoft.Data.Sqlite` | PHP usa motor productivo cliente/servidor. Los otros 4 usan SQLite embebido — DB real sin contenedor extra. |
| Primitiva de query | `PDO::prepare()` | `cursor.execute()` | `db.prepare().get()/all()` | `PreparedStatement.executeQuery()` | `SqliteCommand.ExecuteReader()` | Cinco APIs idiomaticas, mismo patron prepared statement. |
| Bindings | `?` posicional | `?` posicional | `?` posicional | `?` posicional via `setInt()` | `@named` via `Parameters.Add()` | Cuatro stacks con posicional, .NET con named — la API ADO.NET historicamente prefirio nombre. |
| Agrupacion | `array_column()` + `foreach` | `dict.setdefault()` + comprehension | `Map.get()` + iteracion | `HashMap.computeIfAbsent()` | `Dictionary.TryGetValue()` | Cinco idiomas, mismo algoritmo O(N) sobre el resultset. |
| Medicion `db_hits` | counter PHP + `pg_stat_statements` | counter Python | counter Node + `event_loop_lag_ms` | `LongAdder` por ruta | `Interlocked.Increment` por ruta | Todos miden hits reales contra DB. Node suma lag del loop como senal nativa. |
| Costo del N+1 | Bloquea el proceso FPM completo | Bloquea el thread (GIL libre en I/O) | Bloquea el event loop (SQLite sincronico) | Bloquea el worker del pool | Bloquea el worker del pool | Cinco modelos de concurrencia, mismo patron, distinta senal bajo carga. |

**El patron que los siete demuestran es identico:** N+1 sobre SQL escala con N*M independientemente del lenguaje o motor. La correccion — batch loading con `IN(...)` + agrupacion en memoria — tambien es identica en concepto. La diferencia observable bajo carga concurrente es **donde duele** y **con que primitiva idiomatica se expresa la solucion**.

---

## Primitiva central por stack

> Los siete stacks resuelven el mismo problema. Lo que cambia es la primitiva y donde duele.

| Stack | Primitiva central en este caso |
|---|---|
| PHP | PostgreSQL real via `PDO` |
| Python | `sqlite3` stdlib |
| Node.js | `node:sqlite` `db.prepare()` |
| Java 21 | `sqlite-jdbc` `PreparedStatement` + batch `IN(...)` |
| .NET 8 | `Microsoft.Data.Sqlite` `SqliteCommand` + parametros |
| Go 1.23 | `database/sql` — sin ORM que genere el N+1 por accidente |
| Rust 1.83 | `rusqlite`; `collect::<Result<Vec<_>>>()` impide ignorar el error de cursor |

