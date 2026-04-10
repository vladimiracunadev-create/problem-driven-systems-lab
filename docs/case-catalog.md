# 🗂️ Catalogo de casos

> Lista completa de los 12 casos del laboratorio generada desde `shared/catalog/cases.json`.

## 📊 Estado actual

| Icono | Caso | Categoria | Estado | Stacks operativos | Nivel actual | Impacto de negocio |
| --- | --- | --- | --- | --- | --- | --- |
| ⚡ | [01 - API lenta bajo carga](../cases/01-api-latency-under-load/README.md) | Rendimiento | `OPERATIVO` | `php` | PHP + PostgreSQL + worker + Prometheus + Grafana | Reduce latencia visible y evita sobredimensionar infraestructura a ciegas. |
| 🔄 | [02 - N+1 queries y cuellos de botella en base de datos](../cases/02-n-plus-one-and-db-bottlenecks/README.md) | Rendimiento | `OPERATIVO` | `php` | PHP + PostgreSQL | Reduce round-trips, costo por request y desgaste innecesario sobre la base de datos. |
| 🔭 | [03 - Observabilidad deficiente y logs inutiles](../cases/03-poor-observability-and-useless-logs/README.md) | Observabilidad | `OPERATIVO` | `php`, `node`, `python` | PHP + Node.js + Python con logs estructurados, trazas y metricas | Reduce MTTR y convierte incidentes vagos en fallas diagnosticables con evidencia. |
| ⏱️ | [04 - Cadena de timeouts y tormentas de reintentos](../cases/04-timeout-chain-and-retry-storms/README.md) | Resiliencia | `OPERATIVO` | `php` | PHP 8.3 con retries legacy vs timeout corto, backoff, circuit breaker y fallback | Ayuda a reducir fallas en cascada y a disenar limites mas sanos de timeout, retry y backoff. |
| 🧠 | [05 - Presion de memoria y fugas de recursos](../cases/05-memory-pressure-and-resource-leaks/README.md) | Rendimiento | `OPERATIVO` | `php` | PHP 8.3 con estado retenido, presion progresiva y comparacion legacy vs optimized | Sirve para razonar estabilidad, limites de recursos y degradacion progresiva antes del colapso. |
| 🚚 | [06 - Pipeline roto y entrega fragil](../cases/06-broken-pipeline-and-fragile-delivery/README.md) | Entrega | `OPERATIVO` | `php` | PHP 8.3 con deploy legacy vs controlled, preflight y rollback | Ayuda a reducir riesgo en despliegues y a fortalecer rollback, promotion y entrega continua. |
| 🏗️ | [07 - Modernizacion incremental de monolito](../cases/07-incremental-monolith-modernization/README.md) | Arquitectura | `OPERATIVO` | `php` | PHP 8.3 con cambio legacy vs strangler, cobertura progresiva y blast radius observable | Permite modernizar un monolito sin convertir cada cambio en una reescritura riesgosa. |
| 🧩 | [08 - Extraccion de modulo critico sin romper operacion](../cases/08-critical-module-extraction-without-breaking-operations/README.md) | Arquitectura | `OPERATIVO` | `php` | PHP 8.3 con extraccion big bang vs compatible, proxy y cutover gradual | Permite extraer un modulo critico sin cortar checkout, partners ni backoffice. |
| 🌐 | [09 - Integracion externa inestable](../cases/09-unstable-external-integration/README.md) | Resiliencia | `OPERATIVO` | `php` | PHP 8.3 con integracion directa vs adapter endurecido, cache y cuota observable | Permite razonar protecciones frente a dependencias externas que no controlamos. |
| 💸 | [10 - Arquitectura cara para un problema simple](../cases/10-expensive-architecture-for-simple-needs/README.md) | Arquitectura | `OPERATIVO` | `php` | PHP 8.3 con comparacion complex vs right-sized, costo y lead time visibles | Ayuda a tomar decisiones tecnologicas mas proporcionales al problema de negocio. |
| 📊 | [11 - Reportes pesados que bloquean la operacion](../cases/11-heavy-reporting-blocks-operations/README.md) | Operaciones | `OPERATIVO` | `php` | PHP 8.3 con reporting legacy vs aislado y efecto directo sobre escrituras | Permite discutir aislamiento de cargas, reporting y proteccion de flujos operativos. |
| 👤 | [12 - Punto unico de conocimiento y riesgo operacional](../cases/12-single-point-of-knowledge-and-operational-risk/README.md) | Operaciones | `OPERATIVO` | `php` | PHP 8.3 con incidentes legacy vs conocimiento distribuido, runbooks y bus factor | Ayuda a discutir continuidad operacional, documentacion y reduccion de dependencia critica en personas. |

## ✅ Casos operativos hoy

### ⚡ [01 - API lenta bajo carga](../cases/01-api-latency-under-load/README.md)

- Stacks operativos: `php`
- Impacto de negocio: Reduce latencia visible y evita sobredimensionar infraestructura a ciegas.
- Que demuestra: Compara /report-legacy y /report-optimized con latencia, p95 y queries promedio.

### 🔄 [02 - N+1 queries y cuellos de botella en base de datos](../cases/02-n-plus-one-and-db-bottlenecks/README.md)

