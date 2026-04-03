# Problem-Driven Systems Lab

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white)](compose.root.yml)
[![PHP](https://img.shields.io/badge/PHP-8-777BB4?logo=php&logoColor=white)](cases/)
[![Node.js](https://img.shields.io/badge/Node.js-LTS-339933?logo=node.js&logoColor=white)](cases/)
[![Python](https://img.shields.io/badge/Python-3-3776AB?logo=python&logoColor=white)](cases/)
[![Java](https://img.shields.io/badge/Java-JVM-ED8B00?logo=openjdk&logoColor=white)](cases/)
[![.NET](https://img.shields.io/badge/.NET-8-512BD4?logo=dotnet&logoColor=white)](cases/)
[![Status](https://img.shields.io/badge/Estado-Activo-blue)](ROADMAP.md)

Portafolio tecnico orientado a problemas reales de software: rendimiento, observabilidad, resiliencia, arquitectura y continuidad operacional. Este repositorio forma parte del ecosistema publico de Vladimir Acuna y aterriza una linea consistente de trabajo: modernizacion de sistemas vivos, Docker como ruta oficial de ejecucion, documentacion por audiencia y soluciones honestas a problemas de produccion.

## Executive Summary

- El repositorio modela 12 problemas reales, desde sintoma hasta solucion y valor de negocio.
- Los casos `01`, `02` y `03` ya cuentan con implementacion operativa real en PHP.
- El caso `03` ya cuenta tambien con implementaciones operativas en Node.js y Python.
- Docker es la via oficial para ejecutar los casos implementados de forma limpia y reproducible.
- El catalogo del portal y `docs/case-catalog.md` ahora se sostienen desde metadatos compartidos.
- La madurez se comunica con honestidad: `OPERATIVO`, `DOCUMENTADO / SCAFFOLD` o `PLANIFICADO`.
- El objetivo no es competir por sintaxis ni vender seniority vacia, sino evidenciar criterio transferible.

## Que demuestra este laboratorio

| Area | Evidencia |
| --- | --- |
| Diagnostico tecnico | El problema se explica con contexto, sintomas, causas, trade-offs y solucion |
| Ejecucion reproducible | Cada caso implementado tiene `Dockerfile` y `compose.yml` propios |
| Operacion realista | Los casos no se reducen a "sleep demos"; usan DB, worker, logs o telemetria segun corresponda |
| Documentacion profesional | El repo ya tiene rutas para reclutadores, operacion, seguridad, contribucion e instalacion |
| Honestidad tecnica | Se distingue claramente entre casos operativos y scaffolds documentados |

## Taxonomia de madurez actual

| Nivel | Que significa hoy en este repo |
| --- | --- |
| `OPERATIVO` | Caso resolviendo el problema de forma real, con Docker y evidencia observable |
| `DOCUMENTADO / SCAFFOLD` | Caso bien modelado, con estructura y stack folders, pero sin paridad funcional profunda aun |
| `PLANIFICADO` | Linea futura del roadmap, sin prometer madurez operativa actual |

Estado actual:

- `OPERATIVO`: casos [01](cases/01-api-latency-under-load/README.md), [02](cases/02-n-plus-one-and-db-bottlenecks/README.md) y [03](cases/03-poor-observability-and-useless-logs/README.md) en PHP.
- `OPERATIVO` adicional en stacks no PHP: [caso 03](cases/03-poor-observability-and-useless-logs/README.md) en Node.js y Python.
- `DOCUMENTADO / SCAFFOLD`: casos `04` al `12`, y stacks no profundizados de los casos `01` al `03`.

## Por donde empezar

| Perfil | Ruta recomendada | Objetivo |
| --- | --- | --- |
| Reclutador o hiring manager | [RECRUITER.md](RECRUITER.md) | Entender el valor del repo en pocos minutos |
| Lider de ingenieria | [docs/positioning-and-objective.md](docs/positioning-and-objective.md) | Ver el problema que este laboratorio viene a resolver |
| Dev / DevOps | [INSTALL.md](INSTALL.md) -> [RUNBOOK.md](RUNBOOK.md) -> caso [01](cases/01-api-latency-under-load/README.md) | Levantar y evaluar una implementacion operativa real |
| Principiante | [docs/BEGINNERS_GUIDE.md](docs/BEGINNERS_GUIDE.md) | Entender la estructura antes de entrar al codigo |
| Seguridad / operacion | [SECURITY.md](SECURITY.md) -> [RUNBOOK.md](RUNBOOK.md) | Validar postura operativa, limites y reportes |

## Inicio rapido

Ruta oficial para ambientes implementados:

```bash
# Portal del laboratorio
docker compose -f compose.root.yml up -d --build

# Caso 01 (PHP + PostgreSQL + worker + observabilidad)
docker compose -f cases/01-api-latency-under-load/php/compose.yml up -d --build

# Caso 02 (PHP + PostgreSQL)
docker compose -f cases/02-n-plus-one-and-db-bottlenecks/php/compose.yml up -d --build

# Caso 03 (PHP + telemetria util)
docker compose -f cases/03-poor-observability-and-useless-logs/php/compose.yml up -d --build
```

Atajos disponibles:

```bash
make portal-up
make case-up CASE=01-api-latency-under-load STACK=php
make case-up CASE=02-n-plus-one-and-db-bottlenecks STACK=php
make case-up CASE=03-poor-observability-and-useless-logs STACK=php
```

> [!IMPORTANT]
> En este repositorio `make` es una capa de conveniencia. La ruta realmente soportada y mas portable es `docker compose` directo. En Windows puro, esto importa porque el `Makefile` actual usa `/bin/bash`.

## Casos prioritarios

| Caso | Estado | Valor principal |
| --- | --- | --- |
| [01 - API lenta bajo carga](cases/01-api-latency-under-load/README.md) | `OPERATIVO` | Mide latencia, contencion sobre DB, worker concurrente y mejora antes/despues |
| [02 - N+1 y cuellos de botella DB](cases/02-n-plus-one-and-db-bottlenecks/README.md) | `OPERATIVO` | Compara consultas legacy vs optimizadas sobre un modelo relacional real |
| [03 - Observabilidad deficiente](cases/03-poor-observability-and-useless-logs/README.md) | `OPERATIVO` | Contrasta logs inutiles contra telemetria util para reducir MTTR en PHP, Node.js y Python |

El catalogo completo esta en [docs/case-catalog.md](docs/case-catalog.md).

## Documentacion profesional del repositorio

| Documento | Para que sirve |
| --- | --- |
| [RECRUITER.md](RECRUITER.md) | Ruta ejecutiva para evaluacion rapida |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Vista ejecutiva de la arquitectura actual del sistema |
| [INSTALL.md](INSTALL.md) | Instalacion y puesta en marcha recomendada |
| [RUNBOOK.md](RUNBOOK.md) | Operacion diaria, diagnostico y respuesta inicial |
| [SECURITY.md](SECURITY.md) | Politica de seguridad y reporte responsable |
| [SUPPORT.md](SUPPORT.md) | Como pedir ayuda y que informacion incluir |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Reglas para crecer el laboratorio sin degradarlo |
| [CHANGELOG.md](CHANGELOG.md) | Historial notable de cambios y madurez |
| [docs/BEGINNERS_GUIDE.md](docs/BEGINNERS_GUIDE.md) | Ruta simple para primeros pasos |
| [docs/architecture.md](docs/architecture.md) | Estructura del repo y reglas de organizacion |
| [docs/docker-strategy.md](docs/docker-strategy.md) | Por que Docker es el modelo operativo oficial |
| [docs/usage-and-scope.md](docs/usage-and-scope.md) | Limites reales de esta version |
| [docs/recruiter-guide.md](docs/recruiter-guide.md) | Guia extendida para lectores no tecnicos |

## Ecosistema relacionado

Este laboratorio no existe aislado. Se alinea con un ecosistema publico mas amplio:

- Web profesional: [vladimiracunadev-create.github.io](https://vladimiracunadev-create.github.io/)
- Perfil GitHub: [github.com/vladimiracunadev-create](https://github.com/vladimiracunadev-create)
- Grupo GitLab: [gitlab.com/vladimir.acuna.dev-group/vladimir.acuna.dev-group](https://gitlab.com/vladimir.acuna.dev-group/vladimir.acuna.dev-group)

La linea comun entre estos activos es consistente: repos reproducibles, documentacion por audiencia, observabilidad, continuidad operacional y modernizacion de plataformas reales.

## Lo que este repo si es

- Un laboratorio serio para demostrar criterio tecnico transferible.
- Una base reproducible para conversar de rendimiento, observabilidad y arquitectura.
- Un portafolio documentado que privilegia problemas reales por sobre features aisladas.

## Lo que este repo no intenta vender

- Paridad funcional completa en todos los stacks desde la primera iteracion.
- Benchmarks absolutos entre lenguajes.
- "Todo listo" en doce casos al mismo nivel de profundidad.
- Seniority inflada con claims sin evidencia.

## Estructura general

```text
problem-driven-systems-lab/
|- README.md
|- RECRUITER.md
|- INSTALL.md
|- RUNBOOK.md
|- SECURITY.md
|- SUPPORT.md
|- CONTRIBUTING.md
|- CHANGELOG.md
|- ROADMAP.md
|- compose.root.yml
|- portal/
|- docs/
|- shared/
|- templates/
`- cases/
```

## Licencia

El repositorio se publica bajo [MIT](LICENSE). Revisa tambien [docs/usage-and-scope.md](docs/usage-and-scope.md) para entender sus limites de uso y la postura honesta del proyecto.
