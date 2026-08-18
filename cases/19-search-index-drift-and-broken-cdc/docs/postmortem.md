# 🚨 Postmortem — «El producto existe, pero no aparece»

> Reconstrucción a partir de un patrón recurrente. Los nombres son ficticios; la secuencia no.

## Resumen

Un vendedor del marketplace abrió un ticket porque tres de sus artículos no aparecían en la búsqueda. Existían en el catálogo y su página de detalle funcionaba. El ticket estuvo abierto **catorce días** y escaló a ingeniería solo cuando otro vendedor reportó lo mismo.

La investigación encontró **31.400 documentos derivados sobre 2,1 millones** — un 1,5%. El más viejo llevaba **siete meses** sin actualizarse.

## Línea de tiempo

| Fecha | Evento |
|---|---|
| Día 0 | Ticket: «tres artículos no aparecen en la búsqueda». Se cierra como «reindexar y probar». |
| Día 3 | Reindexado completo del catálogo. Los tres artículos aparecen. Ticket cerrado. |
| Día 9 | Segundo vendedor reporta lo mismo, con otros artículos. |
| Día 11 | Se escala. Primera pregunta: **¿cuántos documentos hay en cada lado?** Nadie lo sabe. |
| Día 12 | Se cuenta a mano: 2.104.882 en el catálogo, 2.089.931 en el índice. **14.951 de diferencia.** |
| Día 13 | Se escribe el diff completo. Aparecen las tres caras: 14.951 `missing`, 15.902 `stale`, 547 `orphan`. |
| Día 14 | Se encuentra la causa. Se despliega el outbox. |

## Qué pasó

El servicio de catálogo hacía dual-write: `save()` a PostgreSQL y después `index()` contra Elasticsearch. La llamada al índice estaba envuelta en un `try/catch` que registraba el error en `WARN` y seguía.

Tres cosas confluyeron:

1. **El cliente de Elasticsearch tenía un timeout de 2 segundos.** Durante los picos de tráfico, un porcentaje bajo de escrituras lo superaba. Nadie estaba mirando ese `WARN`.
2. **El reindexado del día 3 no borraba.** Leía el catálogo y escribía al índice. Arregló `missing` y `stale`, y dejó los 547 `orphan` intactos — que era la razón por la que algunos resultados llevaban a 404.
3. **La versión no existía.** Los documentos del índice no tenían número de versión, así que `stale` era **indetectable**: la única comparación posible era «está o no está». Los 15.902 documentos con datos viejos se descubrieron recién cuando se agregó la versión.

El registro `WARN` estaba desde el primer día. Nadie alertaba sobre él, porque un warning que aparece unas decenas de veces por día en un servicio grande es ruido.

## Causas raíz

1. **Dual-write sin transacción común.** La causa estructural.
2. **El error del índice, tragado.** Un `catch` con `log.warn` y sin alerta es un `catch` vacío con más pasos.
3. **Sin versión en los documentos.** Hizo `stale` invisible durante siete meses.
4. **El reindexado no borraba.** Convirtió una corrección parcial en «lo arreglamos».
5. **Ninguna reconciliación agendada.** Nadie comparaba los dos lados, así que la única vía de descubrimiento era el reporte de un cliente.

## Qué se cambió

- **Outbox transaccional** en el servicio de catálogo, con el evento escrito en la misma transacción que el dato.
- **Consumidor con checkpoint durable**, que avanza solo después de la confirmación del índice.
- **Versión en cada documento**, para que `stale` sea detectable y para descartar aplicaciones fuera de orden.
- **Barrido de reconciliación cada hora**, por hashes de bloques de IDs, que repara y **publica `drift_count` y `drift_age_ms`**.
- **Alerta sobre `drift_age_ms`**, no sobre el error rate del cliente.

## La lección

**Un sistema que no falla no es lo mismo que un sistema que funciona.** Durante siete meses la búsqueda respondió 200 con latencia normal a cada petición, y durante siete meses estuvo devolviendo mal el 1,5% de las consultas.

La segunda lección es sobre la métrica: nadie preguntó «¿cuántos documentos hay en cada lado?» hasta el día 11. **Esa pregunta no requiere ninguna herramienta y responde el caso entero** — y no estaba en ningún dashboard porque los dos números vivían en dos sistemas distintos que nadie había puesto uno al lado del otro.

<!-- nav-case-doc -->
---

**Caso 19 · Deriva del índice de búsqueda y CDC roto** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · **🚨 Postmortem**
<!-- /nav-case-doc -->
