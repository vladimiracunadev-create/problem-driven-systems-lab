# Estrategia Docker

> Como y cuando usar Docker en este laboratorio.

## 🎯 Resumen ejecutivo

Docker no es opcional en los casos implementados. Es la ruta oficial para:

- documentar entornos de forma reproducible;
- aislar dependencias por escenario;
- ejecutar demos serias sin configuracion manual extensa;
- comparar stacks sin contaminar el resto del laboratorio.

## 📏 Regla de diseno

El laboratorio no se levanta como un unico sistema enorme. Se trabaja por capas:

| Patron | Uso |
| --- | --- |
| `compose.root.yml` | Portal + 12 casos PHP en una sola entrada |
| `compose.python.yml` | Hub Python con los 12 casos en un solo contenedor |
| `compose.nodejs.yml` | Hub Node.js con los 12 casos en un solo contenedor |
| `compose.portal.yml` | Portal liviano solamente |
| `cases/<caso>/<stack>/compose.yml` | Un escenario concreto y aislado |
| `cases/<caso>/compose.compare.yml` | Comparacion entre stacks del mismo caso |

## 💡 Por que este enfoque es mejor aqui

| Beneficio | Impacto |
| --- | --- |
| Menor consumo | Puedes elegir entre el laboratorio PHP completo o un caso puntual |
| Menos ruido | No mezclas logs ni servicios de otros casos |
| Mejor diagnostico | Cada problema se observa con menos interferencia |
| Portafolio mas claro | Puedes mostrar un caso concreto sin cargar todo el mundo |

## 🧱 Modelo de containerización (simétrico para los stacks operativos)

Los cuatro hubs siguen el **mismo patrón**: un contenedor por lenguaje que spawnea sus casos como subprocesos internos. Los servicios reales (DB, worker, observabilidad) viven aparte porque NO son procesos del lenguaje — son servicios independientes que el caso estudia.

| Stack | Compose | Contenedores Docker que levanta | Mecanismo interno | Puerto host |
| --- | --- | --- | --- | --- |
| **PHP** | `compose.root.yml` | **~7 contenedores** (portal + `php-lab` + 2 PostgreSQL + worker + exporter + prometheus + grafana) | `php-dispatcher` con 12 subprocesos `php -S` en `127.0.0.1:9001-9012` | `8100` |
| **Python** | `compose.python.yml` | **1 contenedor** (`pdsl-python-lab`) | `python-dispatcher` con 12 subprocesos `subprocess.Popen` en `:9001-9012` | `8200` |
| **Node.js** | `compose.nodejs.yml` | **1 contenedor** (`pdsl-node-lab`) | `node-dispatcher` con 12 subprocesos `child_process.spawn` en `:9101 + :9002-9012` | `8300` |
| **Java 21** | `compose.java.yml` | **1 contenedor** (`pdsl-java-lab`) | `java-dispatcher` con 6 subprocesos `ProcessBuilder` (`java Main`) en `:9401-:9406` (casos 01-06; 07-12 pendientes) | `8400` |

> **Asimetria residual del PHP**: PHP levanta ~7 contenedores en lugar de 1 porque los casos `01` y `02` necesitan PostgreSQL **real** corriendo en paralelo, mas el worker de caso 01, mas Prometheus + Grafana. Eso son **servicios independientes** (no subprocesos PHP) que el caso 01 estudia. Las 12 apps PHP se colapsan en `php-lab` (1 contenedor); los servicios reales se mantienen separados porque tienen que serlo.

### Antes y despues del refactor (PHP)

Antes (commit historico): **~20 contenedores Docker**. Despues del dispatcher PHP: **~7 contenedores**. RAM cae de ~2.5 GB a ~1 GB. Costo AWS Fargate cae de USD ~42/mes a USD ~7/mes para los casos.

| Antes (legacy) | Despues (actual) |
| --- | --- |
| `nginx-hub` (path routing) | `php-lab` (dispatcher hace el routing **y** ejecuta los 12 casos) |
| `case01-app` ... `case12-app` (12 contenedores PHP separados) | 12 subprocesos internos de `php-lab` |
| `case01-worker` (separate) | `case01-worker` (sigue separado — es un CLI worker, no HTTP) |
| `case01-db`, `case02-db` (PostgreSQL) | sin cambio |
| `case01-prometheus`, `case01-grafana`, `case01-postgres-exporter` | sin cambio |

### Por que los 4 stacks ahora son simetricos

- **El dispatcher resuelve el problema de "muchos contenedores por lenguaje"**. Para PHP, Python, Node y Java, la unidad logica es "el lenguaje sirve N casos". Eso es 1 contenedor con N subprocesos, NO N contenedores.
- **Los servicios reales del caso 01** (PostgreSQL, worker, observabilidad) son independientes del lenguaje. Si manana se agrega caso 01 en Python con su propio Postgres, ese Postgres seria otro contenedor — no un subproceso del Python lab.
- **Los per-case `compose.yml`** siguen funcionando para "modo aislado" (estudiar UN caso sin ruido) — es la unidad atomica de reproducibilidad.

