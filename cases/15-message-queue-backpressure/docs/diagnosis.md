# 🔍 Diagnóstico

1. **Graficar profundidad, no throughput.** El throughput de un sistema con cola sin límite se ve perfecto hasta el segundo antes del OOM. `queue_depth` es la métrica que crece.
2. **Medir la edad del mensaje más viejo.** `oldest_msg_age_ms` es la latencia real que sufre el usuario final. La latencia del consumidor mide otra cosa: cuánto tarda en procesar uno, no cuánto esperó ese uno.
3. **Buscar la palabra «unbounded» en el código.** `Queue()` sin `maxsize`, `Channel.CreateUnbounded()`, `ConcurrentLinkedQueue`, `mpsc::channel()`. Todas son declaraciones explícitas de que no hay freno.
4. **Verificar si hay descarte silencioso.** Si la cola tiene límite pero nadie cuenta los rechazos, los mensajes desaparecen sin dejar rastro. `messages_dropped_total` tiene que existir aunque valga cero.
5. **Preguntar dónde está el freno.** Si no está en la cola, está en el kernel, en el broker o en el balanceador — pero está en algún lado. Conviene saber cuál antes de que lo muestre un incidente.

<!-- nav-case-doc -->
---

**Caso 15 · Backpressure en colas de mensajes** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · **🔍 Diagnóstico** · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
