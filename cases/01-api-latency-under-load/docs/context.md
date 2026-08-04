# 🗺️ Contexto

La lentitud de una API casi nunca se explica solo por “el lenguaje”.
En producción suele aparecer una mezcla de factores:

- consultas mal diseñadas,
- filtros que invalidan índices,
- enriquecimiento innecesario de respuestas,
- payloads demasiado grandes,
- procesos batch o críticos ejecutándose al mismo tiempo,
- ausencia de tablas resumen o estrategias de lectura.

Este caso modela ese escenario: una API de reportes convive con una tarea operacional que refresca agregados para lectura. La degradación no es decorativa; nace de competir por recursos sobre la misma base de datos.

<!-- catalogo -->

## 📇 Ficha del caso

| | |
|---|---|
| **Categoría** | Rendimiento |
| **Estado** | `OPERATIVO` |
| **Stacks operativos** | 7 de 7 |

> La aplicacion responde bien con pocos usuarios, pero degrada su latencia y estabilidad al aumentar la concurrencia.

## 🧱 Dónde correrlo

| Stack | Versión | URL en el hub | Implementación |
|---|---|---|---|
| 🐘 PHP | `PHP 8.3` | `http://localhost:8100/01/` | [README](../php/README.md) |
| 🐍 Python | `Python 3.12` | `http://localhost:8200/01/` | [README](../python/README.md) |
| 🟢 Node.js | `Node.js 22` | `http://localhost:8300/01/` | [README](../node/README.md) |
| ☕ Java | `Java 21` | `http://localhost:8400/01/` | [README](../java/README.md) |
| 🔵 .NET | `.NET 8` | `http://localhost:8500/01/` | [README](../dotnet/README.md) |
| 🐹 Go | `Go 1.23` | `http://localhost:8600/01/` | [README](../go/README.md) |
| 🦀 Rust | `Rust 1.83` | `http://localhost:8700/01/` | [README](../rust/README.md) |

> ⚠️ **Nota de honestidad del caso:** No busca benchmarkear lenguajes. Busca demostrar diagnostico y remediacion real del problema de latencia bajo carga.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 01 · API lenta bajo carga** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

**🗺️ Contexto** · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🔭 Observabilidad](observability.md) · [📈 Benchmarking](benchmarking.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
