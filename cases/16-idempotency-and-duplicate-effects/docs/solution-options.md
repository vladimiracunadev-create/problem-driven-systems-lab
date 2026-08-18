# 🛠️ Opciones de solución

| Opción | Qué resuelve | Qué cuesta |
|---|---|---|
| **`Idempotency-Key` + reserva atómica** | El duplicado de raíz: `charges_applied` queda en 1 | Un lookup por request y una tabla que hay que mantener |
| **Respuesta cacheada por clave** | El reintento recibe exactamente lo mismo que el original, sin interpretar errores | Guardar el cuerpo de la respuesta, no solo la clave |
| **Ventana de deduplicación (24 h)** | Evita que la tabla crezca sin techo | Un reintento más tardío que la ventana se procesa como nuevo |
| **Outbox pattern** | El efecto que cruza el boundary no puede desincronizarse del cargo | Un worker más y latencia de entrega |
| **Clave derivada del contenido** | Funciona aunque el cliente no mande cabecera | Dos operaciones legítimamente idénticas se confunden con un duplicado |

Las tres primeras son el mismo mecanismo. El **outbox** es independiente: resuelve la otra mitad del problema.

<!-- nav-case-doc -->
---

**Caso 16 · Idempotencia y efectos duplicados** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · **🛠️ Opciones de solución** · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
