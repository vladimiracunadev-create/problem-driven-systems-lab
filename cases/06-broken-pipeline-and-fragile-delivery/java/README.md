# Caso 06 — Java 21

Stack Java operativo del caso 06. Contraste entre deploy directo (sin preflight, sin rollback) vs pipeline controlado (preflight → smoke → promote | rollback).

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `record EnvState(name, version, health)` | Snapshot inmutable por ambiente. |
| `record Deployment(at, variant, env, version, scenario, result)` | Cada deploy queda como `record` en el historial. |
| `ConcurrentHashMap<String,EnvState>` | Estado de ambientes accesible desde el pool sin lock global. |
| `LongAdder` | Contadores por variante: `legacy_deploys`, `controlled_rollbacks`, `controlled_blocked`. |

## Contraste

**Legacy** — deploy directo, deja roto si falla:
```java
if (isBadScenario(scenario)) {
    environments.put(env, new EnvState(env, version, "degraded"));
    legacyBroken.increment();
    return /* "deployed_but_broken" */;
}
```

**Controlled** — state machine con preflight + smoke + rollback:
```java
if (scenario.equals("missing_artifact") || scenario.equals("secret_drift_detected")) {
    return /* blocked_in_preflight */;  // no toca el ambiente
}
if (isBadScenario(scenario)) {
    controlledRollbacks.increment();
    return /* rolled_back_to_<before.version> */;  // ambiente queda en version previa
}
environments.put(env, new EnvState(env, version, "healthy"));  // promote
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
docker compose -f compose.java.yml up -d --build
# legacy deja prod roto
curl "http://127.0.0.1:8400/06/deploy-legacy?env=prod&version=v1.1.0&scenario=secret_drift"
curl http://127.0.0.1:8400/06/environments
# reset + controlled: prod sigue en version previa
curl http://127.0.0.1:8400/06/reset-lab
curl "http://127.0.0.1:8400/06/deploy-controlled?env=prod&version=v1.1.0&scenario=secret_drift"
curl http://127.0.0.1:8400/06/environments
```

## Por que `record` aqui

Los `record` types (JDK 14+ estable) son ideales para deployment events: inmutables, `equals`/`hashCode`/`toString` auto-generados, y se serializan directo a JSON sin mappers. El historial de `/deployments` es esencialmente un append-only log de `record Deployment`.
