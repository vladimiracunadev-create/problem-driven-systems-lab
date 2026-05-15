# 📋 Resumen ejecutivo — los 12 casos en una pagina

> Vista de portafolio para evaluadores no tecnicos (reclutadores, lideres de producto, finanzas, CTO sin tiempo). Cada caso resume **problema → valor de negocio → evidencia reproducible → link al detalle tecnico**.
>
> Esta pagina **no** reemplaza el [catalogo tecnico generado](case-catalog.md) ni los `README.md` por caso — los complementa con un foco editorial: que problema de negocio resuelve cada caso y que evidencia deja en pocos minutos.
>
> Fuente de verdad: [`shared/catalog/cases.json`](../shared/catalog/cases.json). Los textos de esta pagina derivan de ahi, curados para lectura ejecutiva.

## Mapa rapido

| # | Caso | Categoria | Valor de negocio (1 linea) |
|---|------|-----------|----------------------------|
| 01 | API lenta bajo carga | Rendimiento | Reduce latencia visible y evita sobredimensionar infra a ciegas. |
| 02 | N+1 queries y cuellos de DB | Rendimiento | Reduce round-trips, costo por request y desgaste sobre la base. |
| 03 | Observabilidad deficiente y logs inutiles | Observabilidad | Reduce MTTR y convierte incidentes vagos en fallas diagnosticables. |
| 04 | Cadena de timeouts y tormentas de reintentos | Resiliencia | Reduce fallas en cascada y ayuda a disenar limites sanos de timeout/retry. |
| 05 | Presion de memoria y fugas de recursos | Rendimiento | Razonamiento sobre estabilidad y degradacion progresiva antes del colapso. |
| 06 | Pipeline roto y entrega fragil | Entrega | Reduce riesgo en despliegues y fortalece rollback y promotion. |
| 07 | Modernizacion incremental de monolito | Arquitectura | Permite modernizar un legacy sin que cada cambio sea una reescritura. |
| 08 | Extraccion de modulo critico | Arquitectura | Extrae partes clave sin cortar checkout, partners ni backoffice. |
| 09 | Integracion externa inestable | Resiliencia | Razonamiento de protecciones frente a dependencias que no controlamos. |
| 10 | Arquitectura cara para un problema simple | Arquitectura | Decisiones tecnologicas proporcionales al problema de negocio. |
| 11 | Reportes pesados que bloquean operacion | Operaciones | Aislamiento de cargas: reporting deja de degradar la operacion. |
| 12 | Punto unico de conocimiento | Operaciones | Continuidad operacional y reduccion de dependencia critica en personas. |

Los 12 casos estan **OPERATIVOS** en los 4 stacks: PHP/Python/Node.js/Java 21. Detalle de paridad: [`docs/case-catalog.md`](case-catalog.md).

---

## Caso 01 — API lenta bajo carga

**Categoria:** Rendimiento · **Stacks operativos:** PHP, Python, Node.js, Java 21

**Problema.** La API responde bien con pocos usuarios, pero degrada latencia y estabilidad al aumentar la concurrencia. Filtros no sargables + patron N+1 + worker concurrente compitiendo por la DB.

**Valor.** Reduce latencia visible y evita sobredimensionar infra a ciegas — ataca causa raiz, no sintoma.

**Evidencia.** Compara `/report-legacy` vs `/report-optimized` con latencia, p95 y queries promedio sobre la **misma data**. Worker concurrente refresca tabla resumen sin esconder presion real. Metricas locales + Prometheus/Grafana.

**Honestidad.** No benchmarkea lenguajes. Demuestra diagnostico y remediacion del problema de latencia bajo carga.

→ Detalle: [cases/01-api-latency-under-load/README.md](../cases/01-api-latency-under-load/README.md)

---

## Caso 02 — N+1 queries y cuellos de botella en DB

**Categoria:** Rendimiento · **Stacks operativos:** PHP, Python, Node.js, Java 21

**Problema.** Demasiadas consultas por solicitud o acceso a datos ineficiente — el clasico N+1 dentro de bucles + relaciones cargadas innecesariamente.

**Valor.** Reduce round-trips, costo por request y desgaste sobre la DB. Aterriza criterio de acceso a datos **mas alla del ORM**: el problema no es la herramienta, es el patron de carga.

