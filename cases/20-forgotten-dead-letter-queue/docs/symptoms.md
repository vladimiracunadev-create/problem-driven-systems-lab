# 🩺 Síntomas

## Lo que se ve desde afuera

- **Nada.** Es el síntoma principal y el problema entero.
- Un cliente reporta que su pedido «nunca llegó», y en la base efectivamente no está.
- Un reporte de fin de mes que no cuadra por un porcentaje pequeño y constante.
- Alguien abre la DLQ por curiosidad y encuentra cuatrocientos mil mensajes.

## Lo que se ve en las métricas

- **Throughput normal.** El consumidor procesa a la velocidad de siempre.
- **Latencia normal.** Fallar rápido es rápido.
- **Error rate en cero.** El error se manejó: se mandó a la DLQ.
- Y una métrica que casi nunca existe: la profundidad de la DLQ.

## Lo que hace difícil verlo

El consumidor **está haciendo exactamente lo que se le pidió**. Capturar el error, no morirse, seguir con el siguiente mensaje. Eso es resiliencia de manual — y sin la otra mitad, es pérdida de datos con buenos modales.

La segunda dificultad: **la DLQ crece despacio**. Un 4% de mensajes venenosos no se nota en un día. Se nota a los seis meses, cuando la cola tiene un volumen que ya nadie quiere revisar a mano, y cuando el mensaje más viejo lleva tanto tiempo ahí que su contexto de negocio ya venció.

<!-- nav-case-doc -->
---

**Caso 20 · La dead letter queue olvidada** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · **🩺 Síntomas** · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
