# 🧠 Causas raíz

- **Cola sin capacidad por defecto.** En varios stacks «sin límite» es lo que sale si no se escribe nada: un array de PHP, un `Queue()` de Python, un `ConcurrentLinkedQueue` de Java. El freno hay que pedirlo.
- **Ausencia de señal de backpressure hacia el productor.** El productor no tiene forma de enterarse de que el consumidor no da abasto, así que sigue produciendo al mismo ritmo.
- **Descarte silencioso.** Una cola acotada sin contador de rechazos pierde mensajes sin dejar rastro — es peor que la cola sin límite, porque además es invisible.
- **Confundir latencia del consumidor con latencia del mensaje.** El consumidor puede tardar 3 ms por mensaje y el mensaje haber esperado cuatro minutos en la cola.
- **Dead letter queue sin dueño.** Se elige la DLQ porque «no frena ni pierde», y nadie define quién la mira ni con qué frecuencia. Eso es exactamente el [caso 20](../../20-forgotten-dead-letter-queue/README.md).

<!-- nav-case-doc -->
---

**Caso 15 · Backpressure en colas de mensajes** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · **🧠 Causas raíz** · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
