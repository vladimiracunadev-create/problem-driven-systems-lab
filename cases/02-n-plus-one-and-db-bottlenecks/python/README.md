# Caso 02 — Python: N+1 y cuellos de botella en base de datos

Implementacion Python del caso **N+1 queries y cuellos de botella en base de datos**.

Logica funcional identica al stack PHP: expone las mismas rutas, implementa el mismo patron N+1 anidado (orders → customer + items → product + category por cada item) y la variante optimizada con joins consolidados.

## Equivalencia funcional con PHP

| Aspecto | PHP | Python |
|---|---|---|
| Rutas HTTP | `/orders-legacy`, `/orders-optimized`, `/diagnostics/summary`, `/metrics`, `/metrics-prometheus`, `/reset-metrics` | Identicas |
| Patron N+1 (legacy) | SELECT orders → bucle: SELECT customer + SELECT items → bucle: SELECT product + SELECT category | Identico sobre SQLite |
| Queries legacy | 1 + N + N×M + N×M (donde N=pedidos, M=items por pedido) | Identico |
| Optimizado | SELECT orders JOIN customers, luego SELECT items JOIN products JOIN categories | Identico sobre SQLite |
| Base de datos | PostgreSQL 16 (container externo) | **SQLite embebida** (ver razon abajo) |
| Esquema | customers, products, categories, orders, order_items | Identico |
| Puerto | 812 | 832 |

## Por que SQLite en Python y no PostgreSQL

El patron N+1 es independiente del motor de base de datos. La razon de usar SQLite en Python es la misma que en el caso 01: **portabilidad y autocontencion**.

Un N+1 sobre SQLite produce el mismo patron observable (escalada de queries, tiempo de DB) que sobre PostgreSQL. La diferencia entre motores importa cuando se mide throughput bajo carga concurrente real; para demostrar el patron de acceso, SQLite es suficiente y elimina la dependencia de un contenedor externo.

La variante PHP mantiene PostgreSQL porque es el motor que corresponde a su ecosistema de produccion y el caso viene con un stack completo de observabilidad.

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `832`.

## Endpoints

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

## Que observar

- `db_queries` en `orders-legacy`: crece como `1 + N + N*M + N*M`. Con limit=20 y promedio 3 items/pedido, supera 120 queries por request.
- `db_queries` en `orders-optimized`: constante (3-4 queries independientemente de limit).
- `db_time_ms` en legacy supera al optimizado en proporcion directa al numero de pedidos.
- `/diagnostics/summary` muestra la densidad relacional del dataset y la diferencia de queries entre ambas rutas.

## Datos de prueba

Al arrancar, el servidor siembra automaticamente:

- 500 clientes, 4 categorias, 1000 productos
- ~5000 pedidos con 1-5 items cada uno (distribucion uniforme)
- Los datos son deterministicos (semilla fija) para reproducibilidad entre reinicios
