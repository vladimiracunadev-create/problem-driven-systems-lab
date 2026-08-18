# 📝 CHANGELOG

Todos los cambios notables de este laboratorio se registran aqui con foco en madurez tecnica y documental.

## 2026-08-17 - Caso 18: arranque en frio y retraso del autoescalado en los 7 stacks

Sexto caso del **Eje 1 del ROADMAP**. El lab pasa a **18 casos x 7 stacks = 126
endpoints**.

### Added — caso 18, arranque en frio y retraso del autoescalado

`cases/18-cold-start-and-autoscale-lag/` con las **7 implementaciones**.

Contrato uniforme: `/boot-cold` y `/boot-warmed` con clientes concurrentes que
miden `availability_pct` **durante** el escalado, mas `/health` y `/ready`
separados de verdad y `/warmup` para construir el pool tibio antes del trafico.

Metrica central: **`health_vs_ready_gap_ms`** — la ventana exacta en la que el
sistema afirma estar disponible sin estarlo. No es cuanto tarda un servicio en
arrancar: es cuanto tiempo miente mientras arranca.

### Es el unico caso del lab que MIDE el runtime en vez de simularlo

En los diecisiete casos anteriores, el fenomeno se modela. Aca no.

El trabajo por peticion es **el mismo lazo entero puro en los siete stacks**,
sin un solo `sleep`, sin I/O, sin asignacion. `warmup_speedup_x` es el cociente
entre el p99 de las primeras 100 peticiones y el de las que siguen a la 1000:
**que hace ese runtime con el mismo codigo repetido mil veces**.

| Stack | `warmup_speedup_x` | Que lo explica |
|---|---|---|
| ☕ Java 21 | **51,9x** | interpretado → C1 (~200 llamados) → C2 (~10.000, con perfil) |
| 🔵 .NET 8 | **2,3x** | Tier 0 → Tier 1 a los ~30 llamados, con OSR |
| 🐍 Python 3.12 | 1,8x | **no es JIT**: es contencion con los hilos que inicializan |
| 🟢 Node.js 22 | 1,1x | V8 llega a TurboFan enseguida en un lazo asi de simple |
| 🐘 PHP 8.3 | 1,1x | el JIT existe desde 8.0 y viene apagado |
| 🐹 Go 1.23 | 1,0x | binario AOT: la peticion 1 corre el mismo codigo que la 100.000 |
| 🦀 Rust 1.83 | **1,00x** | igual, y sin runtime ni GC que inicializar |

Lo que si esta modelado, y queda escrito en cada README: la parte de I/O de la
inicializacion —abrir el pool, DNS, TLS— es un `sleep` de `io_ms`, porque
esperar a la red no quema CPU y fijarla es lo que vuelve comparables a los
siete. Y en la variante fria, `p99_first_100_ms` **mezcla** el calentamiento del
runtime con la contencion de las instancias que estan inicializando: los dos
efectos ocurren de verdad en produccion, pero es una mezcla. El 1,8x de Python
es contencion pura. El 51,9x de Java no.

### El hallazgo que no estaba en la especificacion: el sistema se realimenta

Del postmortem del caso: las instancias frias de Java atienden lento **despues**
de declararse listas. Esa lentitud mantiene la CPU alta. La CPU alta vuelve a
disparar al autoescalador. El autoescalador produce mas instancias frias.

Ninguna de las dos partes esta rota. Un sistema que escala por una metrica que
su propio arranque empeora se realimenta, y eso no aparece en ningun dashboard
porque no hay nada en rojo.

### Changed — el ranking se cruza en un caso

- **Go toma su septimo oro.** No gana por rapido: gana por **no tener nada que
  calentar**, y porque `sync.Once` es la forma mas legible del lab de decir
  "esto cuesta una sola vez". Que tambien es la trampa: una `sync.Once` en el
  camino de la peticion convierte a la primera peticion de cada proceso en la
  mas lenta de todas.
- **Rust segundo**, un caso despues de quedar sexto. `OnceLock` es el
  equivalente de `sync.Once` y de `Lazy<T>` con algo que ninguno de los dos
  tiene: **el tipo garantiza que el valor no se puede leer antes de estar
  inicializado**. Olvidar el chequeo de readiness deja de ser un bug de runtime
  y pasa a ser un error de compilacion.
- **.NET tercero** por una razon que no es tecnica sino de friccion: tiene la
  curva, y tiene la respuesta **en la caja**. `PublishReadyToRun`, `TieredPGO` y
  `PublishAot` son tres lineas del `.csproj`.
- **Java septimo**, un caso despues de ganar el 17. Tiene las herramientas mas
  potentes contra su propio problema —AppCDS, `TieredStopAtLevel`, GraalVM
  `native-image`— y ninguna viene activada. La diferencia con .NET no esta en
  tener herramientas: esta en que las de .NET vienen puestas.

Ese cruce —Java 🥇 en el 17 y 7º en el 18; Rust 6º en el 17 y 🥈 en el 18— es el
punto del laboratorio. **Un caso que siempre ordena igual a los siete stacks no
esta midiendo nada.**

### Changed — integracion

- **Dispatchers**: registro del caso 18 y puerto interno en los siete
  (`:9018` PHP/Python/Node, `:9418` Java, `:9518` .NET, `:9618` Go, `:9718` Rust).
- **`ci.yml`**: matriz `compose-config` de 127 a **134 archivos**; `hub-probe`
  valida 18 casos por stack; `compose-smoke` suma `case18-dotnet` y `case18-rust`.
- **`shared/catalog/cases.json`** + `docs/case-catalog.md` + los cinco SVG.
- **Perfiles de lenguaje**: agregado recalculado. **Go pasa a 7 oros.**

### Verificado

Los 7 stacks levantados con Docker. Con 2.400 peticiones, 3 instancias y 8
clientes: la variante fria rechaza entre el 12% y el 42% del trafico segun el
throughput de cada runtime, con el proceso vivo y `/health` en 200 todo el
tiempo; la variante con pool tibio y enrutado por readiness rechaza **cero, con
100% de disponibilidad**. Identico en los siete.

## 2026-08-17 - Caso 17: migracion de esquema sin downtime en los 7 stacks

Quinto caso del **Eje 1 del ROADMAP**. El lab pasa a **17 casos x 7 stacks = 119
endpoints**.

### Added — caso 17, migracion de esquema sin downtime

`cases/17-zero-downtime-schema-migration/` con las **7 implementaciones**.

Contrato uniforme: `/migrate-blocking` y `/migrate-expand-contract` con lectores
concurrentes que miden `availability_pct` **durante** la migracion, mas
`/migration/state` y `/backfill`. Metrica central: **`longest_single_lock_ms`**,
que resulto ser la que decide si la app se cae — distinta del tiempo total.

Expand-contract en cuatro fases, con el orden documentado:

1. **Expand** — columna nullable. Es metadata: instantaneo.
2. **Backfill** — por lotes, soltando el lock entre cada uno.
3. **Switch** — feature flag que cambia lecturas y escrituras.
4. **Contract** — recien ahora, en un despliegue posterior, se borra la vieja.

El switch va antes del contract porque **el flag es lo unico reversible en un
segundo**. Si se borra la columna vieja primero, volver atras requiere otra
migracion — y a esa altura ya no hay a donde volver.

### La premisa del ROADMAP resulto equivocada, y eso cambio el caso

El ROADMAP planeaba este caso solo para **PHP + PostgreSQL**, con el argumento
de que "los stacks de SQLite embebido lo modelan mas como ejercicio".

Al implementarlo en los siete quedo claro que el caso **no necesita un motor:
necesita un read-write lock**. Y ahi cada runtime tiene algo distinto que decir,
incluido el que no tiene la primitiva.

| Stack | Read-write lock | Deadline del lector |
|---|---|---|
| PHP | `flock` **del sistema operativo, entre procesos** | `LOCK_NB` de fabrica |
| Python | **no existe** — se construye sobre `Condition` | `Condition.wait(timeout)` |
| Node | **no existe** — el lock es el event loop | **imposible** |
| Java | `ReentrantReadWriteLock` | `tryLock(timeout, unit)` |
| .NET | `ReaderWriterLockSlim` (`IDisposable`) | `TryEnterReadLock(ms)` |
| Go | `sync.RWMutex` | armado con goroutine + `select` |
| Rust | `std::sync::RwLock` | **solo spin acotado** |

### Tres movimientos en el ranking que no habian pasado antes

- **PHP sube al segundo puesto** — primera vez en el Eje 1 que sale del ultimo
  lugar. Su `flock` con `LOCK_SH`/`LOCK_EX` es el unico read-write lock del
  laboratorio provisto por el **sistema operativo**, y el unico que coordina
  **procesos** en vez de hilos: exactamente lo que hace un motor de base de datos.
- **Rust cae al sexto**, y es **el primer caso del lab donde su respuesta es peor
  que la de los otros seis**. La `std` no ofrece `RwLock` con deadline de ninguna
  clase — ni `try_read_for`, ni nada equivalente — asi que la unica opcion sin
  crates externas es un spin que consume CPU en vez de dormir en el kernel.
  Quedo escrito con el mismo enfasis con el que se documentan sus ventajas en los
  casos 12, 14 y 16: un laboratorio que solo muestra donde gana un lenguaje no es
  un laboratorio, es publicidad.
- **Node septimo** con el modo de falla mas severo del caso: el lock exclusivo
  **es el event loop entero**, asi que ni siquiera el timeout del lector puede
  dispararse. En los otros seis un lector con `tryLock(120ms)` al menos falla
  rapido y devuelve 503; aca no falla — no responde.

**Java primero** por ser el unico stack con **deadline y equidad de fabrica**:
`tryLock(timeout, unit)` y el flag de justicia en el constructor. Sin ese flag,
el trafico de lectura constante puede impedir que el escritor entre nunca — la
migracion no arranca y la aplicacion funciona perfecto, que es el peor modo de
fallar porque nada se ve roto.

### Changed — integracion

- **Dispatchers**: registro del caso 17 y puerto interno en los siete
  (`:9017` PHP/Python/Node, `:9417` Java, `:9517` .NET, `:9617` Go, `:9717` Rust).
- **`ci.yml`**: matriz `compose-config` de 120 a **127 archivos**; `hub-probe`
  valida 17 casos por stack; `compose-smoke` suma `case17-java` y `case17-go`.
- **`shared/catalog/cases.json`** + `docs/case-catalog.md` + los cinco SVG.
- **Perfiles de lenguaje**: agregado recalculado. **Java pasa a 2 oros**.

### Verificado

Los 7 stacks levantados con Docker. Con 20.000 filas y 8 lectores concurrentes:
la variante bloqueante mantiene el lock ~400 ms de corrido y rechaza lectores
(24 en la mayoria de los stacks, 8 en PHP y Node por su modelo de ejecucion);
expand-contract hace el mismo trabajo en 10 lotes, baja el lock mas largo a
~40 ms y deja **0 lectores rechazados con 100% de disponibilidad**. Identico en
los siete.

`lock_held_ms` total es casi el mismo en las dos variantes: **el trabajo no
desaparece, se reparte**.

## 2026-08-17 - Caso 16: idempotencia y efectos duplicados en los 7 stacks

Cuarto caso del **Eje 1 del ROADMAP**. El lab pasa a **16 casos x 7 stacks = 112
endpoints**. Mitad del eje entregada.

### Added — caso 16, idempotencia y efectos duplicados

`cases/16-idempotency-and-duplicate-effects/` con las **7 implementaciones**.

Contrato uniforme: `/charge-unsafe` y `/charge-idempotent` sobre los mismos N
reintentos de una misma `Idempotency-Key`, mas `/idempotency/state` y `/outbox`.
Metrica central: `charges_applied` — y **`overcharged_cents`**, que traduce el
bug a la unidad en que el negocio lo discute.

El caso tiene dos mitades:

1. **La reserva atomica de la clave.** `if (!existe) { crear }` son dos
   operaciones con una ventana en el medio; con cinco reintentos concurrentes
   esa ventana produce cinco cobros. La version correcta es una sola operacion:
   `putIfAbsent`, `TryAdd`, `LoadOrStore`, `entry()`, `INSERT ... ON CONFLICT`.
2. **El outbox pattern.** El cargo va a la base y el email a una cola, sin
   transaccion que los abarque. El outbox escribe el efecto en la misma
   escritura que el cargo y deja que un worker lo entregue — at-least-once, y es
   deliberado: duplicar un email es visible y corregible, perderlo no.

