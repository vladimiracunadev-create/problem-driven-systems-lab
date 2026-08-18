# 🔍 Diagnóstico

1. **Medir disponibilidad durante la migración, no después.** La métrica es `readers_failed` mientras el DDL corre. Un despliegue «exitoso» que rechazó el 40% del tráfico durante quince minutos no fue exitoso.
2. **Mirar la duración del lock más largo, no la total.** El trabajo total es el mismo en las dos variantes. Lo que decide si la app se cae es `longest_single_lock_ms`.
3. **Verificar si el motor soporta DDL online.** PostgreSQL agrega columnas nullable sin reescribir la tabla desde la 11; agregar una con `DEFAULT` no nullable en una versión anterior la reescribe entera.
4. **Contar filas en producción, no en staging.** La diferencia entre 200 ms y veinte minutos es el volumen, y staging casi nunca lo tiene.
5. **Preguntar por el orden del switch.** Si el plan borra la columna vieja antes de que el flag esté probado en producción, no hay vuelta atrás.

<!-- nav-case-doc -->
---

**Caso 17 · Migración de esquema sin downtime** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · **🔍 Diagnóstico** · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
