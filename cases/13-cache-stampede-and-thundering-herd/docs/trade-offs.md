# ⚖️ Trade-offs

- **Single-flight serializa.** Si el origen es lento y la clave es muy caliente, los que esperan forman una cola. El soft TTL es lo que evita que esa cola se note.
- **Servir stale es una decisión de producto, no de ingeniería.** Un saldo bancario viejo por 2 segundos puede ser inaceptable; un contador de visitas viejo por 30 segundos no le importa a nadie. La ventana soft se define con quien es dueño del dato.
- **El jitter hace que el TTL deje de ser exacto.** Si algo dependía de que el valor viviera exactamente 5 minutos, ya no. Casi nunca importa, pero hay que saberlo.
- **El lock agrega un punto de falla.** Un lock distribuido mal liberado bloquea la clave hasta que expire su propio TTL. El lock necesita su propio timeout.
- **Coordinar dentro del proceso no alcanza si hay N procesos.** El `Map` en memoria de un runtime resuelve la estampida de ese proceso; con 20 réplicas quedan 20 recálculos en vez de 2000. Mejor, pero no 1.

<!-- nav-case-doc -->
---

**Caso 13 · Cache stampede y thundering herd** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · **⚖️ Trade-offs** · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