### El hallazgo del caso: el ranking y la realidad operativa no coinciden

Es el primer caso del lab donde **la conclusion del veredicto y la decision de
despliegue apuntan a stacks distintos**, y quedo documentado en vez de escondido.

Seis de las siete implementaciones resuelven la carrera **dentro de su proceso**:
`putIfAbsent` (Java), `TryAdd` (.NET), `LoadOrStore` (Go), `entry()` (Rust),
`setdefault` (Python) y el `Map` de Node. Todas correctas con una replica, todas
**incorrectas con dos** — cada pod tiene su tabla, ninguno ve las claves del
otro, y el mismo pago se cobra una vez por pod.

La septima es la de PHP. Sin heap compartido entre requests, esta obligada a
poner la clave en almacenamiento con una operacion atomica del motor
(`ON CONFLICT DO NOTHING`, modelado con `flock`). Es la que peor puntua en fit de
primitivas — septimo puesto — y **la unica que se podria desplegar con tres
replicas**.

El ranking mide expresividad. La pregunta operativa es otra. Las dos respuestas
conviven en el `comparison.md` sin que una tape a la otra.

### Lo que distingue a cada stack

- **Rust primero**: el unico donde **ignorar el resultado de la reserva no
  compila**. El `match` sobre `Occupied`/`Vacant` es exhaustivo, y el `Entry`
  presta el mapa mientras existe — asi que la ventana check-then-act no es
  dificil de escribir, es *inexpresable*. En Java, .NET y Go, `putIfAbsent(k, v);`
  con el retorno descartado compila sin queja, y ese descarte es el bug.
- **.NET cuarto** por una razon interna al propio lab: `TryAdd` **si** es
  atomico, a diferencia de `GetOrAdd` con fabrica, que en el caso 13 hubo que
  envolver en `Lazy<T>`. Dos APIs en la misma clase con garantias distintas.
- **Go tercero** y con un contraste util contra el caso 13: alli `sync.Map` era
  la eleccion equivocada porque cada entrada se creaba y se borraba en cada
  expiracion; aca es la documentada, porque las claves se escriben una vez y se
  leen muchas. Mismo lab, dos casos, dos respuestas opuestas.
- **Python quinto**: `setdefault` expresa bien la operacion, pero su atomicidad
  viene del **GIL y no del contrato del lenguaje**. Por eso el codigo toma igual
  un `Lock` explicito: apoyarse en un detalle de CPython para decir "esto es
  indivisible" es escribir codigo que depende de algo que puede cambiar.
- **Node sexto** con el matiz mas incomodo: `has()` + `set()` es atomico porque
  no hay otro hilo, asi que el codigo correcto es el mas corto de los siete —
  y **deja de ser correcto al escalar a dos procesos, sin ningun aviso**.

### Changed — integracion

- **Dispatchers**: registro del caso 16 y puerto interno en los siete
  (`:9016` PHP/Python/Node, `:9416` Java, `:9516` .NET, `:9616` Go, `:9716` Rust).
- **`ci.yml`**: matriz `compose-config` de 113 a **120 archivos**; `hub-probe`
  valida 16 casos por stack; `compose-smoke` suma `case16-php` y `case16-rust`.
- **`shared/catalog/cases.json`** + `docs/case-catalog.md` + los cinco SVG.
- **Perfiles de lenguaje**: agregado recalculado. **Rust pasa a 8 oros**, y su
  media baja a 1.9 — empata con Go por primera vez.

### Verificado

Los 7 stacks levantados con Docker. Con 5 reintentos de un pago de $25: sin clave
**5 cargos, $100 cobrados de mas y 5 emails**; con clave **1 cargo, 4 duplicados
evitados y 1 email por outbox**. Identico en los siete.

## 2026-08-17 - Caso 15: backpressure en colas de mensajes en los 7 stacks

Tercer caso del **Eje 1 del ROADMAP**. El lab pasa a **15 casos x 7 stacks = 105
endpoints**.

### Added — caso 15, backpressure en colas de mensajes

`cases/15-message-queue-backpressure/` con las **7 implementaciones**.

Contrato uniforme: `/produce-unbounded` y `/produce-bounded` con tres politicas
por parametro (`block`, `drop_oldest`, `dead_letter`), mas `/queue/state` y
`/dlq`. Metricas centrales: `queue_depth_peak` y `oldest_msg_age_ms_peak` —
las dos que casi nunca estan en el dashboard y son las unicas que delatan el
problema.

El caso es sobre que **no hay opcion gratis**:

| Politica | Que paga |
|---|---|
| `block` | latencia: la lentitud viaja aguas arriba hasta el cliente |
| `drop_oldest` | datos: se pierden mensajes, en silencio salvo que se cuenten |
| `dead_letter` | deuda operativa: alguien tiene que mirar esa cola (caso 20) |

La cola sin limite parece una cuarta opcion sin costo. No lo es: solo difiere el
pago hasta el OOM, y mientras tanto **el throughput se ve perfecto**.

### El criterio de ranking cambio respecto de los otros casos

Aca no se midio cual stack expresa mejor la solucion sino **cual hace mas dificil
escribir el bug**, porque el bug tiene dos formas: la cola sin techo y el
descarte que nadie cuenta.

- **Go primero** porque no existe `make(chan T)` con buffer infinito. La version
  incorrecta hay que construirla a mano con una slice y un mutex, y sale **mas
  larga** que la correcta.
- **Rust segundo**: el limite esta en el tipo (`Sender<T>` vs `SyncSender<T>`),
  asi que la confusion no compila. Y `TrySendError::Full(T)` devuelve la
  propiedad del mensaje rechazado — la mejor primitiva del set para una DLQ.
- **.NET tercero**: unico stack donde la politica es un **enum del constructor**,
  decidida una vez para todo el sistema, con callback de descarte incluido.
- **Java quinto** pese a tener la mejor taxonomia de rechazo (`put`/`offer`/
  `offer(timeout)`, espejo de las `RejectedExecutionHandler`): porque
  `ConcurrentLinkedQueue` implementa la **misma interfaz `Queue`** que
  `ArrayBlockingQueue` y no tiene capacidad. Sacar el freno del sistema entero es
  un cambio de una linea que compila y pasa los tests.
- **Node sexto** siendo el **unico stack donde el backpressure es parte del
  protocolo del runtime** (`write()` devuelve `false`, `'drain'` avisa cuando
  seguir) — porque tambien es el unico donde ignorar esa señal compila, pasa los
  tests y funciona en desarrollo.
- **PHP septimo**, y con la leccion mas transferible: no tiene cola en proceso,
  asi que su backpressure vive en `listen.backlog` de FPM, en `pm.max_children`
  y en la DLQ del broker. Es el stack que mejor enseña que **el freno es una
  propiedad del sistema entero, no de la cola**.

### Fuera de alcance a proposito

El ROADMAP pedia "slow-down al producer devolviendo 429". No se implemento:
devolver 429 sin backoff del cliente alimenta una tormenta de reintentos, que es
el [caso 04](cases/04-timeout-chain-and-retry-storms/README.md). Queda anotado
como frontera entre casos, no como deuda.

### Fixed durante la construccion

`self._stop = threading.Event()` en el consumidor de Python pisaba
`Thread._stop()`, un metodo interno que `join()` llama. El sintoma era un
`TypeError: 'Event' object is not callable` en cada rafaga. Renombrado a
`_halt`. Es el tipo de colision que solo aparece al heredar de `Thread`.

### Changed — integracion

- **Dispatchers**: registro del caso 15 y puerto interno en los siete
  (`:9015` PHP/Python/Node, `:9415` Java, `:9515` .NET, `:9615` Go, `:9715` Rust).
- **`ci.yml`**: matriz `compose-config` de 106 a **113 archivos**; `hub-probe`
  valida 15 casos por stack; `compose-smoke` suma `case15-python` y `case15-node`.
- **`shared/catalog/cases.json`** + `docs/case-catalog.md` + los cinco SVG.
- **Perfiles de lenguaje**: agregado de veredictos recalculado. **Go pasa a 6
  oros**, Rust conserva 7.

### Verificado

Los 7 stacks levantados con Docker. Con 120 mensajes y consumidor 3x mas lento:
sin limite `queue_depth_peak` = 120 y `oldest_msg_age_ms_peak` ~ 250-475 ms;
acotada a 32 esa espera baja a ~70-134 ms. Las tres politicas producen su costo
propio: ~200 ms de productor frenado en `block`, 87-88 descartados en
`drop_oldest`, 87-88 a la DLQ en `dead_letter`. Identico en los siete.

## 2026-08-17 - Caso 14: agotamiento del pool de conexiones en los 7 stacks

Segundo caso del **Eje 1 del ROADMAP**. El lab pasa a **14 casos x 7 stacks = 98
endpoints**.

### Added — caso 14, agotamiento del pool de conexiones

`cases/14-connection-pool-exhaustion/` con las **7 implementaciones**, no las
tres que el ROADMAP preveia, por la misma razon estructural del caso 13.

Contrato uniforme: `/pool-leaky` y `/pool-managed` sobre la misma carga, con
`leaked` = `acquired - released` como metrica central, mas `hung`,
`failed_timeout`, `pool_available_after`, `pool_wait_ms_p99` y `littles_law`.

El caso combina dos defectos que se necesitan mutuamente: la devolucion solo en
el camino feliz (cada excepcion se lleva una conexion) y la adquisicion sin
deadline (el que llega tarde no falla, se queda). El resultado es una
indisponibilidad que **no produce errores**: los requests no terminan, asi que
no generan muestras de latencia y el p99 no se dispara — desaparece del grafico.

| Stack | Pool | Deadline | Garantia de devolucion |
|---|---|---|---|
| PHP | array en el proceso | — (un solo proceso) | `finally` |
| Python | `queue.Queue(maxsize=N)` | `get(timeout=...)` | `@contextmanager` |
| Node | array + cola de waiters | `AbortSignal.timeout()` | `finally` en `async` |
| Java | `ArrayBlockingQueue` | `poll(timeout, unit)` | try-with-resources |
| .NET | `SemaphoreSlim` + `ConcurrentBag` | `WaitAsync(timeout)` → `false` | `using var` |
| Go | `chan *conn` bufferizado | `select` + `time.NewTimer` | `defer` |
| Rust | `Mutex<Vec<Conn>>` + `Condvar` | `wait_timeout` | `impl Drop` |

### El hallazgo del caso: Rust gana por lo que impide

Es el **unico caso del laboratorio donde Rust primero no es por expresividad
sino por lo que el lenguaje no deja escribir**. Con `impl Drop` la fuga no se
puede producir por descuido: el `Drop` corre en el return feliz, en el temprano
y durante el desenrollado por panic.

Por eso la variante leaky de Rust tuvo que escribirse a proposito con
`std::mem::forget(lease)` — la unica forma de perder un recurso en Rust seguro.
En los otros seis stacks el leak es lo que pasa si uno se distrae; aca hay que
pedirlo por su nombre, y el nombre es grepeable.

El reverso: **Go baja al quinto puesto por una sola linea**. El canal
bufferizado como pool es la expresion mas economica del set, pero `defer
p.release(c)` hay que acordarse de escribirlo, y olvidarlo compila igual.

### Una decision de fidelidad al reves que la del caso 13

Aca el trabajo que retiene la conexion **si** es un `sleep`. Una conexion se
retiene mientras se espera a la red, no mientras se quema CPU. En el caso 13 un
`sleep` habria escondido el punto; aca quemar CPU lo esconderia. Misma pregunta
—que recurso escasea de verdad—, respuestas opuestas, y las dos documentadas.

### Fixed durante la construccion

`(idx % 100) < fail_rate` parecia un reparto de fallos razonable y no lo era:
con 24 requests y `fail_rate=25` fallaban **las 24**, porque todos los indices
son menores que 25. La variante managed reportaba 24 fallos de query y el
contraste quedaba ilegible. Se reemplazo por `(idx * 37) % 100 < fail_rate`, que
dispersa los fallos por toda la tanda. Aplicado en los siete stacks.

### Changed — integracion

- **Dispatchers**: registro del caso 14 y puerto interno en los siete
  (`:9014` PHP/Python/Node, `:9414` Java, `:9514` .NET, `:9614` Go, `:9714` Rust).
