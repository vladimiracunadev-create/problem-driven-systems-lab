# ☕ Caso 15 — Java 21

<!-- nav-stack -->
[⬅️ Caso 15](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [☕ Perfil de Java](../../../docs/languages/java.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Java del caso 15. Cola sin capacidad contra cola acotada con política explícita.

## Java le puso nombre a cada forma de rechazar

La familia `BlockingQueue` codifica las tres políticas en tres métodos distintos:

```java
q.put(msg);                        // bloquea: backpressure al productor
q.offer(msg);                      // devuelve false: el llamador decide
q.offer(msg, timeout, unit);       // espera acotada y después decide
```

Es la misma idea que las `RejectedExecutionHandler` de `ThreadPoolExecutor` — `AbortPolicy`, `DiscardOldestPolicy`, `CallerRunsPolicy`. **Java tiene una taxonomía con nombre para cada forma de rechazar**, y eso obliga a nombrar la decisión en el código, que es más de lo que hacen la mitad de los stacks.

## El contraste incómodo está una interfaz más arriba

`ConcurrentLinkedQueue` implementa la **misma interfaz `Queue`** que `ArrayBlockingQueue` y no tiene capacidad. Cambiar una por otra es un cambio de una línea que:

- compila sin advertencias
- pasa todos los tests, porque el comportamiento con poca carga es idéntico
- **saca el freno del sistema entero**

Es el mismo tipo estático, la misma API, y una diferencia que solo se ve bajo presión. Por eso la variante `unbounded` de este caso usa exactamente esa clase: es el error real, no uno inventado.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `ArrayBlockingQueue(N)` | La cola acotada. |
| `ConcurrentLinkedQueue` | La versión sin capacidad — misma interfaz, sin freno. |
| `put` / `offer` / `offer(timeout)` | Las tres políticas, con nombre propio. |
| `LongAdder` · `AtomicLong.accumulateAndGet` | Contadores y máximos bajo contención. |

## Rutas

| Ruta | Qué muestra |
|---|---|
| `/health` | liveness |
| `/produce-unbounded?messages=120&consume_ms=2` | `queue_depth_peak` = total producido; `oldest_msg_age_ms_peak` sin techo |
| `/produce-bounded?...&policy=block` | profundidad acotada a `capacity`; `producer_blocked_ms` > 0, nada se pierde |
| `/produce-bounded?...&policy=drop_oldest` | profundidad acotada; `dropped` > 0, el productor nunca se frena |
| `/produce-bounded?...&policy=dead_letter` | profundidad acotada; `dead_lettered` > 0, revisable en `/dlq` |
| `/queue/state` | profundidad pico, bytes ocupados y edad del mensaje más viejo |
| `/dlq?limit=20` | contenido de la dead letter queue |
| `/diagnostics/summary` | acumulado por variante y política |
| `/reset-lab` | limpia DLQ y contadores |

**Parámetros:** `messages` (1–2000), `capacity` (1–1000), `consume_ms` (0–100, cuánto tarda el consumidor por mensaje), `policy` (`block` · `drop_oldest` · `dead_letter`).

## Hub

```bash
docker compose -f compose.java.yml up -d --build
curl "http://127.0.0.1:8400/15/produce-unbounded?messages=120&consume_ms=2"
curl "http://127.0.0.1:8400/15/produce-bounded?messages=120&capacity=32&policy=block&consume_ms=2"
curl "http://127.0.0.1:8400/15/queue/state"
```

## La lección que ningún stack cambia

Las tres políticas pagan algo distinto y **ninguna es gratis**:

| Política | Qué paga |
|---|---|
| `block` | latencia: la lentitud viaja aguas arriba hasta el cliente |
| `drop_oldest` | datos: se pierden mensajes, y en silencio salvo que se cuenten |
| `dead_letter` | deuda operativa: alguien tiene que mirar esa cola ([caso 20](../../20-forgotten-dead-letter-queue/README.md)) |

Una cola sin límite parece una cuarta opción sin costo. No lo es: solo difiere el pago hasta el OOM.
