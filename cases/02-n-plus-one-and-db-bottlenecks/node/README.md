# 🔁 Caso 02 — Node.js 20 + SQLite embebido (`node:sqlite`)

> Implementacion operativa del caso 02 para estudiar N+1 y cuellos de botella relacionales con evidencia observable, manteniendo paridad funcional con la version PHP+Postgres y Python+SQLite — y ahora con **SQL real bajo el contraste** en Node tambien.

## 🎯 Que resuelve

Modela un endpoint de pedidos recientes que requiere enriquecer cada pedido con cliente, items, producto y categoria. Dos variantes:

- `orders-legacy`: N+1 anidado. Por cada pedido: cliente + items; por cada item: producto + categoria. Cada acceso es un `prepare().get()` separado contra SQLite.
- `orders-optimized`: una lectura base con join en memoria contra customers, mas un solo `prepare().all()` con `IN(?, ?, ?, ...)` que agrupa items con producto y categoria por order_id.

## 💼 Por que importa

Este caso muestra el caso clasico donde el costo escala con `1 + N + sum(items_per_order * 2)` y, en Node, ese costo es doble: cada `Statement.get()` es file I/O sincronico sobre SQLite que bloquea el event loop mientras dura. Bajo concurrencia, `event_loop_lag_ms` pega a TODAS las requests en curso.

## 🔬 Analisis Tecnico de la Implementacion (Node.js)

- **Motor de datos:** `node:sqlite` (modulo built-in desde Node 22.5, sin `npm install`, sin compilar bindings nativos). DB inicializada en `:memory:` por instancia o `/tmp/case02.db` segun env. `db.prepare(sql)` cachea el plan; `.get(...)` / `.all(...)` ejecutan con parametros bound. `db_hits` cuenta ejecuciones reales contra el motor.

- **Implementacion Falla (`legacy`):** `recentOrdersLegacy()` ejecuta una lectura base de orders, despues `for (const order of baseOrders)` con `getCustomer.get(order.customer_id)` y `getItems.all(order.id)`. Por cada item, `getProduct.get(item.product_id)`. El patron de costo es real: con `limit=20` y ~3.7 items promedio por order, se generan ~190 `prepare().get()` contra SQLite. Cada uno bloquea el loop durante el roundtrip a JNI/native — el costo agregado se observa en `event_loop_lag_ms` y latencia p95.

- **Sanitizacion Algoritmica (`optimized`):** `recentOrdersOptimized()` resuelve todo con dos `prepare().all()`. El primero proyecta los pedidos y hace join customer en el mismo SELECT con `JOIN customers ON ...`. El segundo construye `IN (?, ?, ?, ...)` con los order_ids y trae items+product+category en una sola query con joins. La agrupacion en JS es O(N) sobre el resultset usando `Map.get()`.

- **Primitivas idiomaticas:** `Database` de `node:sqlite` para conexion, `Statement` (resultado de `db.prepare()`) para prepared statements, `Map` para indices por id, `Set` para filtros de pertenencia, `[].filter().sort().slice()` como pipeline funcional.

## 🧱 Primitivas

| Primitiva | Rol |
|---|---|
| `node:sqlite` `Database` | Conexion al motor SQLite embebido (built-in Node 22.5+). |
| `db.prepare(sql)` | Prepared statement cacheado; las ejecuciones siguientes reusan plan. |
| `Statement.get()` / `.all()` | Ejecucion sincrona; ideal para mostrar el bloqueo del loop bajo N+1. |
| `Map<order_id, items[]>` | Agrupacion O(N) tras el resultset. |
| `process.hrtime.bigint()` | Medicion ns para `db_time_ms` y `event_loop_lag_ms`. |

## 🧱 Servicio

- `app` → API Node.js 20 con rutas legacy y optimized. SQLite embebido inicializado al startup con el dataset semilla.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `822` (modo aislado, ver opciones abajo).

## Como consumir (dos opciones)

**Hub Node.js (recomendado, 8300 en `compose.nodejs.yml`):** este caso queda servido en `http://localhost:8300/02/...` junto a los otros 11 casos.

**Modo aislado (822 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## 🔎 Endpoints

```bash
curl http://localhost:8300/02/
curl http://localhost:8300/02/health
curl "http://localhost:8300/02/orders-legacy?days=30&limit=20"
curl "http://localhost:8300/02/orders-optimized?days=30&limit=20"
curl http://localhost:8300/02/diagnostics/summary
curl http://localhost:8300/02/metrics
curl http://localhost:8300/02/metrics-prometheus
curl http://localhost:8300/02/reset-metrics
```

## 🧭 Que observar

- `db_queries_in_request` en `orders-legacy` escala con `limit` y con la densidad relacional (items/order); cada hit es una ejecucion real contra SQLite, no una metrica derivada;
- `orders-optimized` se mantiene en 2 queries independientemente del `limit`;
- `delta.p95_ms` en `/diagnostics/summary` muestra la diferencia real;
- `event_loop_lag_ms` se dispara en legacy bajo concurrencia: senal Node-especifica que delata el bloqueo del loop por SQLite sincrono.

## ⚖️ Fidelidad

DB real en SQLite embebido — `db_hits` es un contador honesto de ejecuciones contra el motor. El contraste con PHP+PostgreSQL es solo de motor (cliente/servidor vs embebido), no de fidelidad del patron: ambos miden N round-trips reales vs 1 batch. La eleccion de SQLite mantiene el container Node single-process y sin dependencias de red.
