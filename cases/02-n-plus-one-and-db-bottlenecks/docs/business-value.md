# 💼 Valor de negocio

Una base sana evita incidentes recurrentes, mejora rendimiento transversal y reduce costos de hardware/licenciamiento.

<!-- catalogo -->

## 🎯 Resultado de negocio

> Reduce round-trips, costo por request y desgaste innecesario sobre la base de datos.

## 🧾 Qué deja como prueba

- Contrasta /orders-legacy y /orders-optimized sobre la misma base relacional y los mismos datos semilla.
- Mide cuantas queries y cuanto tiempo de DB consume cada request.
- Entrega diagnostics/summary con densidad relacional para explicar por que el problema escala mal.

## 👀 Qué mirar al evaluarlo

- Cuantas consultas hace la ruta legacy frente a la optimized para el mismo limit y rango de dias.
- Como cae db_time_ms_in_request cuando la lectura se consolida.
- Si diagnostics/summary deja claro por que la cardinalidad de items empeora el patron N+1.

> ℹ️ Estas tres secciones se mantienen sincronizadas con [`shared/catalog/cases.json`](../../../shared/catalog/cases.json), la misma fuente que alimenta al portal y al catálogo.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 02 · N+1 queries y cuellos de botella en base de datos** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · **💼 Valor de negocio** · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
