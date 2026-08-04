# 💼 Valor de negocio

Disminuye incidentes silenciosos, reinicios inesperados y consumo innecesario de infraestructura.

<!-- catalogo -->

## 🎯 Resultado de negocio

> Sirve para razonar estabilidad, limites de recursos y degradacion progresiva antes del colapso.

## 🧾 Qué deja como prueba

- Compara /batch-legacy y /batch-optimized con estado acumulado entre requests.
- Hace visible retained_kb, descriptor_pressure y pressure_level en el tiempo.
- Expone state, runs y diagnostics/summary para seguir la degradacion progresiva.

## 👀 Qué mirar al evaluarlo

- Si retained_kb sube en legacy tras varias ejecuciones y se mantiene acotado en optimized.
- Si pressure_level cambia de healthy a warning o critical en el modo defectuoso.
- Si peak_request_kb y retained_after_kb cuentan una historia coherente del leak.

> ℹ️ Estas tres secciones se mantienen sincronizadas con [`shared/catalog/cases.json`](../../../shared/catalog/cases.json), la misma fuente que alimenta al portal y al catálogo.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 05 · Presion de memoria y fugas de recursos** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · **💼 Valor de negocio** · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
