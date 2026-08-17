# 🗺️ ROADMAP — Problem-Driven Systems Lab

> Hacia donde va el laboratorio: nuevos casos de la vida real, mejoras de plataforma, y compromisos de honestidad tecnica.

## Estado actual (2026-08-17)

- **14 casos × 7 stacks operativos = 98 endpoints** detras de 7 hubs simetricos (`compose.root.yml` PHP `:8100`, `compose.python.yml` Python `:8200`, `compose.nodejs.yml` Node `:8300`, `compose.java.yml` Java `:8400`, `compose.dotnet.yml` .NET `:8500`, `compose.go.yml` Go `:8600`, `compose.rust.yml` Rust `:8700`).
- **Casos 01 y 02 con fidelidad universal:** los 7 stacks ejecutan SQL real sobre un motor — PostgreSQL en PHP, SQLite stdlib en Python, `node:sqlite` built-in en Node, `sqlite-jdbc` en Java, `Microsoft.Data.Sqlite` en .NET, `modernc.org/sqlite` (Go puro, sin cgo) en Go, `rusqlite` feature `bundled` en Rust. `db_hits` / `db_queries_in_request` cuentan ejecuciones reales contra motor en los siete runtimes.
- **Caso 01 con el filtro no sargable verificado por el planner:** `EXPLAIN QUERY PLAN` devuelve `SCAN orders` para `WHERE LOWER(region) LIKE 'n%'` y `SEARCH orders USING INDEX idx_orders_region` para el mismo predicado reescrito como rango. Java y .NET usan `journal_mode=WAL` para que el worker que refresca el resumen no bloquee a los lectores — el equivalente embebido del MVCC de PostgreSQL.
- **Asimetria que queda, por diseño:** solo PHP cruza un socket TCP contra un motor externo con pool FPM finito. Los otros seis embeben el motor. Node y Python conservan un round-trip artificial explicito que modela el hop de red ausente. Documentado en cada `comparison.md` y `README.md` de stack, no escondido.
- Documentacion editorial completa (`README.md`, `RECRUITER.md`, `ARCHITECTURE.md`, `RUNBOOK.md`, `SECURITY.md`, `AWS_MIGRATION.md`, `CONTRIBUTING.md`, `CHANGELOG.md`).
- Catalogo unificado en `shared/catalog/cases.json` como fuente de verdad del portal, `docs/case-catalog.md` y la narrativa operativa.
- Portal local con `index.html` + `catalog.php` + `probe.php` server-side para health en vivo.
- CI con validacion estructural + `compose-config` sobre 106 archivos + `portal-probe` PHP + `hub-probe` Python/Node/Java/.NET/Go/Rust sobre los 14 casos por stack en un solo boot.

## Eje 1 — Nuevos casos de la vida real (13-20)

> **Progreso: 2 de 8 entregados.** Los casos 13 y 14 estan operativos en los 7 stacks. Los casos 15-20 siguen en especificacion.

Ocho casos adicionales que extienden el lab con problemas que se ven en sistemas productivos reales. Cada uno mantiene el formato problem-driven: sintoma observable → causa raiz tecnica → solucion idiomatica por stack → evidencia medible.

### Caso 13 — Cache stampede (thundering herd) — ✅ ENTREGADO (2026-08-17)

**Estado:** operativo en los **7 stacks**, no solo en los tres inicialmente previstos. Ver [`cases/13-cache-stampede-and-thundering-herd/`](cases/13-cache-stampede-and-thundering-herd/README.md).

**Lo que se construyo, contra lo que se habia planeado:**

| Planeado | Entregado |
|---|---|
| PHP + Python + Node | Los 7 stacks. El validador de estructura del repo exige las 7 carpetas por caso, y romper esa simetria habria contradicho la identidad del lab. |
| single-flight, TTL con jitter, soft/hard TTL | Los tres, mas el **double-checked locking dentro del vuelo** — sin el, el patron da 3 o 4 recalculos en vez de 1 y el caso enseñaba algo falso. |
| medir hits simultaneos y p99 | `origin_computations`, `stampede_depth`, `coalesced_waiters`, `served_stale`, `p99_wait_ms`. |

**Lo que salio del camino y vale registrar:** el origen es **CPU real**, no un `sleep`. Con un sleep, Node absorbe N esperas concurrentes sin costo y el caso no prueba nada — lo que duele en una estampida real es que el origen *hace* el trabajo N veces. Y en Python hizo falta una barrera de dos fases: sin ella el GIL colapsaba la rafaga y la variante naive daba un falso verde que dependia de `sys.setswitchinterval`.

