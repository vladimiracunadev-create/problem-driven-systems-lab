# 💼 Valor de negocio

Evita caídas en cascada y mejora resiliencia frente a terceros o componentes inestables.

<!-- catalogo -->

## 🎯 Resultado de negocio

> Ayuda a reducir fallas en cascada y a disenar limites mas sanos de timeout, retry y backoff.

## 🧾 Qué deja como prueba

- Contrasta /quote-legacy y /quote-resilient sobre el mismo proveedor simulado.
- Hace visible el costo de retries agresivos, timeouts repetidos y respuestas degradadas con fallback.
- Expone dependency/state, incidents y diagnostics/summary para leer el comportamiento del circuit breaker.

## 👀 Qué mirar al evaluarlo

- Si legacy tarda mas y consume mas intentos ante el mismo escenario provider_down.
- Si resilient abre circuito, usa fallback y contiene la degradacion.
- Si diagnostics/summary deja claro cuando la carga se amplifica y cuando queda contenida.

> ℹ️ Estas tres secciones se mantienen sincronizadas con [`shared/catalog/cases.json`](../../../shared/catalog/cases.json), la misma fuente que alimenta al portal y al catálogo.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 04 · Cadena de timeouts y tormentas de reintentos** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · **💼 Valor de negocio** · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
