# ⚖️ Trade-offs

- **Un pool más grande no es gratis.** Cada conexión ociosa consume memoria en la base y cuenta contra su `max_connections`. Con veinte réplicas, un pool de 50 son mil conexiones que el motor tiene que sostener aunque el tráfico sea de cien req/s.
- **El timeout de adquisición convierte esperas en errores.** Es lo correcto, pero el número de errores 5xx **sube** al aplicarlo. Hay que explicarlo antes de desplegarlo, o alguien va a creer que el arreglo rompió algo.
- **Fallar rápido traslada la decisión al cliente.** Un 503 con `Retry-After` es honesto, pero necesita un cliente que lo respete. Sin backoff del otro lado, el timeout de adquisición alimenta una tormenta de reintentos — que es el [caso 04](../../04-timeout-chain-and-retry-storms/README.md).
- **La devolución garantizada no arregla el estado sucio.** `finally` devuelve la conexión, no la limpia. Una transacción sin cerrar vuelve al pool igual, y el siguiente la hereda.
- **El health check que mira el pool puede sacar de rotación una instancia sana.** Un pico legítimo llena el pool por unos segundos; si el readiness reacciona, se pierde capacidad justo cuando más hace falta.

<!-- nav-case-doc -->
---

**Caso 14 · Agotamiento del pool de conexiones** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · **⚖️ Trade-offs** · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
