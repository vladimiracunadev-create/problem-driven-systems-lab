# 🩺 Síntomas

- El proceso consume **memoria de forma monótona** y el OOM killer lo mata cada tantas horas
- Throughput alto y sin errores, pero los resultados que llegan al usuario están **minutos atrasados**
- El gráfico de latencia del consumidor se ve sano: mide lo que tarda en procesar **un** mensaje, no lo que el mensaje esperó antes
- Los mensajes se pierden sin ninguna traza: no hay error, no hay log, no hay métrica
- Reiniciar el servicio «arregla» la memoria y **pierde todo lo que había en la cola**
- Bajo pico, el consumidor sigue procesando a la misma velocidad — porque el problema nunca fue el consumidor

<!-- nav-case-doc -->
---

**Caso 15 · Backpressure en colas de mensajes** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · **🩺 Síntomas** · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
