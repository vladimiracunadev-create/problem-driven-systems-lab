# 👋 Para el recruiter / hiring manager

> Estado: activo
> Audiencia: reclutadores, hiring managers técnicos, headhunters senior, CTOs de empresas pequeñas
> Este documento te ahorra leer el repo completo. Pensado para evaluación en 90 segundos, verificación en 5 minutos.

---

## TL;DR (30 segundos)

- 5+ años de experiencia traducidos en **evidencia operativa**: 13 problemas reales × 7 stacks de producción = **91 endpoints verificables**.
- **No es un repo de "hola mundo" en 5 lenguajes.** Cada caso reproduce un fallo de producción (N+1 real con SQLite embebido, breaker con CAS, leak de memoria con LRU, retry storm con `AbortController`, etc.) y la solución idiomática del stack.
- **Honestidad técnica explícita:** lo que es DB real, lo que está simulado, lo que falta — todo documentado, nada vendido.
- **`docker compose up` y verificás vos mismo.** No tenés que confiar en mi palabra.

---

## ¿Por qué este repo prueba algo que los portfolios típicos no?

| Portfolio típico | Este lab |
|---|---|
| Hello world / TODO app / blog clone | Problemas reales de producción: N+1, retry storms, leaks, circuit breaker, strangler, idempotencia |
| 1 stack ("soy desarrollador X") | 7 stacks que demuestran que **el problema es lo importante, no la tecnología** |
| README sin ejecutar | `docker compose up` → smoke test verificable en cada caso |
| Claims sin evidencia | Métricas reales (`db_hits`, `p95`, `breaker_state`, `event_loop_lag_ms`) reportadas en cada response JSON |
| Esconde lo incompleto | Tabla explícita "fidelidad real vs simulado" + [ROADMAP](ROADMAP.md) con próximos casos y deuda declarada |
| Frameworks de moda por encima | Stdlib y BCL puras: `HttpServer` JDK, `HttpListener` BCL, `node:http`, `http.server` Python. Demuestra criterio sobre dependencias |

---

## Cómo evaluarlo en 5 minutos (literal)

### 1 — Lee el [README.md](README.md) raíz (2 min)

Vas a ver: tabla de estado actual (7 stacks operativos, 91 endpoints), catálogo de los 13 casos con links, tabla de honestidad de fidelidad, los 7 hubs disponibles.

### 2 — Levantá un hub (1 min)

```bash
# Elegí cualquiera de los 5
docker compose -f compose.dotnet.yml up -d --build   # .NET 8
docker compose -f compose.java.yml   up -d --build   # Java 21
docker compose -f compose.nodejs.yml up -d --build   # Node 20
docker compose -f compose.python.yml up -d --build   # Python 3.12
docker compose -f compose.root.yml   up -d --build   # PHP 8.3 + portal + Prometheus + Grafana
```

### 3 — Verificá un caso end-to-end (1 min)

```bash
# Caso 02 en .NET — N+1 contra SQLite REAL embebido
curl "http://localhost:8500/02/orders-legacy?limit=5"
# → { "orders": [...], "db_hits": 6, "db_time_ms": ~, ... }

curl "http://localhost:8500/02/orders-optimized?limit=5"
# → { "orders": [...], "db_hits": 2, "db_time_ms": ~, ... }
```

`db_hits` es un contador real de ejecuciones contra el motor SQLite, no un número decorativo.

### 4 — Mirá un comparison.md (1 min)

Abrí [`cases/02-n-plus-one-and-db-bottlenecks/comparison.md`](cases/02-n-plus-one-and-db-bottlenecks/comparison.md) — vas a ver cómo los 7 stacks resuelven el mismo problema con primitivas idiomáticas distintas. Same problem, 7 idiomatic solutions, same observable metric.

---

## Qué prueba sobre mi criterio de ingeniería

### 1. Diagnóstico antes que solución

Cada caso parte de **síntoma observable → causa raíz → trade-offs → solución idiomática**, no de "qué cool sería implementar X". El formato problem-driven está sostenido en `cases/<caso>/README.md` con esa estructura.

### 2. Honestidad técnica como política, no como excepción

El repo tiene secciones explícitas que la mayoría de portfolios evita:

