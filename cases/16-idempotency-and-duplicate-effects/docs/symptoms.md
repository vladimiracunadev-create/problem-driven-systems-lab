# 🩺 Síntomas

- Clientes que reportan **cobros duplicados** por la misma operación, y el log muestra que pidieron dos veces
- Emails de confirmación enviados dos o tres veces por el mismo evento
- El duplicado aparece **más seguido cuando el sistema está lento**: más timeouts, más reintentos
- Los importes cuadran en el agregado diario pero no por cliente
- Un botón «pagar» que la gente aprieta dos veces produce dos pagos, y el equipo lo atribuye al usuario
- Después de escalar de uno a dos pods, empiezan a aparecer duplicados que antes no había

<!-- nav-case-doc -->
---

**Caso 16 · Idempotencia y efectos duplicados** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · **🩺 Síntomas** · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
