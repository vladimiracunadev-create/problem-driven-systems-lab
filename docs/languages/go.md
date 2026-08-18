# 🐹 Go

> **Versión fijada:** `1.23` · **Imagen base:** `golang:1.23-alpine` · **Hub:** `:8600` · **Casos operativos:** 18 / 18

[⬅️ Volver a los perfiles de lenguaje](README.md) · [🗺️ Mapa de stacks](../stack-map.md) · [🔄 Protocolo de actualización](../language-upgrade-protocol.md)

---

## 🪪 Identidad

Go es un lenguaje compilado, con tipado estático y recolector de basura, diseñado en Google alrededor de una restricción explícita: **que un equipo grande pueda leer código que no escribió**. El resultado es un lenguaje deliberadamente pequeño —sin herencia, sin genéricos hasta 1.18, sin excepciones, sin sobrecarga de operadores— con una biblioteca estándar inusualmente completa.

**Para qué se usa en la industria:** infraestructura de red y servicios backend, CLIs, y sobre todo el ecosistema cloud-native. Docker, Kubernetes, Terraform, Prometheus y etcd están escritos en Go. Cuando una herramienta tiene que distribuirse como un único binario sin dependencias, Go es la respuesta por defecto.

**Por qué está en este laboratorio:** porque tiene el modelo de concurrencia más económico del set. Una sola primitiva —el canal, más `select`— cubre semáforo, timeout, cola y cancelación. Los otros seis stacks necesitan una clase distinta para cada una de esas cuatro cosas.

---

## ⚙️ Modelo de ejecución

**Goroutines multiplexadas por el runtime sobre threads del sistema operativo.**

Una goroutine arranca con ~2 KB de stack que crece bajo demanda. El runtime las reparte sobre `GOMAXPROCS` procesadores lógicos. Levantar cien mil goroutines es normal; levantar cien mil threads del SO no lo es.

Tres consecuencias que se ven en los casos del laboratorio:

| Consecuencia | Dónde se nota |
|---|---|
| **No hay pool que dimensionar** | En Java el caso 11 se resuelve separando `ThreadPoolExecutor`. En Go no hay pool que agotar, así que el problema se replantea como *limitar concurrencia* con un canal de capacidad N — [caso 11](../../cases/11-heavy-reporting-blocks-operations/go/README.md) |
| **La cancelación viaja hacia abajo** | `context.Context` no es un reloj para el llamador: el callee lo observa con `select { case <-ctx.Done(): }` y **abandona el trabajo**. Es el único stack del lab donde el trabajo realmente se detiene — [caso 04](../../cases/04-timeout-chain-and-retry-storms/go/README.md) |
| **El runtime es observable sin agente** | `runtime.NumGoroutine()` reporta la fuga de concurrencia que ningún otro stack del laboratorio puede medir desde adentro — [caso 05](../../cases/05-memory-pressure-and-resource-leaks/go/README.md) |

---

## 🧰 Primitivas que usa el laboratorio

Una tabla por caso, con la primitiva central y por qué se eligió. Es el material que hay que releer cuando Go publique una versión nueva.

