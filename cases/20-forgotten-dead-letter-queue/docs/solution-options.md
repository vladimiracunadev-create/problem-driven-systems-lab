# 🛠️ Opciones de solución

## 1. Clasificar antes de decidir

La base de todo. Dos categorías, y el código tiene que distinguirlas:

```text
transitorio  → reintentar con backoff, presupuesto acotado
venenoso     → a la DLQ ya mismo, con su clase de error
```

Reintentar lo venenoso quema CPU sin ganar nada. Mandar lo transitorio a la DLQ tira trabajo recuperable. En el laboratorio, esa distinción baja la tasa de dead-letter del **13,87% al 3,97%**.

## 2. Presupuesto de reintentos, no reintentos infinitos

Un reintento sin techo convierte un downstream lento en un consumidor detenido, y conecta directo con el [caso 04](../../04-timeout-chain-and-retry-storms/README.md): sin backoff, N consumidores reintentando a la vez son una tormenta.

Lo correcto es un número chico de intentos con backoff exponencial, y después a la DLQ **con la clase `transient_exhausted`**, que es distinta de veneno y hay que poder distinguirla.

## 3. La DLQ como cola observable

Cuatro cosas, y ninguna es opcional:

- **`dlq_depth`** como métrica publicada, con alerta por umbral.
- **`dlq_oldest_msg_age_ms`**, que es la que dice si algo lo va a reparar.
- **Desglose por clase de error**, que convierte un número en un diagnóstico.
- **Muestras del payload** de los primeros N, para poder depurar sin volcar la cola.

## 4. Un comando de drenaje

```bash
bin/dlq:drain --limit=500 --dry-run
bin/dlq:drain --limit=500
```

Idealmente con `--dry-run`, para ver qué se recuperaría antes de hacerlo. Una DLQ que solo recibe es un cementerio; una de la que se puede volver es un buffer.

> En PHP esto es lo natural: un comando de cron que se ejecuta a mano en un incidente sin redesplegar nada. En los stacks con consumidores embebidos hay que construirlo a propósito.

## 5. Alerta sobre la antigüedad, no solo sobre la profundidad

Una DLQ con mil mensajes de los últimos cinco minutos es un incidente en curso. Mil mensajes del último trimestre son un proceso que nadie opera. **El mismo número, dos problemas distintos**, y solo `dlq_oldest_msg_age_ms` los separa.

## 6. Descartar explícitamente lo que no vale la pena

Perfectamente válido para mensajes cuyo contexto de negocio ya venció. La condición es que sea **una decisión con un TTL escrito**, no una cola que crece porque nadie la mira.

<!-- nav-case-doc -->
---

**Caso 20 · La dead letter queue olvidada** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · **🛠️ Opciones de solución** · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
