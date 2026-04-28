# Caso 06 — Python: Pipeline roto y entrega fragil

Implementacion Python del caso **Broken pipeline and fragile delivery**.

Logica funcional identica al stack PHP: mismo flujo de despliegue con 3 entornos (dev/staging/prod), mismos escenarios de fallo, misma diferencia entre deteccion tardia (legacy) y bloqueo temprano (controlled), mismas rutas.

## Equivalencia funcional con PHP

| Aspecto | PHP | Python |
|---|---|---|
| Rutas HTTP | `/deploy-legacy`, `/deploy-controlled`, `/environments`, `/deployments`, `/diagnostics/summary`, `/metrics`, `/metrics-prometheus`, `/reset-lab` | Identicas |
| Modo legacy | Falla despues de `switch_traffic`; rollback manual necesario | Identico |
| Modo controlled | Bloquea en `preflight` o hace auto-rollback antes de afectar trafico | Identico |
| Entornos | dev, staging, prod con `health`, `current_release`, `last_good_release` | Identicos |
| Escenarios | ok, missing_secret, config_drift, failing_smoke, migration_risk | Identicos |
| Estado persistido | `/tmp/pdsl-case06-php/` | `/tmp/pdsl-case06-python/` |
| Puerto | 816 | 836 |

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `836`.

## Endpoints

```bash
curl http://localhost:836/
curl http://localhost:836/health
curl "http://localhost:836/deploy-legacy?scenario=missing_secret&release=v2.1.0&env=prod"
curl "http://localhost:836/deploy-controlled?scenario=missing_secret&release=v2.1.0&env=prod"
curl http://localhost:836/environments
curl "http://localhost:836/deployments?limit=10"
curl http://localhost:836/diagnostics/summary
curl http://localhost:836/metrics
curl http://localhost:836/metrics-prometheus
curl http://localhost:836/reset-lab
```

## Escenarios disponibles

| Scenario | Comportamiento |
|---|---|
| `ok` | Despliegue completo sin fallos. |
| `missing_secret` | Secreto de configuracion ausente. Legacy: falla post-trafico. Controlled: bloqueado en preflight. |
| `config_drift` | Divergencia de configuracion entre entornos. Legacy: falla en smoke test post-trafico. Controlled: detectado en preflight. |
| `failing_smoke` | Smoke test falla tras el cambio de trafico. Legacy: rollback manual. Controlled: auto-rollback. |
| `migration_risk` | Migracion de base de datos con riesgo de incompatibilidad. Legacy: falla en produccion. Controlled: bloqueado en staging. |

## Que observar

- Legacy: `stage_failed` siempre es `switch_traffic` o posterior; el entorno queda en estado degradado.
- Controlled: `stage_failed` es `preflight` o el rollback ocurre antes de afectar trafico real.
- `/environments` muestra el `health` de cada entorno: `healthy`, `degraded`, `rollback`.
- `/diagnostics/summary` compara `avg_stage_of_failure` entre ambos modos y `rollback_rate`.
- `/deployments` historial con `scenario`, `mode`, `success`, `stage_failed`, `elapsed_ms`.
