# 🔵 Caso 20 — .NET 8

<!-- nav-stack -->
[⬅️ Caso 20](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🔵 Perfil de .NET](../../../docs/languages/dotnet.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack .NET del caso 20. Consumidor silencioso contra consumidor que clasifica, reintenta, mide y drena.

## Los filtros de excepción: la única primitiva del laboratorio que decide sin desenrollar

```csharp
try { Procesar(msg); }
catch (ErrorProceso e) when (e.EsTransitorio)  { Reintentar(); }
catch (ErrorProceso e) when (!e.EsTransitorio) { ADlq(msg, e.Clase); }
```

La diferencia con `catch` + `if` + `throw;` **no es de estilo**. El filtro `when` se evalúa **antes de desenrollar la pila**. Si ninguno matchea, la pila queda intacta y el error sigue subiendo con su stack trace completo.

Para este caso eso es exactamente el dato que falta. Un registro de DLQ sin el punto de falla original no sirve para depurar — y en Java, donde para clasificar hay que capturar, el `throw` de reenvío ya lo acortó.

**Es la única de las siete plataformas que puede decidir sin destruir la evidencia.**

## El corolario menos conocido

```csharp
catch (Exception e) when (Log(e)) { }   // Log siempre devuelve false
```

Un filtro que siempre devuelve `false` es la forma canónica de **registrar sin capturar**: se ve el error, se anota con la pila entera, y la excepción sigue su camino como si nadie la hubiera tocado.

## Lo que .NET comparte con los demás, y agrega

`catch (Exception)` sin filtro se traga los bugs propios igual que en Java, Python y PHP. Y agrega uno propio: `_ = ProcesarAsync(msg)` sin `await` manda la excepción a un `Task` que nadie observa, y desde .NET Core `TaskScheduler.UnobservedTaskException` ni siquiera termina el proceso. **Es más silencioso que el rechazo sin dueño de Node.**

## Un detalle de diseño

Este caso usa **un solo tipo de excepción con una bandera** en vez de una jerarquía profunda. Es idiomático en .NET precisamente porque `when` hace el trabajo que en Java hace la jerarquía: la clasificación vive en el filtro, no en el árbol de tipos.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `catch (Ex e) when (...)` | Decide **antes** de desenrollar: la pila sobrevive. |
| `when (Log(e))` con `false` | Registrar sin capturar. |
| `record` | La muestra del payload, con igualdad estructural. |
| `LINQ` `GroupBy` | El desglose por clase de error en una línea. |

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
docker compose -f compose.dotnet.yml up -d --build
curl "http://127.0.0.1:8500/20/consume-silent?messages=3000"
curl "http://127.0.0.1:8500/20/consume-observed?messages=3000"
curl "http://127.0.0.1:8500/20/dlq/stats"
```

