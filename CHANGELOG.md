# 📝 CHANGELOG

Todos los cambios notables de este laboratorio se registran aqui con foco en madurez tecnica y documental.

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
