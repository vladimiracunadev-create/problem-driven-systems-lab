# Caso 01 — Comparativa multi-stack: API lenta bajo carga (PHP · Python · Node.js · Java · .NET · Go · Rust)

## El problema que ambos resuelven

Una API de reportes que carga datos de clientes con sus pedidos recientes. La variante legacy hace una consulta por cada cliente dentro de un bucle (N+1). La variante optimizada lee todo en 2-3 consultas consolidadas y deja el ensamblado al runtime del lenguaje.

---

## Fidelidad del substrato — los 7 stacks contra un motor real

**Los 7 stacks ejecutan SQL real.** No hay datos en memoria simulando ser una base, ni `sleep()` haciendo de latencia de I/O. `db_hits` / `db_queries_in_request` cuentan ejecuciones reales contra un motor en los siete runtimes.

| Stack | Motor | Driver / primitiva | Concurrencia lector-escritor |
|---|---|---|---|
| PHP | PostgreSQL 16 externo | `PDO` sobre socket TCP | MVCC de PostgreSQL |
| Python | SQLite (archivo) | `sqlite3` stdlib | `threading.RLock` global |
| Node.js 22 | SQLite (`:memory:`) | `node:sqlite` → `DatabaseSync` | proceso unico; el motor serializa |
| Java 21 | SQLite (archivo) | `sqlite-jdbc` → `PreparedStatement` | **WAL** + conexion por request |
| .NET 8 | SQLite (archivo) | `Microsoft.Data.Sqlite` → `SqliteCommand` | **WAL** + conexion por unidad de trabajo |

### El filtro no sargable, verificado por el motor

En Java y .NET la ruta legacy no dice "esto seria lento": el planner lo confirma. `EXPLAIN QUERY PLAN` sobre la misma tabla, con `idx_orders_region` presente:

```text
LEGACY     WHERE LOWER(region) LIKE 'n%'
           →  SCAN orders                                    (tabla completa)

OPTIMIZED  WHERE region >= 'n' AND region < 'o'
           →  SEARCH orders USING INDEX idx_orders_region    (rango indexado)
```

Envolver la columna en `LOWER()` invalida el indice. Reescribir el mismo predicado como rango lo recupera. Es la leccion central del caso y ahora es reproducible con un comando, no una afirmacion.

### Lo que sigue siendo distinto entre stacks

La asimetria que queda no es de fidelidad, es de **naturaleza del motor**, y es deliberada:

- **PHP corre contra un PostgreSQL externo.** La contencion cruza un socket TCP y satura un pool FPM finito. Es el unico stack donde el cuello se ve con `pg_stat_activity`.
- **Los otros cuatro corren SQLite embebido.** El SQL es real y el plan de ejecucion es real, pero no hay hop de red ni pool de conexiones remoto. Node y Python compensan con un round-trip artificial explicito (`ROUNDTRIP_*_MS`, documentado en el codigo) para que el costo de N+1 sea medible; Java y .NET no lo necesitan porque el N+1 ya paga `1 + N` ejecuciones reales.
- **Node es sincronico a proposito.** `DatabaseSync` bloquea el event loop por query. Eso convierte `event_loop_lag_ms` en la señal Node-especifica del caso: el N+1 no penaliza solo a quien lo pide, degrada el throughput del proceso entero.

Quien quiera ver contencion sobre un recurso externo compartido va al stack PHP. Quien quiera ver el mismo problema resuelto con la primitiva idiomatica de cada lenguaje tiene los siete.

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

## Node.js: single-thread event loop, `node:sqlite` sincronico, worker `setInterval`

**Runtime:** Node.js 22 single-thread con event loop libuv. Cada request es una funcion async que comparte el mismo proceso. Un `await` cede al loop pero no libera ningun thread — el costo agregado de awaits secuenciales degrada throughput global del proceso, no solo de la propia request.

**Motor de datos:** SQLite embebido via `node:sqlite` (`DatabaseSync`), built-in desde Node 22.5. Sin `npm install`, sin bindings nativos que compilar — esa fue la razon para descartar `better-sqlite3` y la que hace viable tener motor real sin sumar dependencias.

