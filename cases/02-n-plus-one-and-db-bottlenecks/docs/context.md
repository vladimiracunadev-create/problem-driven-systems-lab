# 🗺️ Contexto

La aplicación ejecuta demasiadas consultas por solicitud o usa el ORM de forma ineficiente, generando saturación de base de datos.

## Justificación
Muchos sistemas parecen correctos funcionalmente, pero escalan mal por decisiones de acceso a datos poco visibles.

<!-- catalogo -->

## 📇 Ficha del caso

| | |
|---|---|
| **Categoría** | Rendimiento |
| **Estado** | `OPERATIVO` |
| **Stacks operativos** | 7 de 7 |

> La aplicacion ejecuta demasiadas consultas por solicitud o usa el acceso a datos de forma ineficiente, generando saturacion de base de datos.

## 🧱 Dónde correrlo

| Stack | Versión | URL en el hub | Implementación |
|---|---|---|---|
| 🐘 PHP | `PHP 8.3` | `http://localhost:8100/02/` | [README](../php/README.md) |
| 🐍 Python | `Python 3.12` | `http://localhost:8200/02/` | [README](../python/README.md) |
| 🟢 Node.js | `Node.js 22` | `http://localhost:8300/02/` | [README](../node/README.md) |
| ☕ Java | `Java 21` | `http://localhost:8400/02/` | [README](../java/README.md) |
| 🔵 .NET | `.NET 8` | `http://localhost:8500/02/` | [README](../dotnet/README.md) |
| 🐹 Go | `Go 1.23` | `http://localhost:8600/02/` | [README](../go/README.md) |
| 🦀 Rust | `Rust 1.83` | `http://localhost:8700/02/` | [README](../rust/README.md) |

> ⚠️ **Nota de honestidad del caso:** No intenta representar un ORM especifico. Reproduce un patron muy real de round-trips repetidos y relaciones cargadas dentro de bucles.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 02 · N+1 queries y cuellos de botella en base de datos** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

**🗺️ Contexto** · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
