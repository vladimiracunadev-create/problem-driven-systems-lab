# 💼 Valor de negocio

Permite renovar plataformas reales sin detener operación ni asumir una reescritura total de alto riesgo.

<!-- catalogo -->

## 🎯 Resultado de negocio

> Permite modernizar un monolito sin convertir cada cambio en una reescritura riesgosa.

## 🧾 Qué deja como prueba

- Contrasta /change-legacy y /change-strangler sobre shared_schema, billing_change y trabajo paralelo.
- Hace visible blast_radius_score, risk_score y progreso de migracion por consumidor.
- Expone migration/state, flows y diagnostics/summary para seguir cobertura extraida, contratos y releases.

## 👀 Qué mirar al evaluarlo

- Si shared_schema falla en legacy y queda contenido en strangler.
- Si consumers, contract_tests y extracted_module_coverage suben al ejecutar la ruta incremental.
- Si diagnostics/summary muestra menos riesgo por cambio y menor radio de impacto.

> ℹ️ Estas tres secciones se mantienen sincronizadas con [`shared/catalog/cases.json`](../../../shared/catalog/cases.json), la misma fuente que alimenta al portal y al catálogo.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 07 · Modernizacion incremental de monolito** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · **💼 Valor de negocio** · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
