# Caso 02 — Java 21

Stack Java operativo del caso 02. Patron N+1 reproducido en memoria, contraste con batch `IN(...)` simulado.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `HashMap<Integer, List<Item>>` | `itemsByOrderId` precomputado actua como tabla relacional indexada. |
| `record` types | `Order` e `Item` inmutables. |
| `LongAdder` | Contadores por ruta lock-free. |

## Contraste

**Legacy** — N+1 dentro del bucle:
```java
for (int i = 0; i < take; i++) {
    Order o = orders.get(i);
    List<Item> items = lookupItemsOneByOne(o.id);  // 1 query por order
    sleepMicros(900);                              // costo de roundtrip
}
```

**Optimized** — batch `IN(...)` + ensamblado O(1):
```java
List<Integer> ids = collectIds(orders, take);
Map<Integer, List<Item>> batch = new HashMap<>();
for (Integer id : ids) batch.put(id, itemsByOrderId.getOrDefault(id, List.of()));
sleepMicros(700);  // un solo roundtrip
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/orders-legacy?limit=20` | 1 query orders + N queries items |
| `/orders-optimized?limit=20` | 1 query orders + 1 batch IN |
| `/diagnostics/summary` | totales + contraste avg/p95/p99 |
| `/metrics` | avg/p95/p99 por ruta |
| `/reset-lab` | reinicia contadores |

## Hub

```
docker compose -f compose.java.yml up -d --build
curl "http://127.0.0.1:8400/02/orders-optimized?limit=10"
```

## Diferencia con PHP/Python/Node

PHP usa PDO + PostgreSQL real. Python usa sqlite3 + DB_LOCK. Node usa `Map`+`Set` en memoria. La version Java se queda en memoria (como Node) y enfoca el contraste en el patron de carga, no en JDBC vs ORM. Mismo problema, idioma distinto.
