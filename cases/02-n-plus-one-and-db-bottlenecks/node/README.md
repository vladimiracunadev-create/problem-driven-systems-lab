# 🔁 Caso 02 — Node.js 20 + datos en memoria

> Implementacion operativa del caso 02 para estudiar N+1 y cuellos de botella relacionales con evidencia observable, manteniendo paridad funcional con la version PHP+Postgres y Python+SQLite.

## 🎯 Que resuelve

Modela un endpoint de pedidos recientes que requiere enriquecer cada pedido con cliente, items, producto y categoria. Dos variantes:

- `orders-legacy`: N+1 anidado. Por cada pedido: cliente + items; por cada item: producto + categoria. Cada acceso es un `await` secuencial.
- `orders-optimized`: una lectura base con join en memoria contra customers, mas un solo batch que agrupa items con producto y categoria por order_id.

## 💼 Por que importa

Este caso muestra el caso clasico donde el costo escala con `1 + N + sum(items_per_order * 2)` y, en Node, ese costo no solo penaliza a la propia request: cada `await` cede al event loop y agrega `event_loop_lag_ms` que pega a TODAS las requests en curso.

## 🔬 Analisis Tecnico de la Implementacion (Node.js)

- **Implementacion Falla (`legacy`):** `recentOrdersLegacy()` ejecuta una lectura base, despues `for (const order of baseOrders) { await ... }` anidando dos awaits dependientes por order, mas `for (const item of items) { await product; await category }`. El patron de costo es real: con `limit=20` y `~3.7` items promedio por order, se generan ~190 round-trips simulados secuenciales. Cada `await sleep()` cede al loop, pero el costo agregado se observa en `event_loop_lag_ms` y latencia p95.

- **Sanitizacion Algoritmica (`optimized`):** `recentOrdersOptimized()` resuelve todo con dos lecturas. La primera proyecta los pedidos y hace el join customer en memoria con `customers.get(o.customer_id)` (acceso `O(1)`). La segunda construye el `Map<order_id, items[]>` agrupando los items por order_id en una sola pasada y resolviendo product/category en memoria. El acceso `Map.get()` es O(1) amortizado y el ensamblado vive en una sola tick del event loop sin yields innecesarios.

- **Estructuras Node-naturales:** `Map` para indices por id, `Set` para filtros de pertenencia, `[].filter().sort().slice()` como pipeline funcional. La ausencia de un ORM hace explicita la decision de N+1 vs batch — no hay magia que la oculte.

## 🧱 Servicio

- `app` → API Node.js 20 con rutas legacy y optimized, datos en memoria autocontenidos.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `822`.

## 🔎 Endpoints

```bash
curl http://localhost:822/
curl http://localhost:822/health
curl "http://localhost:822/orders-legacy?days=30&limit=20"
curl "http://localhost:822/orders-optimized?days=30&limit=20"
curl http://localhost:822/diagnostics/summary
curl http://localhost:822/metrics
curl http://localhost:822/metrics-prometheus
curl http://localhost:822/reset-metrics
```

## 🧭 Que observar

- `db_queries_in_request` en `orders-legacy` escala con `limit` y con la densidad relacional (items/order);
- `orders-optimized` se mantiene en 2 queries independientemente del `limit`;
- `delta.p95_ms` en `/diagnostics/summary` muestra la diferencia real;
- `event_loop_lag_ms` se dispara en legacy bajo concurrencia: senal Node-especifica.

## ⚖️ Nota de honestidad

Datos en memoria con I/O simulado por `setTimeout`. El objetivo no es benchmarkear motores de DB — es mostrar el patron de acceso y como, en Node, el costo del N+1 se traduce en bloqueo agregado del event loop ademas de latencia por request.
