# 🗺️ Contexto

Una integración lenta o inestable dispara reintentos, bloqueos y cascadas de fallas entre servicios.

## Justificación
Es un problema clásico en sistemas distribuidos y microservicios: una falla parcial puede transformarse en caída global.

<!-- catalogo -->

## 📇 Ficha del caso

| | |
|---|---|
| **Categoría** | Resiliencia |
| **Estado** | `OPERATIVO` |
| **Stacks operativos** | 7 de 7 |

> Una integracion lenta o inestable dispara reintentos, bloqueos y cascadas de fallas entre servicios.

## 🧱 Dónde correrlo

| Stack | Versión | URL en el hub | Implementación |
|---|---|---|---|
| 🐘 PHP | `PHP 8.3` | `http://localhost:8100/04/` | [README](../php/README.md) |
| 🐍 Python | `Python 3.12` | `http://localhost:8200/04/` | [README](../python/README.md) |
| 🟢 Node.js | `Node.js 22` | `http://localhost:8300/04/` | [README](../node/README.md) |
| ☕ Java | `Java 21` | `http://localhost:8400/04/` | [README](../java/README.md) |
| 🔵 .NET | `.NET 8` | `http://localhost:8500/04/` | [README](../dotnet/README.md) |
| 🐹 Go | `Go 1.23` | `http://localhost:8600/04/` | [README](../go/README.md) |
| 🦀 Rust | `Rust 1.83` | `http://localhost:8700/04/` | [README](../rust/README.md) |

> ⚠️ **Nota de honestidad del caso:** No reemplaza una integracion real ni una malla completa de servicios. Si reproduce la logica operacional relevante de timeouts, retry storm, circuit breaker y fallback.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 04 · Cadena de timeouts y tormentas de reintentos** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

**🗺️ Contexto** · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
