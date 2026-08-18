# ⚖️ Caso 16 — Comparativa multi-stack: Idempotencia y efectos duplicados (PHP · Python · Node.js · Java · .NET · Go · Rust)

> **TL;DR** — Cinco reintentos del mismo pago sin clave de idempotencia aplican **5 cargos** y emiten **5 emails**: $100 cobrados de más sobre una operación de $25. Con reserva atómica de la clave: **1 cargo, 4 duplicados evitados, 1 efecto**. Los siete stacks dan el mismo número. Pero hay una asimetría que el ranking no captura: **seis de las siete versiones dejan de ser correctas con dos réplicas**, y la séptima es la que peor puntúa.

<!-- nav -->
`🐘 PHP 8.3` · `🐍 Python 3.12` · `🟢 Node.js 22` · `☕ Java 21` · `🔵 .NET 8` · `🐹 Go 1.23` · `🦀 Rust 1.83`

**Estructura:** 🎯 el problema → 🧪 fidelidad del substrato → una sección por stack → ⚖️ tabla de decisión → 📊 primitiva por stack → 🏁 veredicto y ranking
<!-- /nav -->

## 🎯 El problema que los siete resuelven

Un cliente reintenta porque el primer intento dio timeout. **El primer intento sí llegó** — lo que se perdió fue la respuesta.

El cliente no puede distinguir «no llegó» de «llegó y no me enteré», así que reintenta, y hace bien. El problema está del otro lado: el servidor tampoco puede distinguir «primera vez» de «ya procesado», salvo que el cliente le dé una `Idempotency-Key`.

Y la operación que hace funcionar esa clave tiene un requisito que parece menor y no lo es: **reservarla tiene que ser una sola operación indivisible**.

```
if (!existe(key)) { crear(key) }    ← dos operaciones, una ventana en el medio
putIfAbsent(key, v)                 ← una operación
```

Con cinco reintentos concurrentes, esa ventana produce cinco cobros. Y el código con `if` se ve razonable en la review.

La segunda mitad del caso es el **outbox pattern**: el cargo va a la base y el email a una cola, dos sistemas sin transacción que los abarque.

---

## 🧪 Fidelidad del substrato

| Qué | Es real | Es simulado |
|---|---|---|
| La carrera entre reintentos | ✅ Hilos, goroutines o tareas reales con barrera de largada en 6 stacks | ⚠️ Secuencial en PHP |
| La operación atómica de reserva | ✅ La primitiva real de cada runtime | — |
| El ledger y la tabla de idempotencia | — | ⚠️ Estructuras en memoria; en PHP, un archivo con `flock` |
| El efecto lateral | — | ⚠️ Se anota en una lista, no sale un email |
| El outbox | ✅ Escritura en la misma sección crítica que el cargo, drenado por un paso aparte | — |

**La asimetría que importa, dicha de frente:** seis de las siete versiones resuelven la carrera **dentro de su proceso**. Con dos réplicas dejan de ser correctas — cada pod tiene su tabla, ninguno ve las claves del otro, y el mismo pago se cobra una vez por pod. Solo la versión PHP, que por obligación pone la clave en almacenamiento compartido, sobrevive a eso.

---

## 🐘 PHP: la única versión que escala, por obligación

PHP no comparte heap entre requests, así que la clave **tiene** que vivir en el almacenamiento y la atomicidad la aporta el motor:

```sql
INSERT INTO idempotency_keys (key, response)
VALUES (:key, NULL)
ON CONFLICT (key) DO NOTHING
RETURNING id;
```

Si devuelve una fila, ganaste. Si no, es un reintento. Es exactamente `putIfAbsent` — pero garantizado por un `UNIQUE` del motor en vez de por el heap de un proceso.

**Y acá está lo incómodo del caso:** esta es la única de las siete versiones que sigue siendo correcta con veinte réplicas. El stack que peor puntúa en fit de primitivas es el que tiene la respuesta que escala.

---

## 🐍 Python: `setdefault`, y por qué el `Lock` está igual

```python
existing = table.setdefault(key, placeholder)
leader = existing is placeholder
```

`setdefault` hace en una llamada lo que `if key not in d: d[key] = v` hace en dos.

