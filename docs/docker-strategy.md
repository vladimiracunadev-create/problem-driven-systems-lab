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

## 🧱 Tres modelos de containerización (uno por stack) — y por qué son distintos

Una observacion legitima cuando uno mira los hubs: **`localhost:8100` (PHP), `localhost:8200` (Python) y `localhost:8300` (Node) parecen lo mismo desde afuera, pero adentro son arquitecturas distintas.** Esta tabla lo hace explicito:

| Stack | Compose | Contenedores Docker que levanta | Aislamiento por caso | RAM total | Justificacion |
| --- | --- | --- | --- | --- | --- |
| **PHP** | `compose.root.yml` | **~20 contenedores distintos** (portal, hub nginx, **12 apps PHP separadas**, 2 PostgreSQL, exporter, prometheus, grafana, worker) | Fuerte (OS-level): cada caso es su propio proceso en su propio contenedor con `mem_limit` y `cpus` propios | ~2.5 GB | Los casos PHP nacieron primero, **uno por uno**, cada uno modelando un problema operativo distinto. Mantenerlos como contenedores separados refleja como vivirian en produccion (microservicios), permite `mem_limit: 256m` por caso, y un memory leak en `case-11` no tira a `case-07`. El nginx hub solo hace path routing — no es un dispatcher. |
| **Python** | `compose.python.yml` | **1 solo contenedor** (`pdsl-python-lab`) | Cooperativo: 12 subprocesos `subprocess.Popen` en `:9001-:9012` (internos al contenedor) | ~512 MB | El dispatcher Python (`python-dispatcher/app/main.py`) spawnea los 12 servers como subprocesos hijos. Trade-off elegido: **costo y simplicidad** vs aislamiento. Para un lab que demuestra paridad multi-stack sin DB ni observabilidad pesada, 1 contenedor de 512 MB justifica perfectamente. |
| **Node.js** | `compose.nodejs.yml` | **1 solo contenedor** (`pdsl-node-lab`) | Cooperativo: 12 subprocesos `child_process.spawn` en `:9101 + :9002-:9012` | ~512 MB | Espejo del modelo Python (`node-dispatcher/app/main.js`). Misma justificacion: el dispatcher es ligero, los 12 procesos son ligeros, y un solo contenedor cubre los 12 endpoints sin la sobrecarga de 12 imagenes. |

### Trade-offs explicitos

| Aspecto | PHP (12 contenedores) | Python / Node (1 contenedor + 12 subprocesos) |
| --- | --- | --- |
| **RAM total** | ~2.5 GB | ~512 MB |
| **Tiempo de boot** | 15–20 segundos | 3–5 segundos |
| **Aislamiento entre casos** | Fuerte (OS-level: cgroups, namespaces) | Cooperativo (mismo proceso padre, mismo heap del runtime) |
| **Memory leak en 1 caso** | Solo afecta a ese caso (su contenedor muere o degrada solo) | Puede afectar a los otros 11 si revienta el proceso padre |
| **Costo en AWS Fargate** | 12 services × ~USD 3.5 = **USD 42/mes** | 1 service × USD 7 = **USD 7/mes** |
| **Refleja produccion realista** | Modelo de microservicios | Modelo de monorepo con plugins / monolito modular |
| **Donde encaja mejor** | Casos con DB, worker, observabilidad pesada (cases 01, 02) | Casos sin estado externo, donde la gracia es la primitiva del lenguaje |

### Por que NO se uniformaron

Tres razones honestas:

1. **PHP no se puede colapsar a 1 contenedor sin perder el caso 01**. El caso 01 PHP necesita Postgres + worker + exporter + Prometheus + Grafana corriendo en paralelo. Eso son 5+ servicios reales que no son un "subproceso" de PHP. Una vez que tenes esa arquitectura, los otros 11 casos vienen "gratis" como contenedores adicionales.

2. **Python y Node sin estado externo permiten 1 contenedor sin perder nada**. Los 12 casos en estos stacks usan `tmpfile` para state, sin DB. Spawneando como subprocesos se mantiene el aislamiento de **memoria del proceso JS/Python** (cada subproceso tiene su V8/CPython propio) sin pagar el costo de imagenes Docker separadas.

3. **El lab vale mas con la asimetria que sin ella**. Mantener los tres modelos lado a lado **muestra los tres patrones reales** que se ven en produccion: microservicios verdaderos (PHP), monorepo con plugins (Python), single-tenant multi-process (Node). Un evaluador tecnico ve los tres en el mismo repo y entiende que la decision fue consciente.

### Cuando elegir un modelo u otro en tu propio proyecto

| Si tu caso tiene… | Modelo correcto |
| --- | --- |
| DB propia, worker, observabilidad pesada | **N contenedores Docker separados** (modelo PHP) |
| Solo lógica de aplicación, sin estado externo | **1 contenedor con N procesos internos** (modelo Python/Node) |
| Necesidad estricta de aislamiento de memoria entre casos | **N contenedores** — cgroups del kernel garantizan limites |
| Necesidad de minimizar RAM en idle | **1 contenedor** — un solo runtime cargado |
| Posibilidad de leak en un caso afecte a otros | **N contenedores** — failure domain por caso |
| Casos cooperativos que comparten dataset | **1 contenedor** — pueden compartir memoria/cache |

Esta asimetria esta tambien reconocida en la nota de costos del [`AWS_MIGRATION.md`](../AWS_MIGRATION.md) — al migrar a AWS, los modelos se preservan: 12 services Fargate para PHP vs 1 service para los hubs Python/Node, exactamente como en local.

## 🚫 Lo que se evita conscientemente

- un `docker compose up` gigante para todos los lenguajes y futuras variantes al mismo tiempo;
- dependencias cruzadas entre escenarios que deberian ser aislados;
- infraestructura innecesaria solo para "verse enterprise".

## 🛠️ Regla practica actual

- `compose.root.yml` debe dejar visible el laboratorio PHP completo desde `localhost:8080`.
- `compose.python.yml` y `compose.nodejs.yml` deben dejar visibles los 12 casos del stack respectivo desde `localhost:8200` y `localhost:8300`.
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
