# ⚖️ Comparativa multi-stack — Caso 20

> **La dead letter queue olvidada** resuelto en los **7 stacks**, con el mismo contrato de rutas y las mismas métricas.
>
> [⬅️ Volver al caso](README.md) · [🧬 Perfiles de lenguaje](../../docs/languages/README.md)

---

## 🔗 Cierra el arco del caso 15

En el [caso 15](../15-message-queue-backpressure/README.md) la dead letter queue **nace**: es la política de rechazo que salva al productor de bloquearse cuando la cola se llena. Es la decisión correcta.

Acá se ve qué pasa cuando nadie vuelve a mirarla. **Los dos casos son el mismo mecanismo en dos momentos distintos** — y el segundo demuestra que la decisión del primero solo está completa cuando incluye quién la observa y cómo se sale de ella.

---

## 📊 Resultados medidos — 3.000 mensajes, 12% transitorios, 4% venenosos

El escenario es determinista, así que los siete stacks producen **resultados idénticos hasta el último dígito**:

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

Drenar la del consumidor **observado** recupera **0%**: ahí solo hay veneno, que es exactamente lo que una DLQ debería contener.

---

## 🧩 Fidelidad del substrato

| Aspecto | Estado | Detalle |
|---|---|---|
| Clasificación transitorio/veneno | ✅ **Real** | Con la primitiva idiomática de cada runtime. |
| Reintento con presupuesto acotado | ✅ **Real** | `max_retries` por mensaje, con la clase `transient_exhausted` al agotarse. |
| Desglose por clase, antigüedad, umbral | ✅ **Real** | `by_error_class`, `dlq_oldest_msg_age_ms`, `alerts_fired`. |
| Muestreo de payloads | ✅ **Real** | Los primeros N, para poder depurar sin volcar la cola. |
| Replay desde la DLQ | ✅ **Real** | Recupera lo transitorio y deja el veneno. |
| Broker | 🟡 **Modelado** | Una lista en memoria (un archivo JSON en PHP), no SQS ni RabbitMQ. |
| Clase de error por mensaje | 🟡 **Determinista** | Para que el escenario sea reproducible en los 7 stacks. |
| `dlq_oldest_msg_age_ms` | 🟡 **A escala de laboratorio** | Milisegundos, no meses. La interpretación es la misma. |

> **Por qué el broker no importa.** Lo que define este caso no es SQS: es que **un mensaje que falla tiene que ir a algún lado**, y que ese lado necesita profundidad, antigüedad, clasificación y una salida. Eso es igual de cierto con una lista.

---

## 🎯 La dimensión que ordena el caso: qué tan difícil es clasificar mal

| Stack | Contra clasificar mal | Contra tragarse los bugs propios |
|---|---|---|
| 🦀 **Rust 1.83** | `enum` + `match` exhaustivo: **una variante nueva no compila** | **`panic!` no es un `Result`**: canal separado |
| 🔵 **.NET 8** | `catch (Ex e) when (...)`: filtra **sin desenrollar** la pila | Nada (`catch (Exception)` los atrapa) |
| ☕ **Java 21** | Jerarquía `sealed`: una clase nueva debe ir en `permits` | Nada, y `Error` queda fuera de `Exception` |
| 🐹 **Go 1.23** | `errors.Is` / `errors.As` sobre cadenas `%w` | Los `panic` son canal aparte, como en Rust |
| 🐘 **PHP 8.3** | `catch (A \| B $e)` — **sin exhaustividad** | Nada, y `Throwable` lo hace explícito |
| 🐍 **Python 3.12** | Jerarquía de excepciones — **sin exhaustividad** | Nada (`except Exception` los atrapa) |
| 🟢 **Node.js 22** | `instanceof`, **frágil entre paquetes y workers** | Nada |

---

## 🦀 Rust 1.83 — el `enum` es la primitiva exacta del problema

```rust
match procesar(msg) {
    Ok(())                            => ok += 1,
    Err(ErrorProceso::Transitorio(_)) => reintentar(),
    Err(ErrorProceso::Venenoso(c))    => a_dlq(msg, c),
}
```

Lo decisivo no es la elegancia: **agregar una variante rompe la compilación en todos los lugares que la ignoran**. Si mañana aparece `ErrorProceso::Corrupto`, el consumidor no compila hasta que alguien decida si se reintenta o va a la DLQ.

Y el segundo efecto, más silencioso: **un `panic!` no es un `Result`**. Un bug del propio consumidor no puede confundirse con un mensaje venenoso porque no viaja por el mismo canal. Es la causa raíz número 5 del caso, y Rust es el único stack donde estructuralmente no puede ocurrir. Ver [`rust/README.md`](rust/README.md).

## 🔵 .NET 8 — el único que decide sin destruir la evidencia

```csharp
catch (ErrorProceso e) when (e.EsTransitorio)  { Reintentar(); }
catch (ErrorProceso e) when (!e.EsTransitorio) { ADlq(msg, e.Clase); }
```

La diferencia con `catch` + `if` + `throw;` **no es de estilo**: el filtro `when` se evalúa **antes de desenrollar la pila**. Si ninguno matchea, el error sube con su stack trace completo.

Para este caso eso es el dato que falta: **un registro de DLQ sin el punto de falla original no sirve para depurar**. Es la única de las siete plataformas que puede clasificar sin acortar la pila.

Y el corolario canónico: `catch (Exception e) when (Log(e))` con un filtro que devuelve `false` es **registrar sin capturar**. Ver [`dotnet/README.md`](dotnet/README.md).

## ☕ Java 21 — la jerarquía más expresiva, con un costo estructural

`sealed ... permits` cierra la jerarquía: una clase de error nueva **tiene que declararse**, y con `switch` sobre patrones el compilador exige cubrir todas las ramas. Es lo más cerca que llega el set a la exhaustividad de Rust.