- Stacks operativos: `php`
- Impacto de negocio: Reduce round-trips, costo por request y desgaste innecesario sobre la base de datos.
- Que demuestra: Contrasta /orders-legacy y /orders-optimized sobre la misma base relacional y los mismos datos semilla.

### 🔭 [03 - Observabilidad deficiente y logs inutiles](../cases/03-poor-observability-and-useless-logs/README.md)

- Stacks operativos: `php`, `node`, `python`
- Impacto de negocio: Reduce MTTR y convierte incidentes vagos en fallas diagnosticables con evidencia.
- Que demuestra: Compara checkout-legacy contra checkout-observable para ver que cambia cuando existe correlacion real.

### ⏱️ [04 - Cadena de timeouts y tormentas de reintentos](../cases/04-timeout-chain-and-retry-storms/README.md)

- Stacks operativos: `php`
- Impacto de negocio: Ayuda a reducir fallas en cascada y a disenar limites mas sanos de timeout, retry y backoff.
- Que demuestra: Contrasta /quote-legacy y /quote-resilient sobre el mismo proveedor simulado.

### 🧠 [05 - Presion de memoria y fugas de recursos](../cases/05-memory-pressure-and-resource-leaks/README.md)

- Stacks operativos: `php`
- Impacto de negocio: Sirve para razonar estabilidad, limites de recursos y degradacion progresiva antes del colapso.
- Que demuestra: Compara /batch-legacy y /batch-optimized con estado acumulado entre requests.

### 🚚 [06 - Pipeline roto y entrega fragil](../cases/06-broken-pipeline-and-fragile-delivery/README.md)

- Stacks operativos: `php`
- Impacto de negocio: Ayuda a reducir riesgo en despliegues y a fortalecer rollback, promotion y entrega continua.
- Que demuestra: Contrasta /deploy-legacy y /deploy-controlled sobre los mismos escenarios de riesgo.

### 🏗️ [07 - Modernizacion incremental de monolito](../cases/07-incremental-monolith-modernization/README.md)

- Stacks operativos: `php`
- Impacto de negocio: Permite modernizar un monolito sin convertir cada cambio en una reescritura riesgosa.
- Que demuestra: Contrasta /change-legacy y /change-strangler sobre shared_schema, billing_change y trabajo paralelo.

### 🧩 [08 - Extraccion de modulo critico sin romper operacion](../cases/08-critical-module-extraction-without-breaking-operations/README.md)

- Stacks operativos: `php`
- Impacto de negocio: Permite extraer un modulo critico sin cortar checkout, partners ni backoffice.
- Que demuestra: Contrasta /pricing-bigbang y /pricing-compatible sobre los mismos consumidores sensibles.

### 🌐 [09 - Integracion externa inestable](../cases/09-unstable-external-integration/README.md)

- Stacks operativos: `php`
- Impacto de negocio: Permite razonar protecciones frente a dependencias externas que no controlamos.
- Que demuestra: Contrasta /catalog-legacy y /catalog-hardened sobre drift de esquema, rate limit y maintenance window.

### 💸 [10 - Arquitectura cara para un problema simple](../cases/10-expensive-architecture-for-simple-needs/README.md)

- Stacks operativos: `php`
- Impacto de negocio: Ayuda a tomar decisiones tecnologicas mas proporcionales al problema de negocio.
- Que demuestra: Contrasta /feature-complex y /feature-right-sized con costo mensual, servicios tocados y lead time.

### 📊 [11 - Reportes pesados que bloquean la operacion](../cases/11-heavy-reporting-blocks-operations/README.md)

- Stacks operativos: `php`
- Impacto de negocio: Permite discutir aislamiento de cargas, reporting y proteccion de flujos operativos.
- Que demuestra: Contrasta /report-legacy y /report-isolated sobre la misma presion operativa.

### 👤 [12 - Punto unico de conocimiento y riesgo operacional](../cases/12-single-point-of-knowledge-and-operational-risk/README.md)

- Stacks operativos: `php`
- Impacto de negocio: Ayuda a discutir continuidad operacional, documentacion y reduccion de dependencia critica en personas.
- Que demuestra: Contrasta /incident-legacy y /incident-distributed sobre owner_absent, night_shift y tribal_script.

## 🧭 Rutas de evaluacion

| Audiencia | Punto de entrada | Que obtiene |
| --- | --- | --- |
| 👔 Recruiter | [Abrir](../RECRUITER.md) | Confirmar criterio tecnico, honestidad de madurez y capacidad de comunicar decisiones. |
| 🧭 CTO / Head of Engineering | [Abrir](../ARCHITECTURE.md) | Entender si el producto demuestra pensamiento de plataforma, observabilidad y reduccion de riesgo. |
| 🛠️ Developer | [Abrir](../INSTALL.md) | Corroborar que los escenarios se ejecutan de forma limpia y que los endpoints cuentan una historia tecnica util. |
| 🌱 Beginner | [Abrir](../docs/BEGINNERS_GUIDE.md) | Entender que problema modela cada caso y por que Docker se usa aqui como mecanismo de reproducibilidad. |

## 🏷️ Leyenda

| Estado | Significado |
| --- | --- |
| `OPERATIVO` | Implementacion real con Docker y evidencia observable |
| `DOCUMENTADO / SCAFFOLD` | Caso bien modelado pero aun no profundizado del todo |
| `PLANIFICADO` | Futuro del roadmap, todavia no presente en el arbol actual |
