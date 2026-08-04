# 🔵 Caso 07 — .NET 8

<!-- nav-stack -->
[⬅️ Caso 07](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🔵 Perfil de .NET](../../../docs/languages/dotnet.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack .NET operativo del caso 07. Strangler con routing por consumer + ACL como `Func<Request,Response>` registrado en runtime.

## Primitivas .NET nativas

| Primitiva | Rol |
|---|---|
| `ConcurrentDictionary<string, Func<Request,Response>>` | Tabla de routing mutable en runtime. Registrar nuevo modulo = 1 linea, sin reload del proceso. |
| `Func<Request, Response>` delegate | ACL como closure que filtra contrato — la firma del delegate **es** el contrato. |
| `record Request/Response` | Inmutables, audit-friendly, sin boilerplate. |
| `Interlocked.Increment` | Contadores lock-free de calls / migrations. |

## Contraste

**Legacy** — cambio toca shared_schema, blast radius alto:
```csharp
// todos los consumers pegan al mismo monolito
int blastRadius = 4;   // 4 modulos afectados al unisono
int risk = 8;
```

**Strangler** — routing table consulta primero si hay handler nuevo:
```csharp
if (routingTable.TryGetValue($"{consumer}:{op}", out var handler))
    return handler(req);   // routedTo=new-module
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
docker compose -f compose.dotnet.yml up -d --build
curl "http://127.0.0.1:8500/07/change-strangler?consumer=billing&op=change"
```

## Modo aislado

```
docker compose -f cases/07-incremental-monolith-modernization/dotnet/compose.yml up -d --build
curl "http://127.0.0.1:857/change-strangler?consumer=billing&op=change"
```

## Por que `Func<T,R>` y no `interface IHandler`

Una interfaz requiere clase + ctor + registro. Un `Func<Request,Response>` es la firma minima: el delegate **es** el contrato. Registrar un modulo nuevo en `ConcurrentDictionary<string, Func<Request,Response>>` es una linea — equivalente exacto del `ConcurrentHashMap<String, Function<Request,Response>>` de Java. Si el modulo crece a tener estado, basta capturar variables en el closure o pasar a una clase implementando `IHandler` — pero hasta entonces, la firma minima gana.
