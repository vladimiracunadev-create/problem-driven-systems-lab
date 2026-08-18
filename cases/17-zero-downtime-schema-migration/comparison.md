# ⚖️ Caso 17 — Comparativa multi-stack: Migración de esquema sin downtime (PHP · Python · Node.js · Java · .NET · Go · Rust)

> **TL;DR** — Con 20.000 filas y 8 lectores concurrentes, el `ALTER TABLE` bloqueante mantiene el lock **400 ms de corrido** y rechaza lectores. Expand-contract hace el mismo trabajo en 10 lotes: el lock más largo baja a **40 ms** y **ningún lector falla**. `lock_held_ms` total es casi idéntico en las dos: **el trabajo no desaparece, se reparte**. Lo que cambia entre stacks es cuánto ayuda cada uno a darle un plazo al lector.

<!-- nav -->
`🐘 PHP 8.3` · `🐍 Python 3.12` · `🟢 Node.js 22` · `☕ Java 21` · `🔵 .NET 8` · `🐹 Go 1.23` · `🦀 Rust 1.83`

**Estructura:** 🎯 el problema → 🧪 fidelidad del substrato → una sección por stack → ⚖️ tabla de decisión → 📊 primitiva por stack → 🏁 veredicto y ranking
<!-- /nav -->

## 🎯 El problema que los siete resuelven

Un `ALTER TABLE` sobre una tabla caliente toma el lock exclusivo y no lo suelta hasta terminar. Veinte minutos de 503 por agregar una columna.

Lo incómodo es que **el trabajo total no cambia**. Rellenar dos millones de filas cuesta lo que cuesta. Lo que cambia es cómo se reparte: un lock de veinte minutos contra mil locks de un segundo.

Y hay algo que lo vuelve difícil de detectar: **el proceso sigue vivo**. El healthcheck responde, el contenedor no se reinicia, ninguna alerta de proceso dispara. Lo único que falla son las peticiones.

La solución tiene cuatro fases y un orden que no es negociable: **expand → backfill → switch → contract**. El switch va antes del contract porque el feature flag es lo único reversible en un segundo.

---

## 🧪 Fidelidad del substrato

| Qué | Es real | Es simulado |
|---|---|---|
| El lock de la tabla | ✅ El read-write lock real de cada runtime | ⚠️ No hay PostgreSQL: es un lock del proceso (salvo PHP) |
| La contención lector/escritor | ✅ Hilos o goroutines reales en 6 stacks | ⚠️ Secuencial en PHP |
| El deadline del lector | ✅ Con la primitiva real de cada stack | — |
| El tiempo de migración | ✅ Una espera, no CPU | — |
| Las cuatro fases y el feature flag | ✅ Estado real, observable en `/migration/state` | — |

**La excepción interesante es PHP**: su `flock` **sí** es un read-write lock del sistema operativo, entre procesos. Es el único de los siete que coordina procesos y no hilos — que es lo que hace de verdad un motor de base de datos.

---

## 🐘 PHP: el único read-write lock que lo da el sistema operativo

```php
flock($fh, LOCK_SH);            // lock compartido: varios lectores
flock($fh, LOCK_EX);            // lock exclusivo: uno solo, sin lectores
flock($fh, LOCK_SH | LOCK_NB);  // el intento con deadline
```

Los otros seis coordinan **hilos de un mismo proceso**. Este coordina **procesos distintos**, y es el mismo mecanismo que usan los motores por debajo. Un `ALTER TABLE` no bloquea hilos de tu aplicación: bloquea a todos los clientes del motor, estén donde estén.

Y `LOCK_NB` resuelve de fábrica el problema que Go arma con una goroutine y Rust con un spin.

---

## 🐍 Python: la primitiva no existe, y por eso hay que entenderla

**La stdlib de Python no tiene read-write lock.** Hay `Lock`, `RLock`, `Semaphore`, `Condition`, `Event` y `Barrier`. No hay `RWLock`.

```python
while self._writer or self._writer_waiting > 0:   # ← la bandera
    ...
```

Construirlo mal es fácil: la versión ingenua —solo un contador de lectores— deja al escritor esperando **para siempre** mientras siga entrando tráfico. En una migración eso significa que el `ALTER TABLE` nunca arranca y la aplicación funciona perfecto: el peor modo de fallar, porque nada se ve roto.

