# ⚖️ Trade-offs

- **Bloquear propaga la lentitud, y eso puede ser correcto.** Un productor frenado es un cliente que espera. Si ese cliente tiene su propio timeout y reintenta, el backpressure se convierte en una tormenta de reintentos — que es el [caso 04](../../04-timeout-chain-and-retry-storms/README.md).
- **Descartar necesita contarse o no existe.** `drop_oldest` sin `messages_dropped_total` es indistinguible de que todo funcione bien. El descarte silencioso es peor que la cola sin límite.
- **La DLQ no resuelve: muda.** Convierte un problema de capacidad en uno de operación. Si nadie define quién la mira, la deuda se acumula durante meses ([caso 20](../../20-forgotten-dead-letter-queue/README.md)).
- **La capacidad correcta no es un número redondo.** Muy chica, el productor se frena con picos normales. Muy grande, la latencia del mensaje más viejo crece y el OOM solo llega más tarde.
- **Escalar consumidores mueve el cuello de botella.** Casi siempre al recurso que los consumidores comparten: la base de datos, el pool de conexiones ([caso 14](../../14-connection-pool-exhaustion/README.md)) o el servicio externo.

<!-- nav-case-doc -->
---

**Caso 15 · Backpressure en colas de mensajes** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · **⚖️ Trade-offs** · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
