# Catalogo de casos

> Lista completa de los 12 casos del laboratorio con su estado actual.

## Estado actual

| Caso | Nombre | Estado | Nivel actual |
| --- | --- | --- | --- |
| [01](../cases/01-api-latency-under-load/README.md) | API lenta bajo carga | `OPERATIVO` | PHP + PostgreSQL + worker + Prometheus + Grafana |
| [02](../cases/02-n-plus-one-and-db-bottlenecks/README.md) | N+1 queries y cuellos de botella DB | `OPERATIVO` | PHP + PostgreSQL |
| [03](../cases/03-poor-observability-and-useless-logs/README.md) | Observabilidad deficiente y logs inutiles | `OPERATIVO` | PHP + telemetria util |
| [04](../cases/04-timeout-chain-and-retry-storms/README.md) | Cadena de timeouts y tormentas de reintentos | `DOCUMENTADO / SCAFFOLD` | estructura y docs listas |
| [05](../cases/05-memory-pressure-and-resource-leaks/README.md) | Presion de memoria y fugas de recursos | `DOCUMENTADO / SCAFFOLD` | estructura y docs listas |
| [06](../cases/06-broken-pipeline-and-fragile-delivery/README.md) | Pipeline roto y entrega fragil | `DOCUMENTADO / SCAFFOLD` | estructura y docs listas |
| [07](../cases/07-incremental-monolith-modernization/README.md) | Modernizacion incremental de monolito | `DOCUMENTADO / SCAFFOLD` | estructura y docs listas |
| [08](../cases/08-critical-module-extraction-without-breaking-operations/README.md) | Extraccion de modulo critico sin romper operacion | `DOCUMENTADO / SCAFFOLD` | estructura y docs listas |
| [09](../cases/09-unstable-external-integration/README.md) | Integracion externa inestable | `DOCUMENTADO / SCAFFOLD` | estructura y docs listas |
| [10](../cases/10-expensive-architecture-for-simple-needs/README.md) | Arquitectura cara para un problema simple | `DOCUMENTADO / SCAFFOLD` | estructura y docs listas |
| [11](../cases/11-heavy-reporting-blocks-operations/README.md) | Reportes pesados que bloquean la operacion | `DOCUMENTADO / SCAFFOLD` | estructura y docs listas |
| [12](../cases/12-single-point-of-knowledge-and-operational-risk/README.md) | Punto unico de conocimiento y riesgo operacional | `DOCUMENTADO / SCAFFOLD` | estructura y docs listas |

## Ruta recomendada

Si quieres evaluar el repositorio rapido, empieza por:

1. Caso `01` para rendimiento + observabilidad.
2. Caso `02` para modelado serio de acceso a datos.
3. Caso `03` para diagnostico y telemetria.

## Leyenda

| Estado | Significado |
| --- | --- |
| `OPERATIVO` | Implementacion real con Docker y evidencia observable |
| `DOCUMENTADO / SCAFFOLD` | Caso bien modelado pero aun no profundizado del todo |
| `PLANIFICADO` | Futuro del roadmap, todavia no presente en el arbol actual |