**El fallo legacy en Node.js:**
```javascript
// 1 agregacion con filtro no sargable...
const rows = await timedQuery(
  `SELECT customer_id, ROUND(SUM(total_amount), 2) AS total_spend, COUNT(*) AS order_count
   FROM orders
   WHERE CAST(created_at / 86400 AS INTEGER) >= ? AND status = 'paid'
   GROUP BY customer_id ORDER BY total_spend DESC LIMIT ?`,
  [sinceDay, limit], stats, ROUNDTRIP_LEGACY_MS);

// ...y 2 queries dependientes por cada fila.
for (const row of rows) {
  const customer = await timedQuery('SELECT id, name, tier, region FROM customers WHERE id = ?',
                                    [row.customer_id], stats);
  const recent = await timedQuery(
    `SELECT id, total_amount, status, created_at FROM orders
     WHERE customer_id = ? ORDER BY created_at DESC LIMIT 3`, [row.customer_id], stats);
}
```
Para 20 clientes: **41 ejecuciones reales** contra el motor (`db_queries_in_request: 41`). El `CAST(created_at / 86400)` invalida `idx_orders_created_customer` — el motor recorre las 36.000 filas de `orders` en cada request.

**La corrección en Node.js:**
```javascript
const placeholders = ids.map(() => '?').join(',');
const details = await timedQuery(
  `SELECT customer_id, id, total_amount, status, created_at
   FROM (
     SELECT customer_id, id, total_amount, status, created_at,
            ROW_NUMBER() OVER (PARTITION BY customer_id ORDER BY created_at DESC) AS rn
     FROM orders WHERE customer_id IN (${placeholders})
   ) WHERE rn <= 3 ORDER BY customer_id, created_at DESC`,
  ids, stats);
```
Una window function (`ROW_NUMBER() OVER PARTITION BY`) reemplaza las 2N queries dependientes: **2 queries totales**, sin importar el `limit`. El agrupado posterior es un `Map` en memoria — pero sobre un resultado que ya vino resuelto del motor.

**Lo distintivo de este stack:** `DatabaseSync` es **sincronico**. Cada query bloquea el event loop mientras corre. En los demas runtimes el N+1 penaliza al thread que lo ejecuta; en Node penaliza al proceso entero, y `event_loop_lag_ms` es la metrica que lo delata. La espera de red se modela aparte con `await sleep(ROUNDTRIP_*_MS)` porque en un driver cliente-servidor real esa porcion si cederia el loop.

**Worker:** `setInterval(refresh, 20000).unref()` embebido en el proceso, ejecutando `DELETE` + `INSERT ... SELECT` reales sobre `customer_daily_summary`. El `unref()` permite que el proceso muera limpio si solo queda el timer.

**Observabilidad:** endpoint `/metrics-prometheus` con `event_loop_lag_ms` como senal propia del runtime (medida con `setImmediate` callback). No existe equivalente en PHP-FPM ni Python.

---

## Java 21: thread-per-request en JVM, `sqlite-jdbc` con WAL, worker `ScheduledExecutorService`

**Runtime:** JVM con thread pool (cached executor). Cada request HTTP corre en un thread del pool — paralelismo real limitado por nucleos, no por GIL como Python.

**Motor de datos:** SQLite embebido via `sqlite-jdbc` 3.46.1.3 (driver xerial, un solo JAR sin Maven), en archivo bajo `/tmp` con `journal_mode=WAL`.

**El fallo legacy en Java:**
```java
try (PreparedStatement ps = db.prepareStatement(
        "SELECT id, customer_id, region, amount FROM orders " +
        "WHERE LOWER(region) LIKE 'n%' ORDER BY id LIMIT ?")) {   // <- no sargable
    ...
}
dbHits++;

for (int i = 0; i < ids.size(); i++) {                            // <- N+1 real
    try (PreparedStatement ps = db.prepareStatement(
            "SELECT name, tier FROM customers WHERE id = ?")) {
        ps.setInt(1, ids.get(i)[1]);
        ...
    }
    dbHits++;
}
```
`LOWER(region)` envuelve la columna e invalida `idx_orders_region`. El planner lo confirma:

```text
EXPLAIN QUERY PLAN … WHERE LOWER(region) LIKE 'n%'   →  SCAN orders
EXPLAIN QUERY PLAN … WHERE region >= 'n' AND < 'o'   →  SEARCH orders USING INDEX idx_orders_region
```