- **`ci.yml`**: matriz `compose-config` de 99 a **106 archivos**; `hub-probe`
  valida 14 casos por stack; `compose-smoke` suma `case14-java` y `case14-dotnet`.
- **`shared/catalog/cases.json`** + `docs/case-catalog.md` + los cinco SVG.
- **Perfiles de lenguaje**: agregado de veredictos recalculado desde los
  `comparison.md`. **Rust pasa a 7 oros** (gana tambien el 14).

### Verificado

Los 7 stacks levantados con Docker. Con pool de 4, 24 requests y 25% de fallo:
`leaked=4`, `hung≈12`, `pool_available_after=0/4` y ~2 s de pared en la variante
con fuga; `leaked=0`, `pool_available_after=4/4` y ~155 ms en la corregida.
Identico en los siete.

## 2026-08-17 - Eje 1 abre con el caso 13: cache stampede en los 7 stacks

Primer caso del **Eje 1 del ROADMAP** (casos nuevos de la vida real, 13-20). El
lab pasa de 12 a **13 casos x 7 stacks = 91 endpoints**.

### Added — caso 13, cache stampede y thundering herd

`cases/13-cache-stampede-and-thundering-herd/` con las **7 implementaciones**,
no las tres que el ROADMAP preveia. La razon es estructural: `validate-structure.sh`
exige las siete carpetas de stack por caso, y servir `/13/` en tres hubs y 404 en
los otros cuatro habria roto la simetria que es la identidad del laboratorio.

Contrato uniforme en los siete: `/cache-naive` y `/cache-singleflight` sobre la
misma rafaga, con `origin_computations` como metrica central, mas
`stampede_depth`, `coalesced_waiters`, `served_stale` y `p99_wait_ms`.

Primitiva idiomatica distinta por runtime:

| Stack | Primitiva | De donde sale la garantia de ejecucion unica |
|---|---|---|
| PHP | `flock(LOCK_EX)` + double-checked locking | del sistema de archivos, entre procesos |
| Python | dict de vuelos + `threading.Event` | del `Lock` que protege el dict |
| Node | `Map<key, Promise>` | del orden que escribe el autor (`set` antes del `await`) |
| Java | `ConcurrentHashMap.computeIfAbsent` | **del mapa**: atomica por clave |
| .NET | `Lazy<Task<T>>` en `ConcurrentDictionary` | **del `Lazy`**, no del diccionario |
| Go | `sync.WaitGroup` + map bajo `Mutex` | del mutex que protege el registro |
| Rust | `Arc<Flight>` con `Mutex` + `Condvar` | del mutex, y el `Arc` la hace segura de por vida |

### Fixed durante la construccion — dos cosas que el caso enseñaba mal

**1. Single-flight sin double check da 3 o 4 recalculos, no 1.** La primera
version registraba el vuelo, calculaba y borraba la entrada. Con `cost` chico, el
lider de la primera generacion terminaba antes de que los ultimos llamadores
llegaran al registro, y esos se volvian lideres de una segunda generacion. Java
daba 3, Go 2, Rust 7. El arreglo es una relectura de la cache **dentro** del
vuelo — el mismo double check que PHP no puede omitir porque su lock vive en el
almacenamiento. Quedo aplicado en los siete y documentado como la mitad del
patron que se olvida.

**2. En Python la estampida no se dejaba observar.** Sin barrera, el primer hilo
completaba su digest dentro de su propio quantum del GIL y los otros quince
encontraban el valor fresco: `origin_computations` daba 1 y la variante naive
**parecia correcta**. Un falso verde que dependia de `sys.setswitchinterval`. La
barrera de dos fases no infla el numero: reproduce que, cuando una clave caliente
expira, los N requests ya estaban en vuelo y todos leyeron la cache antes de que
ninguno alcanzara a escribirla.

### Changed — integracion en los 7 hubs

- **Dispatchers**: registro del caso 13 y puerto interno en los siete
  (`:9013` PHP/Python/Node, `:9413` Java, `:9513` .NET, `:9613` Go, `:9713` Rust),
  mas el `COPY` correspondiente en cada Dockerfile, el `spawn_case` de
  `entrypoint.sh` y el miembro `case13` del workspace de cargo.
- **`ci.yml`**: matriz `compose-config` de 92 a **99 archivos**; `hub-probe`
  valida los 13 casos por stack en un solo boot; `compose-smoke` suma
  `case13-go` y `case13-rust`.
- **`shared/catalog/cases.json`**: entrada completa del caso 13 con
  `runtime_entries` de los siete stacks. `docs/case-catalog.md` y los cinco SVG
  de `docs/assets/` regenerados desde ahi.

### Changed — generadores que dejan de hardcodear el conteo

`generate_case_catalog.php`, `generate_diagrams.py` y `check-language-versions.sh`
derivaban el numero de casos de una constante escrita a mano. Ahora lo cuentan.
Es lo que evita que el proximo caso deje cinco diagramas diciendo "12" para
siempre.

### Changed — barrido documental

`README.md`, `ARCHITECTURE.md`, `RECRUITER.md`, `RUNBOOK.md`, `INSTALL.md`,
`SECURITY.md`, `AWS_MIGRATION.md`, `ROADMAP.md`, `docs/architecture.md`,
`docs/executive-summary.md`, `docs/problem-map.md`, `docs/QUE-ES-ESTO.md`,
`docs/stack-map.md`, `docs/docker-strategy.md`, `docs/BEGINNERS_GUIDE.md`,
`docs/usage-and-scope.md`, `docs/positioning-and-objective.md`,
`docs/language-upgrade-protocol.md` y los siete `docs/languages/*.md`.

En los perfiles de lenguaje se recalculo el agregado de veredictos leyendo los
`comparison.md` con el mismo parser de `generate_diagrams.py`, en vez de editar
los numeros a mano: Go pasa a **5 oros** (gana tambien el 13), Rust conserva 6.

Los ADR de `docs/adr/` y las entradas historicas de este CHANGELOG **no se
tocaron**: son registros fechados, no afirmaciones sobre el estado de hoy.

### Verificado

Los siete hubs levantados con Docker y probados caso por caso: `13/13` en
`compose.root.yml`, `compose.python.yml`, `compose.nodejs.yml`,
`compose.java.yml`, `compose.dotnet.yml`, `compose.go.yml` y `compose.rust.yml`.
Con `concurrency=16`: los siete dan **16 recalculos** en la variante naive y
**1 recalculo con 15 `coalesced_waiters`** en la corregida.

## 2026-08-04 - Perfiles de lenguaje, protocolo de version y dossier PDF

El workflow `language-drift.yml` (2026-08-03) detecta que un lenguaje publico
version nueva. Faltaba lo que viene despues: **donde esta escrito que revisar**.
Sin eso, el aviso llega y nadie sabe si el caso 03 sigue siendo correcto o si
paso a enseñar la forma vieja de hacer las cosas.

### Added — perfiles de lenguaje

`docs/languages/` con un perfil por stack (`php`, `python`, `node`, `java`,
`dotnet`, `go`, `rust`) mas indice. Cada uno documenta seis cosas:

| Seccion | Que responde |
|---|---|
| Identidad | Que es el lenguaje y para que se usa fuera del lab |
| Modelo de ejecucion | Como corre el codigo, porque de ahi sale que primitiva es la correcta |
| Primitivas en el lab | Que usa cada uno de los 12 casos, con enlace al codigo |
| Rendimiento | Que mide el lab en ese stack y como reproducirlo, con comandos |
| Limites y problemas sin solucion | Lo que ese runtime **no** puede hacer y que caso lo deja visible |
| Ciclo de versiones | Version fijada, cadencia upstream y que revisar en el proximo salto |

Las secciones de rendimiento **no publican benchmarks entre lenguajes**: documentan
que señal expone cada runtime, de donde sale y como reproducir la medicion. La
pendiente legacy/optimized dentro de un mismo stack es comparable; el tiempo
absoluto entre stacks no lo es.

### Added — protocolo de actualizacion

`docs/language-upgrade-protocol.md`: checklist de 10 puntos, en orden. El punto
de partida **no** es el `Dockerfile` sino el perfil del lenguaje — al reves se
termina con un repositorio que compila en la version nueva y sigue enseñando lo
de la version vieja. Enlazado desde `README.md`, desde el body del issue que
genera `language_drift.py` y desde los siete perfiles.

Disparadores concretos ya anotados: `ScopedValue` fuera de preview (Java 25,
caso 03), `node:sqlite` fuera de experimental (Node 24, casos 01 y 02),
free-threading de Python (PEP 703, caso 11) y cancelacion en `std` de Rust
(casos 04 y 09).

### Added — diagramas derivados del catalogo

`scripts/generate_diagrams.py` emite cinco SVG en `docs/assets/`. No se dibujan
a mano: salen de `shared/catalog/cases.json` y —el mapa de calor de fit— de la
seccion *Veredicto* de los once `comparison.md` que la tienen. `--check` corre
en CI: si entra un octavo stack y nadie redibuja, el PR falla.

| Diagrama | Fuente |
|---|---|
| `stack-matrix.svg` | catalogo: 12 casos x 7 stacks |
| `case-map.svg` | catalogo: casos por categoria |
| `execution-models.svg` | catalogo: bloque `languages` |
| `fit-ranking.svg` | veredictos de los `comparison.md` |
| `language-upgrade-flow.svg` | el protocolo |

### Added — dossier PDF

`scripts/build_dossier_pdf.py` compila la documentacion en un PDF con portada,
indice, tablas, bloques de codigo y los SVG embebidos **como vectores** (via
`svglib`, sin rasterizar). Dos perfiles: `completo` (todos los `.md`) y
`ejecutivo` (raiz + `docs/` + README y comparativa de cada caso). Salida en
`dist/`.

### Added — explicacion para personas no tecnicas

`docs/QUE-ES-ESTO.md`: los 12 problemas en lenguaje de todos los dias, sin jerga,
con la analogia del taller mecanico. `docs/BEGINNERS_GUIDE.md` se reescribio
—decia "operativos en PHP, Python y Node.js" cuando ya eran siete stacks— y
ahora incluye un primer experimento reproducible.

### Changed — barrido visual sobre 215 archivos

- H1 con icono coherente por familia documental.
- Navegacion de retorno en los 84 README por stack (caso · comparativa · perfil del lenguaje) y en los 84 documentos de `cases/NN/docs/`.
- Los stubs finos (`business-value.md`, `context.md`, `shared/README.md`) pasan a traer ficha del caso, URLs por stack y nota de honestidad, sincronizados con el catalogo.

### Fixed — incoherencias que el barrido dejo a la vista

| Hallazgo | Estado anterior | Ahora |
|---|---|---|
| `cases.json` → `languages` | 5 stacks; decia que Java "aun sin casos operativos" | 7 stacks con version, imagen, hub, modelo de ejecucion y perfil |
| `runtime_entries.node` | apuntaba a los puertos aislados (`821`…) con `compose_path` por caso | hub `:8300` con `/NN/`, simetrico con Java y .NET; el aislado se conserva en `isolated_port` |
| `portal/Dockerfile` | `php:8.2-apache` contra `php:8.3-cli-alpine` en los casos | `php:8.3-apache` — el repositorio fijaba dos versiones de PHP |
| Badge de stacks en 9 README de caso | `PHP · Python · Node · Java · .NET` | los 7, enlazando a `docs/languages/` |
| `cases/03/README.md` | **sin H1 ni badges** | H1, estado, stacks y categoria |
| `recommended_github_topics` | sin `go` ni `rust` | completos |
| `proof_points` del caso 03 | "transferibilidad en PHP, Node.js y Python" | los siete stacks |

## 2026-08-03 - Barrido documental: el lab pasa de 5 a 7 stacks

Cierra el ciclo abierto por los dos stacks nuevos. Toda la documentacion que
afirmaba "5 stacks / 60 endpoints / 5 hubs / 8 puertos" quedaba desalineada con
el arbol desde el momento en que Go y Rust entraron.

### Changed (conteos y afirmaciones de estado)

| Afirmacion | Antes | Ahora |
|---|---|---|
| Stacks operativos | 5 | **7** |
| Endpoints (12 casos × stacks) | 60 | **84** |
| Hubs simetricos | 5 (`8100`-`8500`) | **7** (`8100`-`8700`) |
| Puertos que cubren el lab | 8 | **10** |
| `compose-config` en CI | 66 archivos | **92 archivos** |
| `hub-probe` en CI | Python/Node/Java/.NET | **+ Go / Rust** |
| Lambdas en la ruta serverless (AWS) | 60 | **84** |
| Services en ECS Fargate | 6 | **9** |

