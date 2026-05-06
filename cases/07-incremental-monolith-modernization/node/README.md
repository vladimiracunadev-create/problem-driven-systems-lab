# Modernizacion incremental de monolito — Node.js

> Implementacion operativa del caso 07 con paridad al stack PHP. Strangler implementado como `Map<consumer, handler>` mutable en runtime — la primitiva mas directa de Node para mover trafico gradualmente sin redeploy.

## Que resuelve

Compara dos formas de hacer un cambio sobre el sistema:

- `change-legacy`: cambio sobre la "god class" acoplada. Cualquier desalineacion (esquema compartido, conflicto de ramas) explota con stacktrace en multiples modulos.
- `change-strangler`: el mismo cambio aplicado contra el modulo extraido, registrado en una tabla de routing. Avance del consumidor en `+25%`, ACL via closure que filtra contrato.

Escenarios: `billing_change`, `shared_schema`, `parallel_conflict`. Consumers: `web`, `mobile`, `backoffice`.

## Primitiva Node-especifica

`const newModuleHandlers = new Map()`: un Map global donde cada consumer apunta al nuevo handler. Registrar un nuevo consumer al strangler es `registerNewHandler('mobile', handler)` — no requiere reload del proceso, no requiere clases ni jerarquia. La ACL es un closure: filtra el contrato esperado en el lugar donde se llama.

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `827`.

## Endpoints

```bash
curl http://localhost:827/
curl http://localhost:827/health
curl "http://localhost:827/change-legacy?scenario=shared_schema&consumer=web"
curl "http://localhost:827/change-strangler?scenario=shared_schema&consumer=web"
curl "http://localhost:827/flows?limit=10"
curl http://localhost:827/diagnostics/summary
curl http://localhost:827/metrics
curl http://localhost:827/metrics-prometheus
curl http://localhost:827/reset-lab
```

## Que observar

- `blast_radius_score` y `risk_score` son sustancialmente menores en strangler que en legacy;
- `consumers_fully_migrated` sube tras varias llamadas a strangler;
- `shared_schema` y `parallel_conflict` rompen legacy con TypeError / Error nativo, mientras strangler completa con 200.
