# 🗺️ Contexto

Este caso cierra el arco que abrió el [caso 15](../../15-message-queue-backpressure/README.md).

Allí la dead letter queue **nace**: es la política de rechazo que salva al productor de bloquearse cuando la cola se llena. Es la decisión correcta. Acá se ve qué pasa cuando nadie vuelve a mirarla.

## Justificación

Un consumidor falla al procesar un mensaje. Lo manda a la DLQ y sigue con el siguiente. El pipeline no se cae, la latencia no sube, el throughput no baja. **El dashboard muestra cero errores** — porque los errores se fueron a otro lado.

Meses después alguien abre la DLQ y encuentra cuatrocientos mil mensajes.

## La distinción que ordena todo

| Clase | Qué significa | Qué corresponde hacer |
|---|---|---|
| **Transitorio** | El mismo mensaje funciona en el próximo intento | **Reintentar** con backoff |
| **Venenoso** | El mismo mensaje **nunca** va a funcionar | **A la DLQ**, ya mismo |

Timeout, 503 del downstream, deadlock de la base: transitorios. Schema roto, campo desconocido, encoding inválido: venenosos.

**Reintentar lo venenoso es quemar CPU. Mandar lo transitorio a la DLQ es tirar trabajo que se podía salvar.** El consumidor que no distingue hace las dos cosas mal a la vez — y el resultado se mide: en el laboratorio, drenar la DLQ del consumidor silencioso recupera el **71,39%** de sus mensajes. Ese es el trabajo que nunca debería haber estado ahí.

## Lo que convierte una DLQ en una cola y no en un agujero

1. **Profundidad publicada** — `dlq_depth` como métrica, no como consulta manual.
2. **Antigüedad del más viejo** — `dlq_oldest_msg_age_ms`. Es la que dice si algo lo va a reparar.
3. **Desglose por clase de error** — convierte «hay 4.000 mensajes» en «hay un bug de schema y tres timeouts».
4. **Muestras del payload** — para poder depurar sin volcar la cola entera.
5. **Una salida** — replay. Una DLQ que solo recibe es un cementerio; una de la que se puede volver es un buffer.

<!-- nav-case-doc -->
---

**Caso 20 · La dead letter queue olvidada** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

**🗺️ Contexto** · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