**La correccion en Java:**
```java
// Mismo predicado, reescrito como rango sargable — recupera el indice.
"SELECT id, customer_id, region, amount FROM orders " +
"WHERE region >= 'n' AND region < 'o' ORDER BY id LIMIT ?"

// Un solo batch IN(...) reemplaza las N queries dependientes.
"SELECT id, name, tier FROM customers WHERE id IN (?,?,?,…)"

// Y la tabla resumen que mantiene el worker.
"SELECT customer_id, order_count, total_amount FROM customer_summary WHERE customer_id IN (…)"
```
`db_hits` pasa de `1 + N` a un numero constante independiente del `limit`.

**Por que WAL:** el worker escribe `customer_summary` mientras los handlers leen. Con `journal_mode=WAL` los lectores no se bloquean con el escritor — es el equivalente embebido del MVCC que da PostgreSQL en el stack PHP. Sin WAL, el `DELETE` + `INSERT ... SELECT` del worker bloquearia cada lectura concurrente, que es precisamente el fallo que el caso enseña a evitar.

**Worker:** `ScheduledExecutorService` cada 5s en thread daemon, con su propia `Connection`. Shutdown limpio via shutdown hook.

**Gestion de recursos:** `try-with-resources` sobre `Connection`, `PreparedStatement` y `ResultSet`. Es la primitiva que garantiza que una excepcion a mitad del N+1 no deje conexiones colgadas — el mismo problema que el caso 14 del roadmap (pool exhaustion) estudia a fondo.

**Observabilidad:** `LongAdder` para contadores lock-free. p95/p99 sobre buffer circular sincronizado.

---

## .NET 8: ThreadPool del CLR, ConcurrentDictionary como summary cache, worker Task.Delay con CancellationToken

**Runtime:** .NET 8 sobre `HttpListener` (BCL). El CLR despacha cada request al `ThreadPool` — worker threads reales, paralelismo limitado por nucleos (no por GIL como Python, no por single-thread como Node). Estado compartido entre threads requiere primitivas concurrentes explicitas (`ConcurrentDictionary`, `Interlocked`, `AsyncLocal`).

**Motor de datos:** SQLite embebido via `Microsoft.Data.Sqlite` 8.0.10 (paquete oficial, ADO.NET-style), en archivo bajo el temp del sistema con `journal_mode=WAL`. Sin EF Core: el caso estudia SQL, no un ORM.

**El fallo legacy en C#:**
```csharp
using (var cmd = db.CreateCommand()) {
    cmd.CommandText = "SELECT id, customer_id, region, amount FROM orders " +
                      "WHERE LOWER(region) LIKE 'n%' ORDER BY id LIMIT $limit";   // <- no sargable
    ...
}
dbHits++;

for (int i = 0; i < rows.Count; i++) {                                            // <- N+1 real
    using var cmd = db.CreateCommand();
    cmd.CommandText = "SELECT name, tier FROM customers WHERE id = $id";
    ...
    dbHits++;
}
```
Espejo exacto del Java: mismo esquema, mismas queries, **mismos resultados fila por fila**. Correr `/report-legacy?limit=5` en ambos hubs devuelve `order_id 12, Customer 1315, silver, north, 934` en los dos.

**La correccion en C#:**
```csharp
// Rango sargable — recupera idx_orders_region.
"SELECT id, customer_id, region, amount FROM orders " +
"WHERE region >= 'n' AND region < 'o' ORDER BY id LIMIT $limit"

// Dos batches IN(...) con parametros generados: customers y customer_summary.
$"SELECT id, name, tier FROM customers WHERE id IN ({placeholders})"
$"SELECT customer_id, order_count, total_amount FROM customer_summary WHERE customer_id IN ({placeholders})"
```

**Worker:** `Task.Run(async () => { while (!ct.IsCancellationRequested) { await Task.Delay(5000, ct); RefreshSummary(); } })`, con su propia conexion y una transaccion explicita (`BeginTransaction` / `Commit`). El `CancellationToken` propaga shutdown limpio en SIGTERM — idiomatico .NET, sin shutdown hook como Java.

**Gestion de recursos:** `using` / `IDisposable` sobre `SqliteConnection`, `SqliteCommand` y `SqliteDataReader`. Es el equivalente directo del `try-with-resources` Java: cierre garantizado incluso si el N+1 revienta a la mitad.

