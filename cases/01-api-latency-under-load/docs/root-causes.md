# 🧠 Causas raíz del problema

## Ruta legacy
- usa la tabla `orders` directamente para agregación,
- filtra con `DATE(created_at)` y desperdicia el índice temporal,
- genera consultas adicionales por cliente (N+1),
- devuelve detalle reciente por cliente como parte del mismo request.

## Presión concurrente
- el worker recalcula la tabla `customer_daily_summary` periódicamente,
- esa operación toca el mismo origen transaccional,
- si el diseño de lectura ya es costoso, la convivencia empeora.

## Lectura optimizada
La corrección no se basa en “esperar menos”, sino en cambiar estrategia:
- lectura contra tabla resumen,
- menor número de consultas,
- payload más contenido,
- mejor separación entre transacción y reporte.

<!-- nav-case-doc -->
---

**Caso 01 · API lenta bajo carga** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · **🧠 Causas raíz** · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🔭 Observabilidad](observability.md) · [📈 Benchmarking](benchmarking.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
