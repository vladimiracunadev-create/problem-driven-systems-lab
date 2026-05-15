# Caso 07 — Java 21

Stack Java operativo del caso 07. Strangler con routing por consumer + ACL como closure.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `ConcurrentHashMap<String, Function<Request, Response>>` | Tabla de routing mutable en runtime. Registrar nuevo modulo = 1 linea, sin reload del proceso. |
| `Function<Request, Response>` | ACL como closure que filtra contrato — la firma del handler **es** el contrato. |
| `record Request/Response` | Inmutables, audit-friendly, sin boilerplate. |
| `LongAdder` | Contadores lock-free de calls / migrations. |

## Contraste

**Legacy** — cambio toca shared_schema, blast radius alto:
```java
// todos los consumers pegan al mismo monolito
int blastRadius = 4;  // 4 modulos afectados al unisono
int risk = 8;
```

**Strangler** — routing table consulta primero si hay handler nuevo:
```java
Function<Request, Response> handler = routingTable.get(consumer + ":" + op);
if (handler != null) return handler.apply(req);   // routedTo=new-module
// fallback al monolito con ACL acotada al consumer
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/change-legacy?consumer=billing&op=change` | blast_radius=4 — afecta todo el monolito |
| `/change-strangler?consumer=billing&op=change` | routed_to=new-billing-svc — monolito intocado |
| `/flows` | migration_progress por consumer + routing_table_size |
| `/diagnostics/summary` | contraste legacy vs strangler |
| `/reset-lab` | reinicia contadores |

## Hub

```
docker compose -f compose.java.yml up -d --build
curl "http://127.0.0.1:8400/07/change-strangler?consumer=billing&op=change"
```

## Modo aislado

```
docker compose -f cases/07-incremental-monolith-modernization/java/compose.yml up -d --build
curl "http://127.0.0.1:847/change-strangler?consumer=billing&op=change"
```
