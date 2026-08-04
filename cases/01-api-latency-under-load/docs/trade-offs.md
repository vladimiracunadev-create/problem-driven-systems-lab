# ⚖️ Trade-offs

La versión optimizada mejora latencia y estabilidad, pero introduce costos reales:

- tabla extra,
- proceso worker adicional,
- posible desfase temporal entre transacción y resumen,
- más disciplina operativa.

Eso es intencional: el laboratorio no pretende vender magia, sino mostrar que casi toda mejora seria también trae decisiones de mantenimiento y consistencia.

<!-- nav-case-doc -->
---

**Caso 01 · API lenta bajo carga** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · **⚖️ Trade-offs** · [💼 Valor de negocio](business-value.md) · [🔭 Observabilidad](observability.md) · [📈 Benchmarking](benchmarking.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
