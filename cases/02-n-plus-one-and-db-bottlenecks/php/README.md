# Caso 02 - PHP 8 + PostgreSQL

Esta variante ya no es un placeholder. Implementa un problema real de N+1 y una corrección medible sobre la misma base de datos.

## Qué resuelve
Modela un feed operacional de pedidos recientes que necesita devolver:

- datos del pedido,
- datos del cliente,
- items del pedido,
- producto y categoría de cada item.

La ruta `orders-legacy` hace múltiples round-trips por pedido e incluso por item. La ruta `orders-optimized` consolida lectura base y detalles con consultas agrupadas.

## Servicios
- `app` -> API PHP 8.3 con endpoints legacy y optimized
- `db` -> PostgreSQL 16 con datos semilla y relaciones reales

## Arranque
```bash
docker compose -f compose.yml up -d --build
```

## Endpoints
```bash
curl http://localhost:812/
curl http://localhost:812/health
curl "http://localhost:812/orders-legacy?days=30&limit=20"
curl "http://localhost:812/orders-optimized?days=30&limit=20"
curl http://localhost:812/diagnostics/summary
curl http://localhost:812/metrics
curl http://localhost:812/metrics-prometheus
curl http://localhost:812/reset-metrics
```

## Qué observar
- `db_queries_in_request`
- `db_time_ms_in_request`
- diferencia de latencia entre legacy y optimized
- caída del costo por request cuando se reemplaza N+1 por cargas consolidadas

## Nota de honestidad
No intenta reproducir un ORM específico. Sí reproduce un patrón muy real: listas enriquecidas que parecen inocentes y terminan escalando mal por round-trips repetidos y relaciones cargadas dentro de bucles.
