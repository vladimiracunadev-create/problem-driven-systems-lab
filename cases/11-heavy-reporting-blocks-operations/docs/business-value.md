# 💼 Valor de negocio

Protege la operación diaria y permite crecer en analítica sin romper el sistema transaccional.

<!-- catalogo -->

## 🎯 Resultado de negocio

> Permite discutir aislamiento de cargas, reporting y proteccion de flujos operativos.

## 🧾 Qué deja como prueba

- Contrasta /report-legacy y /report-isolated sobre la misma presion operativa.
- Hace visible primary_load, lock_pressure, replica_lag_s y queue_depth.
- Expone /order-write, reporting/state y diagnostics/summary para comprobar si la operacion conserva aire.

## 👀 Qué mirar al evaluarlo

- Si mixed_peak deja 503 en report-legacy y mantiene ordenes vivas cuando se usa la ruta aislada.
- Si reporting/state cambia de healthy a warning o critical tras el export pesado.
- Si order-write cuenta una historia coherente del choque entre analitica y transaccionalidad.

> ℹ️ Estas tres secciones se mantienen sincronizadas con [`shared/catalog/cases.json`](../../../shared/catalog/cases.json), la misma fuente que alimenta al portal y al catálogo.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 11 · Reportes pesados que bloquean la operacion** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · **💼 Valor de negocio** · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
