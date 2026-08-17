# 💼 Valor de negocio

Convierte una indisponibilidad que nadie sabe explicar —el servicio no responde, la base está bien— en dos contadores que cualquiera puede leer: cuántas conexiones se pidieron y cuántas volvieron.

<!-- catalogo -->

## 🎯 Resultado de negocio

> Evita indisponibilidades progresivas que reinician el servicio como único remedio, y dimensiona el pool con una fórmula en vez de con intuición.

## 🧾 Qué deja como prueba

- Contrasta `/pool-leaky` y `/pool-managed` con `leaked` y `hung` sobre la misma carga.
- Hace visible `pool_available_after`: si al terminar el pool no volvió a su tamaño, hubo fuga.
- Expone `littles_law` con el tamaño de pool recomendado calculado desde el throughput medido, no estimado.

## 👀 Qué mirar al evaluarlo

- Si `leaked` es exactamente el número de fallos en la variante leaky, y `0` en la corregida.
- Si `hung` en leaky crece hasta consumir el resto de la carga: es el pool agotado en vivo.
- Si `failed_timeout` en la variante corregida reemplaza a `hung`: el mismo problema de capacidad, pero contable.

> ℹ️ Estas tres secciones se mantienen sincronizadas con [`shared/catalog/cases.json`](../../../shared/catalog/cases.json), la misma fuente que alimenta al portal y al catálogo.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 14 · Agotamiento del pool de conexiones** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · **💼 Valor de negocio** · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