<details>
<summary>Especificacion original del caso</summary>

**Sintoma:** la cache de un endpoint caro expira a las 03:00 AM y miles de requests pegan a la DB simultaneamente. La DB cae 90 segundos, el resto del sistema cae con ella.

**Causa real:** falta de single-flight (un solo recalculo concurrente), TTL fijo sin jitter, sin soft TTL que permita servir el valor viejo mientras un solo worker lo refresca.

**Solucion a demostrar:** `golang/sync/singleflight`-style en cada stack (un solo recalculo, los demas esperan al mismo `Future`/`Promise`/`CompletableFuture`), TTL con jitter aleatorio (`base ± rand(0, base/4)`), soft TTL + hard TTL con refresh asincronico.

**Stacks objetivo iniciales:** PHP + Python + Node — la primitiva `single-flight` es muy expresiva: `Promise` con dedupe map en Node, `threading.Event` + dict de inflight en Python, lock por key con `apcu` en PHP.

**Que medir:** numero de hits simultaneos contra el origen cuando la cache expira, profundidad del "stampede" antes y despues, latencia p99 durante el evento.

</details>

---

### Caso 14 — Connection pool exhaustion — ✅ ENTREGADO (2026-08-17)

**Estado:** operativo en los **7 stacks**, no solo en los tres inicialmente previstos. Ver [`cases/14-connection-pool-exhaustion/`](cases/14-connection-pool-exhaustion/README.md).

**Lo que se construyo, contra lo que se habia planeado:**

| Planeado | Entregado |
|---|---|
| Java + .NET + Python | Los 7 stacks, por la misma razon estructural que el caso 13. |
| Little's law, timeout de adquisicion, try-with-resources / using / context manager / finally | Los cuatro, mas `defer` en Go y **`impl Drop` en Rust** — que resulto ser el punto mas interesante del caso. |
| `pool_active`, `pool_waiting`, `pool_wait_ms_p99`, leaks por `acquire` vs `release` | Los cuatro, mas `hung` (los que esperan a algo que ya no existe) y `pool_available_after`. |

**Lo que salio del camino y vale registrar:** el caso quedo siendo **el unico del lab donde Rust gana por lo que el lenguaje impide, no por lo que expresa**. Con `impl Drop` la fuga no se puede escribir por descuido: la variante leaky tuvo que llamar a `std::mem::forget` a proposito, que es la unica forma de perder un recurso en Rust seguro. Go, en cambio, baja al quinto puesto por una sola linea — `defer` hay que acordarse de escribirlo, y olvidarlo compila.

Y una decision de fidelidad que va al reves de la del caso 13: aca el trabajo **si** es un `sleep`. Una conexion se retiene mientras se espera a la red, no mientras se quema CPU. Misma pregunta —¿que recurso escasea de verdad?—, respuesta opuesta.

<details>
<summary>Especificacion original del caso</summary>

**Sintoma:** "could not get connection from pool" bajo carga moderada. Requests cuelgan esperando una conexion que nunca llega; eventualmente timeout.

**Causa real:** queries lentas + pool chico + sin timeout de adquisicion + conexiones leaked en exception paths (la conexion no vuelve al pool porque la excepcion salto fuera del `try/finally`).

**Solucion a demostrar:** pool sizing basado en Little's law (`pool_size = avg_throughput × avg_query_time + buffer`), timeout explicito de adquisicion (`connection_timeout=2s`), `try-with-resources` Java / `using` .NET / context manager Python / `finally` PHP para garantizar release, metrica `pool_wait_ms` observable por request.

**Stacks objetivo iniciales:** Java (`HikariCP`-style con `DataSource` y `getConnection(timeout)`) + .NET (`Microsoft.Data.Sqlite` con pool configurado) + Python (`sqlite3.connect` con `check_same_thread=False` y pool propio).

**Que medir:** `pool_active`, `pool_waiting`, `pool_wait_ms_p99`, leaks detectados con counter de `acquire` vs `release`.

</details>

---

### Caso 15 — Message queue backpressure

**Sintoma:** productores mas rapidos que consumidores. La cola interna crece sin limite, la memoria del proceso explota, eventualmente el OOM killer lo mata. O peor: la cola es bounded silenciosamente y los mensajes se pierden sin alerta.

**Causa real:** ausencia de **backpressure signal** del consumer al producer; buffer ilimitado por defecto; sin dead letter queue para mensajes que no procesan.

**Solucion a demostrar:** bounded queue + rejection policy (`block` vs `drop_oldest` vs `dead_letter`), slow-down al producer cuando `queue_depth > 80%` (returning 429 al cliente o pausando consumo upstream), DLQ con counter, metricas `queue_depth` / `oldest_msg_age_ms` / `messages_dropped_total`.

