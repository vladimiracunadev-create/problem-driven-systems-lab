# 🧠 Causas raíz

- **Ausencia de single-flight.** Nada coordina a los N llamadores que ven el mismo hueco. Cada uno asume que es el único.
- **TTL fijo sin jitter.** Las claves cargadas juntas expiran juntas. El deploy que llena la cache define, sin querer, el momento exacto de la próxima caída.
- **Un solo estado de validez.** Sin soft TTL no hay forma de servir un valor viejo mientras se refresca: alguien tiene que esperar al origen sí o sí.
- **Lock sin double-checked locking.** El error más común al intentar arreglarlo. Se agrega el lock, se mide, y el origen sigue recibiendo N consultas — ahora secuenciales. Falta volver a leer la cache **dentro** del lock.
- **Refresco síncrono en el camino del request.** Recalcular en el hilo que atiende al usuario convierte un problema de cache en un problema de latencia visible.

<!-- nav-case-doc -->
---

**Caso 13 · Cache stampede y thundering herd** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · **🧠 Causas raíz** · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
