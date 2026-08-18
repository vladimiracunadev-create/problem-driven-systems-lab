# ⚖️ Trade-offs

- **Expand-contract tarda más en total, y es correcto.** Las pausas entre lotes son tiempo agregado a propósito. Optimizar la duración total es optimizar la métrica equivocada.
- **Convivir con dos columnas tiene costo.** Durante la transición el código escribe en las dos y lee de una. Es deuda temporal, y hay que agendar el contract o se vuelve permanente.
- **Cuatro despliegues en vez de uno.** Más coordinación, más pasos que pueden salir mal — a cambio de que ninguno tumbe la aplicación.
- **El lote chico no siempre es mejor.** Lotes muy pequeños multiplican el overhead de transacción y pueden hacer que el backfill no termine nunca. Hay un punto medio y depende del motor.
- **El feature flag es código que hay que borrar.** Un flag que se queda para siempre es una rama muerta que alguien va a tener que entender dentro de dos años.
- **El backfill compite con el tráfico real.** Aunque suelte el lock, sigue consumiendo I/O del motor. En hora pico eso se nota aunque nadie reciba un 503.

<!-- nav-case-doc -->
---

**Caso 17 · Migración de esquema sin downtime** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · **⚖️ Trade-offs** · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
