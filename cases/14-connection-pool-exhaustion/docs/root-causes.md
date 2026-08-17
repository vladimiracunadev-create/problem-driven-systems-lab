# 🧠 Causas raíz

- **Devolución solo en el camino feliz.** El `release()` está después del trabajo, no en un `finally`. Toda excepción se lleva una conexión, y no hay ningún log que lo diga.
- **Sin timeout de adquisición.** El `take()` bloqueante convierte «no hay capacidad» en «este request no responde nunca». Es la diferencia entre un 503 contable y una indisponibilidad invisible.
- **Pool dimensionado por intuición.** Muy chico satura con tráfico normal; muy grande traslada el problema al `max_connections` de la base.
- **Queries lentas que retienen más de lo previsto.** El mismo pool que alcanzaba con 20 ms de query no alcanza con 200 ms. El pool no cambió: cambió el tiempo de servicio.
- **Transacciones abiertas que sobreviven al request.** La conexión vuelve al pool pero con estado sucio, y el siguiente que la toma hereda un `BEGIN` que nadie cerró.

<!-- nav-case-doc -->
---

**Caso 14 · Agotamiento del pool de conexiones** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · **🧠 Causas raíz** · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
