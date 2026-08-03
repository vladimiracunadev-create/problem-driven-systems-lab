# Caso 06 — Rust 1.83

Stack Rust operativo del caso 06. Deploy directo sin red de seguridad vs preflight → smoke → promote | rollback.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `enum DeployOutcome` con datos asociados | Los resultados del pipeline son un **tipo**, no strings sueltos. |
| `match` exhaustivo | El compilador exige contemplar todas las variantes. |
| `Mutex<Option<State>>` | Estado de ambientes e historial; la seccion critica es la transaccion completa. |

## Contraste

**Legacy** — aplica y deja el ambiente como quede:
```rust
e.version = version.to_string();
e.health  = health.to_string();   // "degraded" si el escenario es malo
```

**Controlled** — el resultado es un valor del enum, no un string:
```rust
let outcome = if scenario == "missing_artifact" || scenario == "secret_drift_detected" {
    DeployOutcome::BlockedInPreflight { current_version: before.clone() }
} else if is_bad_scenario(scenario) {
    DeployOutcome::RolledBack { to_version: before.clone() }
} else {
    // ...promote...
    DeployOutcome::Promoted
};
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/deploy-legacy?env=prod&version=v1.1.0&scenario=secret_drift` | `deployed_but_broken`, ambiente queda `degraded` |
| `/deploy-controlled?...&scenario=secret_drift` | `rolled_back`, ambiente conserva la version previa |
| `/deploy-controlled?...&scenario=missing_artifact` | `blocked_in_preflight`, no toca el ambiente |
| `/deploy-controlled?...&scenario=clean` | `promoted`, health `healthy` |
| `/environments` | version y health por ambiente |
| `/deployments` | historial de las ultimas 30 corridas |
| `/diagnostics/summary` | deploys, bloqueos y rollbacks acumulados |
| `/reset-lab` | restaura ambientes a `v1.0.0` |

## Hub

```
docker compose -f compose.rust.yml up -d --build
curl "http://127.0.0.1:8700/06/deploy-controlled?env=prod&version=v2.0.0&scenario=secret_drift"
curl http://127.0.0.1:8700/06/environments
```

## Estados como tipo, no como string

En Java, .NET, Go y Node el resultado de este pipeline es un string (`"rolled_back"`, `"promoted"`). Agregar un estado nuevo —digamos `canary`— no rompe nada: cae al `else` de algun `if` y se comporta como si fuera otra cosa.

Aca los resultados son variantes de un `enum`, y el `match` que construye la respuesta es exhaustivo. Si mañana alguien agrega `DeployOutcome::Canary`, **todos los `match` que no la contemplen dejan de compilar**. El compilador enumera los sitios que hay que revisar.

Para una maquina de estados de deploy esa diferencia no es estetica: el estado no contemplado es precisamente el que deja produccion a medio camino, con la version nueva a medias y sin nadie que lo note hasta el reporte del lunes.
