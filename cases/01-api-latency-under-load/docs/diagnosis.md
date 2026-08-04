# 🔍 Diagnóstico

## Hipótesis principal
La latencia no proviene de una espera artificial, sino de una combinación de decisiones técnicas reales:

1. filtro no sargable (`DATE(created_at)`),
2. patrón N+1 para enriquecer la respuesta,
3. agregación sobre tabla transaccional bajo carga,
4. proceso crítico concurrente refrescando resumen.

## Cómo diagnosticar
1. llamar `/report-legacy` con carga concurrente,
2. revisar `/metrics` y `/batch/status`,
3. repetir contra `/report-optimized`,
4. comparar latencia y estabilidad,
5. revisar duración del worker para correlacionar efecto de contención.

## Qué evidencia deja el caso
- tiempos de request en la app,
- duración del refresh del worker,
- estado de heartbeat del proceso crítico,
- diferencia entre una estrategia legacy y una optimizada.

<!-- nav-case-doc -->
---

**Caso 01 · API lenta bajo carga** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · **🔍 Diagnóstico** · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🔭 Observabilidad](observability.md) · [📈 Benchmarking](benchmarking.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
