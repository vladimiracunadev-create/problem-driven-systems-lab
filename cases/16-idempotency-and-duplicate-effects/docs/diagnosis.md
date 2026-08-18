# 🔍 Diagnóstico

1. **Contar cargos por operación, no operaciones.** La métrica es `charges_applied` sobre un mismo `Idempotency-Key`. Si da más de 1, hay duplicación.
2. **Buscar la ventana check-then-act.** `if (!table.containsKey(k)) table.put(k, v)` son dos operaciones con un hueco en el medio. La versión correcta es una sola: `putIfAbsent`, `TryAdd`, `LoadOrStore`, `entry()`, `INSERT ... ON CONFLICT`.
3. **Preguntar dónde vive la tabla de idempotencia.** Si vive en el heap del proceso, funciona con una réplica y deja de funcionar con dos. Ese bug no aparece al escribir el código: aparece al escalar.
4. **Verificar que el reintento reciba la misma respuesta.** Un reintento que recibe un `409 Conflict` obliga al cliente a interpretar un error; uno que recibe la respuesta original no tiene que interpretar nada.
5. **Separar el efecto local del que cruza el boundary.** El cargo y el email no pueden estar en la misma transacción si viven en sistemas distintos. Si no hay outbox, hay una ventana donde uno existe y el otro no.

<!-- nav-case-doc -->
---

**Caso 16 · Idempotencia y efectos duplicados** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · **🔍 Diagnóstico** · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
