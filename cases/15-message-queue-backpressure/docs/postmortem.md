# 🚨 Postmortem — Caso 15: el ingestor se reinicia cada 6 horas y nadie sabe por qué

**Severidad:** SEV-2 (reinicios periódicos con pérdida de datos en cada uno)
**Estado:** Resuelto · Acciones implementadas en el lab
**Documento:** retrospectiva basada en el patrón de incidente que motiva este caso

> Este postmortem es **una reconstrucción narrativa del incidente** que justifica la existencia del caso `15`. No documenta un incidente real de producción — documenta el **patrón operacional** que el lab reproduce y resuelve, en formato de postmortem real para evaluación ejecutiva.

---

## 📝 Resumen

El servicio de ingesta de eventos recibía ~4.000 mensajes por segundo y los escribía en el almacén analítico. Entre el receptor HTTP y el escritor había una cola en memoria sin capacidad máxima.

El escritor sostenía ~3.200 mensajes por segundo. La diferencia —800 por segundo— se acumulaba en la cola. Cada ~6 horas el proceso alcanzaba el límite de memoria del contenedor y Kubernetes lo reiniciaba.

Cada reinicio perdía lo que hubiera en la cola: entre 15 y 17 millones de eventos.

Durante cuatro meses el equipo trató esto como un problema de memoria. Se subió el límite del contenedor tres veces. Cada vez, el intervalo entre reinicios se alargó proporcionalmente, lo que confirmó la teoría equivocada.

**Blast radius:** ~65 millones de eventos perdidos por día, sin ninguna alerta que lo indicara.

---

## 🕒 Timeline

| Hora | Evento |
|---|---|
| T+00 | Deploy que sube el volumen de ingesta un 25% por una integración nueva. |
| T+06 h | Primer `OOMKilled`. Se atribuye a un pico. |
| T+12 h | Segundo `OOMKilled`. Se sube el límite de memoria de 2 GB a 4 GB. |
| T+24 h | `OOMKilled` con 4 GB. Ahora cada 12 horas en vez de cada 6. **Se interpreta como mejora.** |
| +2 semanas | Límite en 8 GB. Reinicios cada 24 h. El ticket se cierra como «mitigado». |
| +4 meses | Analítica reporta que los totales diarios no cuadran con los del origen. |
| +4 meses | Se grafica `queue_depth` por primera vez. Es una recta ascendente que se corta en vertical cada 24 h. |

---

## 🎯 Causa raíz

```java
// El receptor
private final Queue<Event> buffer = new ConcurrentLinkedQueue<>();  // sin capacidad
```

Tres decisiones que se necesitan mutuamente:

1. **Cola sin capacidad.** `ConcurrentLinkedQueue` implementa la misma interfaz `Queue` que `ArrayBlockingQueue` y no tiene límite. Cambiar una por otra habría sido una línea.
2. **Sin señal hacia el productor.** El receptor HTTP devolvía 202 Accepted sin importar la profundidad de la cola. El cliente nunca supo que el sistema no daba abasto.
3. **Sin métrica de profundidad.** El dashboard tenía throughput, latencia del escritor y uso de memoria. Ninguna de las tres muestra este problema: la primera se ve bien, la segunda mide lo que no importa, y la tercera se interpretó como un problema de dimensionamiento.

Lo incómodo: **subir el límite de memoria funcionó**. Cada vez. Y funcionar tres veces seguidas convirtió una hipótesis equivocada en una certeza de equipo.

---

## ✅ Lo que funcionó

- El reinicio automático mantuvo el servicio disponible: nunca hubo caída visible para el cliente.
- El límite de memoria del contenedor impidió que el problema afectara a otros pods del nodo.

## ❌ Lo que no funcionó

- **Las tres métricas del dashboard eran ciegas a este fallo.** Throughput sano, latencia del escritor sana, memoria creciente pero «explicable».
- La mitigación efectiva —subir memoria— reforzó el diagnóstico equivocado en vez de cuestionarlo.
- El 202 Accepted del receptor era una mentira: aceptaba mensajes que iba a perder.
- Nadie notó la pérdida durante cuatro meses porque **nada la contaba**.

---

## 🔧 Acciones

| Acción | Estado |
|---|---|
| Cola acotada con capacidad explícita | ✅ Implementado (`/produce-bounded` en los 7 stacks) |
| Política de cola llena elegida a propósito y documentada | ✅ Implementado (`block` / `drop_oldest` / `dead_letter`) |
| Métricas `queue_depth` y `oldest_msg_age_ms` en el dashboard | ✅ Implementado (`/queue/state`) |
| `messages_dropped_total` y `dlq_depth` contados siempre | ✅ Implementado |
| 429 al cliente cuando la cola supera el 80% | ⛔ Fuera del alcance de este caso — el backoff del cliente se cubre en el [caso 04](../../04-timeout-chain-and-retry-storms/README.md) |
| Proceso de revisión de la DLQ | ⛔ Es el [caso 20](../../20-forgotten-dead-letter-queue/README.md) |

---

## 📚 Lección

> Una cola sin límite no es la opción sin costo: es la opción con el freno roto.

Y la que costó cuatro meses: **subir el límite de memoria funcionó tres veces seguidas**. Una mitigación que funciona repetidamente es la forma más eficaz que existe de enterrar una causa raíz — porque cada éxito confirma la teoría equivocada.

<!-- nav-case-doc -->
---

**Caso 15 · Backpressure en colas de mensajes** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · **🚨 Postmortem**
<!-- /nav-case-doc -->