**Stacks objetivo iniciales:** Node (`stream.Writable` con `highWaterMark`) + Java (`ArrayBlockingQueue` + `RejectedExecutionHandler`) + Python (`queue.Queue(maxsize=N)`).

**Que medir:** `queue_depth` en cada momento, `oldest_msg_age_ms` (edad del mensaje mas viejo en cola), `messages_dropped_total`, throughput sostenible vs spike.

---

### Caso 16 — Idempotencia y efectos duplicados

**Sintoma:** un cliente que reintenta una request HTTP (porque el primer intento dio timeout, network blip, o boton presionado dos veces) termina con el cobro duplicado, el email enviado dos veces, el mensaje publicado dos veces.

**Causa real:** operaciones no idempotentes + retries automaticos del cliente o del proxy. El servidor no distingue "es la primera vez que veo esto" vs "ya procese esto, no lo vuelvas a hacer".

**Solucion a demostrar:** `Idempotency-Key` header persistido en una tabla con `(key, response_body, expires_at)`, dedupe window de 24h, **outbox pattern** para "exactly-once side effect" cuando el efecto cruza un boundary (DB + cola): commit en una sola transaccion local y un worker mueve del outbox al destino real.

**Stacks objetivo iniciales:** PHP (PostgreSQL con `INSERT ... ON CONFLICT DO NOTHING RETURNING id`) + Java (`@Idempotent` middleware + `ConcurrentHashMap` con TTL) + Node (Express middleware + `Map<key, response>` + Redis-style TTL).

**Que medir:** numero de operaciones duplicadas evitadas vs procesadas como nuevas, hit rate del cache de idempotency, latencia agregada por el lookup.

---

### Caso 17 — Migracion de esquema sin downtime (online DDL)

**Sintoma:** una migracion sobre una tabla caliente (`ALTER TABLE users ADD COLUMN ...`) bloquea inserts y reads durante 20 minutos. La app retorna 503, el negocio pierde plata por hora.

**Causa real:** `ALTER TABLE` sobre tabla con millones de filas en motor que no soporta DDL online, sin estrategia de cambio gradual, sin feature flag.

**Solucion a demostrar:** **expand-contract pattern**:
1. **Expand:** agregar la nueva columna nullable, deploy.
2. **Backfill:** worker idempotente que rellena la nueva columna por batches de N filas con `sleep(ms)` entre batches.
3. **Switch:** feature flag que cambia reads y writes de la columna vieja a la nueva.
4. **Contract:** remover la columna vieja en una migracion posterior.

**Stacks objetivo iniciales:** PHP + PostgreSQL (donde el patron tiene mas peso real; los stacks de SQLite embebido lo modelan mas como ejercicio).

**Que medir:** lock contention durante la migracion legacy (`SELECT pg_locks`), tiempo total con backfill vs ALTER bloqueante, requests fallidos durante cada estrategia.

---

### Caso 18 — Cold start y autoscale lag

**Sintoma:** un autoscale event tarda 90 segundos en agregar capacidad real. Durante esos 90s, las instancias existentes saturan y p99 explota. Los healthchecks reportan green porque responden a `/health`, pero los handlers reales estan colgados.

**Causa real:** imagen base gigante (cargar 800 MB lleva tiempo), app sin pre-warm (JIT calienta en las primeras N requests, connection pool se llena lentamente), healthcheck demasiado permisivo (responde `/health` antes de estar listo para trafico real).

**Solucion a demostrar:** warm pool (mantener N instancias paradas y reservadas), endpoint `/warmup` que precarga caches y connection pools antes de aceptar trafico real, healthcheck con readiness gradual (`/health` para liveness, `/ready` para readiness, separados), metrica `cold_start_count` por instancia.

**Stacks objetivo iniciales:** Java (JIT warm-up es el mas dramatico) + .NET (similar) + Node (cold start mas chico pero observable).

**Que medir:** tiempo de primer response despues de boot, latencia de primeras 100 requests vs requests 1000+, `cold_start_count`.

---

### Caso 19 — Search index drift (CDC roto)

**Sintoma:** los usuarios reportan "la busqueda no encuentra productos que claramente existen". El equipo verifica la DB — el producto esta ahi. Verifica el indice (Elasticsearch / OpenSearch) — no esta. El CDC (change data capture) que sincroniza DB → indice se "salto" un evento hace tres dias.

**Causa real:** el pipeline DB → CDC → indice tiene una propiedad de **eventual consistency** que nadie monitorea. Un evento perdido, una reconexion del consumer Kafka, un mensaje en DLQ olvidado — y el indice queda drift contra la verdad de la DB.

