# Caso 01 — Comparativa multi-stack: API lenta bajo carga (PHP · Python · Node.js · Java)

## El problema que ambos resuelven

Una API de reportes que carga datos de clientes con sus pedidos recientes. La variante legacy hace una consulta por cada cliente dentro de un bucle (N+1). La variante optimizada lee todo en 2-3 consultas consolidadas y deja el ensamblado al runtime del lenguaje.

---

## PHP: proceso por request, PostgreSQL, worker en contenedor separado

**Runtime:** PHP-FPM crea un proceso nuevo por cada request HTTP. Ese proceso vive, ejecuta, y muere. No hay estado compartido entre requests salvo la base de datos.

**Motor de datos:** PostgreSQL 16 en un contenedor externo. La conexión se establece via socket TCP por cada proceso FPM. Cada `PDO->prepare()` + `PDOStatement->fetch()` dentro de un bucle cruza esa frontera de red una vez por iteración.

**El fallo legacy en PHP:**
```php
foreach ($orders as &$order) {
    $order['customer'] = $db->timedQuery(
        "SELECT * FROM customers WHERE id = ?", [$order['customer_id']]
    )->fetch();
    // … más queries por pedido
}
```
Para 20 pedidos: 41 llamadas PDO secuenciales. El proceso FPM queda bloqueado en I/O de red durante toda la ejecución. El pool de workers FPM se agota bajo carga concurrente.

**La corrección en PHP:**
```php
$ids = array_map(fn($o) => $o['id'], $orders);
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$customers = $db->timedQuery(
    "SELECT * FROM customers WHERE id IN ($placeholders)", $ids
)->fetchAll();
$customerMap = array_column($customers, null, 'id'); // O(1) por clave
```
PHP usa `array_column()` para construir un hash map asociativo en memoria. El acceso posterior es `$customerMap[$order['customer_id']]` — O(1) sin más I/O.

**Worker:** proceso separado (`worker.php`) en su propio contenedor Docker. Se comunica con la DB directamente. Aislamiento completo del proceso del servidor.

**Observabilidad:** Prometheus + Grafana + postgres-exporter. Los dashboards muestran `pg_stat_activity`, queries activas, tiempos de espera reales.

---

## Python: proceso único, SQLite embebida, worker como hilo

**Runtime:** `ThreadingHTTPServer` crea un hilo por request dentro del mismo proceso Python. El GIL de Python serializa ejecución de bytecode, pero lo libera durante I/O (incluido SQLite). Múltiples requests pueden progresar concurrentemente en I/O.

**Motor de datos:** SQLite embebida via `sqlite3` de stdlib. No hay socket de red — el acceso es una llamada de función al mismo proceso. Un `threading.RLock` (`DB_LOCK`) serializa el acceso para evitar conflictos de escritura entre hilos.

**El fallo legacy en Python:**
```python
for row in orders:
    cur.execute("SELECT * FROM customers WHERE id = ?", (row["customer_id"],))
    row["customer"] = dict(cur.fetchone())
    cur.execute("SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 3",
                (row["customer_id"],))
    row["recent_orders"] = [dict(r) for r in cur.fetchall()]
```
Para 20 pedidos: 41 `cursor.execute()` secuenciales. SQLite procesa cada uno bloqueando el hilo hasta completar. El GIL no ayuda aquí — el cuello es I/O de archivo, no CPU.

**La corrección en Python:**
```python
ids = [r["customer_id"] for r in orders]
placeholders = ",".join("?" * len(ids))
cur.execute(f"SELECT * FROM customers WHERE id IN ({placeholders})", ids)
customer_map = {r["id"]: dict(r) for r in cur.fetchall()}
# Ensamblado en Python puro: O(N) sin más I/O
for row in orders:
    row["customer"] = customer_map.get(row["customer_id"])
```
Python construye el `dict` de clientes con una dict comprehension. El acceso por `customer_map[id]` es O(1). Misma lógica que PHP, idioma diferente.

**Worker:** `threading.Thread(daemon=True)` embebido en el mismo proceso del servidor. Comparte `DB_LOCK` con los handlers de request. No requiere contenedor adicional — portabilidad completa con un solo `docker compose up`.

**Observabilidad:** endpoint `/metrics-prometheus` que expone texto Prometheus scrappeable. Sin Grafana ni postgres-exporter — la variante Python es autocontenida.

---

## Node.js: single-thread event loop, datos en memoria, worker `setInterval`

**Runtime:** Node.js 20 single-thread con event loop libuv. Cada request es una funcion async que comparte el mismo proceso. Un `await` cede al loop pero no libera ningun thread — el costo agregado de awaits secuenciales degrada throughput global del proceso, no solo de la propia request.

**Motor de datos:** estructuras en memoria (`Map<id, customer>`, array de `orders`) con I/O simulado por `setTimeout(roundtrip_ms)`. La eleccion explicita evita compilar bindings nativos (`better-sqlite3`) y mantiene el foco en el patron de acceso, no en el motor.

