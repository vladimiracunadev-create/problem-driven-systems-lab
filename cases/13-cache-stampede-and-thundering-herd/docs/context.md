# 🗺️ Contexto

Una clave de cache caliente expira y, en ese instante, todos los requests que la estaban usando encuentran el hueco a la vez. Ninguno sabe que los otros existen, así que todos van al origen.

## Justificación

Es el fallo que más se parece a una denegación de servicio hecha por uno mismo. El sistema funciona perfecto durante horas y cae en el segundo exacto en que la cache deja de proteger a la base — normalmente de madrugada, cuando un TTL fijo puesto en un deploy hace seis meses vence para mil claves al mismo tiempo.

Lo que lo vuelve difícil de diagnosticar es que **la cache estaba haciendo su trabajo**. El hit rate del dashboard dice 99%. Lo que el dashboard no muestra es cuántos recálculos simultáneos recibe el origen en el 1% restante.

<!-- catalogo -->

## 📇 Ficha del caso

| | |
|---|---|
| **Categoría** | Rendimiento |
| **Estado** | `OPERATIVO` |
| **Stacks operativos** | 7 de 7 |

> Cuando la clave caliente expira, los N llamadores concurrentes recalculan el mismo valor y el origen recibe la ráfaga entera.

## 🧱 Dónde correrlo

| Stack | Versión | URL en el hub | Implementación |
|---|---|---|---|
| 🐘 PHP | `PHP 8.3` | `http://localhost:8100/13/` | [README](../php/README.md) |
| 🐍 Python | `Python 3.12` | `http://localhost:8200/13/` | [README](../python/README.md) |
| 🟢 Node.js | `Node.js 22` | `http://localhost:8300/13/` | [README](../node/README.md) |
| ☕ Java | `Java 21` | `http://localhost:8400/13/` | [README](../java/README.md) |
| 🔵 .NET | `.NET 8` | `http://localhost:8500/13/` | [README](../dotnet/README.md) |
| 🐹 Go | `Go 1.23` | `http://localhost:8600/13/` | [README](../go/README.md) |
| 🦀 Rust | `Rust 1.83` | `http://localhost:8700/13/` | [README](../rust/README.md) |

> ⚠️ **Nota de honestidad del caso:** el origen es CPU real (un digest iterativo), no una consulta a una base de datos ni un `sleep`. Lo que se mide con fidelidad es `origin_computations` — cuántas veces se ejecuta el trabajo caro. La latencia absoluta en milisegundos depende del runtime y no es comparable entre stacks.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 13 · Cache stampede y thundering herd** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

**🗺️ Contexto** · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
