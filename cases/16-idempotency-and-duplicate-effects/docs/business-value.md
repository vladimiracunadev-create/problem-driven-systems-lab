# 💼 Valor de negocio

Convierte cobros duplicados —que se devuelven de a uno, con costo de soporte y de reputación— en un número que se puede poner en un panel: cuántos duplicados se evitaron.

<!-- catalogo -->

## 🎯 Resultado de negocio

> Elimina cobros y notificaciones duplicadas por reintentos del cliente, y evita el costo de soporte y devolución que cada uno genera.

## 🧾 Qué deja como prueba

- Contrasta `/charge-unsafe` y `/charge-idempotent` sobre los mismos N reintentos de una misma clave.
- Hace visible `overcharged_cents`: la plata que el negocio tendría que devolver, en la unidad en que se discute.
- Expone `/outbox` con pendientes y entregados, para ver que el efecto que cruza el boundary no se desincroniza del cargo.

## 👀 Qué mirar al evaluarlo

- Si `charges_applied` es igual a `attempts` sin clave y exactamente 1 con ella.
- Si `duplicates_prevented` da `attempts - 1`: es la prueba de que los reintentos se reconocieron como tales.
- Si `side_effects_emitted` baja de N a 1, y el transporte pasa de directo a outbox.

> ℹ️ Estas tres secciones se mantienen sincronizadas con [`shared/catalog/cases.json`](../../../shared/catalog/cases.json), la misma fuente que alimenta al portal y al catálogo.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 16 · Idempotencia y efectos duplicados** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · **💼 Valor de negocio** · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
