# 🚨 Postmortem — «¿Desde cuándo hay 412.000 mensajes acá?»

> Reconstrucción a partir de un patrón recurrente. Los nombres son ficticios; la secuencia no.

## Resumen

Durante una migración de infraestructura, un ingeniero abrió la consola de SQS para revisar los tamaños de las colas y encontró una DLQ con **412.000 mensajes**. El mensaje más antiguo tenía **catorce meses**.

No hubo incidente. No hubo alerta. Nadie sabía que esa cola existía con ese volumen — el consumidor llevaba dos años funcionando sin una sola caída.

## Línea de tiempo

| Momento | Evento |
|---|---|
| −24 meses | Se despliega el consumidor de eventos de pedido. Incluye DLQ desde el día uno: buena práctica. |
| −24 meses | El manejo de errores es un `try/catch` que registra en `WARN` y manda a la DLQ. Pasa la revisión de código sin objeciones. |
| −14 meses | Un cambio de schema en el productor agrega un campo obligatorio. El consumidor empieza a rechazar el 3% de los mensajes. **Nadie se entera.** |
| −8 meses | Un downstream tiene un mes malo de timeouts. Otros 40.000 mensajes a la DLQ — **todos recuperables**. |
| Día 0 | Durante una migración, alguien abre la consola de SQS. |
| Día 0 | 412.000 mensajes. El más viejo, de hace catorce meses. |
| Día 2 | Se escribe un drenaje ad-hoc. **287.000 se procesan sin cambiar una línea**: eran transitorios. |
| Día 5 | Los 125.000 restantes son del cambio de schema. Su contexto de negocio ya venció: se descartan. |

## Qué pasó

Tres cosas, y ninguna era un bug:

1. **El consumidor no clasificaba.** Un timeout del downstream y un mensaje con schema viejo iban al mismo lugar. Los primeros se habrían resuelto con un reintento; los segundos nunca. El código trataba igual a los dos.

2. **La DLQ no tenía métrica.** El dashboard del servicio mostraba throughput, latencia y error rate. El error rate era **cero** — porque el error se manejaba: se mandaba a la DLQ. La profundidad de esa cola no estaba en ningún gráfico.

3. **No había forma de volver.** Cuando se decidió drenar, no existía ningún comando. Se escribió en dos días, en medio de la investigación, y sin `--dry-run`.

El `log.warn` estaba desde el día uno. Nadie alertaba sobre él: un warning que aparece unos cientos de veces por día en un servicio grande es ruido.

## El número que duele

**287.000 de 412.000 mensajes se procesaron sin cambiar una línea de código.** Un 70% de esa cola era trabajo que se podía haber salvado con un reintento, y que estuvo ahí hasta catorce meses porque nadie miró qué error era.

Los otros 125.000 eran veneno de verdad — y se perdieron igual, porque para cuando se los encontró su contexto de negocio ya no existía.

## Causas raíz

1. **Ausencia de clasificación de errores.** La causa central.
2. **`dlq_depth` fuera del dashboard.** Lo que no se mide, no se mira.
3. **Ausencia de un comando de drenaje.** La mitad que nunca se construye.
4. **Sin clase de error ni muestra del payload.** La investigación tardó cinco días en gran parte por esto.
5. **Alerta sobre error rate, no sobre la DLQ.** El error rate en cero era técnicamente correcto y completamente engañoso.

## Qué se cambió

- Clasificación explícita: transitorio con presupuesto de 3 reintentos con backoff, veneno directo a la DLQ con su clase.
- `dlq_depth` y **`dlq_oldest_msg_age_ms`** publicadas, con alerta sobre la segunda.
- Registro de la clase de error y muestreo de los primeros 50 payloads por clase.
- `bin/dlq:drain` con `--dry-run`, y una revisión semanal agendada.
- TTL explícito: lo que lleva más de 90 días en la DLQ se archiva y se descarta, **por decisión escrita**.

## La lección

**Un mecanismo de seguridad que nadie opera no es un mecanismo de seguridad: es deuda con buena reputación.**

La DLQ estaba desde el día uno y pasó la revisión de código como buena práctica. Lo que faltaba no era la cola: era todo lo demás —clasificar, medir, alertar, drenar— y ninguna de esas cuatro cosas aparece cuando alguien dice «tiene DLQ».

La segunda lección es sobre la métrica: **un error rate de cero puede significar que no hay errores o que los errores se están manejando hacia un agujero.** Distinguir esas dos cosas requiere mirar dónde va lo que se maneja.

<!-- nav-case-doc -->
---

**Caso 20 · La dead letter queue olvidada** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · **🚨 Postmortem**
<!-- /nav-case-doc -->
