# 🗺️ Contexto

El productor va más rápido que el consumidor. La cola interna absorbe la diferencia sin quejarse, la memoria del proceso crece, y una madrugada el OOM killer lo mata. O peor: la cola tenía un límite silencioso y los mensajes se perdieron sin que nadie se enterara.

## Justificación

Lo que hace difícil este caso es que **la cola sin límite se ve bien en todas las métricas que la gente mira**. El throughput es alto. No hay errores. No hay descartes. El productor nunca espera.

Las dos métricas que lo delatan casi nunca están en el dashboard: la **profundidad** de la cola y la **edad del mensaje más viejo**. Un sistema que procesa 1.000 mensajes por segundo con una cola de 400.000 mensajes está entregando resultados de hace siete minutos, y su gráfico de throughput no lo dice.

La otra mitad del problema es conceptual. Cuando se le pone límite a la cola hay que **elegir qué pasa cuando se llena**, y las tres opciones cuestan algo: frenar al productor traslada la lentitud aguas arriba, descartar pierde datos, y la dead letter queue muda el problema a otra cola que alguien tiene que mirar. **No existe una cuarta opción gratis** — y una cola sin límite parece serlo justamente porque el costo llega después y de golpe.

<!-- catalogo -->

## 📇 Ficha del caso

| | |
|---|---|
| **Categoría** | Resiliencia |
| **Estado** | `OPERATIVO` |
| **Stacks operativos** | 7 de 7 |

> Productores más rápidos que consumidores: la cola sin límite crece hasta el OOM y la acotada obliga a elegir entre frenar, perder o mudar el problema.

## 🧱 Dónde correrlo

| Stack | Versión | URL en el hub | Implementación |
|---|---|---|---|
| 🐘 PHP | `PHP 8.3` | `http://localhost:8100/15/` | [README](../php/README.md) |
| 🐍 Python | `Python 3.12` | `http://localhost:8200/15/` | [README](../python/README.md) |
| 🟢 Node.js | `Node.js 22` | `http://localhost:8300/15/` | [README](../node/README.md) |
| ☕ Java | `Java 21` | `http://localhost:8400/15/` | [README](../java/README.md) |
| 🔵 .NET | `.NET 8` | `http://localhost:8500/15/` | [README](../dotnet/README.md) |
| 🐹 Go | `Go 1.23` | `http://localhost:8600/15/` | [README](../go/README.md) |
| 🦀 Rust | `Rust 1.83` | `http://localhost:8700/15/` | [README](../rust/README.md) |

> ⚠️ **Nota de honestidad del caso:** la cola vive dentro del proceso y los mensajes son objetos en memoria, no un broker real. El tiempo de consumo es una espera, que es el modelo fiel: un consumidor se demora esperando I/O, no quemando CPU. En PHP el productor y el consumidor son pasos del mismo bucle porque el lenguaje no tiene concurrencia dentro del proceso.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 15 · Backpressure en colas de mensajes** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

**🗺️ Contexto** · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
