# 🗺️ Contexto

Consultas y procesos de reporting compiten con la operación transaccional y degradan el sistema completo.

## Justificación
Es muy común en plataformas maduras: el negocio pide más información y el sistema termina usando la misma vía que el tráfico crítico.

<!-- catalogo -->

## 📇 Ficha del caso

| | |
|---|---|
| **Categoría** | Operaciones |
| **Estado** | `OPERATIVO` |
| **Stacks operativos** | 7 de 7 |

> Consultas y procesos de reporting compiten con la operacion transaccional y degradan el sistema completo.

## 🧱 Dónde correrlo

| Stack | Versión | URL en el hub | Implementación |
|---|---|---|---|
| 🐘 PHP | `PHP 8.3` | `http://localhost:8100/11/` | [README](../php/README.md) |
| 🐍 Python | `Python 3.12` | `http://localhost:8200/11/` | [README](../python/README.md) |
| 🟢 Node.js | `Node.js 22` | `http://localhost:8300/11/` | [README](../node/README.md) |
| ☕ Java | `Java 21` | `http://localhost:8400/11/` | [README](../java/README.md) |
| 🔵 .NET | `.NET 8` | `http://localhost:8500/11/` | [README](../dotnet/README.md) |
| 🐹 Go | `Go 1.23` | `http://localhost:8600/11/` | [README](../go/README.md) |
| 🦀 Rust | `Rust 1.83` | `http://localhost:8700/11/` | [README](../rust/README.md) |

> ⚠️ **Nota de honestidad del caso:** No reemplaza una replica real ni un data warehouse. Si reproduce el problema operativo importante: reporting sobre el primario versus aislamiento de cargas.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 11 · Reportes pesados que bloquean la operacion** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

**🗺️ Contexto** · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
