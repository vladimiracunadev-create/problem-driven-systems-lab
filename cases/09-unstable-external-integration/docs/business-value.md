# 💼 Valor de negocio

Mitiga dependencia de terceros y evita que un proveedor defina la estabilidad de tu producto.

<!-- catalogo -->

## 🎯 Resultado de negocio

> Permite razonar protecciones frente a dependencias externas que no controlamos.

## 🧾 Qué deja como prueba

- Contrasta /catalog-legacy y /catalog-hardened sobre drift de esquema, rate limit y maintenance window.
- Hace visible budget restante, schema mappings y uso de snapshot cacheado.
- Expone integration/state, sync-events y diagnostics/summary para seguir el desacople frente al tercero.

## 👀 Qué mirar al evaluarlo

- Si rate_limited deja 429 en legacy y continuidad con cache en hardened.
- Si schema_drift sube el adapter_version o los schema_mappings en vez de romper el flujo.
- Si quarantine_events y budget muestran mejor postura operacional en la variante endurecida.

> ℹ️ Estas tres secciones se mantienen sincronizadas con [`shared/catalog/cases.json`](../../../shared/catalog/cases.json), la misma fuente que alimenta al portal y al catálogo.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 09 · Integracion externa inestable** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · **💼 Valor de negocio** · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
