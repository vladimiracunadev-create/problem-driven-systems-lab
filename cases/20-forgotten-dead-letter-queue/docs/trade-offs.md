# ⚖️ Trade-offs

## Clasificar exige conocer los errores del downstream

Distinguir transitorio de venenoso obliga a saber qué significa cada código de error del sistema con el que se habla. Eso es trabajo, se desactualiza cuando el downstream cambia, y **una clasificación equivocada es peor que ninguna**: reintentar veneno cien veces cuesta más que mandarlo a la DLQ enseguida.

## El reintento consume capacidad del consumidor

Cada reintento ocupa un slot que podría estar procesando un mensaje nuevo. Con un downstream degradado, un presupuesto de reintentos generoso convierte una degradación en una detención.

## Guardar muestras de payload guarda datos

Un payload muestreado puede contener datos personales, y la DLQ suele tener retención más larga que el sistema principal. Muestrear obliga a decidir qué se enmascara — y a acordarse de que esa cola también está sujeta a las políticas de datos.

## El replay puede duplicar efectos

Reprocesar un mensaje que **sí** había producido su efecto antes de fallar duplica ese efecto. El drenaje de una DLQ es exactamente el escenario del [caso 16](../../16-idempotency-and-duplicate-effects/README.md), y sin idempotencia del lado del consumidor, drenar es peligroso.

## Alertar sobre la DLQ genera ruido

Un umbral bajo dispara con cada pico transitorio. Uno alto se entera tarde. La salida práctica es alertar sobre la **derivada** —cuánto creció en la última hora— y sobre la **antigüedad**, no sobre el valor absoluto.

## Una DLQ vacía puede ser mala señal

Cero mensajes puede significar que todo funciona — o que el consumidor está descartando en silencio en algún punto anterior. La métrica sana no es «DLQ en cero», es «DLQ con volumen conocido y antigüedad acotada».

<!-- nav-case-doc -->
---

**Caso 20 · La dead letter queue olvidada** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · **⚖️ Trade-offs** · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
