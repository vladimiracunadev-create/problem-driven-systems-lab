# 🧪 Problem-Driven Systems Lab

> **Un laboratorio orientado a problemas reales de software, no a colecciones de sintaxis.**

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white)](compose.root.yml)
[![PHP](https://img.shields.io/badge/PHP-8-777BB4?logo=php&logoColor=white)](cases/)
[![Node.js](https://img.shields.io/badge/Node.js-LTS-339933?logo=node.js&logoColor=white)](cases/)
[![Python](https://img.shields.io/badge/Python-3-3776AB?logo=python&logoColor=white)](cases/)
[![Java](https://img.shields.io/badge/Java-JVM-ED8B00?logo=openjdk&logoColor=white)](cases/)
[![.NET](https://img.shields.io/badge/.NET-8-512BD4?logo=dotnet&logoColor=white)](cases/)
[![Status](https://img.shields.io/badge/Estado-Fase%201%20completa-success)](ROADMAP.md)

---

## 🎯 Propósito del repositorio

Este laboratorio existe para demostrar una idea concreta:

> **Un perfil senior no se define solo por años en una sintaxis, sino por su capacidad de diagnosticar, justificar, priorizar y resolver problemas complejos de software en producción.**

El repositorio documenta, compara y levanta casos reproducibles sobre:

| Área | Problemas que cubre |
|------|-------------------|
| ⚡ **Rendimiento** | Latencia, N+1 queries, cuellos de botella, presión de memoria |
| 🔭 **Observabilidad** | Logs inútiles, falta de trazabilidad, métricas ausentes |
| 🛡️ **Resiliencia** | Timeouts en cascada, reintentos, integraciones inestables |
| 🏗️ **Arquitectura** | Modernización legacy, extracción de módulos, sobrecosto |
| 🚚 **Entrega** | Pipelines rotos, deploys frágiles, deuda de automatización |
| 🧠 **Operaciones** | Conocimiento único, reportes bloqueantes, continuidad |

---

## ❓ Qué problema resuelve este repositorio

En el mercado suele ocurrir que:

- 🔴 se filtra por lenguaje antes que por criterio técnico,
- 🔴 se pide experiencia exacta en un stack aunque el problema real sea arquitectónico,
- 🔴 se confunde "saber sintaxis" con "saber resolver producción",
- 🔴 se habla de seniority en abstracto, pero sin evidencias comparables.

Este repositorio responde a eso con un **enfoque problem-driven**:

1. 📌 Parte desde un problema real con contexto y síntomas
2. 🔍 Explica causas y diagnóstico
3. 🧩 Propone soluciones con trade-offs
4. 🐳 Lo implementa en varios stacks aislados con Docker
5. 📊 Deja estructura lista para medir y comparar

---

## 🚫 Qué NO es este repositorio

| ❌ NO es | ✅ SÍ es |
|---------|---------|
| Enciclopedia de features por lenguaje | Laboratorio de análisis técnico |
| Curso básico de "hola mundo" | Base de comparación multi-stack |
| Benchmark absoluto entre tecnologías | Pieza de portafolio documentada |
| Afirmación falsa de seniority en todo | Guía de diseño reproducible |
| Un único `docker compose up` masivo | Biblioteca de casos extensibles |

---

## 📋 Los 12 casos del laboratorio

| # | Ícono | Caso | Categoría | Valor principal |
|---|-------|------|-----------|-----------------|
| `01` | ⚡ | [API lenta bajo carga](cases/01-api-latency-under-load/) | Rendimiento | Reduce latencia y evita sobredimensionar infra |
| `02` | 🔄 | [N+1 queries y cuellos de botella DB](cases/02-n-plus-one-and-db-bottlenecks/) | Rendimiento | Base sana = menos incidentes y menos costos |
| `03` | 🔭 | [Observabilidad deficiente y logs inútiles](cases/03-poor-observability-and-useless-logs/) | Observabilidad | Menor MTTR y mejores decisiones operacionales |
| `04` | ⛓️ | [Cadena de timeouts y tormentas de reintentos](cases/04-timeout-chain-and-retry-storms/) | Resiliencia | Evita caídas en cascada ante terceros inestables |
| `05` | 🧠 | [Presión de memoria y fugas de recursos](cases/05-memory-pressure-and-resource-leaks/) | Rendimiento | Elimina reinicios silenciosos y consumo innecesario |
| `06` | 🚚 | [Pipeline roto y entrega frágil](cases/06-broken-pipeline-and-fragile-delivery/) | Entrega | Publicar sin riesgo y revertir incidentes |
| `07` | 🏗️ | [Modernización incremental de monolito](cases/07-incremental-monolith-modernization/) | Arquitectura | Evolucionar plataformas vivas sin reescritura total |
| `08` | 🔧 | [Extracción de módulo crítico sin romper ops](cases/08-critical-module-extraction-without-breaking-operations/) | Arquitectura | Desacople controlado de piezas sensibles |
| `09` | 🌐 | [Integración externa inestable](cases/09-unstable-external-integration/) | Resiliencia | Mitigar dependencia de proveedores externos |
| `10` | 💰 | [Arquitectura cara para un problema simple](cases/10-expensive-architecture-for-simple-needs/) | Arquitectura | Reducir costos manteniendo el foco en el negocio |
| `11` | 📊 | [Reportes pesados que bloquean la operación](cases/11-heavy-reporting-blocks-operations/) | Operaciones | Proteger la operación diaria durante analítica |
| `12` | 🧑‍💼 | [Punto único de conocimiento y riesgo operacional](cases/12-single-point-of-knowledge-and-operational-risk/) | Operaciones | Reducir riesgo organizacional y mejorar continuidad |

---

## 🏛️ Principios de diseño

| # | Principio | Descripción |
|---|-----------|-------------|
| 1 | **Problema primero** | El caso manda; el stack acompaña. |
| 2 | **Portal raíz liviano** | El entorno principal sirve como landing y documentación. |
| 3 | **Casos aislados** | Cada caso se levanta por separado. No existe un único `docker compose up` masivo. |
| 4 | **Comparación multi-stack** | Un mismo problema puede implementarse en PHP, Node, Python, Java o .NET. |
| 5 | **Documentación extrema y honesta** | Cada carpeta explica por qué existe, para qué sirve, cómo se usa y sus límites. |
| 6 | **Crecimiento sostenible** | Claridad, mantenibilidad y continuidad antes que cantidad vacía. |

---

## 🐳 Modelo Docker del laboratorio

```
┌─────────────────────────────────────────────────────────────┐
│                    compose.root.yml                          │
│            Portal principal del laboratorio                  │
│  (landing local, arquitectura general, enlaces a casos)      │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│         cases/<caso>/<stack>/compose.yml                     │
│       Un escenario específico aislado                        │
│  (solo las dependencias del stack que quieres trabajar)      │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│         cases/<caso>/compose.compare.yml                     │
│      Comparación multi-stack del mismo caso                  │
│  (solo cuando conviene analizar diferencias lado a lado)     │
└─────────────────────────────────────────────────────────────┘
```

> **¿Por qué no levantar todo junto?**
> Sería más pesado, más caro, más lento de depurar y menos útil para portafolio.
> Docker aquí no es adorno — es una forma de documentar, aislar y comparar.

---

## 📁 Estructura general

```text
problem-driven-systems-lab/
├── 📄 README.md              ← Este archivo
├── 🗺️  ROADMAP.md             ← Estado y fases del laboratorio
├── 🛠️  Makefile               ← Comandos rápidos
├── 🐳  compose.root.yml       ← Portal principal
├── 🔒  .env.example           ← Variables de entorno de ejemplo
├── 🌐  portal/                ← Landing local PHP 8
├── 📚  docs/                  ← Documentación global y ADRs
├── 🔗  shared/                ← Recursos compartidos entre casos
├── 📋  templates/             ← Plantillas para nuevos casos
└── 🧪  cases/                 ← Los 12 casos del laboratorio
    ├── 01-api-latency-under-load/
    ├── 02-n-plus-one-and-db-bottlenecks/
    ├── ...
    └── 12-single-point-of-knowledge-and-operational-risk/
```

---

## 🚀 Cómo navegar este repositorio

### 👔 Ruta para reclutadores o lectores ejecutivos

```
1. Leer este README.md
2. Abrir docs/positioning-and-objective.md
3. Revisar docs/problem-map.md
4. Abrir uno o dos casos relevantes
5. Revisar docs/recruiter-guide.md
```

### 🧑‍💻 Ruta técnica

```
1. docs/architecture.md
2. docs/case-methodology.md
3. cases/01-* hasta cases/12-*
4. templates/
5. shared/
```

---

## ⚙️ Comandos rápidos

```bash
# Ver todos los comandos disponibles
make help

# Levantar el portal principal
make portal-up
make portal-down

# Ver lista de casos
make case-list

# Levantar un caso específico en un stack
make case-up CASE=01-api-latency-under-load STACK=php
make case-down CASE=01-api-latency-under-load STACK=php

# Comparar múltiples stacks de un mismo caso
make compare-up CASE=01-api-latency-under-load
make compare-down CASE=01-api-latency-under-load
```

---

## 📈 Estado actual del repositorio

Esta versión incluye:

- ✅ Estructura completa del laboratorio
- ✅ Portal base PHP 8
- ✅ Documentación raíz extensa
- ✅ 12 casos con base documental
- ✅ Caso 01 con implementación real (PHP + PostgreSQL + worker + Prometheus + Grafana)
- ✅ Carpetas por stack con Dockerfiles de referencia
- ✅ `compose.compare.yml` por caso
- ✅ Convenciones de crecimiento documentadas
- 🔧 Casos 02–12 listos para profundizar implementaciones funcionales

> No pretende cerrar todos los desarrollos funcionales en una primera iteración.
> Pretende dejar una **base extremadamente documentada, coherente y extensible**.

---

## 📚 Índice documental

| Documento | Descripción |
|-----------|-------------|
| [Visión y objetivo](docs/positioning-and-objective.md) | Por qué existe este laboratorio |
| [Arquitectura del repositorio](docs/architecture.md) | Estructura por niveles y reglas |
| [Estrategia Docker](docs/docker-strategy.md) | Patrones de compose y filosofía de aislamiento |
| [Metodología de casos](docs/case-methodology.md) | Las 7 preguntas que responde cada caso |
| [Mapa de problemas](docs/problem-map.md) | Descripción y valor de cada uno de los 12 casos |
| [Mapa de stacks](docs/stack-map.md) | Por qué hay múltiples lenguajes y cómo se usan |
| [Guía para reclutadores](docs/recruiter-guide.md) | Cómo leer el repositorio en 5 minutos |
| [Alcance y uso esperado](docs/usage-and-scope.md) | Qué sí y qué no cubre esta versión |
| [Catálogo de casos](docs/case-catalog.md) | Lista completa con estado de cada caso |
| [Guía de crecimiento](docs/growth-guidelines.md) | Cómo agregar casos y stacks correctamente |
| [Decisiones de arquitectura (ADRs)](docs/adr/) | Decisiones clave documentadas con contexto |

---

## 📜 Convención de crecimiento

Cada nuevo caso debe incluir:

- `README.md` del caso con síntomas, diagnóstico y valor
- Documentación funcional y técnica completa
- Al menos una implementación dockerizada funcional
- Explicación de opciones, trade-offs y justificación del caso

---

## ⚖️ Licenciamiento

Este repositorio está pensado como laboratorio personal/profesional, material de portafolio y base documental reusable.
Revisa [`LICENSE`](LICENSE) y [`docs/usage-and-scope.md`](docs/usage-and-scope.md) para detalles de uso.