Archivos tocados: `README.md`, `ARCHITECTURE.md`, `ROADMAP.md`, `RUNBOOK.md`,
`INSTALL.md`, `RECRUITER.md`, `AWS_MIGRATION.md`, `docs/architecture.md`,
`docs/executive-summary.md`, `docs/usage-and-scope.md`, `docs/docker-strategy.md`,
`docs/stack-map.md`, `docs/adr/0003-docker-per-case-per-stack.md`, los 12
`comparison.md` y los 12 `README.md` de caso.

### Added (la comparativa, que era el punto)

- **Secciones Go y Rust en los 12 `comparison.md`**, con el codigo real de cada
  stack — no una traduccion generica del texto de Java.
- **Tabla "Primitiva central por stack" en los 12 casos**: siete filas, una por
  lenguaje, diciendo cual es la primitiva y donde duele el problema en cada uno.
- `docs/stack-map.md`: filas de Go y Rust con su fortaleza y su contrapartida.

### Corregido: una afirmacion que se pasaba de la raya

El README de Go caso 03 decia que perder la correlacion "pasa de ser un bug de
runtime a un error de compilacion". **Es falso.** Lanzar `go func(){}()` sin
pasar el `ctx` compila perfectamente y ese trabajo queda sin correlacionar. Go
hace la dependencia **visible en la firma**, no obligatoria. La garantia de
compilador para ese problema esta en Rust (`&RequestCtx` con lifetime acotado),
no en Go. Corregido, y la distincion quedo explicita en el `comparison.md` del
caso 03.

### Lo que la documentacion ahora dice y antes no

Tres limitaciones que estaban en el codigo pero no en los documentos:

- **Caso 04:** Go es el unico stack donde el deadline cancela el trabajo aguas
  abajo. Rust con `std` y Java quedan en el mismo lugar — `recv_timeout` y
  `orTimeout()` cortan la espera, no el trabajo. Es el unico caso del lab donde
  Rust queda por detras de Go en la primitiva central, y esta escrito asi.
- **Caso 05:** Rust no impide la fuga. El borrow checker previene use-after-free
  y data races; no previene "guardar de mas". Un `Vec` global que crece compila
  sin warnings.
- **Caso 11:** ni Go ni Rust tienen pool de threads que agotar, asi que el caso
  **no se traduce literal** desde Java. Se modela con semaforo de concurrencia.

### Changed (metadatos del repositorio)

- Descripcion de GitHub: de "5 stacks" a "7 stacks", con Go y Rust listados.
  De paso se corrigio el mojibake que arrastraba (`ingenier<?>a`).
- Topics: se agregan `go` y `rust`.

GitHub Pages queda **fuera de alcance** por decision explicita: no esta
habilitado en el repositorio y no se habilita en esta entrega.

## 2026-08-03 - Fidelidad universal del caso 01: Node/Java/.NET pasan a SQLite real

Cierra la ultima deuda de fidelidad abierta del lab. El caso 01 vendia "los 5 stacks resuelven el mismo problema" mientras **3 de los 5 simulaban el substrato del fallo** con `setTimeout` / `sleepMicros` / `Thread.SpinWait` sobre listas en memoria. Ahora los cinco ejecutan SQL real contra un motor.

### El problema que se cierra

`db_hits` era una metrica derivada en Node/Java/.NET — contaba iteraciones de un bucle, no ejecuciones contra un motor. Peor: el caso enseña **filtro no sargable**, un concepto que solo existe si hay un query planner. Sin motor, "no sargable" era una afirmacion del README que nada respaldaba.

### Changed (codigo)

- **Caso 01 Node:** `node:sqlite` (`DatabaseSync`, built-in desde Node 22.5, sin `npm install` ni bindings nativos). Esquema completo con `customers`, `orders`, `customer_daily_summary`, `worker_state`, `job_runs`. La ruta legacy ejecuta `1 + 2N` queries reales; la optimizada resuelve los detalles con `ROW_NUMBER() OVER (PARTITION BY customer_id ORDER BY created_at DESC)` en una sola query. Imagen base `node:20-alpine` → `node:22-alpine` con `--experimental-sqlite`.
- **Caso 01 Java:** `sqlite-jdbc` 3.46.1.3, archivo bajo `/tmp` con `journal_mode=WAL`, conexion por request con `try-with-resources`. El worker corre con su propia conexion.
- **Caso 01 .NET:** `Microsoft.Data.Sqlite` 8.0.10, misma estrategia de archivo + WAL, `using`/`IDisposable` para el cierre deterministico. Espejo exacto del Java: mismo esquema, mismas queries, mismos resultados fila por fila.
- **`java-dispatcher`:** el caso 01 se compila y ejecuta con `/opt/sqlite-jdbc.jar` en classpath, igual que el caso 02. `Dispatcher.java` pasa `SQLITE_JDBC_JAR` como `extraCp` del caso 01.

### Por que WAL no es un detalle de implementacion

El worker refresca `customer_summary` mientras los handlers leen. Sin `journal_mode=WAL`, el `DELETE` + `INSERT ... SELECT` del worker bloquea cada lectura concurrente — que es exactamente el fallo que el caso enseña a evitar. WAL es el equivalente embebido del MVCC que da PostgreSQL en el stack PHP, y por eso Java y .NET lo activan explicitamente.

### El filtro no sargable, ahora verificable

En Java y .NET la ruta legacy usa `WHERE LOWER(region) LIKE 'n%'` y la optimizada el mismo predicado reescrito como rango. El planner lo confirma:

```text
… WHERE LOWER(region) LIKE 'n%'          →  SCAN orders
… WHERE region >= 'n' AND region < 'o'   →  SEARCH orders USING INDEX idx_orders_region
```

Deja de ser una afirmacion en prosa y pasa a ser reproducible con `EXPLAIN QUERY PLAN`.

### Evidencia medida (via hub, `limit=20`)

| Stack | Legacy | Optimized |
|---|---|---|
| Node `:8300/01` | 41 queries · 66.3 ms | 2 queries · 12.8 ms |
| Java `:8400/01` | 21 hits · 10.8 ms | 4 hits · 5.0 ms |
| .NET `:8500/01` | 21 hits · 13.4 ms | 4 hits · 8.3 ms |

Java y .NET devuelven cifras identicas y la misma primera fila (`Customer 1315`, `order_id 12`), con 1.531 filas en `customer_summary` en ambos — el determinismo cross-stack es verificable, no declarado.

### Lo que NO cambio, a proposito

El **contrato JSON de cada stack**. Java y .NET conservan su shape (`variant`/`rows`/`db_hits`, `/reset-lab`), distinto del de PHP/Python/Node (`mode`/`data`/`db_queries_in_request`, `/reset-metrics`). Converger esos contratos es el item "Suite de tests cross-stack" del ROADMAP, no este cambio: tocarlo aqui habria roto READMEs de los 12 casos y referencias en `AWS_MIGRATION.md` sin relacion con la fidelidad del substrato.

### Deuda que queda registrada

Node y Python conservan un round-trip artificial (`ROUNDTRIP_*_MS`, `artificial_roundtrip_ms`) que modela el hop de red que SQLite embebido no tiene. No es substrato simulado — es transporte simulado, y esta documentado en el codigo y en los README de stack.

### Changed (docs)

- `cases/01/comparison.md`: la seccion "Fidelidad del substrato — asimetria honesta" se reemplaza por "los 5 stacks contra un motor real", con tabla de motor/driver/concurrencia y el bloque `EXPLAIN QUERY PLAN`. Las tres secciones profundas (Node/Java/.NET) se reescriben con el SQL real en lugar de los snippets de `Map`/`HashMap`/`Dictionary`. La tabla final suma filas de driver y de cierre de recursos.
- `cases/01/{node,java,dotnet}/README.md`: seccion `## Fidelidad` reescrita, bloques de contraste con SQL real, y en Node el titulo y la nota de honestidad actualizados.
- `cases/01/README.md`: secciones por stack y arbol de directorios actualizados.
- `README.md` raiz: la tabla "Honestidad de fidelidad" pasa a declarar fidelidad universal en casos 01 y 02; la asimetria restante se reencuadra como naturaleza del motor (solo PHP cruza un socket TCP).
- `ROADMAP.md`: "Fidelidad universal de caso 01" marcada completada con las dos decisiones de diseño que salieron del camino; "Estado actual" actualizado.
- `shared/catalog/cases.json`: `level_detail` del caso 01 refleja los motores reales por stack.

## 2026-08-03 - .NET entra a CI + drift de docs corregido + `--check` del catalogo portable

Cierra una brecha que quedo abierta al agregar el quinto stack: **.NET tenia paridad de codigo pero cero cobertura en CI**. Los 12 casos .NET y el hub `:8500` podian romperse sin que ningun workflow se enterara, mientras `ARCHITECTURE.md` ya afirmaba que CI los validaba.

### El problema descubierto

`fae2296` llevo .NET a los 12 casos, pero `.github/workflows/ci.yml` no mencionaba `dotnet` ni una sola vez. De los 78 compose versionados, la matriz `compose-config` validaba 53 — los 13 archivos .NET (`compose.dotnet.yml` + los 12 per-case) nunca se parseaban, y `hub-probe` solo levantaba Python/Node/Java.

Peor: la documentacion afirmaba lo contrario. `ARCHITECTURE.md` decia `hub-probe los 5 hubs en CI` y `hub-probe (Python/Node/Java/.NET)`; `ROADMAP.md` se contradecia a si mismo entre la linea 13 (`Python/Node/Java`) y la 177 (`Python/Node/Java/.NET`). El repo declara que CI bloquea el drift entre lo que dice y lo que ejecuta — pero nada validaba esas frases.

### Changed (CI)

- `compose-config`: matriz de 53 → **66 archivos**. Se agregan `compose.dotnet.yml` y los 12 `cases/*/dotnet/compose.yml`. Cobertura completa de los 5 hubs + portal + los 60 compose per-case.
- `hub-probe`: nueva entrada `dotnet-hub` (`compose.dotnet.yml`, puerto `8500`) que valida los 12 casos .NET en un solo boot, igual que Python/Node/Java.
- `hub-probe`: la espera inicial del hub pasa de 50 a 120 intentos (100s → 240s). El dispatcher .NET spawnea 12 subprocesos y espera health secuencialmente antes de escuchar; en un runner de 2 vCPU el margen anterior quedaba justo. Es una cota superior — en el camino feliz sale al primer intento.

### Fixed

- `scripts/generate_case_catalog.php`: `--check` daba un falso negativo permanente en Windows. Escribia con `PHP_EOL` (`\r\n` en Windows) y comparaba byte a byte contra un archivo que, con `core.autocrlf=true`, esta en CRLF en la copia de trabajo y en LF en el blob versionado. Ahora escribe LF explicito y compara normalizando fines de linea. `make catalog-check` y `validate-structure.sh` pasan en Windows, Linux y macOS por igual.
- `.gitignore`: se ignora `.claude/worktrees/`. Los worktrees de agentes dejan copias completas del repo dentro del arbol (3.3 MB en el caso detectado) que un `git add .` distraido habria versionado.

### Changed (docs)

- `ARCHITECTURE.md`: diagrama de CI corregido — `compose-config 66 archivos`, `portal-probe hub PHP` como nodo propio y `hub-probe Python/Node/Java/.NET` en lugar de `los 5 hubs`. El hub PHP se valida por `portal-probe`, no por `hub-probe`; la tabla de mecanismos pasa de cinco a seis filas con esa distincion explicita.
- `ROADMAP.md`: se elimina la contradiccion interna sobre la cobertura de `hub-probe`; `40+ archivos` pasa a `66 archivos` en ambas menciones. Fase 3 se marca completada — los 12 `docs/postmortem.md` existen desde `1102a5a`, el ROADMAP todavia los daba por pendientes.
- `README.md`: la descripcion de `AWS_MIGRATION.md` mencionaba los hubs `PHP/Python/Node/Java` — se agrega .NET, que el propio documento ya cubre.

## 2026-05-20 - Fidelidad de caso 02 restaurada en los 5 stacks + asimetria de caso 01 documentada + ROADMAP nuevo

