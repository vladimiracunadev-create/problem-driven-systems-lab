# 🔄 Caso 02 — Python 3.12 + SQLite

> Implementacion operativa del caso 02 para demostrar N+1 anidado y una correccion medible sobre la misma base de datos.

## 🎯 Que resuelve

Modela un feed operacional de pedidos recientes que necesita devolver:

- datos del pedido;
- datos del cliente;
- items del pedido;
- producto y categoria de cada item.

La ruta `orders-legacy` hace multiples round-trips por pedido e incluso por item. La ruta `orders-optimized` consolida lectura base y detalles en consultas agrupadas con `JOIN`.

## 💼 Por que importa

Este caso deja una evidencia muy clara: el problema no es "usar o no usar ORM" en abstracto, sino el patron de acceso a datos. Cuando las relaciones se cargan dentro de bucles, el costo por request crece rapido y desgasta innecesariamente la base, independientemente del lenguaje o el motor.

## 🔬 Analisis Tecnico de la Implementacion (Python)

El patron N+1 en Python sobre SQLite exhibe exactamente el mismo comportamiento que sobre PostgreSQL: cada `cursor.execute()` dentro de un bucle es una barrera de I/O sincronica que acumula tiempo.

- **Punto de Falla (`legacy`):** La funcion `orders_legacy()` carga el listado base con una sola consulta y luego entra en un bucle `for order in orders`. Dentro de ese bucle ejecuta `cursor.execute("SELECT * FROM customers WHERE id = ?", ...)` y despues un segundo bucle anidado `for item in items` donde ejecuta `cursor.execute("SELECT p.*, c.name FROM products p JOIN categories c ... WHERE p.id = ?", ...)` por cada item. El resultado es el patron clasico `1 + N + N*M`: para 20 pedidos con 3 items promedio, el servidor ejecuta mas de 80 consultas secuenciales. SQLite procesa cada `execute()` de forma sincronica bloqueando el hilo hasta terminar, sin posibilidad de pipelining.

- **Correccion Nativa (`optimized`):** La funcion `orders_optimized()` elimina los bucles de base de datos. Extrae todos los IDs de pedidos con `[o["id"] for o in orders]`, construye un placeholder dinamico `",".join("?" * len(order_ids))` y ejecuta un unico `SELECT order_items JOIN products JOIN categories WHERE order_id IN (...)`. El ensamblado final ocurre en Python puro: construye un `dict` con `{oid: [] for oid in order_ids}` e itera los items una sola vez con `grouped[item["order_id"]].append(item)`, complejidad `O(N)` estricta. El numero total de consultas cae a 3 independientemente de cuantos pedidos o items tenga el resultado.

## 🧱 Servicio

- `app` → API Python 3.12 con endpoints legacy y optimized, SQLite embebida con datos semilla deterministicos.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `832`.

## 🔎 Endpoints

```bash
curl http://localhost:832/
curl http://localhost:832/health
curl "http://localhost:832/orders-legacy?days=30&limit=20"
curl "http://localhost:832/orders-optimized?days=30&limit=20"
curl http://localhost:832/diagnostics/summary
curl http://localhost:832/metrics
curl http://localhost:832/metrics-prometheus
curl http://localhost:832/reset-metrics
```

## 🧭 Que observar

- `db_queries` en `orders-legacy` crece como `1 + N + N*M`; con limit=20 y promedio 3 items/pedido, supera 80 consultas por request;
- `db_queries` en `orders-optimized` es constante (3 consultas) independientemente de `limit`;
- `db_time_ms` en legacy supera al optimizado en proporcion directa al numero de pedidos;
- `/diagnostics/summary` muestra densidad relacional del dataset y diferencia de queries entre rutas.

## ⚖️ Nota de honestidad

No intenta reproducir un ORM especifico ni benchmarkear SQLite contra PostgreSQL. Reproduce un patron muy real: listas enriquecidas que parecen inocentes y terminan escalando mal por round-trips repetidos y relaciones cargadas dentro de bucles.
