# 🔵 Caso 12 — .NET 8

<!-- nav-stack -->
[⬅️ Caso 12](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🔵 Perfil de .NET](../../../docs/languages/dotnet.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack .NET operativo del caso 12. Operadores `?.` (null-conditional) + `??` (null-coalescing) como runbook codificado en el sistema de tipos.

## Primitivas .NET nativas

| Primitiva | Rol |
|---|---|
| `?.` null-conditional chain + `??` null-coalescing | Runbook codificado: el compilador con nullable reference types obliga a manejar el caso "owner ausente". Espejo del optional chaining `?.` de Node y del `Optional<T>` Java. |
| `record Owner/Incident` | Inmutables — auditable, copy-on-update via `with`. |
| `Interlocked` sobre `coverage` / `busFactor` | Metricas observables actualizables thread-safe via `/share-knowledge`. |
| `ConcurrentDictionary<string, Owner>` | Registry de owners thread-safe. |

## Contraste

**Legacy** — acceso ciego a estructura anidada:
```csharp
Owner owner = PickOwnerLegacy(scenario);       // null si owner_absent
string script = owner.Runbook[runbookKey];      // NullReferenceException
string executed = script.ToUpperInvariant();    // NRE en cadena
// → catch: mttr 120 min, crashed
```

**Distributed** — chaining defensivo + null-coalescing:
```csharp
Owner? owner = PickOwnerDistributed(scenario);              // null permitido
string? script = owner?.Runbook?.GetValueOrDefault(runbookKey);   // null si falta cualquier eslabon
string fallback = script ?? teamRunbooks[runbookKey];       // degrada al runbook compartido
// degradacion controlada → mttr 35-50 min
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/incident-legacy?scenario=owner_absent&runbook=db_failover` | crashed:NullReferenceException, mttr=120 |
| `/incident-distributed?scenario=owner_absent&runbook=db_failover` | handled via team runbook, mttr=35-50 |
| `/share-knowledge?owner=bob&runbook=db_failover` | coverage sube +15, bus_factor +1 |
| `/incidents` | historial reciente (max 30) |
| `/diagnostics/summary` | contraste + coverage + bus_factor |

## Hub

```
docker compose -f compose.dotnet.yml up -d --build
# Legacy crashea
curl "http://127.0.0.1:8500/12/incident-legacy?scenario=owner_absent&runbook=db_failover"
# Distributed degrada controlado
curl "http://127.0.0.1:8500/12/incident-distributed?scenario=owner_absent&runbook=db_failover"
# Compartir conocimiento sube bus_factor
curl "http://127.0.0.1:8500/12/share-knowledge?owner=bob&runbook=db_failover"
curl http://127.0.0.1:8500/12/diagnostics/summary    # coverage 45, bus_factor 2
```

## Modo aislado

```
docker compose -f cases/12-single-point-of-knowledge-and-operational-risk/dotnet/compose.yml up -d --build
curl http://127.0.0.1:8512/health
```

## Por que `?.` + `??` y no null checks manuales

Es la misma decision que `Optional<T>` en Java, `?.` en Kotlin/Swift/TypeScript, `?` en Rust: **codificar la posibilidad de ausencia en el sistema de tipos**, no en disciplina del developer. Con `Nullable Reference Types` habilitado (`<Nullable>enable</Nullable>` en el csproj), el compilador emite warning si se desreferencia `Owner?` sin chequeo. El `??` obliga a tomar postura ante el caso vacio. El crash del legacy no es una falla de .NET — es una falla de **no usar las herramientas que C# moderno ya ofrece**.