Cierra una asimetria de fidelidad que el lab tenia oculta: caso 02 (N+1) **simulaba el N+1 en memoria** con `Map`/`HashMap`/`Dictionary` en 3 de los 5 stacks, mientras vendia el caso como "los 5 stacks ejecutan el mismo problema". Esta entrega lleva los 5 stacks a SQL real, deja explicita la asimetria que queda en caso 01, y publica el roadmap de los proximos casos.

### El problema descubierto

Caso 02 estudia N+1 — un patron que **es DB-shape por definicion**. Modelarlo con `Map<id, item>` en memoria es didacticamente debil: el lector senior detecta que no hay `prepare()` ni `executeQuery()` ni round-trip, y el contraste pierde peso. La narrativa del caso ("N+1 sobre el mismo problema en 5 lenguajes") no se sostenia.

### La solucion aplicada (cambios de codigo — los hace otro agente)

- Caso 02 Node: `node:sqlite` (modulo built-in desde Node 22.5, sin `npm install`, sin bindings nativos).
- Caso 02 Java: `sqlite-jdbc` (single jar agregado al classpath en build-time, sin Maven).
- Caso 02 .NET: `Microsoft.Data.Sqlite` (paquete oficial Microsoft, ADO.NET-style).
- DB embebida en `:memory:` por instancia o `/tmp/case02.db`. Sin contenedor extra, sin servicio externo. Single-binary se preserva.
- `db_hits` pasa de ser una metrica derivada a un contador real de ejecuciones contra el motor. El contrato JSON externo no cambia.

### La asimetria aceptada y documentada (caso 01)

Caso 01 (latencia bajo carga) se **mantiene como esta** — PHP con PostgreSQL real, Python con SQLite stdlib, Node/Java/.NET con substrato simulado (`setTimeout`/`sleepMicros`/`Task.Delay`). La diferencia con caso 02 es que en caso 01 el **patron de solucion** (worker concurrente refrescando cache + readers no bloqueados) es lo enseñable, y ese patron es real en los 5 stacks gracias a `ConcurrentHashMap`/`ConcurrentDictionary`/`Map` con primitivas concurrentes reales. Lo que es simulado es el substrato del fallo, no la solucion.

Esta asimetria ahora esta documentada explicitamente:
- Seccion "Fidelidad del substrato" agregada al inicio de `cases/01-api-latency-under-load/comparison.md` con tabla `real vs simulado` por stack.
- Seccion `## Fidelidad` agregada al final de los 3 README de stack (node, java, dotnet) de caso 01, con link al ROADMAP.
- Tabla "Honestidad de fidelidad" agregada al `README.md` raiz contrastando caso 01 vs caso 02.

### Added

- `ROADMAP.md` reescrito completo con tres ejes:
  - **Eje 1 — 8 casos nuevos de la vida real (13-20):** cache stampede, connection pool exhaustion, message queue backpressure, idempotencia y efectos duplicados, migracion de esquema sin downtime, cold start y autoscale lag, search index drift, dead letter queue olvidada.
  - **Eje 2 — Mejoras de plataforma:** fidelidad universal de caso 01 (mover Node/Java/.NET a SQLite siguiendo el patron de caso 02), observabilidad Prometheus en los 5 stacks, suite de tests cross-stack, CI completa con loadtest, proof cards live en el portal.
  - **Eje 3 — Honestidad tecnica:** seccion "Fidelidad" obligatoria en cada `comparison.md` con substrato no uniforme, tabla maestra "real vs simulado" en el `README.md` raiz, postmortems del propio lab en `docs/lab-postmortems.md`.

### Changed (docs)

- `cases/02-n-plus-one-and-db-bottlenecks/comparison.md`: rewrite completo. El header narrativo deja de ser "PHP+Python tienen DB, los demas simulan" y pasa a "los 5 stacks ejecutan N+1 real sobre SQL, primitivas idiomaticas distintas". Tabla de fidelidad del substrato. Secciones por stack reescritas con la primitiva real (`node:sqlite`/`db.prepare()`, `sqlite-jdbc`/`PreparedStatement`, `Microsoft.Data.Sqlite`/`SqliteCommand`). Tabla final "Diferencias de decision" actualizada con columnas `Motor DB` (cinco motores reales) y `Primitiva de query` (cinco APIs idiomaticas).
- `cases/02-n-plus-one-and-db-bottlenecks/{node,java,dotnet}/README.md`: reescritos. Las menciones a `Map`/`HashMap`/`Dictionary` se reemplazan por la primitiva real (`Database` de `node:sqlite`, `PreparedStatement` JDBC, `SqliteConnection`/`SqliteCommand`). Tabla de primitivas actualizada. Sin claim de "datos en memoria".
- `cases/02-n-plus-one-and-db-bottlenecks/README.md`: tabla de stacks actualizada — todos los stacks dicen "SQLite real" o "PostgreSQL real" (no "datos en memoria"). Subsecciones Node/Java/.NET reescritas para mencionar la primitiva y el batch real.
- `cases/01-api-latency-under-load/comparison.md`: nueva seccion "Fidelidad del substrato" al inicio (despues del intro). Tabla `real vs simulado` por stack. Explicacion de por que se acepta la asimetria hoy (enseñar la forma idiomatica del patron sin obligar a cada stack a montar PostgreSQL) y link al ROADMAP como compromiso futuro.
- `cases/01-api-latency-under-load/{node,java,dotnet}/README.md`: seccion `## Fidelidad` agregada al final. 3-5 lineas reconociendo que el substrato es simulado mientras el patron es real, con link al stack PHP para ver contencion real y al ROADMAP para el compromiso de mover a SQLite.
- `README.md` raiz: bullets `OPERATIVO en Node.js/Java 21/.NET 8` mencionan SQLite real en caso 02 con la primitiva especifica. Nueva seccion "🎯 Honestidad de fidelidad" con tabla contrastando caso 01 vs caso 02 por stack.
- `ARCHITECTURE.md`: fila de caso 02 en la tabla "Casos operativos actuales" actualizada — "PostgreSQL (PHP) + SQLite real en los otros 4" en lugar de solo "PostgreSQL".
- `shared/catalog/cases.json`: `level_detail` de caso 02 reescrito ("Los 5 stacks ejecutan N+1 real sobre SQL embebido...").

### Out of scope (mantenidos sin cambios)

- Codigo en `cases/02/{node,java,dotnet}/app/` — lo reescribe otro agente con la migracion real a `node:sqlite` / `sqlite-jdbc` / `Microsoft.Data.Sqlite`.
- Dispatchers (`node-dispatcher/`, `java-dispatcher/`, `dotnet-dispatcher/`) — sin cambios.
- Casos 03-12 — sin cambios en ningun sentido.
- PHP y Python caso 02 — ya correctos.

### Why

La narrativa del lab es **honestidad tecnica**. Vender que los 5 stacks ejecutan N+1 sobre SQL real cuando 3 simulan en memoria erosiona ese principio. Esta entrega cierra esa brecha en caso 02 y formaliza la deuda restante (caso 01) con plazo y compromiso explicito en el ROADMAP. El lab gana credibilidad senior — pierde una afirmacion debil, gana una afirmacion verificable.

## 2026-05-20 - .NET 8 cierra paridad multi-stack: los 12 casos operativos en los 5 stacks

.NET 8 pasa de cubrir los primeros 6 casos a cubrir los 12. **Paridad multi-stack completa** entre PHP, Python, Node.js, Java 21 y .NET 8 — los 60 endpoints (12 casos × 5 stacks) operativos detras de 5 hubs simetricos.

### Added (6 Program.cs reales con primitiva BCL distintiva por caso)

- **Caso 07** (`Modernizacion incremental`): `ConcurrentDictionary<string, Func<Request, Response>>` como routing table mutable en runtime; `Func<Request,Response>` delegate como ACL closure; `record Request/Response`. Espejo del `ConcurrentHashMap<String,Function>` Java.
- **Caso 08** (`Extraccion critica`): `Func<PriceRequestOld, PriceRequestNew>` como proxy de compatibilidad de contrato + `ImmutableList<Action<string>>` con `ImmutableInterlocked.Update` como event bus thread-safe (reads sin lock, writes generan nueva lista persistente). Espejo del `Function` proxy + `CopyOnWriteArrayList` Java.
- **Caso 09** (`Integracion externa inestable`): `SemaphoreSlim.Wait(0)` como budget de cuota no bloqueante + `ConcurrentDictionary` como snapshot cache + `Interlocked.CompareExchange` sobre el estado del breaker.
- **Caso 10** (`Arquitectura cara para algo simple`): CPU real medido como N hops de `JsonSerializer.Serialize`/`Deserialize` (alocacion + parsing, presion al LOH cuando los blobs superan 85 KB) vs `Dictionary.TryGetValue` O(1). `Stopwatch` para medicion directa.
- **Caso 11** (`Reportes que bloquean operacion`): `ConcurrentExclusiveSchedulerPair.ExclusiveScheduler` o `Thread` dedicado como aislamiento del trabajo CPU-bound; `Task.Factory.StartNew(task, ..., scheduler)` para submission explicita; `ThreadPool.GetAvailableWorkerThreads` como senal nativa de saturacion (equivalente al `monitorEventLoopDelay` Node y al `ThreadPoolExecutor.getActiveCount()` Java).
- **Caso 12** (`Punto unico de conocimiento`): operadores `?.` (null-conditional) + `??` (null-coalescing) con Nullable Reference Types habilitado (`<Nullable>enable</Nullable>`) como runbook codificado en el sistema de tipos — el compilador advierte sobre desreferencias inseguras. Espejo del `Optional<T>` Java y del optional chaining `?.` Node.
- **12 README.md .NET per caso** reescritos en formato Senior espejado al de Java: primitivas BCL, contraste legacy vs solucion con snippets C#, tabla de rutas, comando hub (`http://localhost:8500/0X/...`), modo aislado y notas idiomaticas comparativas con los otros 4 stacks.
- **12 secciones `.NET 8`** agregadas a cada `comparison.md` con runtime, snippets legacy/optimizado en C#, primitivas distintivas (`AsyncLocal<T>` vs `ThreadLocal<T>`, `ConcurrentDictionary` vs `ConcurrentHashMap`, `Interlocked.CompareExchange` vs `AtomicReference.compareAndSet`, `SemaphoreSlim` vs `Semaphore`, `?.`+`??` vs `Optional<T>`).

### Changed

- **`dotnet-dispatcher/`**: lista de cases ampliada a 12. Puertos internos `:9501-:9512`. (Maneja el otro agente.)
- **`compose.dotnet.yml`**: comentario y healthcheck reflejan 12 casos. (Maneja el otro agente.)
- **12 `compose.yml` per-case .NET** generados con healthcheck. Puertos host: `851`, `852`, `853`, `854`, `855`, `856` (01-06 ya estaban), `857`, `858`, `859`, `8510`, `8511`, `8512`. (Maneja el otro agente.)
- **`shared/catalog/cases.json`**: los 12 casos ahora listan `dotnet` en `operational_stacks` con `runtime_entries.dotnet` completo (`port`, `compose_path`, `readme_path`, `health_path`, `root_path`, `isolated_compose`, `isolated_port`). `level_detail` de cada caso suma mencion a la primitiva .NET distintiva. Entrada `languages.dotnet` actualizada a estado operativo.
- **`docs/case-catalog.md`** regenerado manualmente con los 5 stacks por caso.
- **`README.md`**: tabla de stacks compose `.NET 8 OPERATIVO` (era `OPERATIVO (01-06)`); 60 endpoints (era 48); 5 puertos hub (era 4); 8 puertos cubren el lab entero (era 7); tabla de catalogo con columna `🟦 .NET` por caso y links a `cases/0X-.../dotnet/README.md`; bullet `OPERATIVO en .NET 8` describe los 12 casos con primitivas; quita "DOCUMENTADO / SCAFFOLD: casos 07-12 de .NET"; "Lo que este repo no vende" reformulado a paridad multi-stack universal a nivel funcional.
- **`ARCHITECTURE.md`**: tabla de casos operativos con `.NET ✅` en los 12; `pdsl-dotnet-lab` con 12 subprocesos `:9501-:9512`; capa 3 lista los 5 composes operativos.
- **`ROADMAP.md`**: Fotografia actual y Fase 2 reflejan paridad completa .NET (12 casos) con primitivas BCL distintivas por caso.
- **`RECRUITER.md`**: "12 casos × 5 stacks operativos"; comparison.md cubre los 5 stacks en los 12; 60 endpoints (era 48).
- **`INSTALL.md`**: tabla de hubs `.NET 8 OPERATIVO`; agrega seccion `## 🟦 Laboratorio .NET completo` con comandos hub; sin nota de "scaffold .NET".
- **12 `cases/0X/README.md`**: badge `Stacks` suma `.NET`; fila `🔵 .NET 8` de la tabla "Stacks disponibles" cambia de "🔧 Estructura lista" a `OPERATIVO` con la primitiva BCL distintiva; seccion "### .NET 8 (implementacion operativa)" reemplaza la antigua "### .NET (espacio de crecimiento)" con descripcion concreta de primitivas, link al README .NET del caso, puerto aislado y URL del hub `:8500`.

