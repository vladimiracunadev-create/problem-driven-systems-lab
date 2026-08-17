# 💼 Valor de negocio

Convierte una caída nocturna recurrente e inexplicable en un número que se puede mostrar en una reunión: cuántas veces se ejecuta el trabajo caro cuando la cache deja de proteger.

<!-- catalogo -->

## 🎯 Resultado de negocio

> Evita caídas autoinfligidas en el momento de mayor fragilidad del sistema y reduce el costo de infraestructura del origen.

## 🧾 Qué deja como prueba

- Contrasta `/cache-naive` y `/cache-singleflight` con `origin_computations` sobre la misma ráfaga.
- Hace visible `stampede_depth`, `coalesced_waiters` y `served_stale` en cada ejecución.
- Expone `cache/state` con soft TTL, hard TTL y el jitter aplicado por clave.

## 👀 Qué mirar al evaluarlo

- Si `origin_computations` sube linealmente con `concurrency` en la variante naive y se queda en 1 en la corregida.
- Si `coalesced_waiters` da `concurrency - 1`: es la prueba de que el resto se colgó del mismo recálculo.
- Si el double check dentro del vuelo está presente en los siete stacks — sin él, el patrón da 3 o 4 recálculos en vez de 1.

> ℹ️ Estas tres secciones se mantienen sincronizadas con [`shared/catalog/cases.json`](../../../shared/catalog/cases.json), la misma fuente que alimenta al portal y al catálogo.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 13 · Cache stampede y thundering herd** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · **💼 Valor de negocio** · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
