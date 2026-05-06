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

- El laboratorio modela **12 problemas reales de ingeniería**, utilizando fallos de alta fidelidad inyectados (I/O, Memoria, Excepciones) en lugar de simulaciones abstractas.
- Los casos `01` al `12` son piezas de ingeniería operativa en PHP que incluyen una **Interfaz Gráfica (UI) nativa y moderna** para diagnósticos visuales.
- Implementa patrones profesionales (**Adapter, Strangler, Circuit Breaker**) resolviendo cuellos de botella reales en el runtime de PHP.
- Docker es la vía oficial de ejecución limpia y reproducible.
- [`shared/catalog/cases.json`](shared/catalog/cases.json) es la fuente de verdad del portal, de la documentacion generada y de la narrativa operativa.
- El portal raiz ahora sirve como hub de evaluacion: rutas por audiencia, seleccion por lenguaje, proof cards y probes server-side.
- ☁️ Plan de despliegue en la nube documentado en [AWS_MIGRATION.md](AWS_MIGRATION.md): tres rutas (ECS Fargate, Lambda, EKS), costos reales estimados y paso a paso.

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

- `OPERATIVO` en PHP: todos los casos [01](cases/01-api-latency-under-load/README.md) al [12](cases/12-single-point-of-knowledge-and-operational-risk/README.md), con UI nativa, Prometheus, Grafana y fallos de alta fidelidad.
- `OPERATIVO` en Python: los 12 casos, con logica funcional equivalente a PHP, stdlib pura y autocontenidos en un solo contenedor.
- `OPERATIVO` en Node.js: casos [01](cases/01-api-latency-under-load/README.md), [02](cases/02-n-plus-one-and-db-bottlenecks/README.md), [03](cases/03-poor-observability-and-useless-logs/README.md), [04](cases/04-timeout-chain-and-retry-storms/README.md) y [05](cases/05-memory-pressure-and-resource-leaks/README.md), con primitivas Node-especificas (event loop lag, AbortController, `process.memoryUsage()`).
- `DOCUMENTADO / SCAFFOLD`: stacks adicionales (Java, .NET, Node.js casos 06-12) con estructura base y documentacion lista.

## 🔎 Catálogo de Casos Resolutivos

