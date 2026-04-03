# 🗂️ Catalogo de casos

> Lista completa de los 12 casos del laboratorio generada desde `shared/catalog/cases.json`.

## 📊 Estado actual

| Icono | Caso | Categoria | Estado | Stacks operativos | Nivel actual |
| --- | --- | --- | --- | --- | --- |
| ⚡ | [01 - API lenta bajo carga](../cases/01-api-latency-under-load/README.md) | Rendimiento | `OPERATIVO` | `php` | PHP + PostgreSQL + worker + Prometheus + Grafana |
| 🔄 | [02 - N+1 queries y cuellos de botella en base de datos](../cases/02-n-plus-one-and-db-bottlenecks/README.md) | Rendimiento | `OPERATIVO` | `php` | PHP + PostgreSQL |
| 🔭 | [03 - Observabilidad deficiente y logs inutiles](../cases/03-poor-observability-and-useless-logs/README.md) | Observabilidad | `OPERATIVO` | `php`, `node`, `python` | PHP + Node.js + Python con logs estructurados, trazas y metricas |
| ⏱️ | [04 - Cadena de timeouts y tormentas de reintentos](../cases/04-timeout-chain-and-retry-storms/README.md) | Resiliencia | `DOCUMENTADO / SCAFFOLD` | — | estructura y docs listas |
| 🧠 | [05 - Presion de memoria y fugas de recursos](../cases/05-memory-pressure-and-resource-leaks/README.md) | Rendimiento | `DOCUMENTADO / SCAFFOLD` | — | estructura y docs listas |
| 🚚 | [06 - Pipeline roto y entrega fragil](../cases/06-broken-pipeline-and-fragile-delivery/README.md) | Entrega | `DOCUMENTADO / SCAFFOLD` | — | estructura y docs listas |
| 🏗️ | [07 - Modernizacion incremental de monolito](../cases/07-incremental-monolith-modernization/README.md) | Arquitectura | `DOCUMENTADO / SCAFFOLD` | — | estructura y docs listas |
| 🧩 | [08 - Extraccion de modulo critico sin romper operacion](../cases/08-critical-module-extraction-without-breaking-operations/README.md) | Arquitectura | `DOCUMENTADO / SCAFFOLD` | — | estructura y docs listas |
| 🌐 | [09 - Integracion externa inestable](../cases/09-unstable-external-integration/README.md) | Resiliencia | `DOCUMENTADO / SCAFFOLD` | — | estructura y docs listas |
| 💸 | [10 - Arquitectura cara para un problema simple](../cases/10-expensive-architecture-for-simple-needs/README.md) | Arquitectura | `DOCUMENTADO / SCAFFOLD` | — | estructura y docs listas |
| 📊 | [11 - Reportes pesados que bloquean la operacion](../cases/11-heavy-reporting-blocks-operations/README.md) | Operaciones | `DOCUMENTADO / SCAFFOLD` | — | estructura y docs listas |
| 👤 | [12 - Punto unico de conocimiento y riesgo operacional](../cases/12-single-point-of-knowledge-and-operational-risk/README.md) | Operaciones | `DOCUMENTADO / SCAFFOLD` | — | estructura y docs listas |

## 🧭 Ruta recomendada

Si quieres evaluar el repositorio rapido, empieza por:

1. Caso `01` para rendimiento + observabilidad.
2. Caso `02` para modelado serio de acceso a datos.
3. Caso `03` para diagnostico, logs estructurados y trazabilidad.

## 🏷️ Leyenda

| Estado | Significado |
| --- | --- |
| `OPERATIVO` | Implementacion real con Docker y evidencia observable |
| `DOCUMENTADO / SCAFFOLD` | Caso bien modelado pero aun no profundizado del todo |
| `PLANIFICADO` | Futuro del roadmap, todavia no presente en el arbol actual |
