# ⚖️ Comparativa multi-stack — Caso 19

> **Deriva del índice de búsqueda y CDC roto** resuelto en los **7 stacks**, con el mismo contrato de rutas y las mismas métricas.
>
> [⬅️ Volver al caso](README.md) · [🧬 Perfiles de lenguaje](../../docs/languages/README.md)

---

## 🔬 Un caso donde los siete stacks dan el mismo número

El escenario es determinista —el fallo del índice se decide con un multiplicador primo sobre el índice de escritura— así que los siete producen resultados **idénticos hasta el último dígito**. Eso es a propósito: cuando el resultado es el mismo, lo único que queda para comparar es **cómo se escribe**.

```text
  dual-write:     db=951  index=960   missing=10  stale=50  orphan=19  drift=79
                  recall 98,95%   precision 98,02%   silent_failures=158

  outbox+barrido: db=951  index=951   missing=0   stale=0   orphan=0   drift=0
                  recall 100%     precision 100%     retries=157   checkpoint=2000
```

**98,95% de recall no se ve como un incidente.** Se ve como una búsqueda que anda.

---

## 🧩 Fidelidad del substrato

| Aspecto | Estado | Detalle |
|---|---|---|
| Diff de tres caras | ✅ **Real** | `missing` / `stale` / `orphan` calculados con la primitiva idiomática de cada runtime. |
| Outbox ordenado + checkpoint | ✅ **Real** | Aplicación en orden, reintento acotado, y el checkpoint que se frena en vez de saltear. |
| Barrido de reconciliación | ✅ **Real** | Compara los dos lados y repara las tres caras. |
| Recall y precisión | ✅ **Medidos** | Corriendo consultas de verdad contra los dos lados. |
| Motor de búsqueda | 🟡 **Modelado** | Un diccionario en memoria (un archivo JSON en PHP), no Elasticsearch. |
| Fallo de escritura | 🟡 **Determinista** | Multiplicador primo sobre el índice, para que el escenario sea reproducible. |
| `drift_age_ms` | 🟡 **A escala de laboratorio** | Sale en milisegundos porque todo el escenario corre en decenas. En producción se mide en minutos y horas; la interpretación es la misma. |

> **Por qué el motor no importa.** Lo que define este caso no es Elasticsearch: es que **la base y el índice son dos sistemas sin transacción común**. Eso es igual de cierto con un `dict`.

---

## 🔑 Cómo cada runtime trata las dos mitades del caso

El caso tiene dos mitades, y ningún stack gana las dos por defecto: **impedir que el error se ignore** y **expresar el diagnóstico**.

| Stack | Contra ignorar el error | Para el diff de tres caras |
|---|---|---|
| 🦀 **Rust 1.83** | `#[must_use]` en la `std` + `deny(unused_must_use)` → **no compila** | `HashSet::difference` / `intersection` |
| 🐹 **Go 1.23** | `_ =` visible en el diff + `errcheck` en CI | **No hay tipo conjunto**: recorridos a mano |
| 🔵 **.NET 8** | Nada (`_ = IndexarAsync()` es aún más silencioso) | `Except` / `Join` tipados |
| 🐍 **Python 3.12** | Nada (`except:` lo tapa) | `-`, `&` sobre `set`: **el más corto de los siete** |
| 🐘 **PHP 8.3** | Nada (`@` o `catch` vacío) | `array_diff_key` / `array_intersect_key` |
| ☕ **Java 21** | Nada, y `@Transactional` **sugiere** una atomicidad que no alcanza al índice | `removeAll` / `retainAll` sobre copias |
| 🟢 **Node.js 22** | Nada, y el bug se produce **por no escribir `await`** | `Map` / `Set` a mano |

---

## 🦀 Rust 1.83 — el único que tiene las dos piezas

Este caso nace de una escritura que falló y que nadie miró. En Rust, no mirarla **es algo que hay que escribir**:

```rust
indice.escribir(&doc);          // warning: unused `Result` that must be used
let _ = indice.escribir(&doc);  // compila — y el `let _ =` queda en el diff
```

La advertencia sale **sin configurar nada**: `#[must_use]` está en la definición de `Result` en la `std`. Con `#![deny(unused_must_use)]` —una línea, que este archivo tiene puesta— pasa a ser error de compilación.

Y `HashSet::difference` da el diff de tres caras sin recorrer a mano. Es el único stack del laboratorio con **las dos** piezas. Ver [`rust/README.md`](rust/README.md).

## 🐹 Go 1.23 — el error se ve, el diff se escribe

`_ =` no es azúcar: es una declaración de intención que queda en el diff y se busca con `grep`. Y `errcheck` —presente en casi todos los CI de Go— convierte en build rojo la llamada cuyo error se ignora sin decirlo.

Segunda mejor defensa de los siete, y la diferencia con Rust es de origen: `#[must_use]` está en la biblioteca estándar; `errcheck` es una herramienta externa que alguien tiene que instalar.

En contra: **Go no tiene tipo conjunto**. Tres líneas de álgebra en Python son tres recorridos a mano acá. Es la simetría exacta del [caso 17](../17-zero-downtime-schema-migration/python/README.md), donde Python no tenía read-write lock: la ausencia de una primitiva se paga en el mismo lugar. Ver [`go/README.md`](go/README.md).

## 🔵 .NET 8 — el diagnóstico como consulta tipada

`Except` para `missing` y `orphan`, `Join` para `stale`, con el compilador verificando los tipos de las claves en cada paso. Es el único que expresa las tres caras como **una sola forma**.

