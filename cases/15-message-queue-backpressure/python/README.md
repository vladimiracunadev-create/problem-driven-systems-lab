# 🐍 Caso 15 — Python 3.12

<!-- nav-stack -->
[⬅️ Caso 15](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐍 Perfil de Python](../../../docs/languages/python.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Python del caso 15. Cola sin capacidad contra cola acotada con política explícita.

## La política se elige en la firma de `put()`

Las tres políticas del caso **no son tres estructuras distintas**: son tres formas de llamar al mismo método.

```python
q.put(msg)                    # bloquea: backpressure hacia el productor
q.put_nowait(msg)             # levanta queue.Full: el llamador decide
q.put(msg, timeout=0.05)      # espera acotada y después decide
```

Es la API más explícita del laboratorio en este punto: **no hay un modo por defecto que «haga algo razonable» a tus espaldas**. Si no elegís, no hay comportamiento — tenés que escribir cuál de las tres querés.

El reverso está una línea más arriba: `queue.Queue()` sin `maxsize` es la versión sin límite, y se escribe con menos caracteres que la acotada.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `queue.Queue(maxsize=N)` | La cola acotada. Sin `maxsize`, no hay techo. |
| `put` / `put_nowait` / `put(timeout=)` | Las tres políticas, en la firma del mismo método. |
| `threading.Thread` | El consumidor, que drena a un mensaje cada `consume_ms`. |
| `time.sleep` | El tiempo de consumo. Libera el GIL, así que la contención es real. |

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
docker compose -f compose.python.yml up -d --build
curl "http://127.0.0.1:8200/15/produce-unbounded?messages=120&consume_ms=2"
curl "http://127.0.0.1:8200/15/produce-bounded?messages=120&capacity=32&policy=block&consume_ms=2"
curl "http://127.0.0.1:8200/15/queue/state"
```

## La lección que ningún stack cambia

Las tres políticas pagan algo distinto y **ninguna es gratis**:

| Política | Qué paga |
|---|---|
| `block` | latencia: la lentitud viaja aguas arriba hasta el cliente |
| `drop_oldest` | datos: se pierden mensajes, y en silencio salvo que se cuenten |
| `dead_letter` | deuda operativa: alguien tiene que mirar esa cola ([caso 20](../../20-forgotten-dead-letter-queue/README.md)) |

Una cola sin límite parece una cuarta opción sin costo. No lo es: solo difiere el pago hasta el OOM.