Esa bandera es exactamente lo que Java pide con `new ReentrantReadWriteLock(true)` y lo que .NET **no** ofrece.

---

## 🟢 Node.js: no hay lock, y el caso ocurre igual — de la forma más literal

No hay `RWMutex`, no hay nada que adquirir. Y sin embargo:

**el «lock exclusivo» en Node es el event loop.**

```js
Atomics.wait(shared, 0, 0, ms);   // duerme el hilo entero, sin ceder el turno
```

Un bucle sincrónico de 400 ms no bloquea una tabla: bloquea el proceso entero. Ningún request, ningún timer, ningún socket. La migración no compite con los lectores por un recurso — **se los come**.

La consecuencia dura: el lector no tiene deadline que lo salve, porque **su propio timeout tampoco puede dispararse**. En los otros seis, un lector con `tryLock(120ms)` al menos falla rápido y devuelve 503. Acá no falla: no responde.

Por eso el `await` entre lotes no es una optimización — es el único mecanismo de equidad que existe.

---

## ☕ Java: deadline y equidad, los dos disponibles

```java
rwLock.readLock().tryLock(120, TimeUnit.MILLISECONDS);   // deadline
new ReentrantReadWriteLock(true);                        // equidad
```

Es el único stack que trae las dos cosas de fábrica.

El flag de equidad importa más de lo que parece: **por defecto el lock no es justo**, y con tráfico de lectura constante el escritor puede no entrar nunca. La migración no arranca, y la aplicación funciona perfecto — nada se ve roto.

---

## 🔵 .NET: el deadline como valor, y una carencia que hay que decir

```csharp
if (rwLock.TryEnterReadLock(120)) { ... }   // devuelve false, no lanza
```

Igual que en el caso 14: «no pude leer» es un camino del código, no un `catch`. Es la distinción que el handler necesita para devolver 503 en vez de 500.

Detalle que solo este stack pone a la vista: **`ReaderWriterLockSlim` es `IDisposable`**. Un lock con recursos nativos en un runtime con GC — el recordatorio de que el GC no administra todo.

Y la carencia: **no es justo y no tiene modo justo**. La documentación lo dice: favorece a los lectores. Es el problema que Java resuelve con una perilla y Python con una bandera; acá hay que construirlo encima.

---

## 🐹 Go: lo más simple, sin hambruna, y sin deadline

`sync.RWMutex`: cuatro métodos, cero configuración. Y sin hambruna de escritor — un escritor bloqueado impide que entren lectores nuevos, que es lo que Java pide con un flag.

**Pero no tiene `RLock` con timeout.** `TryRLock()` devuelve inmediatamente; no hay «esperá 120 ms y después rendite». Hay que armarlo:

```go
go func() { rw.RLock(); close(got) }()
select {
case <-got:      return true
case <-timer.C:  go func() { <-got; rw.RUnlock() }(); return false
}
```

Y ahí aparece el detalle que solo se ve escribiéndolo: **el lector se rindió, su goroutine no**. Sigue esperando el lock, por eso hay que dejarle una segunda goroutine que lo libere. En una migración larga es una fuga de goroutines proporcional al tráfico.

---

## 🦀 Rust: el caso donde su respuesta es la peor de las siete

`std::sync::RwLock` **no tiene deadline de ninguna clase**. Solo `read()`, que espera para siempre, y `try_read()`, que no espera nada. `try_read_for(Duration)` vive en `parking_lot`, una crate externa.

Los otros seis lo tienen o lo pueden construir durmiendo. Rust, dentro de la `std`, solo puede hacer spin:

```rust
loop {
    if let Ok(guard) = TABLE.try_read() { return true; }
    if Instant::now() >= deadline { return false; }
    thread::sleep(Duration::from_micros(200));
}
```

Consume CPU mientras espera. Vale decirlo con el mismo énfasis con el que se dicen sus ventajas en los casos 12, 14 y 16: **un laboratorio que solo muestra dónde gana un lenguaje no es un laboratorio, es publicidad**.

Lo que sí aporta: los **guards** sueltan el lock en su `Drop`. No existe el camino de salida que olvida el unlock — en Go hay que escribir el `defer`, en Java el `finally`, en .NET el `try/finally`.

---

## ⚖️ Tabla de decisión

