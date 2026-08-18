# ⚖️ Comparativa multi-stack — Caso 18

> **Arranque en frío y retraso del autoescalado** resuelto en los **7 stacks**, con el mismo contrato de rutas y las mismas métricas.
>
> [⬅️ Volver al caso](README.md) · [🧬 Perfiles de lenguaje](../../docs/languages/README.md)

---

## 🔬 Este caso mide, no simula

Es la diferencia con los otros diecisiete casos del laboratorio, y conviene decirla primero.

El trabajo por petición es **el mismo lazo entero puro en los siete stacks**: `h = (h ^ i) * 16777619`, sin un solo `sleep`, sin I/O, sin asignación de memoria. Lo que se compara es `p99_first_100_ms` contra `p99_after_1000_ms`: **qué hace ese runtime con el mismo código repetido mil veces**.

El número resultante —`warmup_speedup_x`— no es una constante que alguien eligió. Es una medición.

| Stack | `warmup_speedup_x` medido | Qué lo explica |
|---|---|---|
| ☕ Java 21 | **51,9x** | Bytecode interpretado → C1 (~200 llamados) → C2 (~10.000, con perfil) |
| 🔵 .NET 8 | **2,3x** | Tier 0 sin optimizar → Tier 1 a los ~30 llamados, con OSR |
| 🐍 Python 3.12 | 1,8x | **No es JIT**: es contención con los hilos que inicializan bajo el GIL |
| 🟢 Node.js 22 | 1,1x | V8 llega a TurboFan muy rápido en un lazo así de simple |
| 🐘 PHP 8.3 | 1,1x | El JIT existe desde 8.0 pero viene apagado por defecto |
| 🐹 Go 1.23 | 1,0x | Binario AOT: la petición 1 corre el mismo código que la 100.000 |
| 🦀 Rust 1.83 | **1,00x** | Igual, y sin runtime ni GC que inicializar |

> ⚠️ **Honestidad sobre el número.** En la variante fría, `p99_first_100_ms` mezcla dos efectos reales: el calentamiento del runtime **y** la contención con las instancias que están inicializando en paralelo. Los dos ocurren de verdad durante un arranque en frío de producción, así que la mezcla es representativa — pero es una mezcla. El 1,8x de Python es contención pura: no hay JIT que lo explique. El 51,9x de Java no: es la JVM.

---

## 🧩 Fidelidad del substrato

| Aspecto | Estado | Detalle |
|---|---|---|
| Curva de calentamiento | ✅ **Medida** | Lazo entero puro, idéntico en los 7. Sin `sleep`, sin I/O. |
| CPU de la inicialización | ✅ **Real** | Construcción de una tabla de configuración por instancia. |
| I/O de la inicialización | 🟡 **Modelada** | `sleep` de `io_ms`: abrir el pool, DNS, TLS. Esperar a la red no quema CPU, y fijarla vuelve comparables a los 7. |
| Balanceador | 🟡 **En proceso** | Round-robin en memoria, no un LB externo. La distinción liveness/readiness es la real. |
| Autoescalador | 🟡 **Modelado** | Las instancias arrancan con el tráfico ya encima; no hay un HPA de verdad decidiendo. |
| Concurrencia (PHP) | 🟡 **Secuencial** | El servidor embebido es de un solo proceso: el solapamiento arranque/tráfico se modela con un instante de disponibilidad. |

---

## 📊 Resultados medidos — 2.400 peticiones, 3 instancias, 8 clientes, `io_ms=150`

| Stack | Fría: rechazadas | Fría: disponibilidad | Fría: hueco health→ready | Templada: rechazadas | Templada: disponibilidad |
|---|---|---|---|---|---|
| 🐘 PHP 8.3 | 912 | 62,00% | 150,0 ms | **0** | **100%** |
| 🐍 Python 3.12 | 1010 | 57,92% | 168,7 ms | **0** | **100%** |
| 🟢 Node.js 22 | 845 | 64,79% | 168,0 ms | **0** | **100%** |
| ☕ Java 21 | 958 | 60,08% | 165,6 ms | **0** | **100%** |
| 🔵 .NET 8 | 302 | 87,42% | 159,9 ms | **0** | **100%** |
| 🐹 Go 1.23 | 936 | 61,00% | 154,2 ms | **0** | **100%** |
| 🦀 Rust 1.83 | 959 | 60,04% | 154,2 ms | **0** | **100%** |