- **"Honestidad de fidelidad"** en el README raíz — qué casos usan DB real, cuáles simulan.
- **"Lo que NO garantiza"** en SECURITY.md — auth, rate limiting, TLS ausentes por diseño.
- **"Lo que esta guía no resuelve"** en AWS_MIGRATION.md — backups drills, multi-región, compliance.
- **"Lo que este repo no vende"** al final del README.

Un senior reviewer detecta gaps en 30 segundos. Vale más declararlos primero que ser pillado.

### 3. Multi-stack sin religión

Demuestra que entiendo que `ConcurrentHashMap` (Java), `ConcurrentDictionary` (.NET), `Map` (JS) y `dict` (Python) resuelven el mismo problema con primitivas distintas — y que sé cuál es la idiomática en cada uno. Sin tribalismo de stack.

### 4. Operación, no solo código

Cada caso corre en Docker con `compose.yml`, expone `/health`, devuelve métricas reales en el JSON de respuesta (`db_hits`, `latency_ms`, `breaker_state`, `event_loop_lag_ms`), y se levanta en <30s. **Es operacionable, no decorativo.**

### 5. Roadmap pensado, no acumulado

El [ROADMAP.md](ROADMAP.md) está organizado en **3 ejes** (casos nuevos 13-20, mejoras de plataforma, compromisos de honestidad) con criterios de aceptación por entrada. No es una lista de deseos.

### 6. Criterio sobre frameworks

Los stacks no-PHP usan exclusivamente librería estándar — `HttpServer` JDK, `HttpListener` BCL, `node:http`, `http.server` Python. Si trabajamos juntos y te preocupa que sea de los que tiran 200 MB de `node_modules` para un endpoint trivial, este repo te tranquiliza.

### 7. Pensamiento sistémico

[`ARCHITECTURE.md`](ARCHITECTURE.md) tiene los diagramas Mermaid del lab completo. [`AWS_MIGRATION.md`](AWS_MIGRATION.md) tiene 3 rutas alternativas con trade-offs reales. [`SECURITY.md`](SECURITY.md) tiene modelo de amenaza por escenario de despliegue. **El criterio cruza capas: código, arquitectura, despliegue, seguridad, costo.**

---

## Highlights por caso (lectura rápida)

| # | Caso | Por qué importa |
|---|---|---|
| 01 | API lenta bajo carga | Patrón worker concurrente + cache + readers no bloqueados. PHP corre contra PostgreSQL real con contención observable; los otros 4 stacks aplican el mismo patrón con substrato simulado (asimetría declarada). |
| 02 | N+1 y bottlenecks DB | **Los 7 stacks ejecutan N+1 real contra SQL embebido.** `db_hits` mide ejecuciones reales en el motor. Caso con fidelidad universal. |
| 03 | Observabilidad deficiente | `correlation_id` propagado en pipeline async. `ThreadLocal<RequestContext>` en Java, `AsyncLocal<RequestContext>` en .NET. |
| 04 | Timeout chain y retry storms | Circuit breaker con CAS atómico, `AbortController` cooperativo, `CompletableFuture.orTimeout`, `CancellationTokenSource` + `Interlocked.CompareExchange`. |
| 05 | Memory pressure y leaks | LRU manual con `LinkedHashMap.removeEldestEntry` (Java), `Dictionary + LinkedList` (.NET), heap V8 + RSS (Node), `tracemalloc` (Python). |
| 06 | Pipeline roto y delivery frágil | Preflight + rollback con state machine. `record` types + `with`-expressions en .NET. |
| 07 | Modernización del monolito | Strangler fig pattern. `ConcurrentHashMap<String,Function>` (Java), `ConcurrentDictionary<string,Func>` (.NET), `Map<consumer,handler>` (Node). |
| 08 | Extracción de módulo crítico | Extract-and-proxy + cutover gradual. `Proxy` + `EventEmitter` (Node), `Function` proxy + `CopyOnWriteArrayList` (Java), `Func<Old,New>` + `ImmutableList<Action>` (.NET). |
| 09 | Integración externa inestable | Adapter + cache + circuit breaker. `AbortSignal.timeout` (Node), `Semaphore` budget (Java), `SemaphoreSlim` + `Interlocked` breaker (.NET). |
| 10 | Arquitectura sobre-dimensionada | Comparación complex vs right-sized. N hops `JsonSerializer` con presión LOH vs `Dictionary` O(1) lookup en .NET. |
| 11 | Reportes pesando la operación | Aislamiento de carga reportiva del path crítico. `ConcurrentExclusiveSchedulerPair` (.NET), `ThreadPoolExecutor.getActiveCount()` (Java), `monitorEventLoopDelay` (Node). |
| 12 | Single point of knowledge | Runbooks codificados en el sistema de tipos. `Optional<T>` (Java), `?.` + `??` con Nullable Reference Types (.NET). |

