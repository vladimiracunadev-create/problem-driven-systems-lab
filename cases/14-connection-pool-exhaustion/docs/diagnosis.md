# 🔍 Diagnóstico

1. **Contar adquisiciones contra devoluciones.** Es la prueba definitiva y cabe en dos contadores: `acquired - released`. Si el número crece de forma monótona, hay fuga. No hace falta un profiler.
2. **Distinguir pool ocupado de pool vacío.** Son dos estados distintos que la misma métrica muestra igual. `available == 0` con `leaked == 0` es saturación legítima; `available == 0` con `leaked > 0` es una fuga.
3. **Buscar los caminos de salida sin devolución.** Cada `return`, `continue`, `break` o `throw` entre el `acquire` y el `release` es una fuga potencial. La pregunta no es «¿devuelvo la conexión?» sino «¿la devuelvo en **todos** los caminos?».
4. **Verificar que la adquisición tenga deadline.** Sin timeout, el que llega tarde no falla: se queda. Eso convierte un problema de capacidad en una indisponibilidad silenciosa.
5. **Dimensionar con la ley de Little, no a ojo.** `pool_size = throughput × tiempo_de_servicio + buffer`. Un pool de 100 para 5 req/s con queries de 20 ms no es «por las dudas»: son 99 conexiones ociosas que la base tiene que sostener igual.

<!-- nav-case-doc -->
---

**Caso 14 · Agotamiento del pool de conexiones** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · **🔍 Diagnóstico** · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
