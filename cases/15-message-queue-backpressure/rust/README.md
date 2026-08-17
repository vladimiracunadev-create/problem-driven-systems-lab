# 🦀 Caso 15 — Rust 1.83

<!-- nav-stack -->
[⬅️ Caso 15](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🦀 Perfil de Rust](../../../docs/languages/rust.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Rust del caso 15. Cola sin capacidad contra cola acotada con política explícita.

## El límite está en el tipo, no en un parámetro

```rust
let (tx, rx) = mpsc::channel::<Msg>();          // Sender<T>     — sin capacidad
let (tx, rx) = mpsc::sync_channel::<Msg>(32);   // SyncSender<T> — acotado
```

Son **tipos distintos con métodos distintos**. No se puede «olvidar» el límite de un canal acotado ni pedirle backpressure a uno sin límite: el compilador no deja escribir la confusión.

Comparar con Java es directo: allá `ConcurrentLinkedQueue` y `ArrayBlockingQueue` implementan la misma interfaz `Queue`, así que cambiar una por otra es una línea que compila y saca el freno del sistema. Acá eso no existe — el tipo del canal declara si hay freno o no.

## El error de rechazo se lleva el mensaje adentro

```rust
match tx.try_send(msg) {
    Err(TrySendError::Full(msg)) => dlq.push(msg),   // <- msg vuelve
    ...
}
```

`TrySendError::Full(T)` devuelve **la propiedad del valor rechazado**. En Go o Java el mensaje descartado simplemente «sigue en tu mano» por convención; acá el tipo garantiza que no se perdió en el intento y que hay que decidir explícitamente qué hacer con él.

Es exactamente lo que una dead letter queue necesita: el mensaje llega entero, sin clonarlo por las dudas antes de intentar el envío.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `mpsc::sync_channel(N)` → `SyncSender<T>` | La cola acotada. El límite es parte del tipo. |
| `mpsc::channel()` → `Sender<T>` | La versión sin capacidad, con otro tipo. |
| `TrySendError::Full(T)` | El rechazo que devuelve el mensaje. |
| `AtomicI64::fetch_max` | Profundidad pico y edad máxima sin lock. |

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
docker compose -f compose.rust.yml up -d --build
curl "http://127.0.0.1:8700/15/produce-unbounded?messages=120&consume_ms=2"
curl "http://127.0.0.1:8700/15/produce-bounded?messages=120&capacity=32&policy=block&consume_ms=2"
curl "http://127.0.0.1:8700/15/queue/state"
```

## La lección que ningún stack cambia

Las tres políticas pagan algo distinto y **ninguna es gratis**:

| Política | Qué paga |
|---|---|
| `block` | latencia: la lentitud viaja aguas arriba hasta el cliente |
| `drop_oldest` | datos: se pierden mensajes, y en silencio salvo que se cuenten |
| `dead_letter` | deuda operativa: alguien tiene que mirar esa cola ([caso 20](../../20-forgotten-dead-letter-queue/README.md)) |

Una cola sin límite parece una cuarta opción sin costo. No lo es: solo difiere el pago hasta el OOM.
