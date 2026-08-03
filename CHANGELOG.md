# 📝 CHANGELOG

Todos los cambios notables de este laboratorio se registran aqui con foco en madurez tecnica y documental.

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
