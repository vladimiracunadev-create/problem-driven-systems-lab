# Caso 12 — Python: Punto unico de conocimiento y riesgo operacional

Implementacion Python del caso **Single point of knowledge and operational risk**.

Logica funcional identica al stack PHP: mismo flujo de respuesta a incidentes con hero dependency (legacy) vs conocimiento distribuido (distributed), mismo mecanismo de compartir conocimiento, mismos scores de preparacion, mismas rutas.

## Equivalencia funcional con PHP

| Aspecto | PHP | Python |
|---|---|---|
| Rutas HTTP | `/incident-legacy`, `/incident-distributed`, `/share-knowledge`, `/knowledge/state`, `/incidents`, `/diagnostics/summary`, `/metrics`, `/metrics-prometheus`, `/reset-lab` | Identicas |
| Modo legacy | Resolucion depende del heroe; sin runbook, sin backup | Identico |
| Modo distributed | Runbook, personas de backup, simulacros de incidente | Identico |
| Readiness score | `runbook*0.45 + (backup_people+1)*18 + drill*0.25` | Identico |
| `/share-knowledge` | Actualiza runbook/backup/drill; NO registra telemetria | Identico |
| Estado persistido | `/tmp/pdsl-case12-python/` | `/tmp/pdsl-case12-python/` |
| Puerto | 822 | 842 |

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `842`.

## Endpoints

```bash
curl http://localhost:842/
curl http://localhost:842/health
curl "http://localhost:842/incident-legacy?severity=high&service=payments"
curl "http://localhost:842/incident-distributed?severity=high&service=payments"
curl -X POST "http://localhost:842/share-knowledge?type=runbook&detail=payments-runbook-v2"
curl http://localhost:842/knowledge/state
curl "http://localhost:842/incidents?limit=10"
curl http://localhost:842/diagnostics/summary
curl http://localhost:842/metrics
curl http://localhost:842/metrics-prometheus
curl http://localhost:842/reset-lab
```

## Severidades disponibles

| Severity | MTTD simulado | Probabilidad de escalada |
|---|---|---|
| `low` | ~5 min | Baja |
| `medium` | ~15 min | Media |
| `high` | ~45 min | Alta sin runbook |
| `critical` | ~120 min | Muy alta sin distribucion de conocimiento |

## Que observar

- Legacy: `hero_available` determina el resultado; si el heroe no esta disponible, `escalation: true`.
- Distributed: `readiness_score` refleja el nivel de preparacion del equipo; score alto = resolucion rapida.
- Ejecuta `POST /share-knowledge?type=runbook` varias veces y observa como `readiness_score` sube en `/knowledge/state`.
- Agrega personas de backup con `type=backup_person` y observa la reduccion de `avg_resolution_minutes`.
- `/diagnostics/summary` compara `avg_mttd_minutes`, `escalation_rate` y `hero_dependency_rate` entre modos.

## Por que `/share-knowledge` no registra telemetria

La accion de compartir conocimiento es una operacion administrativa, no un flujo de negocio. Registrar su latencia en las metricas de request distorsionaria los percentiles de `incident-legacy` e `incident-distributed`. Esta decision es identica a como PHP maneja `/cutover/advance` en el caso 08.