| Caso | Comparativa multi-stack | Análisis Técnico (PHP) | Análisis Técnico (Python) | Análisis Técnico (Node.js) | Resumen del Problema | Que deja como prueba |
| --- | --- | --- | --- | --- | --- | --- |
| [01 - API lenta bajo carga](cases/01-api-latency-under-load/README.md) | [⚖️ Comparativa](cases/01-api-latency-under-load/comparison.md) | [👉 PHP](cases/01-api-latency-under-load/php/README.md) | [🐍 Python](cases/01-api-latency-under-load/python/README.md) | [🟢 Node.js](cases/01-api-latency-under-load/node/README.md) | `OPERATIVO` | Latencia legacy vs optimized, contencion real sobre DB, metricas Grafana, event loop lag en Node |
| [02 - N+1 y cuellos de botella DB](cases/02-n-plus-one-and-db-bottlenecks/README.md) | [⚖️ Comparativa](cases/02-n-plus-one-and-db-bottlenecks/comparison.md) | [👉 PHP](cases/02-n-plus-one-and-db-bottlenecks/php/README.md) | [🐍 Python](cases/02-n-plus-one-and-db-bottlenecks/python/README.md) | [🟢 Node.js](cases/02-n-plus-one-and-db-bottlenecks/node/README.md) | `OPERATIVO` | N+1 reproducible, costo por request medido y lectura consolidada |
| [03 - Observabilidad deficiente](cases/03-poor-observability-and-useless-logs/README.md) | [⚖️ Comparativa](cases/03-poor-observability-and-useless-logs/comparison.md) | [👉 PHP](cases/03-poor-observability-and-useless-logs/php/README.md) | [🐍 Python](cases/03-poor-observability-and-useless-logs/python/README.md) | [🟢 Node.js](cases/03-poor-observability-and-useless-logs/node/README.md) | `OPERATIVO` | Diferencia clara entre logs pobres y telemetría útil |
| [04 - Timeout chain y retry storms](cases/04-timeout-chain-and-retry-storms/README.md) | [⚖️ Comparativa](cases/04-timeout-chain-and-retry-storms/comparison.md) | [👉 PHP](cases/04-timeout-chain-and-retry-storms/php/README.md) | [🐍 Python](cases/04-timeout-chain-and-retry-storms/python/README.md) | [🟢 Node.js](cases/04-timeout-chain-and-retry-storms/node/README.md) | `OPERATIVO` | Retries agresivos vs CB+fallback; AbortController como timeout primitivo en Node |
| [05 - Presion de memoria y fugas](cases/05-memory-pressure-and-resource-leaks/README.md) | [⚖️ Comparativa](cases/05-memory-pressure-and-resource-leaks/comparison.md) | [👉 PHP](cases/05-memory-pressure-and-resource-leaks/php/README.md) | [🐍 Python](cases/05-memory-pressure-and-resource-leaks/python/README.md) | [🟢 Node.js](cases/05-memory-pressure-and-resource-leaks/node/README.md) | `OPERATIVO` | Degradación progresiva por estado retenido; heap V8 + RSS reales en Node |
| [06 - Pipeline roto y delivery fragil](cases/06-broken-pipeline-and-fragile-delivery/README.md) | [⚖️ Comparativa](cases/06-broken-pipeline-and-fragile-delivery/comparison.md) | [👉 PHP](cases/06-broken-pipeline-and-fragile-delivery/php/README.md) | [🐍 Python](cases/06-broken-pipeline-and-fragile-delivery/python/README.md) | scaffold | `OPERATIVO` | Diferencia entre detectar tarde y bloquear en preflight/rollback |
| [07 - Modernización del Monolito](cases/07-incremental-monolith-modernization/README.md) | [⚖️ Comparativa](cases/07-incremental-monolith-modernization/comparison.md) | [👉 PHP](cases/07-incremental-monolith-modernization/php/README.md) | [🐍 Python](cases/07-incremental-monolith-modernization/python/README.md) | scaffold | `OPERATIVO` | Refactorización incremental mediante patrón strangler fig |
| [08 - Extracción Crítica Módulo](cases/08-critical-module-extraction-without-breaking-operations/README.md) | [⚖️ Comparativa](cases/08-critical-module-extraction-without-breaking-operations/comparison.md) | [👉 PHP](cases/08-critical-module-extraction-without-breaking-operations/php/README.md) | [🐍 Python](cases/08-critical-module-extraction-without-breaking-operations/python/README.md) | scaffold | `OPERATIVO` | Extracción big bang vs extract-and-proxy con cutover gradual |
| [09 - Integración Externa Inestable](cases/09-unstable-external-integration/README.md) | [⚖️ Comparativa](cases/09-unstable-external-integration/comparison.md) | [👉 PHP](cases/09-unstable-external-integration/php/README.md) | [🐍 Python](cases/09-unstable-external-integration/python/README.md) | scaffold | `OPERATIVO` | Aislamiento mediante patrón adapter y validación estricta |
| [10 - Arquitectura Sobre-Dimensionada](cases/10-expensive-architecture-for-simple-needs/README.md) | [⚖️ Comparativa](cases/10-expensive-architecture-for-simple-needs/comparison.md) | [👉 PHP](cases/10-expensive-architecture-for-simple-needs/php/README.md) | [🐍 Python](cases/10-expensive-architecture-for-simple-needs/python/README.md) | scaffold | `OPERATIVO` | Comparación de diseño complejo vs simplificado con costo visible |
| [11 - Reportes Pesando la Operación](cases/11-heavy-reporting-blocks-operations/README.md) | [⚖️ Comparativa](cases/11-heavy-reporting-blocks-operations/comparison.md) | [👉 PHP](cases/11-heavy-reporting-blocks-operations/php/README.md) | [🐍 Python](cases/11-heavy-reporting-blocks-operations/python/README.md) | scaffold | `OPERATIVO` | Impacto de read/write locks bloqueantes mitigado por aislamiento |
| [12 - Single Point of Knowledge](cases/12-single-point-of-knowledge-and-operational-risk/README.md) | [⚖️ Comparativa](cases/12-single-point-of-knowledge-and-operational-risk/comparison.md) | [👉 PHP](cases/12-single-point-of-knowledge-and-operational-risk/php/README.md) | [🐍 Python](cases/12-single-point-of-knowledge-and-operational-risk/python/README.md) | scaffold | `OPERATIVO` | Riesgo operacional y bus factor mitigado por Playbooks integrados |

