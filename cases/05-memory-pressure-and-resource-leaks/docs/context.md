# 🗺️ Contexto

El sistema consume memoria, descriptores o conexiones de forma progresiva hasta degradar o caerse.

## Justificación
Las fugas no siempre se ven en ambientes pequeños; en producción generan incidentes costosos y difíciles de reproducir.

<!-- catalogo -->

## 📇 Ficha del caso

| | |
|---|---|
| **Categoría** | Rendimiento |
| **Estado** | `OPERATIVO` |
| **Stacks operativos** | 7 de 7 |

> El sistema consume memoria, descriptores o conexiones de forma progresiva hasta degradar o caerse.

## 🧱 Dónde correrlo

| Stack | Versión | URL en el hub | Implementación |
|---|---|---|---|
| 🐘 PHP | `PHP 8.3` | `http://localhost:8100/05/` | [README](../php/README.md) |
| 🐍 Python | `Python 3.12` | `http://localhost:8200/05/` | [README](../python/README.md) |
| 🟢 Node.js | `Node.js 22` | `http://localhost:8300/05/` | [README](../node/README.md) |
| ☕ Java | `Java 21` | `http://localhost:8400/05/` | [README](../java/README.md) |
| 🔵 .NET | `.NET 8` | `http://localhost:8500/05/` | [README](../dotnet/README.md) |
| 🐹 Go | `Go 1.23` | `http://localhost:8600/05/` | [README](../go/README.md) |
| 🦀 Rust | `Rust 1.83` | `http://localhost:8700/05/` | [README](../rust/README.md) |

> ⚠️ **Nota de honestidad del caso:** No pretende copiar al milimetro el modelo de memoria de cada runtime. Si deja visible la senal operacional importante: crecimiento silencioso, degradacion progresiva y necesidad de limpieza.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 05 · Presion de memoria y fugas de recursos** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

**🗺️ Contexto** · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