---

## Preguntas de entrevista que este lab te ayuda a responder

| Pregunta de entrevista | Caso al que apunta |
|---|---|
| "Contame de una vez que diagnosticaste un problema de performance" | 01 o 02 — métricas reales, no anécdota |
| "Cómo manejás timeouts y retries en sistemas distribuidos" | 04 — código real con breaker, no slide de teoría |
| "Qué harías frente a un monolito legacy" | 07 — strangler fig implementado |
| "Cómo extraés un módulo crítico sin breakar producción" | 08 — extract-and-proxy con cutover gradual |
| "Cómo medís y prevenís leaks de memoria" | 05 — LRU + métricas de heap por stack |
| "Qué hacés cuando una dependencia externa empieza a fallar" | 09 — adapter + breaker + cache |
| "Cómo evitás que el bus factor sea 1" | 12 — runbooks codificados |
| "Cómo abordás observabilidad en un sistema multi-stack" | 03 — correlation_id idiomático por runtime |
| "Cómo razonás sobre costos cloud" | [AWS_MIGRATION.md](AWS_MIGRATION.md) — 3 rutas con rangos honestos |
| "Cómo evaluás postura de seguridad" | [SECURITY.md](SECURITY.md) — modelo de amenaza por escenario |

---

## Detalle de capacidades demostradas

### Diagnóstico de performance (casos 01, 02, 11)

- N+1 detectado y resuelto con batch `IN(?, ?, ?)` — el contador `db_hits` baja de O(N) a O(1) y la métrica está en el JSON de respuesta.
- Latencia bajo carga resuelta con worker concurrente + cache + readers no bloqueados. Patrón aplicable a cualquier stack — demostrado en los 5.
- Reportes pesados aislados del path crítico con primitiva idiomática del runtime: `ConcurrentExclusiveSchedulerPair` en .NET, scheduler dedicado en Java, `monitorEventLoopDelay` para verificar que el event loop no se bloquea en Node.

### Resiliencia distribuida (casos 04, 09)

- Circuit breaker implementado con CAS atómico (`Interlocked.CompareExchange` en .NET, `AtomicReference` en Java) — no `synchronized`, no `lock`. Estado del breaker observable en cada response (`breaker_state: closed|open|half_open`).
- Cancellation cooperativa con `AbortController` (Node), `CompletableFuture.orTimeout` (Java), `CancellationTokenSource` (.NET) — propaga la cancelación al pipeline completo, sin requests huérfanos.
- Adapter pattern con cache de fallback para integraciones inestables — cuando el upstream cae, el lab degrada en lugar de quebrar.

### Memoria y leaks (caso 05)

- LRU manual implementado con primitivas del lenguaje: `LinkedHashMap.removeEldestEntry` (Java), `Dictionary + LinkedList` doblemente enlazado (.NET), `Map` con timestamps (Node), `OrderedDict` (Python).
- Métricas reales reportadas: `Runtime.totalMemory()` (Java), `Process.WorkingSet64` (.NET), `process.memoryUsage().rss` (Node), `tracemalloc` (Python).
- Patrón legacy vs optimized — el lector compara las dos curvas en vivo.

### Modernización legacy (casos 07, 08)

- **Strangler fig:** routing mutable consumidor por consumidor con `ConcurrentHashMap<String,Function>` (Java), `ConcurrentDictionary<string,Func<Request,Response>>` (.NET), `Map<consumer,handler>` (Node).
- **Extract-and-proxy:** módulo crítico extraído con `Proxy` + `EventEmitter` (Node), `Function` proxy + `CopyOnWriteArrayList` event bus (Java), `Func<Old,New>` + `ImmutableList<Action<string>>` (.NET). Cutover gradual observable por porcentaje.

### Observabilidad real (caso 03)

- `correlation_id` propagado en pipeline async sin ensuciar la firma de los métodos: `ThreadLocal<RequestContext>` en Java, `AsyncLocal<RequestContext>` en .NET, contexto explícito en Node/Python/PHP.
- Logs estructurados con shape consistente entre stacks (`{timestamp, correlation_id, level, msg, ...attrs}`).
- Stack PHP además exporta Prometheus + Grafana real (casos 01 y 02).