La trampa que viene con eso: **LINQ es perezoso**. `Except` no ejecuta nada hasta que alguien enumera, así que un diff calculado bajo un lock y enumerado después puede leer un estado distinto del que comparó. Los `.ToList()` del código no son adorno.

Y comparte el problema de Java con uno propio: `_ = IndexarAsync(doc)` sin `await` manda la excepción a un `Task` que nadie observa, y desde .NET Core eso ni siquiera termina el proceso. Ver [`dotnet/README.md`](dotnet/README.md).

## 🐍 Python 3.12 — el diagnóstico más corto y el bug más corto

```python
missing = db_ids - index_ids
orphan  = index_ids - db_ids
stale   = {i for i in db_ids & index_ids if index[i].version != db[i].version}
```

Ningún otro stack lo escribe tan corto. Es el [caso 17](../17-zero-downtime-schema-migration/python/README.md) al revés: allá la stdlib no traía la primitiva; acá la trae y es la mejor de las siete.

Y la contracara, que hay que decir: `except: pass` es el caso entero en tres palabras. **Python hace el diagnóstico fácil y el bug también.** Ver [`python/README.md`](python/README.md).

## 🐘 PHP 8.3 — el checkpoint durable no es buena práctica, es la única opción

En un runtime share-nothing no hay proceso de larga vida donde vivir un consumidor de CDC: el consumidor **es un comando de cron**. Eso obliga a que el checkpoint sobreviva al proceso.

En Java, Go o .NET el consumidor vive en memoria y es tentador dejar el checkpoint ahí — hasta el primer reinicio. En PHP no hay «ahí», y ese constraint del lenguaje empuja al diseño correcto sin que nadie tenga que acordarse.

En contra: es el único de los siete donde **nada** ayuda a no ignorar el error. `@$indice->escribir($doc)` compila, corre y calla. Ver [`php/README.md`](php/README.md).

## ☕ Java 21 — el framework sugiere una atomicidad que no existe

```java
@Transactional
public void guardar(Documento d) {
    repo.save(d);          // participa de la transacción
    buscador.indexar(d);   // NO participa: es HTTP a otro sistema
}
```

La anotación no miente: cubre lo que puede cubrir. Lo que engaña es que **nada en el código marca dónde termina su alcance**, y el método se lee como una unidad.

A favor: `ConcurrentSkipListMap.tailMap(checkpoint, false)` convierte «lo pendiente» de un filtro en una consulta, ya ordenada y concurrente. Es la mejor expresión del outbox de los siete. Ver [`java/README.md`](java/README.md).

## 🟢 Node.js 22 — el único donde el bug es NO escribir algo

```js
await indice.escribir(doc);   // el error sube y se maneja
indice.escribir(doc);         // el error se va a un rechazo sin dueño
```

En los otros seis hay que **escribir el silencio**. Acá basta con no escribir cuatro letras, y las dos líneas parecen correctas en una revisión rápida.

Desde Node 15 la promesa rechazada mata el proceso en vez de callarse — mejor que el silencio, y todavía peor que un error manejado: un crash arbitrario con un stack que apunta a la promesa y no a quien la creó. `no-floating-promises` es la única defensa real, y no viene puesta.

A favor: el modelo de un solo hilo hace que la escritura a la base y la anotación en el outbox sean **atómicas sin ningún lock**. Ver [`node/README.md`](node/README.md).

---

## 🏁 Veredicto

| Puesto | Stack | Por qué |
|---|---|---|
| 🥇 | **Rust 1.83** | El único con las dos piezas: `#[must_use]` hace que el bug original —ignorar la escritura fallida— **no compile** sin escribirlo a propósito, y `HashSet` da el diff de tres caras sin recorrer a mano. Y la defensa está en la biblioteca estándar, no en una herramienta que hay que instalar. |
| 🥈 | **Go 1.23** | El `_ =` es una declaración de intención auditable, y `errcheck` está en casi todos los CI. Detrás de Rust por dos razones: la herramienta es externa, y sin tipo conjunto el diagnóstico se escribe a mano. |
| 🥉 | **.NET 8** | `Except` y `Join` expresan las tres caras como consultas tipadas — la forma más legible del set. No tiene defensa contra ignorar el error, y su pereza obliga a acordarse del `.ToList()`. |
| 4º | **Python 3.12** | El diagnóstico más corto de los siete. Y el bug más corto también: `except: pass` es el caso entero en tres palabras, sin nada del lenguaje que lo señale. |
| 5º | **PHP 8.3** | Su modelo share-nothing **obliga** al checkpoint durable, que es la decisión que los stacks con procesos largos suelen postergar. Pierde puestos porque no ofrece ninguna ayuda contra ignorar el error. |
| 6º | **Java 21** | `ConcurrentSkipListMap.tailMap` es la mejor expresión del outbox del set, y `@Transactional` es el único elemento del laboratorio que **activamente sugiere** una garantía que no da. Un framework que engaña pesa más que una primitiva que ayuda. |
| 7º | **Node.js 22** | El único stack donde el bug se produce **por no escribir algo**. Su atomicidad de un solo hilo es una ventaja real para el outbox, y no compensa que la causa raíz del caso sea invisible en una revisión de código. |

> **Este caso ordena por una dimensión que ninguno de los otros dieciocho usa: qué hace el lenguaje cuando el programador no mira.** Rust y Go tienen una respuesta; los otros cinco no. Java queda sexto no por lo que le falta sino por lo que promete de más — que es un modo de falla peor que la ausencia.

---

[⬅️ Volver al caso 19](README.md) · [🧬 Perfiles de lenguaje](../../docs/languages/README.md) · [📚 Catálogo de casos](../../docs/case-catalog.md)