| Pregunta | Respuesta |
|---|---|
| ¿La migración tarda menos con expand-contract? | **No, tarda más.** Las pausas entre lotes son tiempo agregado a propósito. Optimizar la duración total es optimizar la métrica equivocada. |
| ¿Cuál es la métrica que importa? | `longest_single_lock_ms`. El total es casi el mismo en las dos variantes; lo que decide si la app se cae es el lock más largo. |
| ¿Por qué el switch va antes del contract? | Porque el feature flag es lo único reversible en un segundo. Si se borra la columna vieja primero, volver atrás requiere otra migración. |
| ¿Por qué en staging pasó? | Porque tenía mil filas y producción dos millones. La diferencia entre 180 ms y 22 minutos es el volumen. |
| ¿Por qué no disparó ninguna alerta? | Porque `/health` no toca la base — una buena práctica que, ese día, garantizó que nadie se enterara. La respuesta es un `/ready` separado ([caso 18](../18-cold-start-and-autoscale-lag/README.md)). |
| ¿Lotes más chicos son siempre mejores? | No. Lotes muy pequeños multiplican el overhead de transacción y el backfill puede no terminar nunca. |

---

## 📊 Primitiva central por stack

| Stack | Read-write lock | Deadline del lector | ¿Hambruna de escritor? |
|---|---|---|---|
| 🐘 PHP | `flock` **del sistema operativo, entre procesos** | `LOCK_NB` de fábrica | La resuelve el SO |
| 🐍 Python | **no existe** — se construye con `Condition` | `Condition.wait(timeout)` | Solo con la bandera que uno escriba |
| 🟢 Node.js | **no existe** — el lock es el event loop | **imposible**: el timeout tampoco dispara | No aplica |
| ☕ Java | `ReentrantReadWriteLock` | `tryLock(timeout, unit)` | **Se evita con el flag de equidad** |
| 🔵 .NET | `ReaderWriterLockSlim` (`IDisposable`) | `TryEnterReadLock(ms)` | **No hay modo justo** |
| 🐹 Go | `sync.RWMutex` | armado con goroutine + `select` | No hay, por diseño |
| 🦀 Rust | `std::sync::RwLock` | **solo spin acotado** | No documentada |

---

## 🏁 Veredicto

> Mide **fit con el problema**, no calidad del lenguaje. Acá el criterio: qué tanto ayuda el stack a que un lector tenga un plazo y a que el escritor entre alguna vez.

| Puesto | Stack | Por qué |
|---|---|---|
| 🥇 | **Java 21** | El único que trae **deadline y equidad de fábrica**: `tryLock(timeout, unit)` y el flag de justicia en el constructor. Son las dos cosas que este caso necesita, y ningún otro stack tiene ambas. |
| 🥈 | **PHP 8.3** | Su `flock` es el único read-write lock del lab que lo provee **el sistema operativo y coordina procesos**, que es lo que hace de verdad un motor. `LOCK_NB` da el deadline sin construir nada. |
| 🥉 | **.NET 8** | `TryEnterReadLock(ms)` devuelve `false` en vez de lanzar, lo que separa «no pude leer» de «falló». Baja del podio más alto porque no tiene modo justo y la documentación admite que favorece a los lectores. |
| 4º | **Go 1.23** | `sync.RWMutex` es lo más simple del set y no tiene hambruna. Pierde por la carencia del deadline: armarlo con goroutine y `select` funciona, pero deja una goroutine viva por cada lector que se rindió. |
| 5º | **Python 3.12** | No tiene la primitiva y hay que escribirla — con la ventaja didáctica de entender qué hace por dentro, y el riesgo de que la versión ingenua deje al escritor sin entrar nunca. |
| 6º | **Rust 1.83** | La `std` no ofrece deadline de ninguna clase, así que la única opción es un spin que consume CPU. Es **el caso donde la respuesta de Rust es peor que la de los otros seis**, y salva el puesto porque los guards eliminan el unlock olvidado. |
| 7º | **Node.js 22** | No tiene locks, y el modo de falla es el más severo: el lock exclusivo es el event loop entero, y **ni siquiera el timeout del lector puede dispararse**. No falla rápido: no responde. |

**Lectura honesta:** los siete llegan al mismo resultado —0 lectores rechazados y el lock más largo dividido por diez— porque el patrón es de arquitectura, no de lenguaje. Si este caso te deja con la conclusión «debería migrar a Java», lo leíste al revés. La conclusión es «debería partir mi migración en lotes y medir el lock más largo en vez del total».