### Continuidad operacional (caso 12)

- Runbooks codificados en el sistema de tipos: `Optional<T>` en Java, Nullable Reference Types con `?.`/`??` en .NET. El compilador no deja que el código tenga `null` no manejado.
- Bus factor abordado con redundancia de conocimiento explícita en el código, no solo en docs.

### Postmortems narrativos

12 postmortems (uno por caso) en formato incidente real: severidad SEV-1/2/3, timeline minuto a minuto, causa raíz técnica, lo que funcionó vs lo que no, action items con dueño, métrica antes/después. Ver [`docs/executive-summary.md`](docs/executive-summary.md). **Muestra cómo se piensa el incidente, no solo cómo se resuelve.**

---

## Señales profesionales que el repo deja

| Señal | Dónde se ve |
|---|---|
| Pensamiento sistémico | El problema manda; el stack acompaña |
| Capacidad de explicación | Cada caso tiene contexto, síntomas, causas y opciones — sin asumir nivel |
| Criterio operativo | Docker como vía oficial, no como decoración |
| Madurez documental | El repo habla distinto según audiencia sin perder coherencia |
| Honestidad | No promete paridad multi-stack donde todavía no existe |
| Criterio sobre frameworks | Stdlib/BCL primero — frameworks solo donde aportan |
| Pensamiento de costo | Plan AWS con rangos realistas, no número único |
| Continuidad operacional | 12 postmortems narrativos como capacidad evaluable |

---

## Disponibilidad y contacto

- **Email:** vladimir.acuna.dev@gmail.com
- **Sitio profesional:** [vladimiracunadev-create.github.io](https://vladimiracunadev-create.github.io/)
- **GitHub:** [github.com/vladimiracunadev-create](https://github.com/vladimiracunadev-create)
- **GitLab:** [gitlab.com/vladimir.acuna.dev-group](https://gitlab.com/vladimir.acuna.dev-group/vladimir.acuna.dev-group)
- **Stack al que aplico:** senior developer / tech lead / staff engineer en cualquiera de los 7 stacks demostrados (preferencia PHP, Python, .NET).
- **Modalidad:** remoto / híbrido (Argentina) / relocation a evaluar.

---

## Lo que este repo NO prueba (honestamente)

- **Frontend complejo / UX / diseño visual.** Mi foco está en backend y operación. La UI nativa de PHP es funcional, no premiada.
- **Conocimiento profundo de un framework específico de moda.** La idea es justamente lo opuesto: BCL/stdlib donde se puede. Si tu posición es "expertise en Next.js + tRPC + Prisma + Tailwind", este no es el repo que te va a convencer.
- **Liderazgo de equipos de 50+ personas.** Mi experiencia de liderazgo es a nivel squad técnico, no organizacional.
- **Paridad sintáctica universal de los 13 casos en los 7 stacks.** Es paridad **funcional con criterio idiomático por runtime** — no traducción literal. El caso 11 es el ejemplo explícito: Go y Rust no tienen pool de threads que agotar, así que el aislamiento se modela con un semáforo de concurrencia, no con un `ExecutorService` traducido.
- **Benchmarks absolutos entre lenguajes.** Las métricas reportadas son operativas (`db_hits`, `latency_ms`), no comparativas marketing.

---

## 📚 Documentos recomendados después de éste

| Documento | Motivo |
|---|---|
| [README.md](README.md) | Historia general del laboratorio y catálogo completo |
| [docs/executive-summary.md](docs/executive-summary.md) | Los 13 casos en una página + postmortems narrativos |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Diagramas Mermaid + decisiones de diseño con su porqué |
| [AWS_MIGRATION.md](AWS_MIGRATION.md) | Plan de despliegue cloud con 3 rutas y mapping SECURITY → AWS |
| [SECURITY.md](SECURITY.md) | Modelo de amenaza por escenario de despliegue |
| [ROADMAP.md](ROADMAP.md) | Próximos casos 13-20 y compromisos de honestidad |
| [INSTALL.md](INSTALL.md) | Validar que el repo se ejecuta de forma limpia |
| [CHANGELOG.md](CHANGELOG.md) | Evolución reciente del laboratorio |
