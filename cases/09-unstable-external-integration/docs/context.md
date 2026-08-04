# 🗺️ Contexto

Una API, servicio o proveedor externo introduce latencia, errores intermitentes o reglas cambiantes que afectan el sistema propio.

## Justificación
Las integraciones externas suelen ser el punto donde el control interno termina y la resiliencia debe comenzar.

<!-- catalogo -->

## 📇 Ficha del caso

| | |
|---|---|
| **Categoría** | Resiliencia |
| **Estado** | `OPERATIVO` |
| **Stacks operativos** | 7 de 7 |

> Una API, servicio o proveedor externo introduce latencia, errores intermitentes o reglas cambiantes que afectan el sistema propio.

## 🧱 Dónde correrlo

| Stack | Versión | URL en el hub | Implementación |
|---|---|---|---|
| 🐘 PHP | `PHP 8.3` | `http://localhost:8100/09/` | [README](../php/README.md) |
| 🐍 Python | `Python 3.12` | `http://localhost:8200/09/` | [README](../python/README.md) |
| 🟢 Node.js | `Node.js 22` | `http://localhost:8300/09/` | [README](../node/README.md) |
| ☕ Java | `Java 21` | `http://localhost:8400/09/` | [README](../java/README.md) |
| 🔵 .NET | `.NET 8` | `http://localhost:8500/09/` | [README](../dotnet/README.md) |
| 🐹 Go | `Go 1.23` | `http://localhost:8600/09/` | [README](../go/README.md) |
| 🦀 Rust | `Rust 1.83` | `http://localhost:8700/09/` | [README](../rust/README.md) |

> ⚠️ **Nota de honestidad del caso:** No reemplaza una integracion real con DLQ ni proveedores externos verdaderos. Si reproduce contratos variables, cuota, cache y adaptacion defensiva.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 09 · Integracion externa inestable** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

**🗺️ Contexto** · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
