# 🗺️ Contexto

La aplicación guarda un documento en la base de datos y después lo manda al índice de búsqueda. Dos escrituras, dos sistemas, **ninguna transacción que las ate**.

Cuando la segunda falla, el usuario no ve un error: ve una búsqueda que responde 200. Solo que lo que devuelve está mal.

## Justificación

Este caso no rompe nada. Esa es toda su dificultad.

Un servicio caído dispara alertas. Un servicio lento dispara alertas. Un índice de búsqueda que devuelve el 98,9% de lo que debería devolver **no dispara nada**: responde rápido, responde 200, y devuelve resultados que se ven perfectamente razonables.

La deriva se acumula de a un documento por vez, y se descubre por el camino más caro: un cliente que llama porque su producto no aparece.

## Las tres caras, que no son la misma cosa

| Cara | Qué es | Qué ve el usuario |
|---|---|---|
| `missing` | Está en la base, no en el índice | **No lo encuentra** |
| `stale` | Está en los dos, con versión vieja | **Lo encuentra mal** — precio viejo, título viejo |
| `orphan` | Está en el índice, borrado en la base | **Fantasmas** — clic en un resultado que da 404 |

Se ven igual desde afuera —«la búsqueda anda rara»— y se arreglan distinto. Un reindexado completo arregla `missing` y `stale`; si no borra lo que sobra, no toca `orphan`.

## Los tres mecanismos de la corrección, y por qué hacen falta los tres

1. **Outbox** — el cambio se anota **junto con** la escritura a la base, en la misma transacción. Si el índice está caído, el cambio no se pierde: queda escrito.
2. **Checkpoint** — el consumidor aplica en orden y solo lo avanza cuando la aplicación se confirma. Un cambio que no entra queda **pendiente**, no perdido.
3. **Reconciliación** — un barrido compara los dos lados y repara. Es la red de seguridad de lo que los dos primeros no cubren: un índice restaurado de un backup viejo, una reindexación parcial, un borrado manual.

El outbox garantiza que ningún cambio **nuevo** se pierda. No arregla los que ya se perdieron. Por eso el barrido no es opcional.

<!-- nav-case-doc -->
---

**Caso 19 · Deriva del índice de búsqueda y CDC roto** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

**🗺️ Contexto** · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