Lo que pierde contra .NET: **para clasificar hay que capturar, y capturar desenrolla la pila**. Al relanzar, el stack trace original ya perdió los frames de abajo.

Detalle que este caso hace visible: `super(m, null, false, false)` desactiva la captura del stack trace, que es lo que domina el costo de una excepción en un lazo caliente. Ver [`java/README.md`](java/README.md).

## 🐹 Go 1.23 — la mejor cadena de contexto, sin exhaustividad

`%w` acumula contexto sin borrar la causa, y `errors.Is` compara **por valor**, así que no se rompe al cruzar límites de paquete — que es exactamente donde falla el `instanceof` de Node.

Lo que no da: **nada obliga a manejar una clase nueva**. Agregar `ErrCorrupto` compila perfecto y cae en el camino por defecto.

Nótese la diferencia con el [caso 19](../19-search-index-drift-and-broken-cdc/comparison.md), donde Go queda segundo: allá el problema era **ignorar** el error, y el `_ =` lo hace visible. Acá el problema es **no mirarlo bien**, y para eso el error-como-valor no alcanza. Ver [`go/README.md`](go/README.md).

## 🐘 PHP 8.3 — union types y el drenaje como comando

`catch (A | B $e)` dice «estos dos se tratan igual» sin duplicar el bloque. Y `Throwable` como raíz común de `Exception` y `Error` **hace explícito** —a diferencia de Java, donde `Error` queda fuera— que capturar todo incluye capturar los bugs propios.

Y una ventaja operativa real: **el drenaje es un comando de cron**. `php bin/dlq:drain --limit=500 --dry-run` se ejecuta a mano en un incidente sin redesplegar nada, que es más de lo que puede decir un consumidor embebido.

En contra: **PHP no tiene exhaustividad de ninguna clase**. Ver [`php/README.md`](php/README.md).

## 🐍 Python 3.12 — cuatro líneas para clasificar, una palabra para arruinarlo

La jerarquía de excepciones clasifica sin ceremonia: cuatro líneas, sin anotaciones, sin `throws`. Y `raise ... from e` mantiene la cadena de causas visible en el traceback desde Python 3.0 — antes que el `%w` de Go y que el `error.cause` de Node.

El peligro está a una palabra: `except Exception` manda a la DLQ **también los bugs del consumidor**. Un `KeyError` por un typo termina indistinguible de un mensaje corrupto, y meses después la conclusión es «datos malos» en vez de «tuvimos un bug tres semanas». Ver [`python/README.md`](python/README.md).

## 🟢 Node.js 22 — el más débil del set, y justo donde el caso pega

Los errores de JavaScript son objetos comunes **sin jerarquía obligatoria**. `instanceof` funciona hasta que el error cruza un límite que rompe la cadena de prototipos: dos copias del mismo paquete, un `worker_thread`, una biblioteca nativa que trae `err.code` como string.

En producción la clasificación degrada a comparar strings —`/timeout/i.test(err.message)`— y eso funciona hasta que alguien cambia un mensaje de error.

`error.cause` llegó en ES2022: Go lo tiene desde 1.13, Python desde la versión 3.0. Ver [`node/README.md`](node/README.md).

---

## 🏁 Veredicto

| Puesto | Stack | Por qué |
|---|---|---|
| 🥇 | **Rust 1.83** | El `enum` con `match` exhaustivo es la primitiva exacta de un caso que trata de clasificar: **una clase de error nueva no compila** hasta que alguien decida qué hacer con ella. Y `panic!` como canal separado de `Result` hace estructuralmente imposible que un bug del consumidor termine en la DLQ disfrazado de dato malo. |
| 🥈 | **.NET 8** | Los filtros `when (...)` son la única primitiva del laboratorio que **decide antes de desenrollar la pila**. Para un registro de DLQ, conservar el punto de falla original es la diferencia entre poder depurarlo y no. |
| 🥉 | **Java 21** | La jerarquía `sealed` es la clasificación más expresiva del set y lo más cerca que llega a la exhaustividad de Rust. Detrás de .NET porque clasificar obliga a capturar, y capturar acorta la pila que la DLQ necesita. |
| 4º | **Go 1.23** | `errors.Is`/`As` sobre cadenas `%w` acumulan contexto sin perder la causa y sobreviven a los límites de paquete. Sin exhaustividad, una clase nueva cae en el camino por defecto sin que nada avise. |
| 5º | **PHP 8.3** | Union types en `catch`, `Throwable` avisando que capturar todo incluye los bugs propios, y el drenaje como comando de cron — una ventaja operativa real en un incidente. Ninguna ayuda del compilador. |
| 6º | **Python 3.12** | Clasifica en cuatro líneas y encadena causas desde la 3.0. Pierde puestos porque `except Exception` está a una palabra de distancia y nada en el lenguaje lo señala. |
| 7º | **Node.js 22** | El único stack donde la herramienta de clasificación —`instanceof`— **es frágil por diseño**: se rompe entre copias de paquete, workers y bibliotecas nativas. La alternativa práctica es comparar strings, y el caso entero depende de clasificar bien. |

> **Este es el último caso del laboratorio, y ordena por lo mismo que el [19](../19-search-index-drift-and-broken-cdc/comparison.md) con una vuelta más.** Allá la pregunta era qué hace el lenguaje cuando el programador **no mira** el error. Acá es qué hace cuando lo mira **mal**. Solo Rust responde con el compilador; los otros seis responden con disciplina — y la disciplina no aparece en el diff.

---

[⬅️ Volver al caso 20](README.md) · [🧬 Perfiles de lenguaje](../../docs/languages/README.md) · [📚 Catálogo de casos](../../docs/case-catalog.md)