**Observabilidad:** `Interlocked.Increment(ref counter)` para contadores lock-free — equivalente del `LongAdder` Java. p95/p99 sobre buffer circular protegido con `lock`.

**Notas idiomaticas vs los otros stacks:**
- `using` / `IDisposable` cumple el rol de `try-with-resources` Java — misma garantia de cierre deterministico.
- `SqliteCommand` con parametros `$nombre` es el analogo del `PreparedStatement` con `?` de JDBC.
- `Interlocked.Increment` es el equivalente de `LongAdder.increment()` Java.
- `Task.Delay` + `CancellationToken` reemplaza el `ScheduledExecutorService` + shutdown hook de Java.
- A diferencia de Node, el CLR permite paralelismo real sin `worker_threads`, y el acceso a SQLite no bloquea un event loop compartido. A diferencia de Python, no hay GIL que serialice bytecode.

---

---

## Go 1.23: `modernc.org/sqlite` en Go puro, worker con goroutine + Ticker

**Runtime:** un binario estatico. `net/http` de la stdlib con una goroutine por request; el runtime las multiplexa sobre `GOMAXPROCS` hilos del SO.

**Motor de datos:** SQLite embebido via `modernc.org/sqlite` — un port de SQLite a Go puro, sin cgo. Esa eleccion es la que permite `CGO_ENABLED=0` y una imagen final sin toolchain de C; `mattn/go-sqlite3` habria obligado a lo contrario.

**El fallo legacy en Go:**
```go
rows, err := db.Query(
    `SELECT id, customer_id, region, amount FROM orders
     WHERE LOWER(region) LIKE 'n%' ORDER BY id LIMIT ?`, limit)   // no sargable
...
for _, x := range raws {                                          // N+1 real
    db.QueryRow("SELECT name, tier FROM customers WHERE id = ?", x.customerID).Scan(&name, &tier)
    dbHits++
}
```

**La correccion en Go:** el mismo predicado como rango (`region >= 'n' AND region < 'o'`) mas dos batches `IN(...)`. `db_hits` pasa de `1+N` a constante.

**Worker:** goroutine con `time.Ticker`. No hay pool que dimensionar ni shutdown hook que registrar — la goroutine muere con el proceso.

**Gestion de recursos:** `defer rows.Close()`. Equivalente del `try-with-resources` de Java, a nivel de funcion en vez de bloque.

**Lo que aporta que ningun otro stack:** `encoding/json` con struct tags. Es el unico stack del lab que serializa el contrato desde tipos en vez de concatenar strings — Java, .NET y Rust arman el JSON a mano con `StringBuilder`/`format!`.

---

## Rust 1.83: `rusqlite` bundled, ownership y `Drop` en lugar de cierre explicito

**Runtime:** binario compilado, un thread del SO por conexion (`std::thread::spawn`). Sin runtime asincronico.

**Motor de datos:** SQLite embebido via `rusqlite` con feature `bundled` — compila SQLite desde fuente **dentro del binario**, sin depender de `libsqlite3` del sistema.

**El fallo legacy en Rust:**
```rust
let mut stmt = conn.prepare(
    "SELECT id, customer_id, region, amount FROM orders \
     WHERE LOWER(region) LIKE 'n%' ORDER BY id LIMIT ?1")?;      // no sargable
...
for (id, cid, region, amount) in rows.iter() {                    // N+1 real
    conn.query_row("SELECT name, tier FROM customers WHERE id = ?1", params![cid], ...)
    db_hits += 1;
}
```

**Gestion de recursos:** aca esta la diferencia con los otros seis stacks. **No hay cierre que escribir.** Cuando la `Connection` sale de scope, su destructor corre. No hay `try-with-resources`, ni `using`, ni `defer`, ni `finally` — la liberacion es una propiedad del tipo, no una construccion que el autor deba recordar.

**Lo que este stack expone y conviene no exagerar:** `std` de Rust **no trae servidor HTTP**. Java tiene `com.sun.net.httpserver`, .NET tiene `HttpListener`, Go tiene `net/http`; aca la capa se escribe sobre `TcpListener` en ~60 lineas. Es deliberado para no arrastrar ~200 crates transitivos en un caso cuyo tema es SQL. En produccion nadie hace esto: se usa `axum` sobre `tokio`.

