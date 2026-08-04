# 🗺️ Contexto

El software funciona en desarrollo, pero falla al desplegar, promover cambios o revertir incidentes con seguridad.

## Justificación
Un delivery frágil aumenta riesgo operativo, detiene equipos y hace que cada cambio sea más caro que el anterior.

<!-- catalogo -->

## 📇 Ficha del caso

| | |
|---|---|
| **Categoría** | Entrega |
| **Estado** | `OPERATIVO` |
| **Stacks operativos** | 7 de 7 |

> El software funciona en desarrollo, pero falla al desplegar, promover cambios o revertir incidentes con seguridad.

## 🧱 Dónde correrlo

| Stack | Versión | URL en el hub | Implementación |
|---|---|---|---|
| 🐘 PHP | `PHP 8.3` | `http://localhost:8100/06/` | [README](../php/README.md) |
| 🐍 Python | `Python 3.12` | `http://localhost:8200/06/` | [README](../python/README.md) |
| 🟢 Node.js | `Node.js 22` | `http://localhost:8300/06/` | [README](../node/README.md) |
| ☕ Java | `Java 21` | `http://localhost:8400/06/` | [README](../java/README.md) |
| 🔵 .NET | `.NET 8` | `http://localhost:8500/06/` | [README](../dotnet/README.md) |
| 🐹 Go | `Go 1.23` | `http://localhost:8600/06/` | [README](../go/README.md) |
| 🦀 Rust | `Rust 1.83` | `http://localhost:8700/06/` | [README](../rust/README.md) |

> ⚠️ **Nota de honestidad del caso:** No reemplaza un CI/CD real ni una plataforma de IaC completa. Si reproduce la logica de delivery que importa: validaciones previas, canary, smoke tests y rollback.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 06 · Pipeline roto y entrega fragil** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

**🗺️ Contexto** · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
