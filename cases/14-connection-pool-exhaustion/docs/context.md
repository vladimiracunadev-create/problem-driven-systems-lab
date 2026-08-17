# 🗺️ Contexto

«Could not get connection from pool.» El error aparece con tráfico moderado, no en un pico. La base está sana, el CPU está bajo, y aun así los requests se acumulan esperando una conexión que nunca llega.

## Justificación

Lo que engaña es que el pool **no está ocupado**: está vacío. Son dos estados distintos que la misma métrica muestra igual. Un pool ocupado se libera solo cuando terminan las queries en vuelo. Un pool vacío por fuga no se libera nunca, porque las conexiones que faltan no las tiene nadie — se perdieron en un camino de excepción donde no había `finally`.

La segunda mitad del problema es que **esperar no tiene límite**. Sin timeout de adquisición, el que llega tarde no falla: se queda. Y mientras se queda, ocupa un hilo, o una goroutine, o una Promise que nadie va a resolver. El sistema deja de responder sin que ningún proceso muera y sin que ninguna alerta de error dispare, porque técnicamente no falló nada.

<!-- catalogo -->

## 📇 Ficha del caso

| | |
|---|---|
| **Categoría** | Rendimiento |
| **Estado** | `OPERATIVO` |
| **Stacks operativos** | 7 de 7 |

> Un pool chico, sin timeout de adquisición y con fugas en el camino de excepción deja de dar conexiones para siempre.

## 🧱 Dónde correrlo

| Stack | Versión | URL en el hub | Implementación |
|---|---|---|---|
| 🐘 PHP | `PHP 8.3` | `http://localhost:8100/14/` | [README](../php/README.md) |
| 🐍 Python | `Python 3.12` | `http://localhost:8200/14/` | [README](../python/README.md) |
| 🟢 Node.js | `Node.js 22` | `http://localhost:8300/14/` | [README](../node/README.md) |
| ☕ Java | `Java 21` | `http://localhost:8400/14/` | [README](../java/README.md) |
| 🔵 .NET | `.NET 8` | `http://localhost:8500/14/` | [README](../dotnet/README.md) |
| 🐹 Go | `Go 1.23` | `http://localhost:8600/14/` | [README](../go/README.md) |
| 🦀 Rust | `Rust 1.83` | `http://localhost:8700/14/` | [README](../rust/README.md) |

> ⚠️ **Nota de honestidad del caso:** las conexiones son objetos en memoria, no sockets contra una base real, y el tiempo de query es un `sleep`. Eso último es deliberado y **más** fiel que quemar CPU: una conexión se retiene mientras se espera a la red. Lo que se mide con fidelidad es `leaked`, `hung` y el estado del pool al terminar.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 14 · Agotamiento del pool de conexiones** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

**🗺️ Contexto** · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
