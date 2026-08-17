# 🩺 Síntomas

- «Could not get connection from pool» con **tráfico moderado**, no en un pico
- La base de datos está sana: CPU bajo, pocas conexiones activas, sin queries lentas en el `pg_stat_activity`
- El error aparece **más seguido con el correr de los días** y desaparece al reiniciar el servicio
- Requests que no fallan ni responden: se quedan colgados hasta el timeout del cliente
- El gráfico de conexiones activas del pool baja en escalones y **nunca vuelve a subir**
- Reiniciar arregla el síntoma por unas horas, lo que confirma que el estado se acumula en el proceso

<!-- nav-case-doc -->
---

**Caso 14 · Agotamiento del pool de conexiones** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · **🩺 Síntomas** · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