| Caso | Primitiva central | Por qué esta y no otra |
|---|---|---|
| [01 · API lenta](../../cases/01-api-latency-under-load/go/README.md) | `modernc.org/sqlite` + `journal_mode=WAL` | Port de SQLite a Go puro: con `CGO_ENABLED=0` el binario queda estático |
| [02 · N+1](../../cases/02-n-plus-one-and-db-bottlenecks/go/README.md) | `database/sql` + `defer rows.Close()` | No es un ORM: **obliga** a escribir el SQL, así que el N+1 es una decisión visible |
| [03 · Observabilidad](../../cases/03-poor-observability-and-useless-logs/go/README.md) | `context.Context` + `log/slog` | Parámetro explícito, no almacenamiento ambiente. `slog` trae JSON estructurado en la stdlib desde 1.21 |
| [04 · Timeouts](../../cases/04-timeout-chain-and-retry-storms/go/README.md) | `context.WithTimeout` + `select` sobre `ctx.Done()` | El deadline **viaja** y el proveedor abandona el trabajo al vencer |
| [05 · Memoria](../../cases/05-memory-pressure-and-resource-leaks/go/README.md) | `runtime.ReadMemStats` + `container/list` | Heap real sin agente externo. La LRU se construye a mano: Go no trae `LinkedHashMap` |
| [06 · Pipeline](../../cases/06-broken-pipeline-and-fragile-delivery/go/README.md) | `sync.Mutex` sobre la transacción completa | La sección crítica es *leer versión → decidir → escribir*, no cada acceso suelto |
| [07 · Monolito](../../cases/07-incremental-monolith-modernization/go/README.md) | `map[string]handlerFunc` + `sync.RWMutex` | La firma **es** el tipo: sin `Function<,>` ni `Func<,>` envolviendo |
| [08 · Extracción](../../cases/08-critical-module-extraction-without-breaking-operations/go/README.md) | `chan busEvent` + `select` con `default` | Publicar sin bloquear: si el buffer está lleno se descarta, no se frena el tráfico |
| [09 · Integración externa](../../cases/09-unstable-external-integration/go/README.md) | `chan struct{}` bufferizado | El canal **es** el semáforo. `struct{}` ocupa cero bytes: es puro conteo |
| [10 · Sobre-arquitectura](../../cases/10-expensive-architecture-for-simple-needs/go/README.md) | `strings.Builder` + mapa de solo lectura | Se llena una vez al arrancar, así que no necesita lock |
| [11 · Reportes](../../cases/11-heavy-reporting-blocks-operations/go/README.md) | `chan struct{}` con capacidad N + `runtime.Gosched()` | Limitador de concurrencia. Es **la misma primitiva** del caso 09 y del 08 |
| [12 · Punto único](../../cases/12-single-point-of-knowledge-and-operational-risk/go/README.md) | comma-ok (`v, ok := m[k]`) | La ausencia está en el tipo de retorno: no se puede usar el valor sin recibir el booleano |
| [13 · Cache stampede](../../cases/13-cache-stampede-and-thundering-herd/go/README.md) | `sync.WaitGroup` como contador con espera | `singleflight` sin dependencias: el líder hace `Add(1)`, los seguidores `Wait()` |
| [14 · Pool de conexiones](../../cases/14-connection-pool-exhaustion/go/README.md) | canal bufferizado + `select` + `defer` | El canal **es** el pool. El límite honesto: `defer` hay que acordarse de escribirlo |
| [15 · Backpressure](../../cases/15-message-queue-backpressure/go/README.md) | `chan` bufferizado + `select` | No existe el canal con buffer infinito: la versión sin tope hay que escribirla a mano |
| [16 · Idempotencia](../../cases/16-idempotency-and-duplicate-effects/go/README.md) | `sync.Map.LoadOrStore` | El caso donde `sync.Map` **sí** corresponde: escribir una vez, leer muchas |
| [17 · Migración sin downtime](../../cases/17-zero-downtime-schema-migration/go/README.md) | `sync.RWMutex` + goroutine para el deadline | Sin hambruna, pero la goroutine sobrevive al lector que se rindió |
| [18 · Arranque en frío](../../cases/18-cold-start-and-autoscale-lag/go/README.md) | binario AOT + `sync.Once` | No gana por rápido: gana por **no tener nada que calentar** |

> 💡 **El patrón que solo se ve mirando la columna entera:** los casos 04, 08, 09 y 11 resuelven cuatro problemas distintos —cancelación, bus de eventos, semáforo de cuota y limitador de concurrencia— con **canal + `select`**. En los otros seis stacks son cuatro APIs diferentes que hay que conocer por separado.

---

## 📈 Rendimiento: qué mide el laboratorio y cómo reproducirlo

> ⚠️ **Este repositorio no publica benchmarks entre lenguajes.** Comparar milisegundos entre siete implementaciones que corren en contenedores distintos, con motores de base distintos, mediría el entorno y no el criterio. Lo que sí se mide es la **pendiente dentro de cada stack**: legacy contra optimized, en el mismo runtime y la misma máquina.

Lo que Go instrumenta sin dependencias externas:

| Señal | De dónde sale | Qué caso la expone |
|---|---|---|
| `avg_ms` · `p95_ms` · `p99_ms` por ruta | muestras en memoria + `sync/atomic` | 01, 02, 10 |
| `heap_used_mb` · `gc_cycles` | `runtime.ReadMemStats` | 05 |
| `goroutines` vivas | `runtime.NumGoroutine()` | 05, 11 |
| `gomaxprocs` · slots usados | `runtime.GOMAXPROCS(0)` | 11 |
| `db_hits` por request | contador propio alrededor de `database/sql` | 01, 02 |

**Reproducir la medición del caso 05 (presión de memoria):**

```bash
docker compose -f compose.go.yml up -d --build
curl -s localhost:8600/05/state
for i in $(seq 1 40); do curl -s "localhost:8600/05/batch-legacy?size_kb=64" > /dev/null; done
curl -s localhost:8600/05/state          # retained_count crece de forma monotonica
for i in $(seq 1 40); do curl -s "localhost:8600/05/batch-optimized?size_kb=64" > /dev/null; done
curl -s localhost:8600/05/state          # retained_count se estabiliza en cap=1000
```

**Especificación de rendimiento que el caso 11 verifica:** con el limitador puesto, `/report-isolated` mantiene como máximo 2 reportes concurrentes (`ran_on_pool: reporting-limiter`) y `/order-write` no debe marcar `degraded: true`. Sin el limitador, `/report-legacy` deja que cada request levante su goroutine y la escritura se degrada. La verificación es cualitativa y reproducible, no un número absoluto.

---

## 🚧 Límites, problemas sin solución y desafíos

Lo que Go **no** resuelve, documentado con el mismo criterio que lo que sí:

