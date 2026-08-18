# 🗺️ ROADMAP — Problem-Driven Systems Lab

> Hacia donde va el laboratorio: nuevos casos de la vida real, mejoras de plataforma, y compromisos de honestidad tecnica.

## Estado actual (2026-08-17)

- **20 casos × 7 stacks operativos = 140 endpoints** detras de 7 hubs simetricos (`compose.root.yml` PHP `:8100`, `compose.python.yml` Python `:8200`, `compose.nodejs.yml` Node `:8300`, `compose.java.yml` Java `:8400`, `compose.dotnet.yml` .NET `:8500`, `compose.go.yml` Go `:8600`, `compose.rust.yml` Rust `:8700`).
- **Casos 01 y 02 con fidelidad universal:** los 7 stacks ejecutan SQL real sobre un motor — PostgreSQL en PHP, SQLite stdlib en Python, `node:sqlite` built-in en Node, `sqlite-jdbc` en Java, `Microsoft.Data.Sqlite` en .NET, `modernc.org/sqlite` (Go puro, sin cgo) en Go, `rusqlite` feature `bundled` en Rust. `db_hits` / `db_queries_in_request` cuentan ejecuciones reales contra motor en los siete runtimes.
- **Caso 01 con el filtro no sargable verificado por el planner:** `EXPLAIN QUERY PLAN` devuelve `SCAN orders` para `WHERE LOWER(region) LIKE 'n%'` y `SEARCH orders USING INDEX idx_orders_region` para el mismo predicado reescrito como rango. Java y .NET usan `journal_mode=WAL` para que el worker que refresca el resumen no bloquee a los lectores — el equivalente embebido del MVCC de PostgreSQL.
- **Asimetria que queda, por diseño:** solo PHP cruza un socket TCP contra un motor externo con pool FPM finito. Los otros seis embeben el motor. Node y Python conservan un round-trip artificial explicito que modela el hop de red ausente. Documentado en cada `comparison.md` y `README.md` de stack, no escondido.
- Documentacion editorial completa (`README.md`, `RECRUITER.md`, `ARCHITECTURE.md`, `RUNBOOK.md`, `SECURITY.md`, `AWS_MIGRATION.md`, `CONTRIBUTING.md`, `CHANGELOG.md`).
- Catalogo unificado en `shared/catalog/cases.json` como fuente de verdad del portal, `docs/case-catalog.md` y la narrativa operativa.
- Portal local con `index.html` + `catalog.php` + `probe.php` server-side para health en vivo.
- CI con validacion estructural + `compose-config` sobre 148 archivos + `portal-probe` PHP + `hub-probe` Python/Node/Java/.NET/Go/Rust sobre los 20 casos por stack en un solo boot.

## Eje 1 — Nuevos casos de la vida real (13-20) — ✅ CERRADO (2026-08-17)

> **Los 8 casos estan entregados y operativos en los 7 stacks.** Este eje se cierra: sus casos ya no son plan, son laboratorio. Las especificaciones originales quedaron archivadas en el historial de git — lo que sigue es el registro de que se construyo y que se aprendio.

El plan original asignaba 2-3 stacks objetivo por caso. **Se construyeron los 7 en los 8 casos**, por una razon estructural: `scripts/validate-structure.sh` exige las siete carpetas por caso, y romper esa simetria habria contradicho la identidad del lab. La decision resulto ademas mas util de lo previsto — varios de los hallazgos mas interesantes salieron justamente de los stacks que el plan no habia considerado.

