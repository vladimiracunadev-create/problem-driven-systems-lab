# 🛠️ Opciones de solución

| Opción | Qué cuesta | Cuándo tiene sentido |
|---|---|---|
| **Bloquear el productor** (`block`) | Latencia: la lentitud viaja aguas arriba hasta el cliente | Cuando ningún mensaje se puede perder: pagos, órdenes, eventos de auditoría |
| **Descartar el más viejo** (`drop_oldest`) | Datos: se pierden mensajes en silencio salvo que se cuenten | Telemetría, métricas, posiciones de GPS — donde el dato nuevo vale más que el viejo |
| **Dead letter queue** (`dead_letter`) | Deuda operativa: alguien tiene que mirar esa cola | Cuando el mensaje importa pero no es urgente, y hay un proceso real de revisión |
| **Rechazar con 429 al cliente** | Traslada la decisión al llamador | Cuando el productor es un cliente externo que puede reintentar con backoff |
| **Escalar el consumidor** | Dinero, y no resuelve el pico instantáneo | Cuando la diferencia productor/consumidor es estructural, no un pico |

Las tres primeras son las que este caso ejecuta. **Ninguna es gratis, y esa es la lección**: la cola sin límite parece la cuarta opción sin costo, pero solo difiere el pago.

<!-- nav-case-doc -->
---

**Caso 15 · Backpressure en colas de mensajes** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · **🛠️ Opciones de solución** · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