**El detalle incómodo:** CPython garantiza que no se interrumpe a la mitad, pero eso es una propiedad de la *implementación*, no del lenguaje. No está en la especificación, y una implementación sin GIL —PyPy con STM, o el free-threading de PEP 703— no tiene por qué mantenerlo.

Por eso el código toma igual un `Lock` explícito: apoyarse en el GIL para expresar «esto es indivisible» es depender de un detalle que puede cambiar.

---

## 🟢 Node.js: la primitiva distintiva es una ausencia

```js
if (!table.has(key)) table.set(key, entry);   // atómico en Node
```

Node **no tiene ninguna operación atómica de mapa**, porque no la necesita: entre esas dos líneas no puede correr nada más. En Java, Go o Rust ese mismo código es un bug de concurrencia; acá es correcto.

**Y esa es la trampa:** el código ingenuo funciona en un proceso y deja de funcionar en cuanto hay dos. Con `cluster`, PM2 en modo fork o dos pods, cada proceso tiene su `Map` y ninguno ve las claves del otro.

No hay error de compilación, no hay warning, no hay test que lo detecte. **Es el único stack donde la corrección depende de cuántos procesos hay** — y el código correcto para uno es incorrecto para dos sin cambiar una línea.

---

## ☕ Java: `putIfAbsent` resuelve la carrera y dice quién ganó

```java
Entry winner = idempotency.putIfAbsent(key, mine);
if (winner == null) { /* soy el primero */ }
```

Devuelve `null` si ganaste y el valor existente si perdiste: en una sola llamada resuelve la carrera **y** te dice de qué lado quedaste.

El contraste con la versión rota son dos líneas contra una, y la de dos líneas es la que se escribe sola cuando uno no está pensando en concurrencia.

---

## 🔵 .NET: `TryAdd`, y el contraste con el caso 13 en la misma clase

```csharp
if (Idempotency.TryAdd(key, mine))   // "es la primera vez que veo esto"
```

El `if` se lee como la pregunta del negocio.

**Lo interesante es el contraste interno:** en el [caso 13](../13-cache-stampede-and-thundering-herd/dotnet/README.md), `GetOrAdd` **no** garantizaba fábrica única y hubo que envolver el trabajo en `Lazy<T>`. Acá `TryAdd` **sí** es atómico — porque no ejecuta ninguna fábrica, solo inserta un valor ya construido.

Las dos APIs viven en la misma clase con garantías distintas, y saber cuál es cuál es la diferencia entre cobrar una vez y cobrar cinco.

---

## 🐹 Go: `LoadOrStore`, y el caso donde `sync.Map` sí corresponde

```go
actual, loaded := idempotency.LoadOrStore(key, mine)
```

El mismo contrato del comma-ok que Go usa en todas partes: valor y bandera en una operación.

**Lo distintivo acá es el *cuándo*, no el *qué*.** `sync.Map` está documentado para claves que se escriben una vez y se leen muchas — exactamente este caso. Y es lo contrario del [caso 13](../13-cache-stampede-and-thundering-herd/go/README.md), donde un `map` bajo mutex era mejor porque cada entrada se creaba y se borraba en cada expiración.

Mismo laboratorio, dos casos, dos respuestas opuestas. La regla que las separa es el patrón de escritura, no la preferencia.

---

## 🦀 Rust: el compilador exige contemplar las dos ramas

```rust
match table.entry(key) {
    Entry::Occupied(e) => { /* ya estaba: es un reintento */ }
    Entry::Vacant(e)   => { e.insert(v); /* soy el primero */ }
}
```

Es la misma operación que las otras tres, con una diferencia decisiva: **en Java, .NET y Go, ignorar el valor de retorno compila**.

```java
table.putIfAbsent(key, entry);   // ← retorno descartado; compila
```

Ese descarte silencioso es exactamente el bug del caso: el código reserva la clave y sigue como si hubiera ganado, sin mirar si perdió.

Y hay algo más que solo Rust aporta: el `Entry` **toma prestado el mapa** mientras existe. La ventana check-then-act no es difícil de escribir — **es inexpresable**, porque nadie más puede tocar el mapa hasta que se resuelva el `match`.

---

## ⚖️ Tabla de decisión

