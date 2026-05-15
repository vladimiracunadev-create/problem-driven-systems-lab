# Caso 02 — Comparativa multi-stack: N+1 y cuellos de botella en base de datos (PHP · Python · Node.js · Java)

## El problema que ambos resuelven

Un feed de pedidos que necesita devolver, por cada pedido, el cliente, los items, y por cada item el producto y su categoría. La variante legacy construye ese grafo con queries anidadas dentro de bucles. La variante optimizada lo construye con joins consolidados y ensamblado en memoria.

---

## PHP: PostgreSQL, cursores PDO, agrupación con array_column

**Runtime:** PHP-FPM. Cada request es un proceso efímero. No hay estructuras de datos que sobrevivan entre requests.

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
Patron: `1 + N + N*M + N*M`. Para 20 pedidos con 3 items promedio: más de 120 llamadas PDO. PostgreSQL abre un cursor transaccional por cada `prepare()`. El pool de conexiones se agota.

**La corrección en PHP:**
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

// Agrupación O(N) en PHP puro sin más I/O
$grouped = [];
foreach ($items as $item) {
    $grouped[$item['order_id']][] = $item;
}
foreach ($orders as &$order) {
    $order['items'] = $grouped[$order['id']] ?? [];
}
```
PHP usa `array_column()` e iteración asociativa. El FPM recupera su rol: procesamiento CPU sin cruzar la frontera de red.

**Observabilidad adicional PHP:** `pg_stat_statements` via postgres-exporter. Se pueden ver las queries reales ejecutadas contra PostgreSQL desde Grafana.

---

## Python: SQLite, sqlite3 stdlib, dict comprehensions

**Runtime:** `ThreadingHTTPServer`. Los hilos comparten la conexión SQLite protegida por `threading.RLock`.

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

**La corrección en Python:**
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

# Agrupación en Python puro: dict comprehension + defaultdict pattern
grouped = {}
for item in items_all:
    grouped.setdefault(item["order_id"], []).append(item)

for order in orders:
    order["items"] = grouped.get(order["id"], [])
```
Python usa `dict.setdefault()` y list comprehensions. El resultado es funcionalmente idéntico al PHP con `array_column()`.

---

## Node.js: bucles `await` anidados, `Map` + `Set` como primitivas, single-thread

**Runtime:** Node.js 20 single-thread con event loop. El N+1 anidado se traduce literalmente en `for await (...) { for await (...) }` — el costo es `1 + N + sum(items_por_order * 2)` round-trips simulados secuencialmente.

**El fallo legacy en Node.js:**
```javascript
for (const order of baseOrders) {
  order.customer = await timedQuery(() => customers.get(order.customer_id), stats);
  const items = await timedQuery(() => orderItems.get(order.id) || [], stats);
  for (const item of items) {
    item.product = await timedQuery(() => products.get(item.product_id), stats);
    item.category = await timedQuery(() => categories.get(item.product.category_id), stats);
  }
  order.items = items;
}
```
Con `limit=20` y ~3.7 items/order: ~190 awaits secuenciales. Cada `await` cede al loop pero el siguiente vuelve a la cola — bajo concurrencia, `event_loop_lag_ms` se dispara y el throughput del proceso cae.

**La corrección en Node.js:**
```javascript
const ids = new Set(baseOrders.map(o => o.id));
const itemsByOrder = await timedQuery(() => {
  const grouped = new Map();
  for (const orderId of ids) {
    const items = (orderItems.get(orderId) || []).map(it => {
      const product = products.get(it.product_id);
      const category = product ? categories.get(product.category_id) : null;
      return { id: it.id, quantity: it.quantity, unit_price: it.unit_price, product, category };
    });
    grouped.set(orderId, items);
  }
  return grouped;
}, stats);
for (const order of baseOrders) order.items = itemsByOrder.get(order.id) || [];
```
Dos lecturas. Joins en memoria con `Map.get()` O(1) y `Set.has()` para filtros de pertenencia. La ausencia de un ORM hace explicita la decision — no hay magia que la oculte.

---

## Java 21: HashMap precomputado como tabla indexada, batch `IN(...)` simulado

**Runtime:** JVM con thread pool. Cada handler corre en thread propio; los seed maps quedan inmutables tras `seedData()` — lectura paralela segura sin lock.

**Motor de datos:** En memoria con `List<Order>` y `HashMap<Integer, List<Item>>`. La estructura `itemsByOrderId` es la version Java de un join precomputado (lo que JDBC + `IN(...)` traerian de PostgreSQL).

**El fallo legacy en Java:**
```java
for (int i = 0; i < take; i++) {
    Order o = orders.get(i);
    List<Item> items = lookupItemsOneByOne(o.id);   // 1 query por order → N+1
    sleepMicros(900);                               // costo de roundtrip
}
```
N+1 clasico. En Java productivo seria `PreparedStatement.executeQuery()` dentro del bucle — cada iteracion crea statement, lo manda, lee result set, lo cierra.

**La correccion en Java:**
```java
List<Integer> ids = collectIds(orders, take);
Map<Integer, List<Item>> batch = new HashMap<>();
for (Integer id : ids) batch.put(id, itemsByOrderId.getOrDefault(id, List.of()));
sleepMicros(700);   // 1 sola vez
```
Espejo de `SELECT * FROM items WHERE order_id IN (?, ?, ?, ...)`. JDBC tiene `setArray()` y batching en `PreparedStatement.addBatch()` que hace lo mismo a nivel protocolo.

**Por que no JDBC real aqui:** mantener los 12 casos Java sin Maven y sin dependencias. JDBC + driver PostgreSQL agregaria 6 MB+ al container y un punto de configuracion. El patron `IN(...)` vs N round-trips se demuestra igual con `HashMap`.

---

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Node.js | Razon |
|---|---|---|---|---|
| Motor DB | PostgreSQL 16 | SQLite embebida | Datos en memoria + I/O simulado | PHP usa motor productivo. Python embebido en stdlib. Node mantiene foco en el patron. |
| Agrupación | `array_column()` + `foreach` | `dict.setdefault()` + list comprehension | `Map.get()` + `[].map()` + `Set.has()` | Tres idiomas, mismo algoritmo O(N). |
| Medición real | `pg_stat_statements` via Grafana | `db_queries` contado por el servidor | `db_queries` + `event_loop_lag_ms` | Solo Node expone lag del loop como senal nativa. |
| Costo del N+1 anidado | Bloquea el proceso FPM completo | Bloquea el thread (GIL libre en I/O) | Cede al loop pero degrada throughput global | Tres modelos de concurrencia, mismo patron, distinta senal bajo carga. |

**El patron que los tres demuestran es identico:** el costo de N+1 escala con N*M independientemente del lenguaje o motor. La corrección — batch loading + agrupación en memoria — también es identica en concepto. La diferencia observable bajo carga concurrente es **donde duele**.
