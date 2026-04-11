# 🧪 Problem-Driven Systems Lab

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white)](compose.root.yml)
[![PHP](https://img.shields.io/badge/PHP-8-777BB4?logo=php&logoColor=white)](cases/)
[![Node.js](https://img.shields.io/badge/Node.js-LTS-339933?logo=node.js&logoColor=white)](cases/)
[![Python](https://img.shields.io/badge/Python-3-3776AB?logo=python&logoColor=white)](cases/)
[![Java](https://img.shields.io/badge/Java-JVM-ED8B00?logo=openjdk&logoColor=white)](cases/)
[![.NET](https://img.shields.io/badge/.NET-8-512BD4?logo=dotnet&logoColor=white)](cases/)
[![Status](https://img.shields.io/badge/Estado-Activo-blue)](ROADMAP.md)

Portafolio técnico orientado a problemas reales de software: rendimiento, observabilidad, resiliencia, arquitectura y continuidad operacional. Este repositorio forma parte del ecosistema público de Vladimir Acuña y traduce esa narrativa en escenarios ejecutables, documentados y honestos sobre su madurez real.

## 🎯 Executive Summary

- El laboratorio modela **12 problemas reales de ingeniería**, comunicando con transparencia cuáles cuentan con profundidad operativa hoy.
- Los casos `01` al `12` son operativos en PHP y ahora incluyen una **Interfaz Gráfica (UI) nativa y moderna** al abrir sus endpoints desde el navegador, preservando el JSON original para herramientas programáticas.
- El caso `03` tambien es operativo en Node.js y Python.
- Docker es la via oficial de ejecucion limpia y reproducible.
- [`shared/catalog/cases.json`](shared/catalog/cases.json) es la fuente de verdad del portal, de la documentacion generada y de la narrativa operativa.
- El portal raiz ahora sirve como hub de evaluacion: rutas por audiencia, seleccion por lenguaje, proof cards y probes server-side.

## 💻 Interfaz Visual Integrada

El laboratorio no es solo una "API JSON ciega". Los 12 casos en PHP ahora interceptan solicitudes HTTP de navegadores (mediante cabeceras `Accept`) y devuelven **Dashboards Interactivos**. Esto permite a reclutadores, líderes y desarrolladores *ver* cómo se bloquea una base de datos, cómo aumentan las latencias, y probar escenarios en vivo usando estéticas modernas sin afectar el núcleo programático.

## 💡 Que demuestra este producto

| Area | Evidencia concreta |
| --- | --- |
| Diagnostico tecnico | Cada caso parte desde sintomas, causas, trade-offs y solucion esperada |
| Ejecucion reproducible | Cada stack mantiene `Dockerfile` y `compose.yml` propios |
| Operacion realista | Los casos operativos no son demos vacias: usan DB, worker, metricas, logs o trazas segun corresponda |
| Claridad para audiencias mixtas | El portal y la documentacion separan rutas para recruiter, liderazgo tecnico, developer y beginner |
| Honestidad tecnica | Se distingue explicitamente entre `OPERATIVO` y `DOCUMENTADO / SCAFFOLD` |

## 🧭 Como evaluarlo rapido

| Perfil | Punto de entrada | Que deberia poder concluir |
| --- | --- | --- |
| Recruiter / hiring manager | [RECRUITER.md](RECRUITER.md) | El repo deja evidencia real y no solo una narrativa bonita |
| CTO / Head of Engineering | [ARCHITECTURE.md](ARCHITECTURE.md) | Hay criterio sistemico, foco en operacion y reduccion de riesgo |
| Developer / DevOps | [INSTALL.md](INSTALL.md) → [RUNBOOK.md](RUNBOOK.md) | El entorno levanta limpio y los casos operativos cuentan una historia tecnica verificable |
| Beginner | [docs/BEGINNERS_GUIDE.md](docs/BEGINNERS_GUIDE.md) | La estructura y la taxonomia de madurez son comprensibles antes de entrar al codigo |

Si quieres una sola puerta de entrada local con los 12 casos PHP disponibles, levanta `docker compose -f compose.root.yml up -d --build` y abre `http://localhost:8080`.

## 🏷️ Madurez actual

| Nivel | Significado |
| --- | --- |
| `OPERATIVO` | Caso resolviendo el problema de forma real, con Docker y evidencia observable |
| `DOCUMENTADO / SCAFFOLD` | Caso bien modelado, con estructura y docs listas, pero sin la misma profundidad funcional todavia |
| `PLANIFICADO` | Futuro del roadmap, aun no presente en el arbol actual |

Estado actual:

- `OPERATIVO`: casos [01](cases/01-api-latency-under-load/README.md), [02](cases/02-n-plus-one-and-db-bottlenecks/README.md), [03](cases/03-poor-observability-and-useless-logs/README.md), [04](cases/04-timeout-chain-and-retry-storms/README.md), [05](cases/05-memory-pressure-and-resource-leaks/README.md), [06](cases/06-broken-pipeline-and-fragile-delivery/README.md), [07](cases/07-incremental-monolith-modernization/README.md), [08](cases/08-critical-module-extraction-without-breaking-operations/README.md), [09](cases/09-unstable-external-integration/README.md), [10](cases/10-expensive-architecture-for-simple-needs/README.md), [11](cases/11-heavy-reporting-blocks-operations/README.md) y [12](cases/12-single-point-of-knowledge-and-operational-risk/README.md) en PHP.
- `OPERATIVO` adicional fuera de PHP: [caso 03](cases/03-poor-observability-and-useless-logs/README.md) en Node.js y Python.
- `DOCUMENTADO / SCAFFOLD`: stacks todavia no profundizados fuera de las variantes ya operativas y la estructura base de los otros runtimes.

## 🔎 Catálogo de Casos Resolutivos

| Caso | Estado | Que deja como prueba |
| --- | --- | --- |
| [01 - API lenta bajo carga](cases/01-api-latency-under-load/README.md) | `OPERATIVO` | Latencia legacy vs optimized, contencion real sobre DB, worker concurrente y observabilidad con Grafana |
| [02 - N+1 y cuellos de botella DB](cases/02-n-plus-one-and-db-bottlenecks/README.md) | `OPERATIVO` | N+1 reproducible, costo por request medido y lectura consolidada contra la misma base relacional |
| [03 - Observabilidad deficiente](cases/03-poor-observability-and-useless-logs/README.md) | `OPERATIVO` | Diferencia clara entre logs pobres y telemetria util en PHP, Node.js y Python |
| [04 - Timeout chain y retry storms](cases/04-timeout-chain-and-retry-storms/README.md) | `OPERATIVO` | Comparacion entre retries agresivos y resiliencia con circuit breaker y fallback |
| [05 - Presion de memoria y fugas](cases/05-memory-pressure-and-resource-leaks/README.md) | `OPERATIVO` | Degradacion progresiva por estado retenido frente a limpieza y limites de recursos |
| [06 - Pipeline roto y delivery fragil](cases/06-broken-pipeline-and-fragile-delivery/README.md) | `OPERATIVO` | Diferencia entre detectar tarde, bloquear en preflight y hacer rollback seguro |
| [07 - Modernización del Monolito](cases/07-incremental-monolith-modernization/README.md) | `OPERATIVO` | Refactorización incremental mediante patrón strangler fig y progreso validado por consumidor |
| [08 - Extracción Crítica Módulo](cases/08-critical-module-extraction-without-breaking-operations/README.md) | `OPERATIVO` | Extracción big bang vs extract-and-proxy, permitiendo un cutover progresivo gradual |
| [09 - Integración Externa Inestable](cases/09-unstable-external-integration/README.md) | `OPERATIVO` | Aislamiento mediante patrón adapter, validación estricta y caché protectora |
| [10 - Arquitectura Sobre-Dimensionada](cases/10-expensive-architecture-for-simple-needs/README.md) | `OPERATIVO` | Comparación de diseño complejo/costoso vs simplificado, midiendo visibilidad de costo y lead time |
| [11 - Reportes Pesando la Operación](cases/11-heavy-reporting-blocks-operations/README.md) | `OPERATIVO` | Impacto de read vs write locks: mitigado mediante réplicas, isolation routes o materialized views |
| [12 - Single Point of Knowledge](cases/12-single-point-of-knowledge-and-operational-risk/README.md) | `OPERATIVO` | Visualización en vivo de riesgo operacional y dependencias personales vs prácticas con Playbooks y distribución |

El catalogo completo detallado se genera desde metadatos automatizados y vive en [docs/case-catalog.md](docs/case-catalog.md). Cada caso se sirve mediante un robusto servidor en PHP listo para consumir tanto por UI Web como por API.

## 🖥️ Portal y experiencia de producto

La raiz del laboratorio ya no es solo una lista de archivos. El portal local ahora cumple cuatro funciones:

- explica el producto por audiencia;
- deja elegir lenguaje y ver solo casos realmente operativos;
- muestra por que importa cada caso y que evidencia deberia verse;
- ejecuta probes server-side para devolver `status code`, latencia y ultima verificacion real desde el propio portal.

Esto lo vuelve mucho mas claro para reclutadores, lideres y personas que quieren corroborar rapido si el producto esta vivo y por que importa.

## 🚀 Inicio rapido

```bash
# Laboratorio completo en PHP + portal

docker compose -f compose.root.yml up -d --build

# Portal liviano solamente

docker compose -f compose.portal.yml up -d --build

# Casos aislados si quieres revisar uno por uno

docker compose -f cases/01-api-latency-under-load/php/compose.yml up -d --build
docker compose -f cases/02-n-plus-one-and-db-bottlenecks/php/compose.yml up -d --build
docker compose -f cases/03-poor-observability-and-useless-logs/php/compose.yml up -d --build
docker compose -f cases/04-timeout-chain-and-retry-storms/php/compose.yml up -d --build
docker compose -f cases/05-memory-pressure-and-resource-leaks/php/compose.yml up -d --build
docker compose -f cases/06-broken-pipeline-and-fragile-delivery/php/compose.yml up -d --build
docker compose -f cases/07-incremental-monolith-modernization/php/compose.yml up -d --build
docker compose -f cases/08-critical-module-extraction-without-breaking-operations/php/compose.yml up -d --build
docker compose -f cases/09-unstable-external-integration/php/compose.yml up -d --build
docker compose -f cases/10-expensive-architecture-for-simple-needs/php/compose.yml up -d --build
docker compose -f cases/11-heavy-reporting-blocks-operations/php/compose.yml up -d --build
docker compose -f cases/12-single-point-of-knowledge-and-operational-risk/php/compose.yml up -d --build
docker compose -f cases/03-poor-observability-and-useless-logs/node/compose.yml up -d --build
docker compose -f cases/03-poor-observability-and-useless-logs/python/compose.yml up -d --build
```

Tambien existen atajos con `make`, pero la ruta soportada y mas portable sigue siendo `docker compose` directo.

## 📚 Documentacion del repositorio

| Documento | Rol |
| --- | --- |
| [RECRUITER.md](RECRUITER.md) | Ruta ejecutiva para evaluacion rapida |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Vista ejecutiva de la arquitectura actual |
| [INSTALL.md](INSTALL.md) | Instalacion y puesta en marcha recomendada |
| [RUNBOOK.md](RUNBOOK.md) | Operacion diaria y chequeos iniciales |
| [SECURITY.md](SECURITY.md) | Politica de seguridad y reporte responsable |
| [SUPPORT.md](SUPPORT.md) | Como pedir ayuda y que informacion incluir |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Reglas para crecer el laboratorio sin degradarlo |
| [CHANGELOG.md](CHANGELOG.md) | Historial notable de cambios |
| [docs/architecture.md](docs/architecture.md) | Mapa estructural del repositorio |
| [docs/case-catalog.md](docs/case-catalog.md) | Catalogo sincronizado desde metadatos |
| [docs/docker-strategy.md](docs/docker-strategy.md) | Por que Docker es el modelo operativo oficial |
| [docs/recruiter-guide.md](docs/recruiter-guide.md) | Guia extendida para lectores no tecnicos |

## 🏗️ Arquitectura en una frase

El sistema se organiza como una capa editorial en raiz, un portal de evaluacion con entrada completa PHP o modo liviano, una biblioteca de casos problem-driven y stacks aislados por Docker. La arquitectura completa esta documentada en [ARCHITECTURE.md](ARCHITECTURE.md) y [docs/architecture.md](docs/architecture.md).

## 🌐 Ecosistema relacionado

- Web profesional: [vladimiracunadev-create.github.io](https://vladimiracunadev-create.github.io/)
- Perfil GitHub: [github.com/vladimiracunadev-create](https://github.com/vladimiracunadev-create)
- Grupo GitLab: [gitlab.com/vladimir.acuna.dev-group/vladimir.acuna.dev-group](https://gitlab.com/vladimir.acuna.dev-group/vladimir.acuna.dev-group)

## ✅ Lo que este repo si es

- Un laboratorio serio para demostrar criterio tecnico transferible.
- Una base reproducible para conversar de rendimiento, observabilidad y arquitectura.
- Un portfolio documentado que privilegia problemas reales sobre features aisladas.

## 🚫 Lo que este repo no vende

- Paridad funcional completa en todos los stacks desde la primera iteracion.
- Benchmarks absolutos entre lenguajes.
- Paridad profunda de los doce casos en todos los stacks hoy.
- Seniority inflada con claims sin evidencia.

## ⚖️ Licencia

El repositorio se publica bajo [MIT](LICENSE). Revisa tambien [docs/usage-and-scope.md](docs/usage-and-scope.md) para entender sus limites de uso y la postura honesta del proyecto.