El número de rechazos depende de cuántas peticiones alcanza a emitir cada runtime dentro de la ventana de 150 ms, así que **no es una medida de calidad del stack** — es una consecuencia de su throughput. Lo que sí es comparable, y es idéntico en los siete: **el hueco existe en todos, y el pool tibio con enrutado por readiness lo cierra en todos**.

---

## 🔑 La primitiva de cada runtime

| Stack | Modelo de compilación | Inicialización perezosa | Salida contra el arranque en frío |
|---|---|---|---|
| 🐘 **PHP 8.3** | Interpretado + opcache | — (share-nothing) | `opcache` + `opcache.preload` + `pm.start_servers` |
| 🐍 **Python 3.12** | Bytecode interpretado, sin JIT | Imports diferidos | **Ninguna**: solo diseño del código |
| 🟢 **Node.js 22** | JIT en capas (Ignition→TurboFan) | Lazy `require` | `--build-snapshot`, SEA |
| ☕ **Java 21** | JIT en capas (interp→C1→C2) | `static` holder, `Lazy` | AppCDS, `TieredStopAtLevel`, GraalVM |
| 🔵 **.NET 8** | JIT en capas (Tier 0→Tier 1, OSR) | `Lazy<T>` | `PublishReadyToRun`, `TieredPGO`, `PublishAot` |
| 🐹 **Go 1.23** | AOT a binario estático | `sync.Once` | **No hace falta** |
| 🦀 **Rust 1.83** | AOT sin runtime ni GC | `OnceLock<T>`, `LazyLock<T>` | **No hace falta** |

---

## 🐘 PHP 8.3 — el único que arranca en frío en cada petición, por diseño

PHP es share-nothing: la petición termina y el proceso descarta todo. Lo que en Java es un problema de despliegue, en PHP sería un problema de **cada request** — si no fuera por `opcache`, que compila cada archivo a opcodes una vez y los guarda en **memoria compartida entre procesos**.

Es el equivalente exacto de `PublishReadyToRun` o de AppCDS, con dos diferencias: viene activado de fábrica, y su caché la comparten los procesos en vez de los hilos.

El corolario incómodo: cada worker nuevo de FPM vuelve a pagar lo que opcache no cubre. **El pool tibio de PHP es `pm.start_servers`: configuración, no código.**

Ver [`php/README.md`](php/README.md).

## 🐍 Python 3.12 — sin JIT, y sin artefacto compilado al que escapar

Ni compilación en capas, ni OSR, ni desoptimización: CPython compila a bytecode una vez y lo interpreta siempre igual. La petición 1 es tan rápida como la 100.000, y ninguna va a mejorar.

Lo que cuesta es el **arranque**: cada `import` compila, ejecuta el módulo completo y resuelve sus dependencias. Un proyecto con 200 módulos tarda segundos. Y a diferencia de .NET o Java, **Python no tiene artefacto compilado al que escapar** — la única palanca es de diseño.

Ver [`python/README.md`](python/README.md).

## 🟢 Node.js 22 — el JIT no es su problema; el grafo de `require` sí

V8 optimiza en capas de verdad, y desoptimiza si un tipo cambia. Pero el 1,1x medido acá dice que para un lazo simple llega a código optimizado casi de inmediato.

**El número es honesto e incompleto**: el arranque en frío de Node vive en `require`, que lee, parsea y ejecuta cada módulo del árbol antes de la primera línea propia. Este caso no lo mide, y por eso hay que decirlo en vez de dejar que el número lo tape. Los snapshots existen; no son el camino por defecto.

Ver [`node/README.md`](node/README.md).

## ☕ Java 21 — el caso canónico, medido en 52x

El mismo método, sin tocar una línea, corre **cincuenta veces más rápido** a la petición diez mil que a la primera. La instancia que el autoescalador acaba de sumar no solo tarda en estar lista: **atiende mal las primeras miles de peticiones**.

Y ahí está el hallazgo del [postmortem](docs/postmortem.md): esa lentitud mantiene la CPU alta, lo que vuelve a disparar al autoescalador, lo que produce más instancias frías. **El sistema se realimenta, y ninguna de las dos partes está rota.**

Java tiene la caja de herramientas más profunda contra su propio problema —AppCDS, `TieredStopAtLevel`, GraalVM `native-image`— y ninguna viene puesta por defecto.

Ver [`java/README.md`](java/README.md).

## 🔵 .NET 8 — el mismo problema, con la respuesta en la caja

2,3x de curva: existe, es real, y es de otro orden que la de la JVM porque RyuJIT llega a Tier 1 a los treinta llamados en vez de a los diez mil.