| Caso | Titulo | Categoria | Lo que aporto que no estaba en el plan |
|---|---|---|---|
| [13](cases/13-cache-stampede-and-thundering-herd/README.md) | Cache stampede y thundering herd | Rendimiento | El single-flight ingenuo **seguia recomputando**: falta el doble chequeo de la cache *dentro* del vuelo. El bug quedo documentado como parte de la leccion. |
| [14](cases/14-connection-pool-exhaustion/README.md) | Agotamiento del pool de conexiones | Resiliencia | Rust necesita `std::mem::forget` para **poder** filtrar una conexion: su `Drop` hace del leak un acto deliberado. |
| [15](cases/15-message-queue-backpressure/README.md) | Backpressure en colas de mensajes | Resiliencia | Las tres politicas de rechazo (`block` / `drop_oldest` / `dead_letter`) resultaron ser tres decisiones de negocio distintas, no tres implementaciones. Y aqui **nace la DLQ** que el caso 20 encuentra olvidada. |
| [16](cases/16-idempotency-and-duplicate-effects/README.md) | Idempotencia y efectos duplicados | Resiliencia | **Seis de las siete implementaciones dejan de ser correctas con dos replicas.** Solo la de PHP, respaldada por almacenamiento, sobrevive. Quedo escrito. |
| [17](cases/17-zero-downtime-schema-migration/README.md) | Migracion de esquema sin downtime | Entrega | El caso no necesitaba un motor: necesitaba un **read-write lock**. PHP subio al segundo puesto con `flock` (el unico del SO, entre procesos) y **Rust cayo al sexto** — primer caso donde su respuesta es peor que la de los otros seis. |
| [18](cases/18-cold-start-and-autoscale-lag/README.md) | Arranque en frio y retraso del autoescalado | Resiliencia | **El unico caso que mide una propiedad del runtime en vez de simularla**: el mismo lazo entero en los 7 stacks da Java 51,9x contra Rust 1,00x de curva de calentamiento. |
| [19](cases/19-search-index-drift-and-broken-cdc/README.md) | Deriva del indice de busqueda y CDC roto | Observabilidad | La deriva **no es una cosa, son tres** (`missing` / `stale` / `orphan`) y se arreglan distinto. El caso ordena por **que hace el lenguaje cuando el programador no mira**. |
| [20](cases/20-forgotten-dead-letter-queue/README.md) | La dead letter queue olvidada | Resiliencia | Cierra el arco del 15. Drenar la DLQ del consumidor silencioso recupera el **71,39%**: trabajo que se habia tirado por no mirar que error era. |

### Tres cosas que este eje dejo claras

**1. El ranking se cruza, y eso es el punto.** Java gana el caso 17 y queda **septimo** en el 18. Rust queda sexto en el 17 y **segundo** en el 18. PHP, ultimo del agregado, sube al **segundo puesto** en el 17 porque su `flock` es el unico read-write lock del laboratorio provisto por el sistema operativo. Un caso que siempre ordena igual a los siete stacks no esta midiendo nada.

**2. La ausencia de una primitiva enseña tanto como su presencia.** Python no tiene read-write lock (caso 17) y Go no tiene tipo conjunto (caso 19). En los dos, la ausencia se paga en el mismo lugar: codigo propio donde deberia haber biblioteca — y obliga a entender que hace la primitiva por dentro.

**3. Los peores modos de falla no rompen nada.** El caso 17 tiene el healthcheck en verde durante veinte minutos de 503. El 18, un pipeline sano que rechaza el 40% del trafico. El 19, una busqueda con 98,95% de recall. El 20, un error rate de cero mientras se pierde el 14% de los mensajes. **Los cuatro se ven bien desde el dashboard**, y esa es la clase de problema que este laboratorio existe para hacer visible.


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

**Estimado:** ~200 lineas por stack en el dispatcher para exponer un agregado de los 20 casos del stack. Dashboards Grafana JSON commiteados en `cases/01-api-latency-under-load/shared/observability/`.

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

**Plan:** seccion nueva en el `README.md` raiz que liste los 20 casos con una columna por stack indicando si el substrato es real (DB / kernel / network) o simulado (sleep / memoria / setTimeout). Vista de un vistazo, sin tener que abrir cada `comparison.md`.

---

### Postmortems del propio lab

**Estado:** pendiente.

**Plan:** `docs/lab-postmortems.md` con entradas cuando una decision tecnica del lab cambia. Por ejemplo: "2026-05-20 — caso 02 paso de Map en memoria a SQLite embebido en Node/Java/.NET; el patron N+1 sin DB real era didacticamente debil y el lector senior lo detectaba". Modela el propio principio que el lab enseña (postmortems honestos > narrativas perfectas).

---

## Fases historicas (cerradas)

Las fases anteriores quedan registradas para referencia historica:

- **Fase 1 — Base estructural** (completada): nombre y posicionamiento, portal liviano, estructura problem-driven con 20 casos, documentacion base.
- **Fase 1.5 — Profesionalizacion documental** (completada): familia documental completa en raiz, alineacion editorial con el ecosistema publico de Vladimir Acuna.
- **Fase 2 — Profundizacion tecnica** (completada): los 20 casos × 7 stacks (PHP/Python/Node/Java/.NET/Go/Rust) operativos con primitivas idiomaticas distintivas por caso y por lenguaje.
- **Fase 3 — Valor de portafolio** (completada): `docs/executive-summary.md` cubierto, diagramas en `ARCHITECTURE.md` cubierto, postmortems cubiertos (`docs/postmortem.md` en los 20 casos).
- **Fase 4 — Laboratorio expandido** (completada): los 8 casos nuevos (13-20) quedaron entregados y operativos en los 7 stacks. El Eje 1 de este ROADMAP se cierra con ellos; siguen abiertos el Eje 2 (plataforma) y el Eje 3 (honestidad tecnica).
