# 🩺 Síntomas

## Lo que se ve desde afuera

- **Un cliente llama** porque su producto no aparece en la búsqueda. Existe en la base.
- Un resultado de búsqueda que lleva a un **404**: el documento ya no está.
- Precios, títulos o estados **viejos** en los resultados, correctos al abrir el detalle.
- «La búsqueda anda rara» como reporte recurrente, sin ninguna reproducción confiable.
- Un reindexado completo «lo arregla» — durante unos días.

## Lo que se ve en las métricas

Casi nada, y ese es el problema:

- El servicio de búsqueda responde **200 con latencia normal**.
- El error rate del índice está en cero: **las escrituras que fallaron ya no se cuentan**.
- El consumidor de CDC muestra lag cero, porque avanzó su offset igual.
- Los contadores de documentos —si alguien los compara— difieren en unos pocos por mil.

## Lo que hace difícil verlo

**Nada está roto.** No hay excepción, no hay caída, no hay latencia. El único síntoma es una diferencia entre dos números que casi nunca están en el mismo dashboard: cuántos documentos hay en la base y cuántos en el índice.

Y hay una trampa adicional: la deriva es **acumulativa y lenta**. Un 0,5% de escrituras perdidas por día no se nota en una semana. Se nota a los seis meses, cuando alguien pregunta por qué la búsqueda «empeoró».

<!-- nav-case-doc -->
---

**Caso 19 · Deriva del índice de búsqueda y CDC roto** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · **🩺 Síntomas** · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
