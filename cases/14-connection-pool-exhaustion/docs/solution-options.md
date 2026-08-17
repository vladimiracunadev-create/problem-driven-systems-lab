# 🛠️ Opciones de solución

| Opción | Qué resuelve | Qué cuesta |
|---|---|---|
| **Devolución garantizada por el lenguaje** (`try-with-resources`, `using`, `defer`, `finally`, `Drop`, context manager) | Elimina la fuga de raíz: `leaked` queda en 0 por construcción | Ninguno. Es la opción correcta en los siete stacks |
| **Timeout de adquisición** | Convierte una indisponibilidad silenciosa en un 503 contable y con `Retry-After` | Hay que decidir qué hacer con el que no alcanzó |
| **Dimensionar con la ley de Little** | Un pool proporcional al tráfico real en vez de a la intuición | Requiere medir throughput y tiempo de servicio, no estimarlos |
| **Métrica `acquired - released`** | Detecta la fuga antes de que agote el pool | Dos contadores; el costo es acordarse de mirarlos |
| **Health check que mire el pool** | El servicio se declara no-listo antes de colgar requests | Un pool momentáneamente lleno puede sacar de rotación una instancia sana |

Las dos primeras no son alternativas: la devolución garantizada evita la fuga, el timeout limita el daño de la saturación legítima. Hacen falta las dos.

<!-- nav-case-doc -->
---

**Caso 14 · Agotamiento del pool de conexiones** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · **🛠️ Opciones de solución** · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