**Evidencia.** `/orders-legacy` vs `/orders-optimized` sobre la misma base relacional y mismos datos semilla. Mide cantidad de queries y tiempo de DB por request. `diagnostics/summary` explica por que escala mal.

**Honestidad.** No representa un ORM especifico. Reproduce el patron real de round-trips repetidos.

→ Detalle: [cases/02-n-plus-one-and-db-bottlenecks/README.md](../cases/02-n-plus-one-and-db-bottlenecks/README.md)

---

## Caso 03 — Observabilidad deficiente y logs inutiles

**Categoria:** Observabilidad · **Stacks operativos:** PHP, Python, Node.js, Java 21

**Problema.** Hay errores, pero no hay trazabilidad para identificar causa raiz rapido. Logs sin correlacion + sin contexto + sin metricas.

**Valor.** Reduce MTTR. Convierte "el sistema fallo" en "el sistema fallo aqui, por esto, en este request".

**Evidencia.** `checkout-legacy` vs `checkout-observable` — cambia que hay correlacion real, logs estructurados, trazas, metricas y `diagnostics/summary` para reconstruir incidentes con evidencia. Demostrado en los **3 runtimes** sin vender paridad falsa.

**Honestidad.** No reemplaza una plataforma de tracing distribuido. Si deja base reproducible para mostrar por que logs pobres alargan MTTR.

→ Detalle: [cases/03-poor-observability-and-useless-logs/README.md](../cases/03-poor-observability-and-useless-logs/README.md)

---

## Caso 04 — Cadena de timeouts y tormentas de reintentos

**Categoria:** Resiliencia · **Stacks operativos:** PHP, Python, Node.js, Java 21

**Problema.** Una integracion lenta dispara reintentos sin control → bloqueos → cascadas de fallas entre servicios.

**Valor.** Reduce fallas en cascada. Ayuda a disenar limites sanos de timeout, retry y backoff con evidencia, no con intuicion.

**Evidencia.** `/quote-legacy` vs `/quote-resilient` sobre el mismo proveedor simulado. Visibiliza el costo de retries agresivos. Circuit breaker con estados leibles via `dependency/state`, `incidents`, `diagnostics/summary`.

**Honestidad.** No reemplaza una malla de servicios. Reproduce la logica operacional clave: timeouts, retry storm, circuit breaker, fallback.

→ Detalle: [cases/04-timeout-chain-and-retry-storms/README.md](../cases/04-timeout-chain-and-retry-storms/README.md)

---

## Caso 05 — Presion de memoria y fugas de recursos

**Categoria:** Rendimiento · **Stacks operativos:** PHP, Python, Node.js, Java 21

**Problema.** El sistema acumula memoria, descriptores o conexiones de forma progresiva hasta degradar o caerse. Problemas silenciosos que **no fallan de inmediato**.

**Valor.** Razonamiento sobre estabilidad y degradacion progresiva antes del colapso. Discusion sobre limites de recursos y necesidad de reinicios.

**Evidencia.** `/batch-legacy` vs `/batch-optimized` con estado acumulado entre requests. Expone `retained_kb`, `descriptor_pressure`, `pressure_level` en el tiempo. Node mide con `process.memoryUsage()` (heap V8 + RSS + external), Python con `tracemalloc`.

**Honestidad.** No copia al milimetro el modelo de memoria de cada runtime. Si deja visible la senal operacional: crecimiento silencioso → degradacion → necesidad de limpieza.

→ Detalle: [cases/05-memory-pressure-and-resource-leaks/README.md](../cases/05-memory-pressure-and-resource-leaks/README.md)

---

## Caso 06 — Pipeline roto y entrega fragil

**Categoria:** Entrega · **Stacks operativos:** PHP, Python, Node.js, Java 21

**Problema.** El software funciona en dev, pero falla al desplegar, promover o revertir. Drift entre ambientes, secretos perdidos, smoke tests que pasan tarde.

**Valor.** Reduce riesgo en despliegues. Fortalece rollback, promotion y entrega continua sin teatro de checklists.

