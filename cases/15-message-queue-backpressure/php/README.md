# 🐘 Caso 15 — PHP 8.3

<!-- nav-stack -->
[⬅️ Caso 15](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐘 Perfil de PHP](../../../docs/languages/php.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack PHP del caso 15. Cola sin capacidad contra cola acotada con política explícita.

## La diferencia que aporta este stack: no tiene la primitiva

PHP **no tiene cola en proceso**. No hay `queue.Queue`, ni `chan`, ni `BlockingQueue`, ni `Channel`. Un array dentro de una request desaparece cuando la request termina, así que no puede haber un productor y un consumidor compartiéndolo.

Consecuencia directa: **en PHP el backpressure no vive en el lenguaje, vive en el transporte**. Las tres políticas existen igual en producción, pero están en otra capa:

| Política | Dónde vive en PHP |
|---|---|
| bloquear | `listen.backlog` de PHP-FPM y el accept queue del kernel. Cuando se llena, el kernel deja de aceptar conexiones y el cliente ve la espera |
| descartar | `pm.max_children` alcanzado: FPM devuelve 502 y el request se pierde |
| dead letter | la DLQ del broker real (SQS, RabbitMQ, Redis Streams), porque la cola de verdad nunca estuvo en PHP |

Y hay algo que PHP enseña mejor que nadie **justamente por no tener la primitiva**: el backpressure no es una propiedad de la cola, es una propiedad del sistema entero. Si el freno no está en tu proceso, está en el kernel, en el broker o en el balanceador — pero está en algún lado, y conviene saber cuál antes de que te lo muestre un incidente.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `array` + `array_shift()` | La cola. Sin capacidad por defecto: «sin límite» es lo que sale si no se escribe nada. |
| `usleep()` | El tiempo de consumo por mensaje. |
| `memory_limit` | El único techo real de la versión sin límite — y cuando se alcanza, el proceso muere. |

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
docker compose -f compose.root.yml up -d --build
curl "http://127.0.0.1:8100/15/produce-unbounded?messages=120&consume_ms=2"
curl "http://127.0.0.1:8100/15/produce-bounded?messages=120&capacity=32&policy=block&consume_ms=2"
curl "http://127.0.0.1:8100/15/queue/state"
```

## Nota de fidelidad

El productor y el consumidor son pasos del mismo bucle porque PHP no tiene concurrencia dentro del proceso: cada 3 mensajes producidos se drena 1. Las métricas de profundidad, edad y pérdida son comparables con los otros stacks; `producer_blocked_ms` no lo es, porque acá mide el drenaje intercalado y no una espera real.

## Dashboard

Con `Accept: text/html`, la raíz devuelve un panel para lanzar ambas variantes:

```bash
docker compose -f cases/15-message-queue-backpressure/php/compose.yml up -d --build
# abrir http://localhost:8115/
```

## La lección que ningún stack cambia

Las tres políticas pagan algo distinto y **ninguna es gratis**:

| Política | Qué paga |
|---|---|
| `block` | latencia: la lentitud viaja aguas arriba hasta el cliente |
| `drop_oldest` | datos: se pierden mensajes, y en silencio salvo que se cuenten |
| `dead_letter` | deuda operativa: alguien tiene que mirar esa cola ([caso 20](../../20-forgotten-dead-letter-queue/README.md)) |

Una cola sin límite parece una cuarta opción sin costo. No lo es: solo difiere el pago hasta el OOM.
