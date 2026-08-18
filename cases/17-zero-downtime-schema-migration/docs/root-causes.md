# 🧠 Causas raíz

- **DDL bloqueante sobre una tabla caliente.** El motor toma el lock exclusivo y lo mantiene hasta terminar; no hay nada que la aplicación pueda hacer mientras tanto.
- **Migración en un solo paso.** Agregar la columna, rellenarla y cambiar el código en la misma operación obliga a que todo entre en la misma ventana de lock.
- **Sin feature flag.** Sin un interruptor que separe «la columna existe» de «la aplicación la usa», el cambio de esquema y el de comportamiento van juntos y no se pueden revertir por separado.
- **Backfill sin pausas.** Un backfill por lotes sin `sleep` entre ellos es un `ALTER TABLE` largo escrito en pedazos: el motor nunca recupera aire.
- **Validado contra un dataset de juguete.** La migración que tardó 200 ms en staging con mil filas tarda veinte minutos con dos millones, y eso solo se descubre en producción.

<!-- nav-case-doc -->
---

**Caso 17 · Migración de esquema sin downtime** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · **🧠 Causas raíz** · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