**Evidencia.** `/deploy-legacy` vs `/deploy-controlled` sobre los mismos escenarios de riesgo. Visibiliza cuando un pipeline falla tarde y deja el ambiente degradado, versus cuando bloquea en preflight o revierte limpio. `environments`, `deployments`, `diagnostics/summary`.

**Honestidad.** No reemplaza un CI/CD real ni IaC completo. Reproduce la logica de delivery que importa: validaciones previas, canary, smoke tests, rollback.

→ Detalle: [cases/06-broken-pipeline-and-fragile-delivery/README.md](../cases/06-broken-pipeline-and-fragile-delivery/README.md)

---

## Caso 07 — Modernizacion incremental de monolito

**Categoria:** Arquitectura · **Stacks operativos:** PHP, Python, Node.js, Java 21

**Problema.** El legacy sigue siendo critico, pero su evolucion es lenta, riesgosa y cara. Cada cambio amenaza con romper algo no relacionado.

**Valor.** Modernizar sin convertir cada cambio en reescritura. Foco en **evolucion segura de sistemas vivos**, no greenfield idealizado.

**Evidencia.** `/change-legacy` vs `/change-strangler` sobre `shared_schema`, `billing_change` y trabajo paralelo. Mide `blast_radius_score`, `risk_score` y progreso de migracion por consumidor. `migration/state`, `flows`, `diagnostics/summary`.

**Honestidad.** No reemplaza un programa real con multiples servicios y equipos. Reproduce blast radius, migracion por consumidor, contratos, avance incremental.

→ Detalle: [cases/07-incremental-monolith-modernization/README.md](../cases/07-incremental-monolith-modernization/README.md)

---

## Caso 08 — Extraccion de modulo critico sin romper operacion

**Categoria:** Arquitectura · **Stacks operativos:** PHP, Python, Node.js, Java 21

**Problema.** Hay que desacoplar una parte clave, pero esa parte participa en flujos sensibles (checkout, partners, backoffice) y no admite quiebres.

**Valor.** Extraer un modulo critico sin cortar operacion. Criterio de refactor y separacion de dominios sobre sistemas vivos.

**Evidencia.** `/pricing-bigbang` vs `/pricing-compatible` sobre los mismos consumidores sensibles. Visibiliza `compatibility_proxy_hits`, `contract_tests`, progreso de cutover por consumidor. Node usa `Proxy` nativo para compat de contrato + `EventEmitter` para eventos de cutover.

**Honestidad.** No simula un rollout distribuido completo ni feature flags globales. Reproduce la logica clave: proxy de compatibilidad, contratos, cutover gradual.

→ Detalle: [cases/08-critical-module-extraction-without-breaking-operations/README.md](../cases/08-critical-module-extraction-without-breaking-operations/README.md)

---

## Caso 09 — Integracion externa inestable

**Categoria:** Resiliencia · **Stacks operativos:** PHP, Python, Node.js, Java 21

**Problema.** Una API externa introduce latencia, errores intermitentes o reglas cambiantes que afectan el sistema propio. No controlamos al tercero.

**Valor.** Razonamiento de protecciones frente a dependencias externas. Resiliencia realista frente a terceros, **no** solo manejo local de excepciones.

**Evidencia.** `/catalog-legacy` vs `/catalog-hardened` sobre drift de esquema, rate limit y maintenance window. Visibiliza budget restante, schema mappings, snapshot cacheado. `integration/state`, `sync-events`.

**Honestidad.** No reemplaza una integracion real con DLQ ni proveedores externos verdaderos. Reproduce contratos variables, cuota, cache, adaptacion defensiva.

→ Detalle: [cases/09-unstable-external-integration/README.md](../cases/09-unstable-external-integration/README.md)

---

## Caso 10 — Arquitectura cara para un problema simple

**Categoria:** Arquitectura · **Stacks operativos:** PHP, Python, Node.js, Java 21

**Problema.** La solucion tecnica consume mas servicios, complejidad y costo del que el problema de negocio realmente necesita. Sobre-ingenieria disfrazada de "estandar".

**Valor.** Decisiones tecnologicas proporcionales al problema. Criterio para **decir no a complejidad innecesaria** cuando la situacion pide simplicidad.