### Out of scope (mantenidos sin cambios)

- Implementaciones PHP, Python, Node, Java: sin tocar.
- CI workflows: sin actualizar en este pase (los maneja el agente que escribe codigo .NET).

Java 21 pasa de cubrir los primeros 6 casos a cubrir los 12. Paridad multi-stack completa entre PHP, Python, Node.js y Java — los 48 endpoints (12 casos × 4 stacks) operativos detras de 4 hubs simetricos.

### Added (6 Main.java reales con primitiva distintiva por caso)

- **Caso 07** (`Modernizacion incremental`): `ConcurrentHashMap<String, Function<Request, Response>>` como routing table mutable en runtime; `Function` como ACL closure. Espejo del `Map<consumer, handler>` Node.
- **Caso 08** (`Extraccion critica`): `Function<PriceRequestOld, PriceRequestNew>` como proxy de compatibilidad de contrato + `CopyOnWriteArrayList<Consumer<String>>` como event bus thread-safe (reads paralelos sin lock, writes copian array). Espejo de `Proxy` + `EventEmitter` Node.
- **Caso 09** (`Integracion externa inestable`): `Semaphore` como budget de cuota (`tryAcquire` no bloqueante) + `ConcurrentHashMap` como snapshot cache + `AtomicReference<String>` como breaker state.
- **Caso 10** (`Arquitectura cara para algo simple`): CPU real medido como N hops de `StringBuilder` (alocacion + traversal por hop) vs `HashMap.get` O(1). `System.nanoTime()` para medicion directa.
- **Caso 11** (`Reportes que bloquean operacion`): `ThreadPoolExecutor` acotado a 4 threads como pool principal (saturacion realista); `ExecutorService` dedicado para reporting; `CompletableFuture.supplyAsync(task, executor)` para submission explicita. `mainPool.getActiveCount()` y `getQueue().size()` como senal nativa de saturacion (equivalente al `monitorEventLoopDelay` Node).
- **Caso 12** (`Punto unico de conocimiento`): `Optional<T>` + `map/flatMap/orElse` como runbook codificado en el sistema de tipos; `AtomicInteger` para coverage y bus_factor. Espejo del optional chaining `?.` Node — el tipo obliga a manejar el caso vacio.
- **6 README.md Java per caso** con primitivas, contraste de codigo, rutas, modo hub y aislado.
- **6 secciones Java en `comparison.md`** (cases 07-12) con runtime, snippets legacy/optimizado, primitiva distintiva.

### Changed

- **`java-dispatcher/app/Dispatcher.java`**: lista de cases ampliada de 6 a 12 entradas. Puertos internos `:9401-:9412`.
- **`java-dispatcher/Dockerfile`**: COPY de los 12 Main.java + 12 invocaciones `javac` separadas (cada Main.class en su `/cases/0X/`).
- **`compose.java.yml`**: comentario y healthcheck reflejan 12 casos.
- **6 `compose.yml` per-case** generados para cases 07-12 con healthcheck. Puertos host: `847`, `848`, `849`, `8410`, `8411`, `8412` (sin colisiones con 01/02/03 que usan 841/842/843).
- **`shared/catalog/cases.json`**: cases 07-12 ahora listan `java` en `operational_stacks` con `runtime_entries.java` completo.
- **`docs/case-catalog.md`** regenerado.
- **`README.md`**: tabla compose `OPERATIVO` (era `PARCIAL`); 48 endpoints (era 42); tabla de catalogo con celdas Java pobladas en 07-12 con primitiva especifica en la columna "Que deja como prueba"; sin "(3 stacks)" residual.
- **`ARCHITECTURE.md`**: tabla de casos operativos con Java ✅ en los 12; pdsl-java-lab con 12 subprocesos `:9401-:9412`.
- **`docs/architecture.md`**: status table Java ✅ en los 12.
- **`docs/docker-strategy.md`**: tabla principal y reglas reflejan 12 casos Java.
- **`docs/executive-summary.md`**: intro + cases 07-12 listan `Java 21` en stacks operativos.
- **`docs/usage-and-scope.md`**: fila "01 al 12 operativos en Java 21"; paridad ajustada.
- **`AWS_MIGRATION.md`**: inventario y costos reflejan 12 casos Java (java-lab USD 7); 48 endpoints (12 × 4); ALB suma `/java/01..12/*`; DoD `/java/01..12/health`.
- **`RECRUITER.md`**: "12 casos × 4 stacks operativos"; comparison.md cubre los 4 stacks en los 12.
- **`RUNBOOK.md`**: 48 endpoints / 4 hubs; tabla casos aislados suma 6 filas Java (07-12); seccion diagnostico Java actualizada a 12 casos.
- **`INSTALL.md`**: Java OPERATIVO; URLs `01..12/health`; sin nota de "07-12 pendientes".
- **`ROADMAP.md`**: Fotografia actual y Fase 2 reflejan paridad completa Java (12 casos).
- **CI** (`.github/workflows/ci.yml`): `compose-config` matrix suma 6 java composes 07-12; `hub-probe` java-hub cases `"01..12"` (era `"01..06"`).

### Smoke test

Boot real `docker compose -f compose.java.yml up -d` con los 12 casos:
- Build OK (12 `javac` separados por colision de clase `Main`)
- Hub healthy en ~3s
- Los 12 `/0X/health` responden 200 con payload coherente (case + stack)
- Shutdown limpio via SIGTERM

## 2026-05-15 - Barrido documental post-Java + verificacion funcional de los 6 casos

Tras agregar Java 21 como 4to stack operativo, varias docs y READMEs seguian afirmando "3 stacks" / "3 hubs" / "Java planificado", y los 6 `comparison.md` por caso eran "PHP · Python · Node.js" sin seccion Java. Esta entrega es un barrido honesto que sincroniza narrativa con estado real + verificacion funcional de los 6 casos Java contra el patron Node.

### Changed

- `README.md`: "Tres hubs" → "Cuatro hubs operativos"; "36 endpoints / 3 puertos" → "42 endpoints / 4 puertos"; fila `compose.java.yml` `PARCIAL (casos 01-06)`; nota AWS_MIGRATION ahora dice "hubs PHP/Python/Node/Java".
- `ARCHITECTURE.md`: tabla de casos operativos con columna Java (✅ en 01-06, — en 07-12); seccion "Modelo de containerizacion" pasa a "4 stacks"; agrega `pdsl-java-lab` con `ProcessBuilder` en `:9401-:9406`; lista de composes raiz incluye `compose.java.yml`.
- `docs/architecture.md`: lista de composes raiz suma Java; **corrige tabla de estado operativo** — antes mostraba `node=scaffold` en cases 06-12 (Node ya era operativo en los 12); ahora Java ✅ en 01-06 y Node ✅ en los 12; tabla "Modelo de ejecucion" incluye `compose.nodejs.yml` y `compose.java.yml`.
- `docs/usage-and-scope.md`: fila nueva "Casos 01-06 operativos en Java 21"; nota de paridad ajustada a "Java 01-06; .NET scaffold".
- `INSTALL.md`: tabla muestra Java `PARCIAL (01-06)` en `8400` (antes `851-859 PLANIFICADO`); nueva seccion "Laboratorio Java" con comando `up`; alcance honesto al final menciona Java 01-06 y deuda 07-12.
- **6 README.md de caso (`cases/01..06/README.md`)**: fila "☕ Java | 🔧 Estructura lista" → "☕ Java 21 | OPERATIVO (\<primitiva\>)" con la primitiva especifica del caso. Caso 01 ademas tiene seccion narrativa Java con `ConcurrentHashMap`/`LongAdder`/`ScheduledExecutorService`.
- **6 comparison.md (`cases/01..06/comparison.md`)**: titulo suma `· Java`; seccion Java agregada con runtime, snippet legacy, snippet correccion, primitiva distintiva (~40 lineas por caso). Tablas finales "Diferencias de decision" se dejan estables — el contenido nuevo cubre el contraste sin refactorizar el resumen.

### Verified

Smoke funcional de los 6 casos Java corriendo `java Main` directo (sin Docker):

- **Caso 01**: `/report-legacy` retorna rows sin `lifetime_orders`; `/report-optimized` retorna rows con `lifetime_orders` y `lifetime_amount` (la cache `ConcurrentHashMap` esta poblada por el worker — 1531 customer summaries por ciclo).
- **Caso 02**: `/orders-legacy` con `db_hits=N+1`; `/orders-optimized` con `db_hits=2` (1 orders + 1 batch IN).
- **Caso 03**: `/checkout-legacy` retorna `status:error` sin id; `/checkout-observable` retorna `correlation_id` UUID que tambien aparece en `/logs` con campos estructurados.
- **Caso 04**: `/quote-legacy?fail=on` retorna `status:failed, attempts:5`; `/quote-resilient?fail=on` retorna `status:fallback` con `breaker:closed`; tras 3 fallos consecutivos pasa a `short_circuited` con `breaker:open`.
- **Caso 05**: `/batch-legacy` incrementa `retained_count` monoticamente; `/batch-optimized` se mantiene en `cap=1000`.
- **Caso 06**: `/deploy-legacy?scenario=secret_drift` deja `prod` en `degraded`; `/deploy-controlled?scenario=secret_drift` deja `prod` en la version previa (`rolled_back`).

No son demos: cada uno computa, muta estado y devuelve evidencia distinta entre legacy y optimizada.

## 2026-05-15 - Java 21 entra como 4to stack operativo: casos 01-06 + hub consolidado

Hasta hoy los stacks Java/.NET vivian como scaffolds genericos (un Main.java con `/fast`, `/slow`, `/cpu` sin solucionar el problema del caso). Esta entrega convierte los 6 primeros casos en implementaciones Java reales que resuelven cada problema con primitivas distintivas del lenguaje y los pone detras de un hub consolidado al estilo Python/Node.

### Added

- **`compose.java.yml`** en raiz, puerto `8400`. Mirror simetrico de `compose.python.yml` y `compose.nodejs.yml`: un solo contenedor, un solo puerto, dispatcher interno que enruta `/01..06/*` a subprocesos `java Main` en puertos internos `9401-9406`. Healthcheck en `/01/health`.
- **`java-dispatcher/`** con `Dockerfile` y `app/Dispatcher.java`. Compila todos los `Main.java` de los 6 casos + el dispatcher en build-time (arranque rapido), spawna cada caso como `Process` con `ProcessBuilder`, proxy via `HttpClient` (JDK built-in). Shutdown hook propaga SIGTERM.
- **`cases/01..06/java/app/Main.java`** reescritos como implementaciones reales (no scaffolds). Cada uno con `/health`, dos rutas contraste (`-legacy` vs `-optimized`/`-resilient`/`-observable`/`-controlled`), `/diagnostics/summary`, `/metrics`, `/reset-lab`. Sin Maven — single-file por caso, compilado en build con `javac`.
- **Primitivas Java distintivas por caso:**
  - 01 (API latency): `ConcurrentHashMap` para summary cache lock-free entre worker y handlers; `LongAdder` para p95/p99; `ScheduledExecutorService` para el worker `report-refresh-java`.
  - 02 (N+1): `HashMap<Integer,List<Item>>` precomputado como tabla relacional indexada; batch `IN(...)` simulado; `record` types.
  - 03 (Observability): `ThreadLocal<RequestContext>` para propagar `correlation_id` (equivalente a `ScopedValue` sin preview flags); log estructurado JSON inline; `/logs` endpoint con ultimos 200.
  - 04 (Timeouts): `CompletableFuture.orTimeout(Duration)` como deadline cooperativo; `AtomicReference<BreakerState>` con CAS para transiciones closed→open→half_open; fallback cacheado.
  - 05 (Memory): `LinkedHashMap.removeEldestEntry` como LRU built-in del JDK; `Runtime.getRuntime().totalMemory()/freeMemory()/maxMemory()` para medir heap directo; `System.gc()` opcional en `/reset-lab`.
  - 06 (Pipeline): `record EnvState` y `record Deployment` inmutables; `ConcurrentHashMap` por ambiente; state machine como guards en codigo (preflight → smoke → promote | rollback).