### Trade-offs heredados (que el refactor preserva)

| Aspecto | Hub (1 contenedor + N subprocesos) | Per-case (1 contenedor por caso, modo aislado) |
| --- | --- | --- |
| RAM | ~512 MB - 1 GB total | ~256 MB por caso aislado |
| Boot | 3-6 segundos | 5-10 segundos |
| Aislamiento entre casos | Cooperativo (mismo runtime padre) | Fuerte (OS-level cgroups) |
| Memory leak en 1 caso | Puede afectar a los otros 11 | Aislado |
| Cuando usar | Demo del lab completo, vista del catalogo | Reproducir UN problema sin contaminacion (caso 05 memoria, caso 11 event loop) |

### Cuando elegir cada modelo en tu propio proyecto

| Si tu caso tiene... | Modelo correcto |
| --- | --- |
| DB propia, worker dedicado, observabilidad pesada | **Servicios separados + 1 hub para los procesos del lenguaje** (modelo actual) |
| Solo lógica de aplicación, sin estado externo | **1 contenedor con N procesos internos** (cualquiera de los 4 hubs) |
| Necesidad estricta de aislamiento de memoria entre casos | **Per-case compose** o N contenedores con cgroups |
| Necesidad de minimizar RAM en idle | **1 hub con dispatcher** — un solo runtime cargado |
| Posibilidad de leak en un caso afecte a otros | **N contenedores con `mem_limit`** — failure domain por caso |
| Casos cooperativos que comparten dataset | **1 contenedor** — pueden compartir memoria/cache |

Esta simetria se preserva al migrar a AWS — ver [`AWS_MIGRATION.md`](../AWS_MIGRATION.md): los hubs se mapean a ECS Fargate services (uno por lenguaje), y los servicios reales del caso 01 se mapean a RDS PostgreSQL + ECS worker + AMP/AMG.

## 🚫 Lo que se evita conscientemente

- un `docker compose up` gigante para todos los lenguajes y futuras variantes al mismo tiempo;
- dependencias cruzadas entre escenarios que deberian ser aislados;
- infraestructura innecesaria solo para "verse enterprise".

## 🛠️ Regla practica actual

- `compose.root.yml` debe dejar visible el laboratorio PHP completo desde `localhost:8080`.
- `compose.python.yml` y `compose.nodejs.yml` deben dejar visibles los 12 casos del stack respectivo desde `localhost:8200` y `localhost:8300`.
- `compose.java.yml` debe dejar visibles los casos 01-06 desde `localhost:8400`.
- Los casos `01` al `12` deben poder levantarse con Docker de forma limpia tambien por separado (modo aislado).
- Cada `compose.yml` debe incluir solo la infraestructura que el problema realmente necesita.
- La presencia de `compose.compare.yml` no implica que todos los stacks tengan la misma profundidad funcional.

Ademas, **cada lenguaje tiene su modelo de containerizacion** — PHP como microservicios reales (12 contenedores), Python y Node como hub-con-subprocesos (1 contenedor cada uno). Ver seccion **"Tres modelos de containerización"** arriba para el porque y los trade-offs explicitos.

La familia PHP comparte un runtime comun en `docker/php/Dockerfile`. La familia Python usa `python:3.12-alpine` directo. La familia Node usa `node:20-alpine` directo. Cada lenguaje futuro seguira el patron `compose.{lang}.yml` con su bloque de puertos propio en la raiz.

## 🔎 Ejemplos concretos

- Caso `01`: necesita `app + db + worker + observabilidad` porque el problema es contencion real bajo carga.
- Caso `02`: necesita `app + db` porque el N+1 debe verse sobre relaciones reales.
- Caso `03`: usa solo `app` porque el foco esta en logs, trazas y diagnostico, no en una DB externa.
- Caso `04`: usa solo `app` porque el foco esta en timeouts, retries, circuit breaker y fallback.
- Caso `05`: usa solo `app` porque el foco esta en presion de memoria y recursos acumulados.
- Caso `06`: usa solo `app` porque el foco esta en pipeline, ambientes y rollback.
- Caso `07`: usa solo `app` porque el foco esta en modernizacion incremental y cambio seguro.
- Caso `08`: usa solo `app` porque el foco esta en extraccion compatible y cutover gradual.
- Caso `09`: usa solo `app` porque el foco esta en contrato externo, cache y cuota.
- Caso `10`: usa solo `app` porque el foco esta en complejidad, costo y proporcionalidad.
- Caso `11`: usa solo `app` porque el foco esta en competencia entre reporting y operacion.
- Caso `12`: usa solo `app` porque el foco esta en continuidad operacional y distribucion de conocimiento.

## 📝 Nota sobre el Makefile

El `Makefile` es util como atajo, pero no reemplaza la estrategia oficial. Si trabajas en Windows puro, prefiere `docker compose` directo.
