# 🔵 Caso 10 — .NET 8

<!-- nav-stack -->
[⬅️ Caso 10](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🔵 Perfil de .NET](../../../docs/languages/dotnet.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack .NET operativo del caso 10. CPU real medido como N hops de serializacion `JsonSerializer` vs `Dictionary` O(1).

## Primitivas .NET nativas

| Primitiva | Rol |
|---|---|
| `Dictionary<string, long>.TryGetValue` | Acceso O(1) — el "right-sized" del caso. |
| `JsonSerializer.Serialize` + traversal loops | CPU real cobrado por hop de la version compleja (serializacion + parsing). |
| `Stopwatch` / `Environment.TickCount64` | Medicion directa del CPU time por request. |
| `Interlocked.Increment` | Contadores por variante. |

## Contraste

**Complex** — N hops con serializacion costosa por hop:
```csharp
for (int h = 0; h < hops; h++) {
    var blob = JsonSerializer.Serialize(payload);   // alocacion + traversal
    payload = JsonSerializer.Deserialize<Dictionary<string,object>>(blob)!;
    // mas trabajo simulado por hop
}
// hops > 20 → internal_timeout (seasonal_peak)
```

**Right-sized** — Dictionary O(1):
```csharp
long? value = directStore.TryGetValue(key, out var v) ? v : null;
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
docker compose -f compose.dotnet.yml up -d --build
curl "http://127.0.0.1:8500/10/feature-complex?key=feature-1&hops=8"
curl "http://127.0.0.1:8500/10/feature-right-sized?key=feature-1"
curl http://127.0.0.1:8500/10/decisions
```

## Modo aislado

```
docker compose -f cases/10-expensive-architecture-for-simple-needs/dotnet/compose.yml up -d --build
curl http://127.0.0.1:8510/health
```

## Que mide el CPU real

A diferencia de un caso simulado con `Task.Delay()`, aqui el trabajo es CPU real (`JsonSerializer.Serialize`/`Deserialize` con alocacion en LOH a partir de cierto tamano). Bajo carga concurrente, el `complex` consume threads del `ThreadPool` y crea contencion observable; ademas, las alocaciones grandes pasan al LOH y disparan colecciones Gen2. `right_sized` es essentialmente gratis. El lab no inventa el costo — lo demuestra. Mismo principio que el `StringBuilder` loops de Java.