- **6 `README.md` Java per caso** (no stubs) con tabla de primitivas, snippet de contraste, rutas, ejemplos hub + aislado, y diferencias de runtime vs PHP/Python/Node.
- **Healthcheck en los 6 `compose.yml` per-case** (`/health` cada 10s, 10 reintentos). Modo aislado (`docker compose -f cases/0X/java/compose.yml up`) sigue funcionando con puertos host `841-846`.

### Changed

- `.github/workflows/ci.yml`:
  - `compose-config` matrix amplia a 46 archivos (suma `compose.java.yml` + los 6 java per-case).
  - `hub-probe` matrix incluye `java-hub` con la lista de cases parametrizada (`01 02 03 04 05 06`), reusando el mismo job pero respetando que Java es parcial.
- `shared/catalog/cases.json`: cases 01-06 ahora listan `java` en `operational_stacks` con `runtime_entries.java` (port 8400, compose.java.yml, isolated_compose, isolated_port).
- `docs/case-catalog.md` regenerado desde `cases.json`.
- `README.md`: tabla de hubs marca Java como **PARCIAL (casos 01-06)**, comandos de levantamiento incluyen `docker compose -f compose.java.yml up`, conteo de endpoints sube a 42 (12 PHP + 12 Python + 12 Node + 6 Java) detras de 4 puertos.
- `ROADMAP.md`: Fotografia actual y Fase 2 reflejan Java 21 como 4to stack operativo parcial; mencion explicita de las primitivas por caso. Anuncio de que casos 07-12 Java quedan pendientes.

### Why

El roadmap historicamente mencionaba "sumar Java o .NET para algun caso especifico". Tras cerrar PHP/Python/Node con paridad completa, Java entra como contraste fuerte: tipado estatico + GC + thread pool real + `CompletableFuture` + `ConcurrentHashMap` son primitivas que los otros stacks no expresan limpio. Hacerlo via hub (no 12 contenedores) preserva la simetria arquitectonica establecida con Python/Node — sigue habiendo "un compose por lenguaje" como afirma `docs/docker-strategy.md`.

### Smoke test

- `javac` sobre los 6 Main.java + Dispatcher.java → OK local.
- Boot local sin Docker (`java Main` directo) del caso 01 java: `/health`, `/report-legacy`, `/report-optimized`, `/batch/status`, `/metrics` todos responden 200 con payload coherente. Worker `report-refresh-java` refrescando 1531 customer summaries en ~4ms. Contraste medible: legacy ~18ms (4 db_hits) vs optimized ~3ms (2 db_hits).
- `docker compose -f compose.java.yml config` OK.
- `docker compose -f cases/0X/java/compose.yml config` OK para los 6.
- `bash scripts/validate-structure.sh` → OK (estructura + catalogo regenerado).

## 2026-05-15 - Resumen ejecutivo: los 12 casos en una pagina

Faltaba una vista agregada para lectores no tecnicos (recruiters, lideres de producto, finanzas, CTO sin tiempo). Los `README.md` por caso y `docs/case-catalog.md` cubren bien el detalle tecnico, pero ninguno respondia "¿que problema de negocio resuelve cada uno y que evidencia deja en 5 minutos?" en una sola pasada. Esta entrega abre Fase 3.

### Added

- `docs/executive-summary.md`: pagina unica con tabla resumen + seccion por caso (problema · valor · evidencia · honestidad · link al detalle). Contenido derivado de `shared/catalog/cases.json` para mantener consistencia con la fuente de verdad. Incluye seccion final "Que NO encontraras" para honestidad de scope y rutas rapidas por audiencia.

### Changed

- `README.md`: fila "Recruiter / hiring manager" en la tabla "Como evaluarlo rapido" ahora apunta `RECRUITER.md` → `docs/executive-summary.md`. Nueva entrada en la tabla de documentos.
- `ROADMAP.md`: Fase 3 pasa de **planificada** a **en progreso** con la vista agregada cubierta.

### Why

`RECRUITER.md` es la puerta de entrada para evaluacion ejecutiva, pero queda en nivel "narrativa del producto". El catalogo tecnico vive en `docs/case-catalog.md`. Faltaba el puente: una pagina donde alguien escanea los 12 casos en orden y entiende **valor de negocio + evidencia** sin entrar a leer 12 README. Esa pieza ahora existe.

## 2026-05-15 - CI: smoke de los 3 hubs (cierra asimetria PHP-only)

Hasta ahora CI solo probaba boot real del hub PHP (`portal-probe`). Los hubs Python (`compose.python.yml`) y Node (`compose.nodejs.yml`) quedaban fuera del smoke, asi como la mayoria de los `compose.yml` per-case de esos dos stacks. Esta entrega cierra esa asimetria sin disparar la matriz de CI.

### Added

- Nuevo job `hub-probe` en `.github/workflows/ci.yml` con matriz de 2 entradas paralelas (`python-hub` en `:8200`, `node-hub` en `:8300`). Cada entrada hace `docker compose up -d --build`, espera `/01/health` y luego probea los 12 casos via `/01..12/health`. Un solo boot por hub valida la paridad de los 12 casos del stack.

### Changed

- `compose-config` matrix ampliada de 16 a 40 archivos: ahora incluye `compose.python.yml`, `compose.nodejs.yml` y los 24 `compose.yml` per-case de Node y Python (antes solo caso 03 de cada uno). Sigue siendo un check barato — solo `docker compose config`.
- `ROADMAP.md`: Fase 4 marca CI minima como **parcialmente cubierta** (smoke de los 3 hubs + validacion estructural completa); pendiente smoke per-case node/python si llega a hacer falta.

### Why

`portal-probe` (PHP) demostraba boot real del laboratorio entero en cada PR. Sin equivalentes en Python/Node, un regression en el dispatcher Node o en `compose.python.yml` solo se detectaba al correrlos a mano. El job `hub-probe` por stack mantiene el costo CI bajo (2 boots paralelos cubren 24 casos) y replica la garantia que ya existia para PHP.

### Smoke test

- `python -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))"` OK (workflow parsea).
- `docker compose -f compose.python.yml config` OK; `docker compose -f compose.nodejs.yml config` OK.
- Los 24 `compose.yml` node+python validan via `docker compose config` localmente.

## 2026-05-08 - PHP dispatcher operativo: paridad arquitectonica completa con Python/Node

Cierra la asimetria que hasta ayer documentabamos como "deuda reconocida": PHP usaba ~20 contenedores (12 apps separadas + nginx hub + DB + observabilidad), mientras Python y Node usaban 1 contenedor con 12 subprocesos. **Ahora los tres stacks comparten el mismo patron arquitectonico** (1 dispatcher por lenguaje), preservando los servicios reales del caso 01 que NO son procesos PHP.

### Added

- `php-dispatcher/` con `Dockerfile`, `app/entrypoint.sh` y `app/dispatcher.php`. Espejo del patron de `python-dispatcher/` y `node-dispatcher/`:
  - `entrypoint.sh` spawnea los 12 servidores PHP (`php -S`) como subprocesos en `127.0.0.1:9001-:9012` con env DB-aware (caso 01 conecta a `case01-db`, caso 02 a `case02-db`, casos 03-12 sin DB).
  - `dispatcher.php` actua como router script de `php -S 0.0.0.0:8100` que enruta `/01..12/*` proxy-eando con `file_get_contents` + `stream_context`. Forward de query strings, headers, body POST/PUT/DELETE.
  - `tini` como PID 1 para signal forwarding limpio (SIGTERM/SIGINT propagado al shell, que mata los 12 hijos antes de salir).
- `php-dispatcher/Dockerfile` con `pdo_pgsql` instalado (necesario para casos 01 y 02 que conectan a PostgreSQL).

### Changed

- `compose.root.yml` reescrito: pasa de 14 services PHP (`php-hub` + 12 `caseXX-app`) a **1 service `php-lab`** con dispatcher. Servicios reales del caso 01 (PostgreSQL, worker, Prometheus, Grafana, exporter) NO se tocan — siguen siendo contenedores aparte porque son servicios independientes del lenguaje. Conteo total: ~20 contenedores → **~7 contenedores**. RAM total: ~2.5 GB → **~1 GB**.
- `cases/01-api-latency-under-load/shared/observability/prometheus.yml`: target del scrape pasa de `app:8080` a `php-lab:8100` con `metrics_path: /01/metrics-prometheus` (Prometheus llega al caso 01 via el dispatcher, en vez del contenedor `case01-app` que ya no existe).
- `docker/nginx/php-hub.conf` **eliminado** — el dispatcher PHP hace el routing ahora, ya no necesita nginx.

### Documentation sweep

- `docs/docker-strategy.md`: la seccion "Tres modelos" pasa a llamarse **"Modelo de containerización (simétrico para los 3 stacks)"**. Tabla unica con los 3 stacks siguiendo el mismo patron. Antes/despues del refactor PHP. Trade-offs heredados (hub vs per-case modo aislado).
- `README.md` raiz: nota debajo de la tabla de hubs aclara que los 3 hubs son simetricos; PHP tiene contenedores extras solo por los servicios reales del caso 01.
- `ARCHITECTURE.md`: subseccion de containerizacion actualizada con la nueva simetria y el refactor.
- `AWS_MIGRATION.md`: inventario reemplaza `php-hub` + `case01-app..case12-app` por una sola fila `php-lab`. Topologia ALB con `/php/*` → `tg-php-lab` (en vez de 12 target groups). **Costos recalculados**: total 24x7 baja de USD ~165 a **USD ~130-140/mes** (PHP pasa de USD 42 en 12 services a USD 7 en 1 dispatcher). Apagado fuera de horario: USD ~70-90/mes.
- `docs/architecture.md`: descripcion de `compose.root.yml` actualizada al nuevo modelo.

### Smoke test

- `php -l dispatcher.php` OK; `sh -n entrypoint.sh` OK.
- Spawn local sin Docker de los 10 casos PHP sin DB (03-12) detras del dispatcher: 10/10 responden 200 a `/XX/health`. Casos 06 y 12 retornan payloads completos end-to-end con query strings (`/06/deploy-controlled?...` y `/12/incident-distributed?...`).
- Casos 01 y 02 PHP no se testean localmente (requieren PostgreSQL + worker), pero el codigo de los casos no se toco — solo cambia el contenedor donde corren.

## 2026-05-07 - Asimetria de containerizacion por stack documentada explicitamente

### Documentation

- `docs/docker-strategy.md`: nueva seccion **🧱 Tres modelos de containerización (uno por stack) — y por qué son distintos**. Aclara que los tres hubs `compose.root.yml`/`compose.python.yml`/`compose.nodejs.yml` parecen simetricos pero adentro son arquitecturas distintas:
  - **PHP**: ~20 contenedores Docker reales (12 apps separadas + DB + observabilidad). Microservicios con aislamiento OS-level.
  - **Python**: 1 contenedor con 12 subprocesos `subprocess.Popen` internos.
  - **Node.js**: 1 contenedor con 12 subprocesos `child_process.spawn` internos.
- Tabla de trade-offs explicitos: RAM total (~2.5 GB vs ~512 MB), tiempo de boot (15-20s vs 3-5s), aislamiento (OS-level vs cooperativo), failure domain por memory leak, costo en AWS Fargate (12 services vs 1).
- Tabla "cuando elegir cada modelo" en tu propio proyecto.
- Justificacion explicita de por que NO se uniformaron los tres stacks (PHP no se puede colapsar a 1 contenedor por el caso 01; Python y Node si pueden por no tener estado externo; mantener los 3 modelos lado a lado muestra patrones reales que se ven en produccion).

### Changed

- `README.md`: nota visible debajo de la tabla de los 3 hubs apuntando a la nueva seccion. Aclara que "1 puerto por lenguaje" no implica "1 contenedor por lenguaje".
- `ARCHITECTURE.md`: subseccion nueva "Modelos de containerizacion por stack" debajo de la tabla de casos operativos, con link al detalle en docker-strategy.

## 2026-05-07 - AWS_MIGRATION.md actualizado: paridad Node + hubs + mapping de seguridad

