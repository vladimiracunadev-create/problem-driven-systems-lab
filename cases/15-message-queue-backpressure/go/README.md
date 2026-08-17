# 🐹 Caso 15 — Go 1.23

<!-- nav-stack -->
[⬅️ Caso 15](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐹 Perfil de Go](../../../docs/languages/go.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Go del caso 15. Cola sin capacidad contra cola acotada con política explícita.

## La capacidad es parte de la construcción, no un parámetro opcional

```go
q := make(chan msg, capacity)   // el número está escrito, siempre
```

**No existe `make(chan T)` con buffer infinito.** O el canal es sin buffer, o la capacidad está en la línea. Go no ofrece la versión sin límite — por eso la variante `unbounded` de este caso hay que construirla a mano con una slice y un mutex.

Esa ausencia es la lección del stack: en Java o Python la cola sin techo es lo que sale por defecto; acá hay que escribirla a propósito, con más código que la correcta.

## Las tres políticas son tres formas del mismo envío

```go
q <- msg                                   // bloquea: backpressure
select { case q <- msg: default: }         // no bloquea: el llamador decide
select { case q <- msg: case <-time.After(d): }   // espera acotada
```

Es la **misma primitiva** del [caso 04](../../04-timeout-chain-and-retry-storms/go/README.md) (cancelación), del [08](../../08-critical-module-extraction-without-breaking-operations/go/README.md) (bus de eventos), del [09](../../09-unstable-external-integration/go/README.md) (cuota) y del [14](../../14-connection-pool-exhaustion/go/README.md) (pool). Cinco problemas distintos, un concepto que aprender.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `chan msg` bufferizado | La cola acotada. La capacidad va en el `make`. |
| `select` con `default` | Envío no bloqueante: la política de descarte. |
| `len(q)` / `cap(q)` | Profundidad y capacidad, sin instrumentación extra. |
| slice + `sync.Mutex` | La cola sin límite, construida a mano porque el lenguaje no la da. |

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
docker compose -f compose.go.yml up -d --build
curl "http://127.0.0.1:8600/15/produce-unbounded?messages=120&consume_ms=2"
curl "http://127.0.0.1:8600/15/produce-bounded?messages=120&capacity=32&policy=block&consume_ms=2"
curl "http://127.0.0.1:8600/15/queue/state"
```

## La lección que ningún stack cambia

Las tres políticas pagan algo distinto y **ninguna es gratis**:

| Política | Qué paga |
|---|---|
| `block` | latencia: la lentitud viaja aguas arriba hasta el cliente |
| `drop_oldest` | datos: se pierden mensajes, y en silencio salvo que se cuenten |
| `dead_letter` | deuda operativa: alguien tiene que mirar esa cola ([caso 20](../../20-forgotten-dead-letter-queue/README.md)) |

Una cola sin límite parece una cuarta opción sin costo. No lo es: solo difiere el pago hasta el OOM.
