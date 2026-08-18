# 💼 Valor de negocio

Convierte «hay que desplegar a las 3 de la mañana» en «se despliega cuando esté listo», y elimina la ventana de indisponibilidad programada que el negocio venía aceptando como inevitable.

<!-- catalogo -->

## 🎯 Resultado de negocio

> Permite cambiar el esquema de una tabla caliente sin ventana de mantenimiento ni 503 para el usuario final.

## 🧾 Qué deja como prueba

- Contrasta `/migrate-blocking` y `/migrate-expand-contract` con `availability_pct` medida **durante** la migración.
- Hace visible `longest_single_lock_ms`: la métrica que decide si la app se cae, distinta del tiempo total.
- Expone `/migration/state` con la fase actual, el progreso del backfill y el estado del feature flag.

## 👀 Qué mirar al evaluarlo

- Si `readers_failed` es mayor que cero en la variante bloqueante y exactamente cero en la corregida.
- Si `longest_single_lock_ms` baja de la duración entera de la migración a la de un solo lote.
- Si el `lock_held_ms` total es parecido en las dos: **el trabajo no desaparece, se reparte**.

> ℹ️ Estas tres secciones se mantienen sincronizadas con [`shared/catalog/cases.json`](../../../shared/catalog/cases.json), la misma fuente que alimenta al portal y al catálogo.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 17 · Migración de esquema sin downtime** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · **💼 Valor de negocio** · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