| Límite | Por qué importa | Dónde se ve |
|---|---|---|
| **`defer` olvidado = deadlock que compila** | Es la única categoría de bug que Rust elimina y Go no. Adquirís el semáforo, retornás temprano, y el permiso nunca vuelve | [caso 09](../../cases/09-unstable-external-integration/comparison.md) |
| **`rows.Err()` olvidado silencia el fallo parcial** | El cursor puede fallar a mitad y el loop termina normal. Rust lo hace imposible con `collect::<Result<Vec<_>>>()` | [caso 02](../../cases/02-n-plus-one-and-db-bottlenecks/comparison.md) |
| **No hay LRU en la biblioteca estándar** | `container/list` + un mapa, a mano. Java lo resuelve con una línea (`removeEldestEntry`) | [caso 05](../../cases/05-memory-pressure-and-resource-leaks/go/README.md) |
| **Manejo de errores verboso** | `if err != nil` en cada llamada. Es explícito y auditable, y también es la crítica más repetida al lenguaje |  transversal |
| **`context.Context` es cooperativo, no forzoso** | Si el callee no revisa `ctx.Done()`, el deadline no hace nada. Go propaga la señal; no interrumpe threads | [caso 04](../../cases/04-timeout-chain-and-retry-storms/go/README.md) |
| **Sin exhaustividad en el sistema de tipos** | No hay `enum` con `match` exhaustivo: agregar un estado nuevo no rompe la compilación de los `switch` existentes | [caso 06](../../cases/06-broken-pipeline-and-fragile-delivery/comparison.md) |

**Desafío abierto del stack en este laboratorio:** el caso 06 es el único donde Go queda tercero, y la razón es de fondo — un pipeline de deploy es una máquina de estados, y Go no tiene forma de que el compilador exija cubrir todos los estados. La decisión tomada (un `sync.Mutex` sobre la transacción completa) es correcta, pero no hay red de seguridad de tipos debajo.

---

## 🏆 Dónde gana y dónde pierde en el laboratorio

Agregado de los veredictos de las 17 comparativas que rankean: **7 primeros puestos, media 2.0** — el mejor promedio del set.

- 🥇 **Gana en 01, 04, 08, 09, 13 y 15** — todos los casos donde el problema es concurrencia, cancelación o coordinación. La economía conceptual del canal —y del `WaitGroup`— se paga sola.
- 🥈 **Segundo en 02, 03, 05, 07, 11 y 12** — sólido en todo, sin picos.
- 🥇 **Gana en 18** — su binario estático AOT no tiene curva de calentamiento que medir (1,0x), y `sync.Once` es la forma más legible del lab de decir «esto cuesta una sola vez».
- 🥉 **Tercero en 16**
- **4º en 17** — `sync.RWMutex` no tiene hambruna de escritor, pero tampoco `RLock` con timeout: armarlo deja una goroutine viva por cada lector que se rindió. — `sync.Map.LoadOrStore` con el contrato comma-ok de siempre, y el caso donde `sync.Map` sí corresponde.
- **5º en 14** — el canal como pool es la expresión más económica del set, pero `defer` es una línea que hay que acordarse de escribir, y olvidarla compila.
- 🥉 **Tercero en 06** — el único caso donde la falta de exhaustividad de tipos le cuesta el podio.

**Lectura honesta:** Go es el stack más consistente del laboratorio, no el más brillante. Rust gana más casos (6 contra 5) pero también cae al quinto puesto en el caso 04. El 14 es la excepción: es el único caso donde Go baja del tercer puesto, y la causa es exactamente esa línea.

---

## 🔄 Ciclo de versiones

| | |
|---|---|
| **Versión fijada hoy** | `1.23` (`golang:1.23-alpine`) |
| **Cadencia upstream** | Dos releases menores por año (febrero y agosto) |
| **Política de soporte** | Las dos últimas versiones menores reciben parches de seguridad |
| **Producto en endoflife.date** | `go` |

**Qué revisar en el próximo salto:**

1. **Cambios en el scheduler** — el argumento del [caso 11](../../cases/11-heavy-reporting-blocks-operations/go/README.md) depende de cómo el runtime reparte goroutines sobre `GOMAXPROCS`. Un cambio ahí no rompe el código, pero puede volver falsa la explicación.
2. **Nuevas primitivas en `sync` o `context`** — si la stdlib incorpora un semáforo o un limitador de tasa, los casos 09 y 11 pasarían a enseñar la construcción manual de algo que ya viene hecho.
3. **`log/slog`** — estable desde 1.21, pero cualquier cambio en la API afecta al caso 03.
4. **Iteradores y `range over func`** (1.23) — ya disponibles; ningún caso los usa todavía. Vale evaluar si el caso 02 quedaría más claro con ellos.

El detalle del procedimiento —qué archivos tocar y en qué orden— está en [docs/language-upgrade-protocol.md](../language-upgrade-protocol.md).

---

## 🚀 Levantar el stack

```bash
docker compose -f compose.go.yml up -d --build
```

Los 18 casos quedan servidos en `http://localhost:8600/NN/`. Para correr un caso aislado —útil cuando la medición necesita el runtime limpio— cada caso trae su propio `compose.yml` en `cases/NN-*/go/`.
