# Caso 01 — Comparativa PHP vs Python: API lenta bajo carga

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

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Razon |
|---|---|---|---|
| Motor DB | PostgreSQL 16 (externo) | SQLite (embebida) | PHP no tiene motor embebido de produccion. Python tiene `sqlite3` en stdlib. |
| Worker | Contenedor Docker separado | `threading.Thread` en proceso | PHP-FPM no comparte estado entre procesos. Python sí puede compartir `DB_LOCK`. |
| Observabilidad | Prometheus + Grafana stack | `/metrics-prometheus` endpoint | PHP necesita exporters externos. Python expone el formato directamente. |
| Concurrencia | FPM workers (multiproceso) | Threads en un proceso (GIL) | Modelos distintos con el mismo resultado para I/O-bound workloads. |

**El patron que ambos demuestran es identico:** N+1 vs batch loading. La diferencia observable (`db_queries`, `db_time_ms`) es la misma. El motor de base de datos no cambia el patron — lo que cambia es el overhead de cada query (socket TCP en PHP vs llamada local en Python).
