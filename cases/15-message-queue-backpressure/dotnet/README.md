# 🔵 Caso 15 — .NET 8

<!-- nav-stack -->
[⬅️ Caso 15](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🔵 Perfil de .NET](../../../docs/languages/dotnet.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack .NET del caso 15. Cola sin capacidad contra cola acotada con política explícita.

## El único stack donde la política es un enum del constructor

```csharp
new BoundedChannelOptions(capacity) {
    FullMode = BoundedChannelFullMode.Wait          // backpressure
             | BoundedChannelFullMode.DropOldest    // descarta el viejo
             | BoundedChannelFullMode.DropWrite     // descarta el nuevo
}
```

En Python, Java o Go la política se expresa eligiendo **qué método llamar en cada sitio de envío** — y por lo tanto se puede elegir distinto en dos lugares del mismo sistema sin que nada lo note. Acá la decisión se toma **una vez**, al construir el canal, y después todos los productores la heredan.

Hay un segundo detalle que ningún otro stack tiene: el canal **avisa cuando descarta**.

```csharp
Channel.CreateBounded<Msg>(options, dropped => Interlocked.Increment(ref droppedCount));
```

No hay que inferir la pérdida de un contador propio ni confiar en que alguien se acuerde de incrementarlo. El descarte silencioso —la peor variante de este caso— es difícil de escribir en .NET.

## El reverso

`Channel.CreateUnbounded<T>()` es igual de fácil de escribir y no lleva ninguna advertencia. Un método, cero parámetros, y el sistema se queda sin freno.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `Channel.CreateBounded` + `BoundedChannelFullMode` | Capacidad y política, en el constructor. |
| callback `itemDropped` | El descarte se reporta solo. |
| `WriteAsync` / `TryWrite` | Envío con espera o sin ella. |
| `ChannelReader.ReadAllAsync()` | El consumidor, como `await foreach`. |

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
docker compose -f compose.dotnet.yml up -d --build
curl "http://127.0.0.1:8500/15/produce-unbounded?messages=120&consume_ms=2"
curl "http://127.0.0.1:8500/15/produce-bounded?messages=120&capacity=32&policy=block&consume_ms=2"
curl "http://127.0.0.1:8500/15/queue/state"
```

## La lección que ningún stack cambia

Las tres políticas pagan algo distinto y **ninguna es gratis**:

| Política | Qué paga |
|---|---|
| `block` | latencia: la lentitud viaja aguas arriba hasta el cliente |
| `drop_oldest` | datos: se pierden mensajes, y en silencio salvo que se cuenten |
| `dead_letter` | deuda operativa: alguien tiene que mirar esa cola ([caso 20](../../20-forgotten-dead-letter-queue/README.md)) |

Una cola sin límite parece una cuarta opción sin costo. No lo es: solo difiere el pago hasta el OOM.
