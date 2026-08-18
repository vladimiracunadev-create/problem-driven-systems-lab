# 🧠 Causas raíz

- **Operaciones no idempotentes con retries automáticos.** El cliente, el proxy o el balanceador reintentan; el servidor no distingue el reintento del pedido nuevo.
- **Check-then-act en vez de una operación atómica.** La ventana entre mirar y escribir es todo el bug, y el código se ve razonable en la review.
- **Tabla de idempotencia en memoria del proceso.** Correcta con una réplica, incorrecta con dos — y el salto de una a dos casi nunca se prueba.
- **Efecto lateral fuera de la transacción.** El cargo en la base y el email en la cola, sin nada que los ate. Si el proceso muere en el medio, quedan desincronizados.
- **Sin ventana de deduplicación.** Una clave que vive para siempre es una tabla que crece para siempre; una que caduca demasiado rápido deja pasar reintentos tardíos.

<!-- nav-case-doc -->
---

**Caso 16 · Idempotencia y efectos duplicados** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · **🧠 Causas raíz** · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
