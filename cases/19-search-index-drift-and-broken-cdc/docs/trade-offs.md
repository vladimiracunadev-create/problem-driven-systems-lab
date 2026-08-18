# ⚖️ Trade-offs

## El outbox agrega una escritura a cada transacción

Cada cambio escribe dos filas en vez de una. Sobre una tabla caliente, eso es carga real y un `INSERT` más en el camino crítico. A cambio, ningún evento puede perderse sin que el dato tampoco exista.

## Aplicar en orden limita el paralelismo

Un consumidor que respeta el orden global no puede paralelizar. La salida es particionar por clave del documento —orden por documento, paralelismo entre documentos— y eso agrega la complejidad de mantener un checkpoint por partición.

## La reconciliación completa cuesta lo que cuesta

Comparar diez millones de documentos contra diez millones de entradas de índice no es gratis: pega a los dos sistemas y compite con el tráfico real. La comparación por hashes de bloques lo abarata mucho y agrega el trabajo de mantener esos hashes.

## Reparar automáticamente puede propagar un error

Un barrido que confía en la base y reescribe el índice arregla el 99% de los casos — y en el 1% restante, si el problema estaba en la base, lo propaga. Reparar en automático es correcto cuando hay una fuente de verdad clara. Cuando no la hay, el barrido debería **avisar**, no arreglar.

## El versionado ocupa espacio y hay que mantenerlo

Una columna de versión por documento es barata. Mantenerla correcta en todos los caminos de escritura —incluidas las migraciones y las cargas masivas— no lo es. Una versión que no se incrementa es peor que no tenerla: hace que `stale` sea invisible.

## Cero deriva no es un objetivo alcanzable

Siempre hay una ventana entre la escritura a la base y su aplicación al índice. El objetivo realista no es cero: es **una deriva acotada y medida**, con `drift_age_ms` por debajo de un umbral que el negocio acepte.

<!-- nav-case-doc -->
---

**Caso 19 · Deriva del índice de búsqueda y CDC roto** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · **⚖️ Trade-offs** · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
