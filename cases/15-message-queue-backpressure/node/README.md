# 🟢 Caso 15 — Node.js 22

<!-- nav-stack -->
[⬅️ Caso 15](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🟢 Perfil de Node.js](../../../docs/languages/node.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Node del caso 15. Cola sin capacidad contra cola acotada con política explícita.

## El único stack donde el backpressure es parte del protocolo del runtime

```js
const ok = writable.write(chunk);
if (!ok) await once(writable, 'drain');   // el freno
```

`write()` devuelve `false` cuando el buffer interno pasó el `highWaterMark`. Eso **no es un error ni un rechazo**: el chunk se aceptó igual. Es una señal de cortesía — «seguí si querés, pero estoy acumulando».

Ningún otro stack del laboratorio tiene esto integrado en su API de escritura. En Python, Java o Go el backpressure es algo que uno construye eligiendo qué método llamar; en Node el runtime ya lo ofrece.

## Y por eso mismo, la trampa

**Ignorar ese `false` compila, pasa los tests y funciona en desarrollo.** El único síntoma es que el buffer interno crece sin límite hasta el OOM. Es un freno que hay que apretar a mano, y la firma no obliga a nadie a mirarlo.

La variante `unbounded` de este caso hace exactamente eso: ignora el valor de retorno y pone el `highWaterMark` en infinito — que es lo que pasa cuando alguien «arregla» un warning de backpressure subiendo el umbral en vez de respetarlo.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `stream.Writable` con `highWaterMark` | La cola con capacidad. En `objectMode` se cuenta en objetos, no en bytes. |
| valor de retorno de `write()` | La señal de backpressure. |
| `events.once(w, 'drain')` | La espera hasta que se pueda seguir. |
| `writableLength` | La profundidad actual del buffer interno. |

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
docker compose -f compose.nodejs.yml up -d --build
curl "http://127.0.0.1:8300/15/produce-unbounded?messages=120&consume_ms=2"
curl "http://127.0.0.1:8300/15/produce-bounded?messages=120&capacity=32&policy=block&consume_ms=2"
curl "http://127.0.0.1:8300/15/queue/state"
```

## La lección que ningún stack cambia

Las tres políticas pagan algo distinto y **ninguna es gratis**:

| Política | Qué paga |
|---|---|
| `block` | latencia: la lentitud viaja aguas arriba hasta el cliente |
| `drop_oldest` | datos: se pierden mensajes, y en silencio salvo que se cuenten |
| `dead_letter` | deuda operativa: alguien tiene que mirar esa cola ([caso 20](../../20-forgotten-dead-letter-queue/README.md)) |

Una cola sin límite parece una cuarta opción sin costo. No lo es: solo difiere el pago hasta el OOM.
