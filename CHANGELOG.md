# 📝 CHANGELOG

Todos los cambios notables de este laboratorio se registran aqui con foco en madurez tecnica y documental.

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
