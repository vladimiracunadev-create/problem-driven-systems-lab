# 🐹 Caso 06 — Go 1.23

<!-- nav-stack -->
[⬅️ Caso 06](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐹 Perfil de Go](../../../docs/languages/go.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Go operativo del caso 06. Deploy directo sin red de seguridad vs preflight → smoke → promote | rollback.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `sync.Mutex` protegiendo estructuras explicitas | La seccion critica es la transaccion completa (leer version → decidir → escribir), no cada acceso. |
| structs con tags `json` | `envState` y `deployment` serializan directo, sin construir el JSON a mano. |
| `defer stateMu.Unlock()` | El lock se libera aunque la ruta retorne por cualquiera de sus tres caminos. |

## Contraste

**Legacy** — aplica y deja el ambiente como quede:
```go
environs[env] = envState{Name: env, Version: version, Health: health}  // degraded si el escenario es malo
```

**Controlled** — toda la secuencia bajo un solo lock:
```go
stateMu.Lock()
defer stateMu.Unlock()

before := environs[env]                       // leer estado actual

if scenario == "missing_artifact" { ... }     // preflight: bloquea sin tocar nada
if isBadScenario(scenario) { ... }            // smoke falla → rollback a before.Version
environs[env] = envState{...Health: "healthy"} // promote
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/deploy-legacy?env=prod&version=v1.1.0&scenario=secret_drift` | `deployed_but_broken`, ambiente queda `degraded` |
| `/deploy-controlled?...&scenario=secret_drift` | `rolled_back`, ambiente conserva `v1.0.0` |
| `/deploy-controlled?...&scenario=missing_artifact` | `blocked_in_preflight`, no toca el ambiente |
| `/environments` | version y health por ambiente |
| `/deployments` | historial de las ultimas 30 corridas |
| `/diagnostics/summary` | deploys, bloqueos y rollbacks acumulados |
| `/reset-lab` | restaura ambientes a `v1.0.0` |

## Hub

```
docker compose -f compose.go.yml up -d --build
curl "http://127.0.0.1:8600/06/deploy-controlled?env=prod&version=v2.0.0&scenario=secret_drift"
curl http://127.0.0.1:8600/06/environments
```

## Por que mutex y no `sync.Map`

Go tiene `sync.Map`, el analogo directo del `ConcurrentHashMap` que usa Java aca. No se usa, a proposito.

La seccion critica de este caso no es "leer o escribir una clave": es **leer la version actual, decidir si promover o revertir, y escribir el resultado**. Eso es una transaccion logica. Un mapa concurrente la haria segura por operacion y aun asi incorrecta en conjunto — otro deploy puede colarse entre el read y el write, y el rollback revertiria a una version que ya no era la vigente.

El mutex hace visible que el invariante es la secuencia completa. Es el mismo razonamiento que hace que `ConcurrentHashMap` no sea suficiente en Java para este caso; la diferencia es que en Go la estructura no sugiere lo contrario.
