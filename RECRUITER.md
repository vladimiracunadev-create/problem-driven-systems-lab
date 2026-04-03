# RECRUITER

> Estado: activo
> Audiencia: reclutadores, hiring managers, lideres tecnicos
> Executive Summary: este repositorio demuestra como se modelan y resuelven problemas reales de software con criterio tecnico, Docker como via oficial y documentacion profesional, sin inflar la madurez real del trabajo.

## Valor de negocio

Este laboratorio no busca impresionar con cantidad de carpetas. Su valor esta en mostrar un patron profesional de trabajo:

- partir desde un problema real y no desde una tecnologia aislada;
- explicar sintomas, diagnostico, trade-offs y solucion;
- levantar entornos reproducibles;
- comunicar claramente que ya esta operativo y que sigue en evolucion.

## Que evidencia entrega hoy

| Area | Evidencia visible |
| --- | --- |
| Rendimiento | Casos `01` y `02` resuelven problemas reales de latencia y acceso a datos en PHP |
| Observabilidad | Caso `03` muestra la diferencia entre logs inutiles y telemetria diagnostica |
| Docker / operacion | Cada caso implementado tiene `compose.yml` propio y una ruta limpia de arranque |
| Documentacion | Existe una familia de documentos por audiencia: instalacion, runbook, seguridad, soporte, contribucion |
| Honestidad tecnica | El repo distingue entre casos `OPERATIVO` y `DOCUMENTADO / SCAFFOLD` |

## Que mirar en 5 minutos

1. Abre [README.md](README.md) para entender la historia general del laboratorio.
2. Revisa [docs/positioning-and-objective.md](docs/positioning-and-objective.md) para ver que problema profesional resuelve este repo.
3. Mira uno de estos casos: [01](cases/01-api-latency-under-load/README.md), [02](cases/02-n-plus-one-and-db-bottlenecks/README.md) o [03](cases/03-poor-observability-and-useless-logs/README.md).
4. Abre [docs/case-catalog.md](docs/case-catalog.md) para ver el estado del resto del laboratorio.
5. Revisa [RUNBOOK.md](RUNBOOK.md) para confirmar que la operacion esta pensada con criterio realista.

## Que senales profesionales deja el repo

| Senal | Donde se ve |
| --- | --- |
| Pensamiento sistemico | El problema manda; el stack acompana |
| Capacidad de explicacion | Cada caso tiene contexto, sintomas, causas y opciones de solucion |
| Criterio operativo | Docker se usa como via oficial, no como decoracion |
| Madurez documental | El repositorio habla distinto segun audiencia sin perder coherencia |
| Honestidad | No promete paridad multi-stack donde todavia no existe |

## Lo que este repositorio si es hoy

- una pieza de portafolio tecnico seria y navegable;
- una base reproducible para demostrar performance, observabilidad y modernizacion;
- una muestra clara de criterio transferible entre stacks.

## Lo que todavia no intenta vender

- doce casos ya profundizados al mismo nivel;
- equivalencia funcional completa entre PHP, Node, Python, Java y .NET;
- un producto SaaS terminado o una plataforma productiva cerrada.

## Contexto dentro del ecosistema publico

Este laboratorio se alinea con una linea publica mas amplia centrada en modernizacion legacy, performance, observabilidad, delivery reproducible y documentacion por audiencia.

- Sitio profesional: [vladimiracunadev-create.github.io](https://vladimiracunadev-create.github.io/)
- Perfil GitHub: [github.com/vladimiracunadev-create](https://github.com/vladimiracunadev-create)
- Grupo GitLab: [gitlab.com/vladimir.acuna.dev-group/vladimir.acuna.dev-group](https://gitlab.com/vladimir.acuna.dev-group/vladimir.acuna.dev-group)

## Documentos recomendados despues

| Documento | Motivo |
| --- | --- |
| [INSTALL.md](INSTALL.md) | Validar que el repositorio se puede ejecutar de forma limpia |
| [docs/docker-strategy.md](docs/docker-strategy.md) | Entender por que Docker esta en el centro del modelo operativo |
| [docs/usage-and-scope.md](docs/usage-and-scope.md) | Ver limites reales y evitar sobreinterpretar la madurez actual |
| [CHANGELOG.md](CHANGELOG.md) | Revisar la evolucion reciente del laboratorio |
