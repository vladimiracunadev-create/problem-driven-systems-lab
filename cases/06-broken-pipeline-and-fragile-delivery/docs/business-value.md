# 💼 Valor de negocio

Permite publicar con menos riesgo, reducir incidentes y mejorar la velocidad real de entrega.

<!-- catalogo -->

## 🎯 Resultado de negocio

> Ayuda a reducir riesgo en despliegues y a fortalecer rollback, promotion y entrega continua.

## 🧾 Qué deja como prueba

- Contrasta /deploy-legacy y /deploy-controlled sobre los mismos escenarios de riesgo.
- Hace visible cuando un pipeline falla tarde y deja el ambiente degradado versus cuando bloquea en preflight o revierte.
- Expone environments, deployments y diagnostics/summary para seguir salud por ambiente.

## 👀 Qué mirar al evaluarlo

- Si controlled bloquea missing_secret o config_drift antes de tocar staging o prod.
- Si failing_smoke deja rollback automatico en controlled y ambiente degradado en legacy.
- Si el historial por ambiente muestra diferencia real entre detectar tarde y contener temprano.

> ℹ️ Estas tres secciones se mantienen sincronizadas con [`shared/catalog/cases.json`](../../../shared/catalog/cases.json), la misma fuente que alimenta al portal y al catálogo.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 06 · Pipeline roto y entrega fragil** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · **💼 Valor de negocio** · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