**Solucion a demostrar:** **reconciliacion periodica** (un job que compara `SELECT count(*), max(updated_at) FROM products` vs el equivalente en el indice cada 5 minutos), metrica `index_drift_count`, replay desde el ultimo checkpoint, alert si drift > threshold.

**Stacks objetivo iniciales:** Python (worker async que compara los dos) + PHP (cron-style).

**Que medir:** `drift_count`, `drift_age_ms` (cuanto tiempo lleva la diferencia sin detectarse), tiempo de reconciliacion.

---

### Caso 20 — Dead letter queue olvidada

**Sintoma:** "el sistema funciona perfecto". El equipo esta tranquilo. Mientras tanto, la dead letter queue tiene 80.000 mensajes acumulados desde hace 6 meses sin que nadie los mire. Cuando alguien los abre, hay un patron de fallos repetidos que indicaba un bug en produccion que nadie noto.

**Causa real:** errores caen a DLQ silenciosamente, sin alerta sobre profundidad, sin proceso de revision humana, sin clasificacion por tipo de error.

**Solucion a demostrar:** alerta por threshold de profundidad (`dlq_depth > 100`), dashboard que agrupa por tipo de error (`message.error_class`), runbook de drenaje (reproceso vs descartado vs investigacion manual), sampling automatico de los primeros N mensajes a logs estructurados.

**Stacks objetivo iniciales:** Node (con `Map<error_class, count>` en memoria) + Java (`ConcurrentHashMap<String, LongAdder>` + endpoint `/dlq/stats`).

**Que medir:** `dlq_depth`, `dlq_oldest_msg_age_ms`, distribucion por `error_class`, ratio de drenaje exitoso vs reenvio fallido.

## Eje 2 — Mejoras de plataforma

Cambios transversales que aplican a todos los casos existentes o futuros.

### Fidelidad universal de caso 01

**Estado:** completada (2026-08-03).

Node/Java/.NET pasaron a SQLite real en caso 01, siguiendo el patron ya aplicado a caso 02:
- Node: `node:sqlite` built-in (`DatabaseSync`, sin npm install).
- Java: `sqlite-jdbc` (single jar, sin Maven), archivo con `journal_mode=WAL`.
- .NET: `Microsoft.Data.Sqlite` (paquete oficial), archivo con `journal_mode=WAL`.

El `setTimeout`/`sleepMicros`/`Task.Delay` desaparecio del substrato. `db_hits` / `db_queries_in_request` cuentan ejecuciones reales contra el motor en los 7 stacks. En Java, .NET, Go y Rust el filtro no sargable quedo verificable con `EXPLAIN QUERY PLAN` (`SCAN orders` con `LOWER(region)` vs `SEARCH orders USING INDEX idx_orders_region` con el rango reescrito).

Dos decisiones que salieron del camino y vale la pena registrar:

- **WAL no es un detalle de implementacion, es la leccion.** El worker escribe `customer_summary` mientras los handlers leen. Sin `journal_mode=WAL` el escritor bloquea a los lectores — exactamente el fallo que el caso enseña a evitar. WAL es el equivalente embebido del MVCC que da PostgreSQL en el stack PHP.
- **El contrato JSON no se toco.** Java y .NET conservan su shape (`variant`/`rows`/`db_hits`, `/reset-lab`), distinto del de PHP/Python/Node (`mode`/`data`/`db_queries_in_request`, `/reset-metrics`). Converger esos contratos es el item "Suite de tests cross-stack" de mas abajo, no este.

**Deuda que queda:** en Node y Python persiste un round-trip artificial (`ROUNDTRIP_*_MS`, `artificial_roundtrip_ms`) que modela el hop de red que SQLite embebido no tiene. Esta documentado en el codigo y en los README de stack; no es substrato simulado, es transporte simulado.

---

### Observabilidad de los 7 stacks

**Estado:** parcial — hoy solo PHP exporta `/metrics-prometheus` consumido por Prometheus + Grafana.

**Plan:**
- Instrumentar Python/Node/Java/.NET con `/metrics` Prometheus-compatible (formato OpenMetrics, sin libreria pesada — texto plano via `printf` es suficiente).
- Agregar dashboards Grafana por stack (latencia p50/p95/p99, `db_hits`, `event_loop_lag_ms` Node, `ThreadPool.GetAvailableWorkerThreads` .NET, `ThreadPoolExecutor.getActiveCount()` Java).
- Centralizar via un solo Prometheus que scrappea los 7 hubs.

