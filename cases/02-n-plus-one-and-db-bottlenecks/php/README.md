# 🔄 Caso 02 - PHP 8 + PostgreSQL

> Implementacion operativa real del caso 02 para demostrar N+1 y una correccion medible sobre la misma base de datos.

## 🎯 Que resuelve

Modela un feed operacional de pedidos recientes que necesita devolver:

- datos del pedido;
- datos del cliente;
- items del pedido;
- producto y categoria de cada item.

La ruta `orders-legacy` hace multiples round-trips por pedido e incluso por item. La ruta `orders-optimized` consolida lectura base y detalles con consultas agrupadas.

## 💻 Interfaz Visual Nativa

Al abrir la ruta raíz en tu navegador (`Accept: text/html`), este caso inyecta automáticamente un **Dashboard visual interactivo** renderizado en Vanilla JS/CSS. Esto permite observar las métricas y efectos simulados en tiempo real sin perder la capacidad de responder a consultas JSON de CLI o Postman.

## 💼 Por que importa

Este caso deja una evidencia muy clara: el problema no es "usar o no usar ORM" en abstracto, sino el patron de acceso a datos. Cuando las relaciones se cargan dentro de bucles, el costo por request crece rapido y desgasta innecesariamente la base.

## 🔬 Análisis Técnico de la Implementación (PHP)

El problema del **N+1** en PHP no es exclusivo de los ORMs pesados (como Eloquent o Doctrine), y este caso demuestra cómo implementaciones manuales también lo sufren si no se tiene una estrategia racional de consolidación.

*   **Punto de Falla (`legacy`):** La API recupera el arreglo base e instancia un bucle estructural (`foreach ($orders as &$order)`). Al interior, ejecuta operaciones asíncronicas repetitivas invocando `PDOStatement->fetch()` sobre una sentencia preparada `timedQuery(...)`. El motor relacional es forzado a abrir múltiples cursores transaccionales por cada ID individual asfixiando el Socket (PostgreSQL connection pool agotado).
*   **Corrección Nativa (`optimized`):** PHP elimina el iterador DB-bound. Succiona los IDs en matriz usando `array_map(fn($o) => $o['id'], $orders)`. Con eso, inyecta identificadores sobre un constructo expansivo `implode(',', array_fill(0, count($ids), '?'))`. Una única sentencia `(SELECT ... WHERE order_id IN (...))` extrae todos los dependientes por bache. Al retornar los arreglos planos masivos, **el FPM recupera su rol**: usar el procesador interno para agrupar arrays mediante iteraciones asociativas puras `isset($groupedItems[$ik])` (complejidad `O(N)` estricta) y empalmar los nodos en la estructura visual sin cruzar nunca más la frontera I/O del servidor.

## 🧱 Servicios

- `app` -> API PHP 8.3 con endpoints legacy y optimized.
- `db` -> PostgreSQL 16 con datos semilla y relaciones reales.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## Como consumir (dos opciones)

**Hub PHP (recomendado, 8100 en `compose.root.yml`):** este caso queda servido en `http://localhost:8100/02/...` junto a los otros 11 casos.

**Modo aislado (812 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## 🔎 Endpoints

```bash
curl http://localhost:8100/02/
curl http://localhost:8100/02/health
curl "http://localhost:8100/02/orders-legacy?days=30&limit=20"
curl "http://localhost:8100/02/orders-optimized?days=30&limit=20"
curl http://localhost:8100/02/diagnostics/summary
curl http://localhost:8100/02/metrics
curl http://localhost:8100/02/metrics-prometheus
curl http://localhost:8100/02/reset-metrics
```

## 🧭 Que observar

- `db_queries_in_request`;
- `db_time_ms_in_request`;
- diferencia de latencia entre legacy y optimized;
- caida del costo por request cuando se reemplaza N+1 por cargas consolidadas;
- explicacion relacional que entrega `diagnostics/summary`.

## ⚖️ Nota de honestidad

No intenta reproducir un ORM especifico. Si reproduce un patron muy real: listas enriquecidas que parecen inocentes y terminan escalando mal por round-trips repetidos y relaciones cargadas dentro de bucles.