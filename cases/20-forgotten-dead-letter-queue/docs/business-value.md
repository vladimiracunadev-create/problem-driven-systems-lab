# 💼 Valor de negocio

## Qué se elimina

La pérdida silenciosa de datos. En el laboratorio, el consumidor silencioso manda a la DLQ el **13,87%** de los mensajes y no recupera ninguno. El consumidor observado manda el **3,97%** —solo el veneno real— y recupera el resto sin que llegue a la cola.

Y la medición que cierra el caso: drenar la DLQ del consumidor silencioso recupera el **71,39%** de sus mensajes. **Ese porcentaje es trabajo que se había tirado y que se podía salvar con un reintento.**

## Por qué se subestima

Porque el sistema no se cae. Ni una alerta, ni un pico de latencia, ni un error en el dashboard. El consumidor está haciendo lo que se le pidió: capturar el error, no morirse, seguir.

El costo aparece meses después y con otro nombre: un cliente cuyo pedido nunca se procesó, un reporte que no cuadra, una conciliación que da un número raro. Ninguno de esos se rastrea hasta una cola que nadie estaba mirando.

## El indicador honesto

No es `dlq_depth`. Es **`dlq_oldest_msg_age_ms`**.

Mil mensajes de los últimos cinco minutos son un incidente en curso: alguien está trabajando en eso. Los mismos mil del último trimestre son un proceso que nadie opera, y el número de mañana va a ser más alto.

## Qué habilita

Confiar en «se procesó» como afirmación. Cuando la DLQ tiene profundidad conocida, antigüedad acotada y una salida, se puede construir encima: conciliaciones automáticas, garantías de entrega hacia el cliente, SLAs de procesamiento. Sobre un pipeline cuya tasa de pérdida nadie conoce, cada una de esas promesas hereda un error que no se puede cuantificar.

---

**Este caso cierra el [caso 15](../../15-message-queue-backpressure/README.md).** Allí la DLQ nace como la decisión correcta frente a una cola llena. Acá se ve que esa decisión solo está completa cuando incluye quién la mira y cómo se sale de ella. **Un mecanismo de seguridad que nadie opera no es un mecanismo de seguridad: es deuda con buena reputación.**

<!-- nav-case-doc -->
---

**Caso 20 · La dead letter queue olvidada** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · **💼 Valor de negocio** · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
