# 🦀 Caso 20 — Rust 1.83

<!-- nav-stack -->
[⬅️ Caso 20](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🦀 Perfil de Rust](../../../docs/languages/rust.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Rust del caso 20. Consumidor silencioso contra consumidor que clasifica, reintenta, mide y drena.

## El `enum` de error con `match` exhaustivo

En un caso cuyo núcleo es **clasificar**, esa es la primitiva exacta:

```rust
enum ErrorProceso {
    Transitorio(&'static str),
    Venenoso(ClaseVeneno),
}

match procesar(msg) {
    Ok(())                            => ok += 1,
    Err(ErrorProceso::Transitorio(_)) => reintentar(),
    Err(ErrorProceso::Venenoso(c))    => a_dlq(msg, c),
}
```

Lo decisivo no es la elegancia: es que **agregar una variante rompe la compilación en todos los lugares que la ignoran**. Si mañana aparece `ErrorProceso::Corrupto`, el consumidor no compila hasta que alguien decida si eso se reintenta o va a la DLQ.

En los otros seis stacks una clase de error nueva cae en el `else`, en el `catch (Exception)` o en el camino por defecto, y termina en la DLQ como `unclassified` sin que nada avise. Go se acerca con `errors.Is`/`As` pero **no tiene exhaustividad**; Java se acerca con jerarquías `sealed` y necesita un `switch` sobre patrones para exigirla.

## El segundo efecto, más silencioso

**Un `panic!` no es un `Result`.**

Un bug del propio consumidor —un índice fuera de rango, un `unwrap` sobre `None`— no puede confundirse con un mensaje venenoso, porque **no viaja por el mismo canal**.

En Python, Java, .NET, PHP y Node el `except`/`catch` genérico se traga las dos cosas y las deja indistinguibles en la DLQ. Cuando alguien la revisa meses después, la conclusión es «datos malos» en vez de «tuvimos un bug tres semanas».

Es la causa raíz número 5 del caso, y Rust es el único stack donde estructuralmente no puede ocurrir.

## El costo, y es el de siempre

El `enum` obliga a que todas las clases de error vivan en un solo lugar. En un sistema con muchos módulos eso significa o un `enum` grande, o conversiones entre `enum`s por capa (`From`, `?`). Es más ceremonia que un `raise` de Python — y es lo que compra la exhaustividad.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `enum` de error | La clasificación, como tipo. |
| `match` exhaustivo | Una variante nueva **no compila** hasta que se maneje. |
| `Result<T, E>` | El canal de los errores esperados. |
| `panic!` | El canal **separado** de los bugs: no puede disfrazarse de veneno. |

## Transitorio contra venenoso

| Clase | Qué significa | Qué corresponde |
|---|---|---|
| **Transitorio** | El mismo mensaje funciona en el próximo intento | **Reintentar** con backoff |
| **Venenoso** | El mismo mensaje **nunca** va a funcionar | **A la DLQ**, ya mismo |

**Reintentar lo venenoso es quemar CPU. Mandar lo transitorio a la DLQ es tirar trabajo que se podía salvar.** El consumidor que no distingue hace las dos cosas mal a la vez.

## Rutas

| Ruta | Qué muestra |
|---|---|
| `/health` | estado básico del servicio |
| `/consume-silent?messages=3000` | cualquier fallo a la DLQ, sin clasificar ni reintentar |
| `/consume-observed?messages=3000` | clasificar, reintentar lo transitorio, alertar |
| `/dlq/stats` | profundidad, antigüedad del más viejo y desglose por clase |
| `/dlq/drain?limit=500` | replay: qué se recupera y qué sigue siendo veneno |
| `/diagnostics/summary` | acumulado por variante, más la nota de fidelidad |
| `/reset-lab` | vacía la DLQ y las métricas |

**Parámetros:** `messages` (10–200k), `transient_pct`, `poison_pct`, `max_retries`, `alert_threshold`, `sample_size`, `limit`.

## Lo que sale, y es idéntico en los siete

```text
  silencioso:  ok=2584  reintentos=0    a la DLQ=416  (13,87%)
               by_error_class = { unclassified: 416 }   alertas=0  muestras=0

  observado:   ok=2881  reintentos=297  a la DLQ=119  (3,97%)
               by_error_class = { schema_mismatch: 29, unknown_field: 31,
                                  null_required: 31, invalid_encoding: 28 }
               alertas=1  muestras=20
```

Y la medición que cierra el caso — drenar la DLQ del consumidor **silencioso**:

```text
  recuperados = 297 de 416  →  71,39%      ← nunca debieron estar ahí
  siguen fallando = 119                     ← veneno de verdad
```

Drenar la del consumidor **observado** recupera 0%: ahí solo hay veneno, que es exactamente lo que una DLQ debería contener.

## Cierra el arco del caso 15

En el [caso 15](../../15-message-queue-backpressure/README.md) la dead letter queue **nace**: es la política de rechazo que salva al productor de bloquearse cuando la cola se llena. Es la decisión correcta.

Acá se ve qué pasa cuando nadie vuelve a mirarla. **Los dos casos son el mismo mecanismo en dos momentos distintos** — y el segundo demuestra que la decisión del primero solo está completa cuando incluye quién la observa y cómo se sale de ella.

## Hub

```bash
docker compose -f compose.rust.yml up -d --build
curl "http://127.0.0.1:8700/20/consume-silent?messages=3000"
curl "http://127.0.0.1:8700/20/consume-observed?messages=3000"
curl "http://127.0.0.1:8700/20/dlq/stats"
```

