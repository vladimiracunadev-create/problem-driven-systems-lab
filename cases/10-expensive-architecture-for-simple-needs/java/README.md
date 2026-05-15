# Caso 10 — Java 21

Stack Java operativo del caso 10. CPU real medido como N hops de serializacion vs HashMap O(1).

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `HashMap.get(key)` | Acceso O(1) — el "right-sized" del caso. |
| `StringBuilder` loops | CPU real cobrado por hop de la version compleja (serializacion + traversal). |
| `System.nanoTime()` | Medicion directa del CPU time por request. |
| `LongAdder` | Contadores por variante. |

## Contraste

**Complex** — N hops con serializacion costosa por hop:
```java
for (int h = 0; h < hops; h++) {
    StringBuilder hop = new StringBuilder(2048);
    for (int i = 0; i < 200; i++) hop.append((char) ('A' + (i % 26)));
    payload.append(hop);
}
// hops > 20 → internal_timeout (seasonal_peak)
```

**Right-sized** — HashMap O(1):
```java
Long value = directStore.get(key);   // O(1), 0 hops
return /* 1 service touched */;
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/feature-complex?key=feature-1&hops=8` | elapsed_ms alto, cost_usd_month_est = hops * 25, lead_time = hops * 2 |
| `/feature-complex?key=feature-1&hops=25` | internal_timeout — sobrearquitectura bajo seasonal_peak |
| `/feature-right-sized?key=feature-1` | elapsed_ms minimo, cost_usd_month_est = 3, lead_time = 1 |
| `/decisions` | ADRs del lab (justificacion de no sobreingenierar) |
| `/diagnostics/summary` | contraste de calls, timeouts, decisiones |

## Hub

```
docker compose -f compose.java.yml up -d --build
curl "http://127.0.0.1:8400/10/feature-complex?key=feature-1&hops=8"
curl "http://127.0.0.1:8400/10/feature-right-sized?key=feature-1"
curl http://127.0.0.1:8400/10/decisions
```

## Modo aislado

Puerto `8410`.

## Que mide el CPU real

A diferencia de un caso simulado con `Thread.sleep()`, aqui el trabajo es CPU real (`StringBuilder` loops). Bajo carga concurrente, el `complex` consume threads del pool y crea contencion observable, mientras `right_sized` es essentialmente gratis. El lab no inventa el costo — lo demuestra.