Y después están las tres líneas del `.csproj`: `PublishReadyToRun`, `TieredPGO`, `PublishAot`. Sin cambiar de distribución ni de toolchain.

**Esa es la razón del orden en este caso**: .NET no tiene mejores herramientas que Java, tiene las suyas activables con una línea. La diferencia entre «existe» y «está puesto».

Ver [`dotnet/README.md`](dotnet/README.md).

## 🐹 Go 1.23 — no gana por rápido, gana por no tener nada que calentar

Binario estático AOT: sin VM, sin JIT, sin classloader. `warmup_speedup_x` de 1,0x no es un experimento fallido, es el resultado.

Y `sync.Once` es la inicialización perezosa hecha explícita: el primer llamador la ejecuta, el resto espera, nunca corre dos veces. **También es la trampa**: una `sync.Once` en el camino de la petición convierte a la primera petición de cada proceso en la más lenta de todas.

Lo que Go no resuelve: abrir el pool, resolver DNS, negociar TLS. **Su ventaja es sobre la mitad de CPU del arranque, no sobre la de I/O** — y en un servicio real con cinco dependencias, la de I/O suele ser la más grande.

Ver [`go/README.md`](go/README.md).

## 🦀 Rust 1.83 — la curva más plana, y el estado «no lista» hecho inalcanzable

1,00x exacto. Sin VM, sin JIT, sin GC que inicializar.

`OnceLock` es el equivalente de `sync.Once` y de `Lazy<T>`, con una diferencia que ninguno de los dos tiene: **el tipo garantiza que el valor no se puede leer antes de estar inicializado**. `get()` devuelve `Option`, así que el estado «todavía no está lista» es inalcanzable, no solo improbable. Olvidar el chequeo de readiness deja de ser un bug de runtime y pasa a ser un error de compilación.

Después del [caso 17](../17-zero-downtime-schema-migration/rust/README.md), donde su respuesta fue la peor de los siete, este es el reverso exacto.

Ver [`rust/README.md`](rust/README.md).

---

## 🏁 Veredicto

| Puesto | Stack | Por qué |
|---|---|---|
| 🥇 | **Go 1.23** | Curva plana medida (1,0x), binario estático que arranca en milisegundos, y `sync.Once` como la forma más legible de decir «esto cuesta una sola vez». Gana además el ciclo completo: compila rápido, así que el bucle desplegar-escalar-desplegar es corto. |
| 🥈 | **Rust 1.83** | La curva más plana de todas (1,00x) y la única garantía de tipo del laboratorio contra servir desde una instancia no inicializada: con `OnceLock`, el estado «no lista» es inalcanzable. Detrás de Go solo por el tiempo de compilación, que en un caso sobre velocidad de despliegue es un costo real. |
| 🥉 | **.NET 8** | Tiene la curva (2,3x) y tiene la respuesta **en la caja**: `PublishReadyToRun`, `TieredPGO`, `PublishAot`. Tres líneas del `.csproj`, sin cambiar de toolchain. Es la mejor relación entre problema y fricción de la solución. |
| 4º | **PHP 8.3** | Sin curva que medir, y `opcache` activado de fábrica con caché compartida **entre procesos**. Pierde puestos porque arranca en frío estructuralmente, en cada petición, y porque su pool tibio vive en configuración de FPM en vez de en el código. |
| 5º | **Node.js 22** | Plano en lo medido (1,1x), pero su arranque en frío real vive en el grafo de `require`, que este caso no alcanza. Los snapshots resuelven buena parte y están fuera del camino por defecto. |
| 6º | **Python 3.12** | No hay curva porque no hay JIT, pero tiene el arranque más lento de los siete —cada `import` ejecuta el módulo entero— y **es el único sin ninguna salida compilada**. La única palanca es rediseñar los imports. |
| 7º | **Java 21** | 51,9x medidos: el arranque en frío canónico, y el único stack donde la lentitud posterior a estar «listo» **realimenta al autoescalador**. Tiene las herramientas más potentes contra su propio problema y ninguna viene activada. |

> **Java gana el [caso 17](../17-zero-downtime-schema-migration/comparison.md) y queda séptimo acá; Rust queda sexto allá y segundo acá.** Ese cruce es el punto del laboratorio: no hay un lenguaje mejor, hay problemas que premian modelos de ejecución distintos. Un caso que siempre ordena igual a los siete stacks no está midiendo nada.

---

[⬅️ Volver al caso 18](README.md) · [🧬 Perfiles de lenguaje](../../docs/languages/README.md) · [📚 Catálogo de casos](../../docs/case-catalog.md)