| Pregunta | Respuesta |
|---|---|
| ¿No alcanza con que el cliente no reintente? | No. Sin reintento se pierden operaciones legítimas cuando la red falla. El cliente hace lo correcto; el servidor tiene que distinguir. |
| ¿Basta con un `if (!existe)`? | No. Son dos operaciones con una ventana en el medio, y bajo concurrencia esa ventana es el bug entero. |
| ¿Qué devolver ante un reintento? | **Exactamente la respuesta original**, no un `409`. Un error obliga al cliente a interpretar; la respuesta guardada no. |
| ¿Cuánto vive la clave? | Es un compromiso, no un número correcto. Corta deja pasar reintentos tardíos; larga hace crecer la tabla. 24 h es convención. |
| ¿La tabla en memoria alcanza? | **Solo con una réplica.** Con dos, cada pod tiene la suya y el pago se cobra una vez por pod. Es el bug que aparece al escalar. |
| ¿Por qué hace falta el outbox? | Porque el cargo y el email viven en sistemas distintos. Sin outbox hay una ventana donde uno existe y el otro no. |
| ¿El outbox garantiza exactly-once? | No: at-least-once. Y es deliberado — duplicar un email es visible y corregible, perderlo no. |

---

## 📊 Primitiva central por stack

| Stack | Operación atómica de reserva | ¿Sobrevive a varias réplicas? |
|---|---|---|
| 🐘 PHP | `flock` sobre almacenamiento compartido (modela `ON CONFLICT DO NOTHING`) | ✅ **Sí** |
| 🐍 Python | `dict.setdefault` bajo `Lock` | ❌ No |
| 🟢 Node.js | `Map.has()` + `set()` — atómico por el modelo de un solo hilo | ❌ No |
| ☕ Java | `ConcurrentHashMap.putIfAbsent` | ❌ No |
| 🔵 .NET | `ConcurrentDictionary.TryAdd` | ❌ No |
| 🐹 Go | `sync.Map.LoadOrStore` | ❌ No |
| 🦀 Rust | `HashMap::entry` + `match` exhaustivo | ❌ No |

---

## 🏁 Veredicto

> Mide **fit con el problema**, no calidad del lenguaje. Acá el criterio: qué tanto ayuda el lenguaje a que la reserva sea atómica y a que el resultado no se pueda ignorar.

| Puesto | Stack | Por qué |
|---|---|---|
| 🥇 | **Rust 1.83** | El único donde ignorar el resultado de la reserva **no compila**: el `match` sobre `Occupied`/`Vacant` es exhaustivo. Y el `Entry` presta el mapa, así que la ventana check-then-act es inexpresable, no solo desaconsejada. |
| 🥈 | **Java 21** | `putIfAbsent` resuelve la carrera y dice quién ganó en una llamada. Es la formulación más directa del patrón, y la que los otros tres runtimes copiaron con otro nombre. |
| 🥉 | **Go 1.23** | `LoadOrStore` devuelve valor y bandera con el mismo contrato comma-ok de todo el lenguaje. Suma que `sync.Map` es aquí la estructura documentada para el caso — el opuesto exacto del 13. |
| 4º | **.NET 8** | `TryAdd` es igual de correcto y el `if` se lee como la pregunta del negocio. Baja un puesto porque convive con `GetOrAdd`, que parece equivalente y no lo es — la trampa del caso 13. |
| 5º | **Python 3.12** | `setdefault` expresa bien la operación, pero su atomicidad viene del GIL y no del contrato del lenguaje. Hay que agregar el `Lock` para decir lo que uno quiere decir. |
| 6º | **Node.js 22** | El código correcto es el más corto de los siete y **deja de ser correcto al escalar a dos procesos**, sin ningún aviso. Es el único stack donde la corrección depende del despliegue. |
| 7º | **PHP 8.3** | Sin heap compartido, la reserva es la más costosa de escribir. **Y es la única de las siete que sigue siendo correcta con veinte réplicas.** |

**Lectura honesta:** el ranking mide expresividad de la primitiva, y por eso PHP queda último. Pero si la pregunta fuera «¿cuál de estas siete implementaciones desplegarías con tres réplicas?», la respuesta sería **la de PHP y ninguna otra**.

Ese es el punto que el caso deja: las seis primeras resuelven la carrera dentro de su proceso, que es donde uno la ve. La séptima la resuelve donde realmente ocurre.
