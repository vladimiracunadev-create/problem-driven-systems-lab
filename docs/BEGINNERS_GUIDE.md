# 🌱 Guia para Principiantes

Si es tu primera vez en este repositorio, esta es la ruta mas simple para entenderlo sin perderte.

## 1️⃣ En 6 pasos

1. Lee [README.md](../README.md) para entender la idea general.
2. Revisa [docs/positioning-and-objective.md](positioning-and-objective.md) para ver que problema profesional resuelve este laboratorio.
3. Mira [docs/case-catalog.md](case-catalog.md) para identificar los casos operativos.
4. Sigue [INSTALL.md](../INSTALL.md) y levanta uno de los casos `01` al `06`.
5. Usa [RUNBOOK.md](../RUNBOOK.md) si algo no levanta como esperas.
6. Recorre el caso que mas te interese y luego vuelve al resto del catalogo.

## 🧠 Terminos clave

| Termino | Significado en este repo |
| --- | --- |
| Problem-driven | El problema manda; el stack se elige para resolverlo |
| Operativo | Caso ya implementado con evidencia real y Docker funcional |
| Documentado / Scaffold | Caso modelado y estructurado, pero aun no resuelto a fondo |
| Compare | Archivo `compose.compare.yml` para comparar stacks del mismo caso |

## 🚪 Mejores puntos de entrada

| Caso | Por que empezar ahi |
| --- | --- |
| [01](../cases/01-api-latency-under-load/README.md) | Tiene rendimiento, base de datos, worker y observabilidad |
| [02](../cases/02-n-plus-one-and-db-bottlenecks/README.md) | Muestra un problema de DB muy comun y facil de reconocer |
| [03](../cases/03-poor-observability-and-useless-logs/README.md) | Muestra rapidamente por que la observabilidad importa |
| [04](../cases/04-timeout-chain-and-retry-storms/README.md) | Sirve para entender retries, circuit breaker y degradacion controlada |
| [06](../cases/06-broken-pipeline-and-fragile-delivery/README.md) | Hace visible por que deploy, preflight y rollback importan |

## 💡 Consejo practico

No intentes levantar todo al mismo tiempo para "ver si funciona". En este laboratorio conviene trabajar un caso por vez. Asi se entiende mejor el problema y se evita ruido innecesario.
