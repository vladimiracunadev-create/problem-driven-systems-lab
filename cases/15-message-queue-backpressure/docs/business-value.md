# 💼 Valor de negocio

Convierte un reinicio inexplicable cada tantas horas —y una pérdida de datos que nadie sabía que existía— en una decisión explícita y documentada sobre qué se sacrifica cuando el sistema no da abasto.

<!-- catalogo -->

## 🎯 Resultado de negocio

> Evita caídas por memoria y pérdidas silenciosas de mensajes, y obliga a decidir explícitamente qué se sacrifica cuando el consumidor no da abasto.

## 🧾 Qué deja como prueba

- Contrasta `/produce-unbounded` y `/produce-bounded` con las tres políticas sobre la misma carga.
- Hace visible `queue_depth_peak`, `oldest_msg_age_ms_peak` y `queue_bytes_peak`: profundidad, latencia real y memoria.
- Expone `messages_dropped_total` y `dlq_depth` para que ninguna pérdida quede sin contar.

## 👀 Qué mirar al evaluarlo

- Si `queue_depth_peak` en la variante sin límite iguala al total producido: la cola absorbió todo.
- Si `oldest_msg_age_ms_peak` es 3 o 4 veces mayor sin límite que con límite, con el mismo throughput.
- Si cada política acotada paga algo distinto: `producer_blocked_ms` en `block`, `dropped` en `drop_oldest`, `dlq_depth` en `dead_letter`.

> ℹ️ Estas tres secciones se mantienen sincronizadas con [`shared/catalog/cases.json`](../../../shared/catalog/cases.json), la misma fuente que alimenta al portal y al catálogo.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 15 · Backpressure en colas de mensajes** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · **💼 Valor de negocio** · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
