# 🛠️ Opciones de solución

## 1. Outbox transaccional — la base de todo

El cambio se escribe **en la misma transacción** que el dato:

```sql
BEGIN;
  UPDATE productos SET precio = 990 WHERE id = 42;
  INSERT INTO outbox (agregado_id, version, payload) VALUES (42, 7, '...');
COMMIT;
```

Si el commit falla, no queda ni el dato ni el evento. Si tiene éxito, quedan los dos. **Es la única forma de que el evento no pueda perderse sin que el dato tampoco exista.**

## 2. Checkpoint que avanza solo con la confirmación

El consumidor lee el outbox en orden y avanza el checkpoint **después** de que el índice confirmó. Dos reglas:

- **En orden**: saltear un cambio dejaría una versión vieja pisando a una nueva.
- **Después de confirmar**: un cambio que no entra queda pendiente, no perdido.

El checkpoint tiene que ser **durable**. Un checkpoint en memoria se pierde en el primer reinicio y el consumidor vuelve a empezar — o peor, se saltea todo lo que ya había leído.

## 3. Reconciliación periódica — la red de seguridad

Un barrido que compara los dos lados y repara:

```text
missing → indexar
stale   → reindexar
orphan  → borrar del índice
```

Cubre lo que el outbox no puede: un índice restaurado de un backup viejo, una reindexación parcial, un borrado manual, un bug ya corregido que dejó residuo.

Para volúmenes grandes se compara por **hashes de bloques de IDs** en vez de documento por documento, y solo se baja al detalle en los bloques que difieren.

## 4. Versión en cada documento

Sin versión no hay forma de detectar `stale`: solo se puede saber si el documento **está**, no si está **al día**. Es lo que convierte el diff de dos caras en uno de tres.

Y sirve para algo más: descartar aplicaciones fuera de orden. Si llega la versión 6 cuando el índice ya tiene la 7, se ignora.

## 5. Alerta sobre la deriva, no sobre el error

Lo que hay que monitorear no es el error rate del cliente del índice —que ya es cero, porque el error se tragó— sino:

- `drift_count` por encima de un umbral
- **`drift_age_ms`**, que es la que dice si algo lo va a reparar
- El lag del outbox: `outbox_pending`

## 6. Aceptar la deriva con un presupuesto explícito

Válido para índices donde una diferencia del 0,1% no cambia nada. La condición es que sea **una decisión medida**, con el número a la vista, y no la ausencia de medición.

<!-- nav-case-doc -->
---

**Caso 19 · Deriva del índice de búsqueda y CDC roto** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · **🛠️ Opciones de solución** · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
