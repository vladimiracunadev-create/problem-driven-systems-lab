# 🗺️ Contexto

Se necesita desacoplar una parte clave del sistema, pero esa parte participa en flujos sensibles y no admite quiebres.

## Justificación
Es un reto frecuente cuando se busca aliviar cuellos de botella o reducir dependencia del monolito sin apagar el negocio.

<!-- catalogo -->

## 📇 Ficha del caso

| | |
|---|---|
| **Categoría** | Arquitectura |
| **Estado** | `OPERATIVO` |
| **Stacks operativos** | 7 de 7 |

> Se necesita desacoplar una parte clave del sistema, pero esa parte participa en flujos sensibles y no admite quiebres.

## 🧱 Dónde correrlo

| Stack | Versión | URL en el hub | Implementación |
|---|---|---|---|
| 🐘 PHP | `PHP 8.3` | `http://localhost:8100/08/` | [README](../php/README.md) |
| 🐍 Python | `Python 3.12` | `http://localhost:8200/08/` | [README](../python/README.md) |
| 🟢 Node.js | `Node.js 22` | `http://localhost:8300/08/` | [README](../node/README.md) |
| ☕ Java | `Java 21` | `http://localhost:8400/08/` | [README](../java/README.md) |
| 🔵 .NET | `.NET 8` | `http://localhost:8500/08/` | [README](../dotnet/README.md) |
| 🐹 Go | `Go 1.23` | `http://localhost:8600/08/` | [README](../go/README.md) |
| 🦀 Rust | `Rust 1.83` | `http://localhost:8700/08/` | [README](../rust/README.md) |

> ⚠️ **Nota de honestidad del caso:** No simula un rollout distribuido completo ni feature flags globales. Si deja visible la logica clave: proxy de compatibilidad, contratos y cutover gradual.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 08 · Extraccion de modulo critico sin romper operacion** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

**🗺️ Contexto** · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
