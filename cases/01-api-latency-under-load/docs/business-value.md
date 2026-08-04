# 💼 Valor de negocio

Resolver este patrón tiene impacto directo en negocio y operación:

- evita sobredimensionar infraestructura antes de tiempo,
- mejora experiencia de usuarios de reportes,
- reduce riesgo durante procesos críticos concurrentes,
- habilita decisiones con evidencia antes/después,
- muestra criterio senior: no solo “hacer que responda”, sino hacer que conviva con la operación real.

<!-- catalogo -->

## 🎯 Resultado de negocio

> Reduce latencia visible y evita sobredimensionar infraestructura a ciegas.

## 🧾 Qué deja como prueba

- Compara /report-legacy y /report-optimized con latencia, p95 y queries promedio.
- Mantiene un worker concurrente que refresca la tabla resumen sin esconder la presion real del sistema.
- Exporta metricas locales y tambien Prometheus/Grafana para mostrar evidencia antes y despues.

## 👀 Qué mirar al evaluarlo

- Diferencia entre legacy y optimized en avg_ms, p95_ms y max_ms.
- Cambio real en avg_db_queries y avg_db_time_ms cuando se usa la tabla resumen.
- Actividad del worker y costo de refresco en batch/status, job-runs y diagnostics/summary.

> ℹ️ Estas tres secciones se mantienen sincronizadas con [`shared/catalog/cases.json`](../../../shared/catalog/cases.json), la misma fuente que alimenta al portal y al catálogo.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 01 · API lenta bajo carga** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · **💼 Valor de negocio** · [🔭 Observabilidad](observability.md) · [📈 Benchmarking](benchmarking.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
