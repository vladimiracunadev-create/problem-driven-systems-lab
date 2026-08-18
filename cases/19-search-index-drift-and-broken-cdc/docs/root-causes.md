# 🧠 Causas raíz

## 1. Dual-write sin transacción común

La causa estructural. La base y el índice son **dos sistemas distintos**, y no hay forma de escribirlos atómicamente sin two-phase commit —que casi nadie quiere— o sin un outbox. Cualquier código que escriba primero uno y después el otro tiene una ventana donde los dos difieren.

## 2. El error de la segunda escritura, ignorado

La causa inmediata, y la que cada lenguaje trata distinto:

| Stack | Cómo se ignora | Qué lo impide |
|---|---|---|
| 🦀 Rust | `let _ = escribir()` | **`#[must_use]`** avisa; `deny(unused_must_use)` no compila |
| 🐹 Go | `_ = Escribir()` | **`errcheck`** en CI marca la versión sin `_ =` |
| 🐍 Python | `except: pass` | Nada |
| ☕ Java | `catch (Exception e) {}` | Nada |
| 🔵 .NET | `catch {}` o `_ = IndexarAsync()` | Nada |
| 🐘 PHP | `@$indice->escribir()` | Nada |
| 🟢 Node | **no escribir `await`** | `no-floating-promises`, si está puesta |

Node merece el subrayado: es el único donde el bug se produce **por no escribir algo**. En los otros seis hay que escribir el silencio a propósito.

## 3. El consumidor de CDC que avanza su offset sin confirmar

Un consumidor que hace *commit* del offset antes de aplicar el cambio —o que lo hace en un `finally`— pierde exactamente los mensajes que fallaron. Y como el lag queda en cero, el dashboard dice que todo está al día.

## 4. Reindexado sin borrar

Reindexar «desde cero» leyendo la base y escribiendo al índice arregla `missing` y `stale`, y **no toca `orphan`**: lo que sobra sigue ahí, porque nadie lo miró. Es la razón por la que un reindexado completo puede dejar la búsqueda con fantasmas.

## 5. Ninguna reconciliación agendada

La causa de fondo: **nadie compara los dos lados a propósito**. Sin un barrido periódico que cuente y repare, la única forma de descubrir la deriva es que un cliente la reporte.

> En PHP esto es menos probable por una razón estructural: en un runtime share-nothing el consumidor **es** un comando de cron, así que el barrido y el checkpoint durable son la forma natural de escribirlo. En los stacks con procesos de larga vida es tentador dejar el checkpoint en memoria — hasta el primer reinicio.

<!-- nav-case-doc -->
---

**Caso 19 · Deriva del índice de búsqueda y CDC roto** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · **🧠 Causas raíz** · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