**Evidencia.** `/feature-complex` vs `/feature-right-sized` con costo mensual estimado, servicios tocados y lead time. Backlog de simplificacion y coordinacion requerida. `architecture/state`, `decisions`. Node mide CPU real como N rondas de `JSON.stringify`/`parse`.

**Honestidad.** No modela una organizacion real ni FinOps completo. Deja visible la tension entre complejidad innecesaria, costo y adecuacion real al problema.

→ Detalle: [cases/10-expensive-architecture-for-simple-needs/README.md](../cases/10-expensive-architecture-for-simple-needs/README.md)

---

## Caso 11 — Reportes pesados que bloquean la operacion

**Categoria:** Operaciones · **Stacks operativos:** PHP, Python, Node.js, Java 21

**Problema.** Reporting compite con operacion transaccional y degrada el sistema completo. Aparece tarde y cuesta caro porque no se detecta hasta que ya bloquea ventas.

**Valor.** Aislamiento de cargas. Reporting deja de degradar la operacion. Conecta datos y operacion en un problema cotidiano.

**Evidencia.** `/report-legacy` vs `/report-isolated` sobre la misma presion operativa. Visibiliza `primary_load`, `lock_pressure`, `replica_lag_s`, `queue_depth`. Node usa `monitorEventLoopDelay()` para medir lag real del event loop. `/order-write` confirma si la operacion conserva aire.

**Honestidad.** No reemplaza una replica real ni un data warehouse. Reproduce el problema operativo: reporting sobre el primario vs aislamiento de cargas.

→ Detalle: [cases/11-heavy-reporting-blocks-operations/README.md](../cases/11-heavy-reporting-blocks-operations/README.md)

---

## Caso 12 — Punto unico de conocimiento y riesgo operacional

**Categoria:** Operaciones · **Stacks operativos:** PHP, Python, Node.js, Java 21

**Problema.** Una persona o procedimiento concentra tanto conocimiento que el sistema se vuelve fragil ante ausencias o rotacion. **Bus factor = 1**.

**Valor.** Continuidad operacional. Reduccion de dependencia critica en personas. Riesgo operacional y sostenibilidad del conocimiento como parte de la calidad del sistema, no solo del codigo.

**Evidencia.** `/incident-legacy` vs `/incident-distributed` sobre `owner_absent`, `night_shift`, `tribal_script`. Visibiliza `mttr_min`, `blocker_count`, `handoff_quality`, `bus_factor_min`. `/share-knowledge` muestra como cambia la continuidad al distribuir conocimiento.

**Honestidad.** No reemplaza una organizacion real ni un programa formal de on-call. Reproduce el riesgo central: memoria tribal, bus factor, continuidad operacional.

→ Detalle: [cases/12-single-point-of-knowledge-and-operational-risk/README.md](../cases/12-single-point-of-knowledge-and-operational-risk/README.md)

---

## Que NO encontraras en este laboratorio

Honestidad explicita para no vender lo que no es:

- **No es un benchmark de lenguajes.** Los 4 stacks (PHP/Python/Node/Java) resuelven los 12 problemas con primitivas nativas distintas; el contraste muestra criterio, no "cual es mas rapido".
- **No reemplaza plataformas reales.** Tracing distribuido, CI/CD enterprise, mallas de servicios y feature flags globales quedan fuera. Lo que si esta: reproduccion fiel de la **logica operativa** de cada problema.
- **No es production-grade tal cual.** Modelo de amenaza: localhost / LAN confiable. Para Internet ver [SECURITY.md](../SECURITY.md) (auth, rate limit, TLS son responsabilidad de quien expone).
- **No promete paridad multi-stack en futuros casos.** PHP + Python + Node.js cubren los 12 hoy. Java/.NET existen como scaffolds y se sumaran por caso solo si aportan contraste real.

## Rutas rapidas

- **Reclutador / lider no tecnico:** esta pagina + [`RECRUITER.md`](../RECRUITER.md).
- **CTO / arquitecto:** [`ARCHITECTURE.md`](../ARCHITECTURE.md) → 1-2 casos en detalle.
- **Developer:** [`INSTALL.md`](../INSTALL.md) → `make portal-up` → recorrer el portal.
- **Operacion / SRE:** [`RUNBOOK.md`](../RUNBOOK.md) + [`SECURITY.md`](../SECURITY.md).