**Verificacion cruzada:** Java, .NET, Go y Rust generan el dataset con el mismo LCG. `/report-legacy?limit=5` devuelve la misma primera fila en los cuatro (`order_id 12, Customer 1315, silver, north, 934`), con `db_hits 6` y 1.531 filas en `customer_summary`.

## Diferencias de decisión, no de corrección — PHP · Python · Node · Java · .NET

> Los stacks Go y Rust tienen su seccion propia arriba; el contraste de los **siete** esta en "Primitiva central por stack" al final.

| Aspecto | PHP | Python | Node.js | Java | .NET | Razon |
|---|---|---|---|---|---|---|
| Motor DB | PostgreSQL 16 (externo) | SQLite (archivo) | SQLite (`:memory:`) | SQLite (archivo, WAL) | SQLite (archivo, WAL) | Cinco motores reales. Solo PHP cruza un socket TCP; los otros cuatro embeben el motor sin sumar contenedor. |
| Driver / primitiva | `PDO` | `sqlite3` stdlib | `node:sqlite` → `DatabaseSync` | `sqlite-jdbc` → `PreparedStatement` | `Microsoft.Data.Sqlite` → `SqliteCommand` | Cada stack usa la via idiomatica de su ecosistema, sin ORM. |
| Cierre de recursos | fin de proceso FPM | `finally` + `close()` | proceso unico, conexion global | `try-with-resources` | `using` / `IDisposable` | La garantia de no filtrar conexiones es lo que cambia entre runtimes. |
| Worker | Contenedor Docker separado | `threading.Thread` en proceso | `setInterval(...).unref()` en proceso | `ScheduledExecutorService` | `Task.Delay` + `CancellationToken` | FPM no comparte estado. Los demas si — Node sin lock por single-thread; Java/.NET con primitivas concurrentes. |
| Observabilidad | Prometheus + Grafana | `/metrics-prometheus` | `/metrics-prometheus` + `event_loop_lag_ms` | `LongAdder` + buffer p95/p99 | `Interlocked` + buffer p95/p99 | Solo Node expone lag del loop. Java y .NET exponen contadores lock-free. |
| Concurrencia | FPM workers (multiproceso) | Threads en un proceso (GIL) | Single-thread event loop | JVM ThreadPool (paralelismo real) | CLR ThreadPool (paralelismo real) | Cinco modelos. Mismo patron N+1, distintas senales bajo carga. |
| Costo de await secuencial | Bloquea el proceso FPM completo | Bloquea el thread, libera GIL en I/O | Cede al loop pero penaliza throughput global | Bloquea el thread del pool, otros siguen | Bloquea el thread del pool, otros siguen | El comportamiento bajo carga concurrente es lo que mas diferencia los runtimes. |

**El patron que los siete demuestran es identico:** N+1 vs batch loading. La diferencia observable (`db_queries`, `db_time_ms`) es la misma. Lo que cambia es **donde duele**: en PHP el pool FPM se agota; en Python el thread queda en I/O; en Node se acumula lag del event loop porque `node:sqlite` es sincronico; en Java/.NET se saturan los worker threads del pool; en Go se satura el scheduler sin que exista un pool que agotar; en Rust se ocupan threads del SO 1:1.

---

## Primitiva central por stack

> Los siete stacks resuelven el mismo problema. Lo que cambia es la primitiva y donde duele.

| Stack | Primitiva central en este caso |
|---|---|
| PHP | PostgreSQL 16 externo via `PDO`; worker en contenedor aparte |
| Python | `sqlite3` stdlib + `threading.RLock`; worker en thread |
| Node.js | `node:sqlite` `DatabaseSync` **sincronico**; `event_loop_lag_ms` como señal propia |
| Java 21 | `sqlite-jdbc` + WAL; `try-with-resources`; `ScheduledExecutorService` |
| .NET 8 | `Microsoft.Data.Sqlite` + WAL; `using`/`IDisposable`; `Task.Delay`+`CancellationToken` |
| Go 1.23 | `modernc.org/sqlite` (Go puro, sin cgo) + WAL; `defer`; goroutine + `Ticker` |
| Rust 1.83 | `rusqlite` bundled + WAL; **`Drop` sin cierre explicito**; `std::thread` |