**El fallo legacy en Node.js:**
```javascript
for (const row of aggregated) {
  row.customer = await timedQuery(() => customers.get(row.customer_id), stats);
  row.recent_orders = await timedQuery(
    () => orders.filter(o => o.customer_id === row.customer_id)
                .sort((a,b) => b.created_at - a.created_at).slice(0,3),
    stats
  );
}
```
Para 20 pedidos: 41 awaits secuenciales. Cada `await sleep(1.2)` cede al event loop, pero la callback de la siguiente iteracion vuelve a la cola. El loop se mantiene caliente atendiendo otras tareas pero el throughput por request crece linealmente con `limit`. Lo distintivo: bajo concurrencia, la metrica `event_loop_lag_ms` se dispara — es la senal Node-especifica del bloqueo agregado.

**La corrección en Node.js:**
```javascript
const idSet = new Set(aggregated.map(row => row.customer_id));
const recentMap = await timedQuery(() => {
  const grouped = new Map();
  for (const order of orders) {
    if (!idSet.has(order.customer_id)) continue;
    const list = grouped.get(order.customer_id) || [];
    list.push(order);
    grouped.set(order.customer_id, list);
  }
  return grouped;
}, stats);
return aggregated.map(row => ({ ...row, recent_orders: recentMap.get(row.customer_id) || [] }));
```
Una sola lectura batch, agrupacion en memoria con `Map.get()` O(1), ensamblado funcional con `.map()`. Sin awaits anidados, sin yields innecesarios al loop entre items.

**Worker:** `setInterval(refresh, 20000).unref()` embebido en el proceso. El `unref()` permite que el proceso muera limpio si solo queda el timer. Comparte estructuras en memoria con los handlers — no necesita lock porque Node es single-thread.

**Observabilidad:** endpoint `/metrics-prometheus` con `event_loop_lag_ms` como senal propia del runtime (medida con `setImmediate` callback). No existe equivalente en PHP-FPM ni Python — es lo que delata el bloqueo agregado del loop.

---

## Java 21: thread-per-request en JVM, datos en memoria, worker `ScheduledExecutorService`

**Runtime:** JVM con thread pool (cached executor). Cada request HTTP corre en un thread del pool — paralelismo real limitado por nucleos, no por GIL como Python. Estado compartido entre threads requiere primitivas concurrentes explicitas (`ConcurrentHashMap`, `AtomicReference`, `LongAdder`).

**Motor de datos:** Datos en memoria (`ArrayList<Order>`, `HashMap<Integer,Customer>`). Mismo patron que Node: foco en el cuello sin meter PostgreSQL.

**El fallo legacy en Java:**
```java
for (Order o : orders)
    if (lowerRegion(o.region).startsWith("n")) scanned.add(o);   // scan O(N) no sargable
for (int i = 0; i < take; i++) {
    Customer c = lookupCustomerOneByOne(o.customerId);            // busqueda lineal
    sleepMicros(1200);                                            // roundtrip simulado
}
```
Bajo carga concurrente, **cada thread del pool** ejecuta este loop con sleeps secuenciales. El pool se llena rapido (N threads × 1.2ms × hits). Diferencia clave vs Node: aqui el bloqueo es por-thread, no del proceso entero.

**La correccion en Java:**
```java
List<Order> matched = ordersByRegionPrefix.getOrDefault("n", List.of());  // O(1)
Map<Integer, Customer> batch = new HashMap<>();
for (int i = 0; i < take; i++) {
    if (!batch.containsKey(cid)) batch.put(cid, customerById.get(cid));   // O(1)
}
sleepMicros(700);                                                          // 1 sola vez
CustomerSummary s = summaryCache.get(o.customerId);                        // ConcurrentHashMap
```
`summaryCache` es `ConcurrentHashMap<Integer, CustomerSummary>` actualizado por el worker. Los handlers leen sin lock — esa es la garantia que da `ConcurrentHashMap` y que `synchronized Map` no daria sin contencion.

**Worker:** `ScheduledExecutorService` corriendo cada 5s en thread daemon. El handler lee `summaryCache` mientras el worker actualiza — sin contencion gracias al modelo de la estructura.

**Observabilidad:** `LongAdder` para contadores lock-free (mejor throughput que `synchronized int` bajo carga). p95/p99 calculados sobre buffer circular sincronizado.

---

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Node.js | Razon |
|---|---|---|---|---|
| Motor DB | PostgreSQL 16 (externo) | SQLite (embebida) | Datos en memoria + I/O simulado | PHP usa motor productivo. Python tiene `sqlite3` en stdlib. Node mantiene foco en el patron sin compilar bindings nativos. |
| Worker | Contenedor Docker separado | `threading.Thread` en proceso | `setInterval(...).unref()` en proceso | FPM no comparte estado. Python y Node si pueden — Node sin lock por single-thread. |
| Observabilidad | Prometheus + Grafana | `/metrics-prometheus` | `/metrics-prometheus` + `event_loop_lag_ms` | Solo Node expone lag del loop, propio del runtime. |
| Concurrencia | FPM workers (multiproceso) | Threads en un proceso (GIL) | Single-thread event loop | Tres modelos. Mismo patron N+1, distintas senales bajo carga. |
| Costo de await secuencial | Bloquea el proceso FPM completo | Bloquea el thread, libera GIL en I/O | Cede al loop pero penaliza throughput global del proceso | El comportamiento bajo carga concurrente es lo que mas diferencia los runtimes. |

**El patron que los tres demuestran es identico:** N+1 vs batch loading. La diferencia observable (`db_queries`, `db_time_ms`) es la misma. Lo que cambia es **donde duele**: en PHP el pool FPM se agota; en Python el thread queda en I/O; en Node se acumula lag del event loop.