El catalogo completo detallado se genera desde metadatos automatizados y vive en [docs/case-catalog.md](docs/case-catalog.md). Cada caso se sirve mediante un robusto servidor en PHP listo para consumir tanto por UI Web como por API.

## 🖥️ Portal y experiencia de producto

La raiz del laboratorio ya no es solo una lista de archivos. El portal local ahora cumple cuatro funciones:

- explica el producto por audiencia;
- deja elegir lenguaje y ver solo casos realmente operativos;
- muestra por que importa cada caso y que evidencia deberia verse;
- ejecuta probes server-side para devolver `status code`, latencia y ultima verificacion real desde el propio portal.

Esto lo vuelve mucho mas claro para reclutadores, lideres y personas que quieren corroborar rapido si el producto esta vivo y por que importa.

## 🚀 Inicio rapido

### Convención de stacks por lenguaje

Cada lenguaje tiene su propio compose en la raíz del repositorio. Un comando levanta los 12 casos de ese lenguaje. Los stacks son independientes y pueden correr en paralelo sin colisión de puertos.

| Archivo | Lenguaje | Puertos expuestos | Estado |
| --- | --- | --- | --- |
| [`compose.root.yml`](compose.root.yml) | PHP 8.3 | `8080` portal · `8100` PHP hub · `9091` Prometheus · `3001` Grafana | `OPERATIVO` |
| [`compose.python.yml`](compose.python.yml) | Python 3.12 | `8200` Python hub | `OPERATIVO` |
| `compose.nodejs.yml` | Node.js | `8300` Node hub | `PLANIFICADO` (caso 03 ya operativo de forma aislada en `cases/03-.../node/compose.yml`, puerto `823`) |
| `compose.java.yml` | Java / JVM | `8400` Java hub | `PLANIFICADO` |
| `compose.dotnet.yml` | .NET 8 | `8500` .NET hub | `PLANIFICADO` |

Un puerto por lenguaje para los casos. Los servicios de soporte (DB, Prometheus, Grafana) tienen los suyos propios porque son servicios distintos. Mientras un stack no tenga su `compose.{lang}.yml` raíz, los casos individuales se levantan vía `cases/<caso>/<stack>/compose.yml` y exponen el puerto `8XY` (X=stack, Y=caso).

```bash
# PHP: portal + nginx hub (12 casos internos) + DB + Prometheus + Grafana
docker compose -f compose.root.yml up -d --build

# Python: dispatcher (12 casos internos en un contenedor)
docker compose -f compose.python.yml up -d --build

# Portal liviano solamente
docker compose -f compose.portal.yml up -d --build
```

### Ejecucion aislada de un solo caso

Cada caso mantiene su propio `compose.yml` interno para desarrollo o revisión individual:

```bash
# PHP aislado (ejemplo caso 01)
docker compose -f cases/01-api-latency-under-load/php/compose.yml up -d --build

# Python aislado (ejemplo caso 01)
docker compose -f cases/01-api-latency-under-load/python/compose.yml up -d --build
```

Tambien existen atajos con `make`, pero la ruta soportada y mas portable sigue siendo `docker compose` directo.

## 📚 Documentacion del repositorio

| Documento | Rol |
| --- | --- |
| [RECRUITER.md](RECRUITER.md) | Ruta ejecutiva para evaluacion rapida |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Vista ejecutiva de la arquitectura actual |
| [AWS_MIGRATION.md](AWS_MIGRATION.md) | ☁️ Plan de migracion a AWS (ECS Fargate · Lambda · EKS) con costos y paso a paso |
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

- Paridad funcional completa en todos los stacks (Java, .NET, Node.js casos 06-12 siguen en scaffold).
- Benchmarks absolutos entre lenguajes.
- Seniority inflada con claims sin evidencia.

## ⚖️ Licencia

El repositorio se publica bajo [MIT](LICENSE). Revisa tambien [docs/usage-and-scope.md](docs/usage-and-scope.md) para entender sus limites de uso y la postura honesta del proyecto.
