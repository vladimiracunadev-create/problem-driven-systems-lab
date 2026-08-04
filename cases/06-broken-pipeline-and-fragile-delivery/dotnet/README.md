# 🔵 Caso 06 — .NET 8

<!-- nav-stack -->
[⬅️ Caso 06](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🔵 Perfil de .NET](../../../docs/languages/dotnet.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack .NET operativo del caso 06. Contraste entre deploy directo (sin preflight, sin rollback) vs pipeline controlado (preflight → smoke → promote | rollback).

## Primitivas .NET nativas

| Primitiva | Rol |
|---|---|
| `record EnvState(string Name, string Version, string Health)` | Snapshot inmutable por ambiente con `with`-expressions para rollback. |
| `record Deployment(DateTime At, string Variant, string Env, string Version, string Scenario, string Result)` | Cada deploy queda como `record` en el historial. |
| `ConcurrentDictionary<string,EnvState>` | Estado de ambientes accesible desde el `ThreadPool` sin lock global. |
| `Interlocked.Increment` | Contadores por variante: `legacy_deploys`, `controlled_rollbacks`, `controlled_blocked`. |

## Contraste

**Legacy** — deploy directo, deja roto si falla:
```csharp
if (IsBadScenario(scenario)) {
    environments[env] = new EnvState(env, version, "degraded");
    Interlocked.Increment(ref legacyBroken);
    return /* "deployed_but_broken" */;
}
```

**Controlled** — state machine con preflight + smoke + rollback:
```csharp
if (scenario is "missing_artifact" or "secret_drift_detected") {
    return /* blocked_in_preflight */;   // no toca el ambiente
}
if (IsBadScenario(scenario)) {
    Interlocked.Increment(ref controlledRollbacks);
    return /* rolled_back_to_<before.Version> */;   // ambiente queda en version previa
}
environments[env] = new EnvState(env, version, "healthy");   // promote
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/deploy-legacy?env=prod&version=v1.1.0&scenario=secret_drift` | deja `prod` degradado |
| `/deploy-controlled?env=prod&version=v1.1.0&scenario=secret_drift` | rollback automatico al version previo |
| `/deploy-controlled?env=prod&version=v1.1.0&scenario=missing_artifact` | bloqueado en preflight, ambiente intocado |
| `/environments` | estado actual por ambiente |
| `/deployments` | historial reciente (max 30) |
| `/diagnostics/summary` | contraste total por variante |
| `/reset-lab` | restaura ambientes a `v1.0.0 healthy` |

## Hub

```
docker compose -f compose.dotnet.yml up -d --build
# legacy deja prod roto
curl "http://127.0.0.1:8500/06/deploy-legacy?env=prod&version=v1.1.0&scenario=secret_drift"
curl http://127.0.0.1:8500/06/environments
# reset + controlled: prod sigue en version previa
curl http://127.0.0.1:8500/06/reset-lab
curl "http://127.0.0.1:8500/06/deploy-controlled?env=prod&version=v1.1.0&scenario=secret_drift"
curl http://127.0.0.1:8500/06/environments
```

## Modo aislado

```
docker compose -f cases/06-broken-pipeline-and-fragile-delivery/dotnet/compose.yml up -d --build
curl http://127.0.0.1:856/health
```

## Por que `record` aqui

Los `record` types (C# 9+) son ideales para deployment events: inmutables, `Equals`/`GetHashCode`/`ToString` auto-generados, y se serializan directo a JSON con `System.Text.Json` sin DTOs adicionales. El historial de `/deployments` es esencialmente un append-only log de `record Deployment`. Las `with`-expressions permiten construir el `EnvState` post-rollback sin mutar el snapshot original.
