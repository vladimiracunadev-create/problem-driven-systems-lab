# ⚖️ Trade-offs

- **La tabla de idempotencia es estado nuevo que hay que operar.** Necesita ventana, limpieza y su propio dimensionamiento. Es menos trabajo que devolver cobros, pero no es gratis.
- **Guardar la respuesta cuesta más que guardar la clave.** Y es lo que hace que el reintento reciba lo mismo que el original en vez de un `409` que el cliente tiene que interpretar.
- **La ventana de dedupe es un compromiso, no un número correcto.** Corta deja pasar reintentos tardíos; larga hace crecer la tabla. 24 horas es una convención, no una verdad.
- **El outbox agrega latencia al efecto.** El email sale cuando el worker drena, no cuando se aplica el cargo. A cambio, no puede perderse.
- **La entrega del outbox es at-least-once, no exactly-once.** El worker puede entregar dos veces si muere después de enviar y antes de marcar. Es una decisión consciente: **duplicar un email es visible y corregible; perderlo, no**.
- **En memoria alcanza hasta que hay dos réplicas.** Es el trade-off más importante del caso, y el que menos se prueba antes de escalar.

<!-- nav-case-doc -->
---

**Caso 16 · Idempotencia y efectos duplicados** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · **⚖️ Trade-offs** · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