**Estimado:** ~200 lineas por stack en el dispatcher para exponer un agregado de los 14 casos del stack. Dashboards Grafana JSON commiteados en `cases/01-api-latency-under-load/shared/observability/`.

---

### Suite de tests cross-stack

**Estado:** hoy se valida por `curl` manual y por `portal-probe` / `hub-probe` en CI (smoke `/health` por caso).

**Plan:** tests automaticos (`pytest` o `node:test`) que peguen al hub de cada lenguaje y validen que la **shape del JSON de `/0X/...`** coincide entre los 7 stacks. Por ejemplo, `GET /02/orders-optimized?limit=20` debe devolver el mismo set de keys top-level (`orders`, `db_hits`, `db_time_ms`, `count`) con los mismos tipos en los 7 hubs.

**Estimado:** ~400 lineas de tests + matriz CI que corre los 7 hubs en paralelo y ejecuta la suite.

---

### CI completa

**Estado:** parcial — `compose-config` sobre 92 archivos, `portal-probe` PHP, `hub-probe` Python/Node/Java/.NET/Go/Rust sobre `/health`. Falta validacion funcional.

**Plan:** workflow GitHub Actions que `docker compose up --build` los 7 stacks en paralelo, corra la suite cross-stack del punto anterior, ejecute un loadtest minimo (`hey -n 1000 -c 50`) contra `/02/orders-legacy` y `/02/orders-optimized` en los 7 hubs, y publique evidencia como artifact (latencias, `db_hits`, p95, p99).

**Estimado:** workflow YAML + script de loadtest portable + parser de output de `hey`.

---

### Portal del lab — proof cards live

**Estado:** hoy el portal sirve metadata estatica del catalogo + probes server-side de `/health`.

**Plan:** agregar **proof cards** que ejecuten endpoints en vivo (`/0X/orders-legacy` vs `/0X/orders-optimized`) y muestren el contraste lado a lado en la UI: `db_hits`, `latency_ms`, `before` vs `after`. El portal pasa de "indice de docs" a "demo interactiva".

**Estimado:** componentes JS en `portal/app/`, endpoints proxy en `portal/app/probe.php`, layout responsive.

## Eje 3 — Honestidad tecnica

Compromisos editoriales transversales para que el lab no venda fidelidad que no entrega.

### Seccion "Fidelidad" explicita en cada `comparison.md`

**Estado:** aplicada en caso 01 y caso 02. Pendiente en casos 03-12.

**Regla:** cualquier `comparison.md` donde el substrato no sea uniforme entre los 7 stacks debe incluir una seccion **"Fidelidad del substrato"** al inicio (despues del intro), con una tabla que distinga **qué es real / qué es simulado** por stack. El lector no debe descubrir asimetrias leyendo codigo.

---

### Tabla maestra "real vs simulado" por caso

**Estado:** pendiente.

**Plan:** seccion nueva en el `README.md` raiz que liste los 14 casos con una columna por stack indicando si el substrato es real (DB / kernel / network) o simulado (sleep / memoria / setTimeout). Vista de un vistazo, sin tener que abrir cada `comparison.md`.

---

### Postmortems del propio lab

**Estado:** pendiente.

**Plan:** `docs/lab-postmortems.md` con entradas cuando una decision tecnica del lab cambia. Por ejemplo: "2026-05-20 — caso 02 paso de Map en memoria a SQLite embebido en Node/Java/.NET; el patron N+1 sin DB real era didacticamente debil y el lector senior lo detectaba". Modela el propio principio que el lab enseña (postmortems honestos > narrativas perfectas).

---

## Fases historicas (cerradas)

Las fases anteriores quedan registradas para referencia historica:

- **Fase 1 — Base estructural** (completada): nombre y posicionamiento, portal liviano, estructura problem-driven con 14 casos, documentacion base.
- **Fase 1.5 — Profesionalizacion documental** (completada): familia documental completa en raiz, alineacion editorial con el ecosistema publico de Vladimir Acuna.
- **Fase 2 — Profundizacion tecnica** (completada): los 14 casos × 7 stacks (PHP/Python/Node/Java/.NET/Go/Rust) operativos con primitivas idiomaticas distintivas por caso y por lenguaje.
- **Fase 3 — Valor de portafolio** (completada): `docs/executive-summary.md` cubierto, diagramas en `ARCHITECTURE.md` cubierto, postmortems cubiertos (`docs/postmortem.md` en los 14 casos).
- **Fase 4 — Laboratorio expandido** (en progreso, abierta por este ROADMAP): los 8 casos nuevos (13-20) y las mejoras de plataforma listadas arriba son la continuacion natural.
