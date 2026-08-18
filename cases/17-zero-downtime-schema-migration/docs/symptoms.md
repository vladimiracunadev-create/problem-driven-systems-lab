# 🩺 Síntomas

- La aplicación devuelve **503 durante toda la ventana de despliegue**, y vuelve sola cuando la migración termina
- El healthcheck sigue en verde: **el proceso está vivo**, lo que falla son las peticiones
- El `pg_locks` (o equivalente) muestra un lock `AccessExclusiveLock` sostenido sobre una sola tabla
- La cola de conexiones a la base crece hasta agotar el pool ([caso 14](../../14-connection-pool-exhaustion/README.md))
- El equipo programa los despliegues «para la madrugada», que es la forma de convivir con el problema en vez de resolverlo
- Un `ALTER TABLE` que en staging tardó 200 ms tarda veinte minutos en producción, porque staging tiene mil filas y producción dos millones

<!-- nav-case-doc -->
---

**Caso 17 · Migración de esquema sin downtime** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · **🩺 Síntomas** · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
