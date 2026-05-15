# 👔 RECRUITER

> Estado: activo
> Audiencia: reclutadores, hiring managers, lideres tecnicos
> Executive Summary: este repositorio demuestra como se modelan y resuelven problemas reales de software con criterio tecnico, Docker como via oficial y documentacion profesional, sin inflar la madurez real del trabajo.

## 💼 Valor de negocio

Este laboratorio no busca impresionar con cantidad de carpetas. Su valor esta en mostrar un patron profesional de trabajo:

- partir desde un problema real y no desde una tecnologia aislada;
- explicar sintomas, diagnostico, trade-offs y solucion;
- levantar entornos reproducibles;
- comunicar claramente que ya esta operativo y que sigue en evolucion.

## 📡 Que evidencia entrega hoy

| Area | Evidencia visible |
| --- | --- |
| Rendimiento Real | Casos `01`, `02` y `05` resuelven latencia, N+1 y saturación física de memoria (OOM/Drift) en PHP, Python, Node.js y Java |
| Observabilidad Experta | Caso `03` implementa trazabilidad nativa, logs estructurados y jerarquías de excepciones en los cuatro stacks (Java usa `ThreadLocal<RequestContext>` para correlation) |
| Resiliencia Determinista | Casos `04`, `06` y `09` demuestran resiliencia real ante timeouts físicos y errores de compilador; `04` con `AbortController` cooperativo en Node y `CompletableFuture.orTimeout` + circuit breaker en Java |
| Arquitectura y Fallos de I/O | Casos `07` a `12` cubren bloqueos de escritura (`flock`), deuda de conocimiento y modernización física |
| Paridad multi-stack honesta | Los **12 casos operativos en los 4 stacks**: PHP, Python, Node.js, Java 21. Cada lenguaje con primitivas nativas distintas por caso (`AbortController` Node, `CompletableFuture.orTimeout` Java, `tracemalloc` Python, `flock` PHP, etc.). 48 endpoints operativos. |
| Interfaz Nativa (Dashboards) | Los 12 casos PHP exponen una **UI Web Interactiva** para visualizar el fallo en vivo desde cualquier navegador |
| Docker / Infraestructura | Cada caso implementa `compose.yml` propio para entornos de ingeniería aislados; ademas **4 hubs consolidados** (`compose.root.yml` PHP `:8100`, `compose.python.yml` `:8200`, `compose.nodejs.yml` `:8300`, `compose.java.yml` `:8400`) levantan los stacks completos con un comando cada uno |
| Documentación Pro | Análisis técnicos profundos con funciones de lenguaje, algoritmos y patrones de diseño; `comparison.md` multi-stack PHP · Python · Node.js · Java para los 12 casos |
| Honestidad Técnica | Distinción explícita de madurez: de simuladores teóricos a piezas de ingeniería verificables |

## ⚡ Que mirar en 5 minutos

1. Abre [README.md](README.md) para entender la historia general del laboratorio.
2. Revisa [docs/positioning-and-objective.md](docs/positioning-and-objective.md) para ver que problema profesional resuelve este repo.
3. Mira uno de estos casos: [01](cases/01-api-latency-under-load/README.md) ([👉 Senior Analysis](cases/01-api-latency-under-load/php/README.md)), [04](cases/04-timeout-chain-and-retry-storms/README.md) ([👉 Senior Analysis](cases/04-timeout-chain-and-retry-storms/php/README.md)), [05](cases/05-memory-pressure-and-resource-leaks/README.md) ([👉 Senior Analysis](cases/05-memory-pressure-and-resource-leaks/php/README.md)) o [06](cases/06-broken-pipeline-and-fragile-delivery/README.md) ([👉 Senior Analysis](cases/06-broken-pipeline-and-fragile-delivery/php/README.md)).
4. Abre [docs/case-catalog.md](docs/case-catalog.md) para ver el estado del resto del laboratorio.
5. Revisa [RUNBOOK.md](RUNBOOK.md) para confirmar que la operacion esta pensada con criterio realista.

## ✅ Que senales profesionales deja el repo

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

- paridad profunda de los doce casos en todos los stacks;
- equivalencia funcional completa entre PHP, Node, Python, Java y .NET;
- un producto SaaS terminado o una plataforma productiva cerrada.

## 🌐 Contexto dentro del ecosistema publico

Este laboratorio se alinea con una linea publica mas amplia centrada en modernizacion legacy, performance, observabilidad, delivery reproducible y documentacion por audiencia.

- Sitio profesional: [vladimiracunadev-create.github.io](https://vladimiracunadev-create.github.io/)
- Perfil GitHub: [github.com/vladimiracunadev-create](https://github.com/vladimiracunadev-create)
- Grupo GitLab: [gitlab.com/vladimir.acuna.dev-group/vladimir.acuna.dev-group](https://gitlab.com/vladimir.acuna.dev-group/vladimir.acuna.dev-group)

## 📚 Documentos recomendados despues

| Documento | Motivo |
| --- | --- |
| [INSTALL.md](INSTALL.md) | Validar que el repositorio se puede ejecutar de forma limpia |
| [docs/docker-strategy.md](docs/docker-strategy.md) | Entender por que Docker esta en el centro del modelo operativo |
| [docs/usage-and-scope.md](docs/usage-and-scope.md) | Ver limites reales y evitar sobreinterpretar la madurez actual |
| [CHANGELOG.md](CHANGELOG.md) | Revisar la evolucion reciente del laboratorio |