### Changed

- `AWS_MIGRATION.md` refleja la realidad del repo post-Node:
  - Inventario incluye `node-lab` (dispatcher Node, 12 casos internos en `:9101 + :9002-:9012`) junto al `python-lab` y `php-hub`.
  - Topologia objetivo ECS Fargate documenta los **3 hubs por lenguaje** detras de un ALB con path routing por lenguaje (`/php/*`, `/py/*`, `/node/*`) — espejo del modelo local de los 3 composes (`compose.root.yml`, `compose.python.yml`, `compose.nodejs.yml`).
  - Tabla de costos Opcion A actualizada: 3 hubs Fargate (php-hub via 12 services, python-hub y node-hub como tasks unicas con dispatchers internos). Total 24x7 sube de USD ~145 a USD ~165/mes (incluye node-hub + WAF), con apagado fuera de horario en USD ~85–110.
  - Opcion B Lambda escala a 36 funciones (12 PHP + 12 Python + 12 Node) compartiendo Aurora Serverless v2 + CloudFront + WAF.

### Added

- Nueva seccion **🛡️ Como AWS resuelve los hallazgos abiertos del SECURITY.md** que mapea cada hallazgo (A1-A2 altos, M1-M4 medios) a la mitigacion AWS recomendada con costo aproximado:
  - **A1** (sin auth) → ALB OIDC + Cognito User Pool, o Lambda@Edge, o WAF X-API-Key
  - **A2** (DoS event loop caso 11) → WAF rate-based rule + ALB health checks + Auto Scaling
  - **M1** (verbo HTTP) → WAF custom rule por path/metodo
  - **M2** (Host reflejado) → CloudFront origin request policy + WAF managed rules
  - **M3** (sin rate limiting) → WAF rate-based rules + CloudFront cache + API Gateway throttling
  - **M4** (atomicidad de state) → DynamoDB con conditional writes / RDS / S3 ETag — el problema desaparece al moverse fuera de `/tmp`
- Ejemplo concreto end-to-end de como `/node/11/report-legacy?rows=5000000` queda blindado en AWS (Cognito → WAF rate limit → ALB health check → CloudWatch alarm → Auto Scaling), con costo total ~USD 6-10/mes.
- Tabla de **defensas adicionales que AWS aporta** (CloudFront edge, AWS Shield Standard, GuardDuty, CloudTrail, IAM task roles, VPC privadas, Secrets Manager + KMS, AWS Config + Security Hub).
- Definition of Done extendida con checks por stack (PHP/Python/Node) y validacion explicita del mapping de seguridad.

### Documentation

- `README.md` raiz: bullet del Executive Summary y fila de la tabla de docs principales mencionan ahora el mapping `SECURITY.md` → AWS dentro de `AWS_MIGRATION.md`.

## 2026-05-07 - Postura de seguridad documentada con honestidad

### Security

- `SECURITY.md` reescrito con un **analisis completo** del lab: modelo de amenaza explicito (3 escenarios localhost/LAN/Internet), defensas activas verificadas por revision manual con `archivo:linea` (SQL injection, allowlist de scenarios, regex de SKU/release, clamping numerico, paths fijos, sin shell exec, sin eval, AbortSignal cooperativo, etc.), y los hallazgos abiertos clasificados por severidad — A1 sin auth, A2 DoS del event loop en caso 11, M1 sin validacion de metodo HTTP, M2 reflejo del header Host en probe.php, M3 sin rate limiting, M4 sin atomicidad en escrituras de state.
- Checklist mínimo para exponer mas alla de localhost (reverse proxy + TLS + auth + rate limit + bloquear `/reset-lab`).
- Nota explicita sobre la complicacion del bind localhost-only: requiere mover el portal a la misma red Docker que los hubs y resolver por DNS interno (no implementado todavía).

### Changed

- `README.md` raiz: nueva seccion **🔐 Postura de seguridad y modelo de despliegue** con tabla de 3 escenarios + resumen de garantias activas + frontera honesta de lo que no se garantiza + link a `SECURITY.md`. Tambien fila nueva "Security engineer" en la tabla "Como evaluarlo rapido".

## 2026-05-06 - Node.js hub `compose.nodejs.yml` operativo: tres puertos cubren el lab

### Added

- `compose.nodejs.yml` en la raiz expone el dispatcher Node en `8300`. Sirve los 12 casos via routing por path (`/01/health`...`/12/health`) sin exponer los 12 puertos per-case. Patron espejo de `compose.python.yml`.
- `node-dispatcher/` con `Dockerfile` y `app/main.js`: spawnea los 12 servers como subprocesos internos (no expuestos al host) y proxy-ea por prefijo de path. Maneja shutdown graceful con SIGTERM/SIGINT. Caso 01 corre en `:9101` (en vez de `:9001`) porque algunos hosts Windows reservan `9001`; los demas casos usan `:9002`-`:9012`.

### Changed

- `cases/03-poor-observability-and-useless-logs/node/app/server.js` ahora honra `process.env.PORT` (antes hardcodeaba `8080`). Bug que impedia correr el caso 03 dentro del hub.
- `README.md` raiz: la fila `compose.nodejs.yml` pasa de `PLANIFICADO` a `OPERATIVO`. Nueva narrativa: **6 puertos cubren el laboratorio entero** (3 hubs + portal + Prometheus + Grafana). Los per-case quedan documentados como modo estudio aislado para casos donde la medicion lo requiere (`05` memoria, `11` event loop).
- `ROADMAP.md`, `ARCHITECTURE.md`, `RUNBOOK.md`: reflejan paridad de los 3 hubs y aclaran cuando usar per-case (modo estudio).

### Why

La asimetria PHP/Python (1 puerto cada uno) vs Node (12 puertos) era ruido innecesario. El plan oficial siempre fue 1 puerto por lenguaje; la deuda solo era de implementacion. Cerrarla deja el lab con 6 puertos efectivos en lugar de 42 potenciales.

## 2026-05-06 - Node.js multi-stack completo: casos 06 al 12 operativos

### Added

- Caso `06` Node.js: pipeline legacy vs controlled con `AbortController` + `AbortSignal` propagado por cada paso. Cancela cooperativamente si el cliente desconecta o si el deadline se vence — limpieza nativa, sin polling. Puerto `826`.
- Caso `07` Node.js: strangler como `Map<consumer, handler>` mutable en runtime. Registrar el routing del nuevo modulo es una linea, sin reload del proceso. ACL como closure que filtra contrato. Puerto `827`.
- Caso `08` Node.js: `Proxy` nativo intercepta `computeFinalPrice` y traduce `cost_usd` -> `price` en vuelo. `EventEmitter` (`cutoverBus`) publica cada avance del cutover. Puerto `828`.
- Caso `09` Node.js: `AbortSignal.timeout(ms)` (Node 18+) marca deadline del llamado externo + circuit breaker en memoria con tres estados (closed/open/half_open) y reapertura automatica tras cooldown. Puerto `829`.
- Caso `10` Node.js: el costo de la sobrearquitectura se mide como CPU real — N rondas de `JSON.stringify`/`parse` sobre arrays grandes en `complex` vs acceso O(1) en `right_sized`. Bajo `seasonal_peak`, complex devuelve 502 por timeout interno. Puerto `8210`.
- Caso `11` Node.js: `perf_hooks.monitorEventLoopDelay()` mide el lag real del event loop. `report-legacy` ejecuta CPU sincronico que castiga el loop entero (visible en `event_loop_lag_ms_p99`); `report-isolated` cede control con `setImmediate`. Puerto `8211`.
- Caso `12` Node.js: optional chaining (`a?.b?.c ?? default`) como **runbook codificado en el lenguaje** — distributed evita el crash que sufre legacy con acceso ciego a estructuras anidadas. `share-knowledge` sube `coverage` y baja `mttr_min` de forma medible. Puerto `8212`.
- Healthchecks Docker en `compose.yml` de los 7 casos.

### Changed

- `README.md` raiz: catalogo con columna "Análisis Técnico (Node.js)" completa para los 12 casos; estado actual indica paridad multi-stack PHP + Python + Node.js completa.
- `ROADMAP.md`: Fotografia actual y avance Fase 2 reflejan paridad Node.js completa con detalle de la primitiva nativa por caso.
- `cases/06..12/node/README.md`: re-escritos con el problema, la primitiva Node y endpoints reales (eran scaffolds).

## 2026-05-05 - Node.js multi-stack: casos 01, 02, 04 y 05 operativos

### Added

- Caso `01` Node.js: implementacion con datos en memoria + worker `setInterval` + metrica `event_loop_lag_ms` medida con `setImmediate`.
- Caso `02` Node.js: N+1 anidado con `await` secuencial vs batch en `Map`+`Set`, exponiendo `event_loop_lag_ms` como senal Node-especifica.
- Caso `04` Node.js: `AbortController`/`AbortSignal` como timeout primitivo cooperativo + circuit breaker con estado persistido + fallback cacheado.
- Caso `05` Node.js: medicion real con `process.memoryUsage()` separando `heapUsed`, `heapTotal`, `rss` y `external`; fuga real cross-request en array de modulo, sanitizacion via `Map` acotado y eviction.

### Changed

- `README.md` raiz: nueva columna "Análisis Técnico (Node.js)" en el catalogo de casos resolutivos.
- `comparison.md` de casos `01`, `02`, `03`, `04` y `05`: titulo y tabla actualizados a multi-stack (PHP · Python · Node.js); seccion Node.js agregada con codigo, decisiones y diferencias de runtime.
- `cases/01..05/README.md`: estados de stack actualizados (Node.js como `OPERATIVO`); README caso 01 incorpora seccion dedicada a Node.
- `ARCHITECTURE.md`, `docs/architecture.md`, `RUNBOOK.md`, `ROADMAP.md`, `RECRUITER.md`, `docs/positioning-and-objective.md`, `docs/usage-and-scope.md`, `docs/BEGINNERS_GUIDE.md`: refleja paridad multi-stack honesta.
- `shared/catalog/cases.json`: `node` agregado a `operational_stacks` de casos `01`, `02`, `04`, `05` con `runtime_entries` (puertos `821`, `822`, `824`, `825`); `docs/case-catalog.md` regenerado.

## 2026-04-03 - Catalogo compartido, CI minima y caso 03 multi-stack

### Added

- `ARCHITECTURE.md` como vista ejecutiva de la arquitectura actual.
- `shared/catalog/cases.json` como fuente de verdad del catalogo.
- `scripts/generate_case_catalog.php` para generar `docs/case-catalog.md`.
- `.github/workflows/ci.yml` con validacion estructural, chequeo del catalogo generado y smoke boot de compose.

### Changed

- `portal/app/index.php` ahora consume metadatos compartidos y presenta una landing mas profesional con iconos y estados.
- `compose.root.yml` monta el catalogo compartido para eliminar duplicacion manual del portal.
- `scripts/validate-structure.sh`, `.gitignore`, `Makefile`, `shared/README.md` y `templates/problem-metadata.json` endurecidos para crecimiento mas limpio.
- Caso `03` profundizado en Node.js y Python con `legacy` vs `observable`, logs estructurados, trazas, metricas y endpoints de diagnostico.

## 2026-04-02 - Profesionalizacion documental

### Added

- `RECRUITER.md` como ruta ejecutiva para evaluacion rapida.
- `INSTALL.md`, `RUNBOOK.md`, `SUPPORT.md`, `SECURITY.md` y `CONTRIBUTING.md` en la raiz.
- `docs/BEGINNERS_GUIDE.md` para primeros pasos.

### Changed

- `README.md` reestructurado con rutas por audiencia, taxonomia honesta y contexto de ecosistema.
- `ROADMAP.md`, `docs/recruiter-guide.md`, `docs/usage-and-scope.md`, `docs/positioning-and-objective.md`, `docs/case-catalog.md` y `docs/docker-strategy.md` alineados con el nuevo estandar editorial.

## 2026-04-02 - Casos 02 y 03 operativos en PHP

### Added

- Caso `02` implementado con PostgreSQL real y comparacion N+1 legacy vs lectura optimizada.
- Caso `03` implementado con comparacion entre logs pobres y telemetria util.

### Changed

- Estrategia Docker consolidada como via oficial para casos implementados.
- Limpieza de artefactos versionados y endurecimiento de validacion estructural.
- Caso `01` ajustado para manejar metricas temporales fuera del arbol del repositorio.
