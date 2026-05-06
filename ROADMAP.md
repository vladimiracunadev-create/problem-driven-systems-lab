# ROADMAP - Problem-Driven Systems Lab

> Estado actual y evolucion priorizada del laboratorio.

## Fotografia actual

- Casos `01` al `12` operativos en PHP.
- Casos `01` al `12` operativos en Python.
- Casos `01` al `12` operativos en Node.js, cada uno con la primitiva nativa que mejor expresa el problema: `event_loop_lag_ms` y `process.memoryUsage()` para presion real, `AbortController`/`AbortSignal.timeout` para cancelacion y deadlines, `Map<consumer, handler>` para strangler, `Proxy` para compatibilidad de contrato, `EventEmitter` para cutover events, `monitorEventLoopDelay()` para impacto sobre el loop, optional chaining como runbook codificado.
- Docker por caso y por stack definido como ruta oficial.
- Familia documental profesional incorporada en la raiz del repo.
- Catalogo y portal conectados por metadatos compartidos.
- Los otros stacks siguen creciendo de forma gradual sobre la misma base problem-driven.

## Fase 1 - Base estructural

Estado: completada

- Nombre y posicionamiento propio del repositorio.
- Portal raiz liviano para aterrizaje local.
- Estructura problem-driven con 12 casos priorizados.
- Documentacion base, ADRs y plantillas de crecimiento.

## Fase 1.5 - Profesionalizacion documental

Estado: completada

- README raiz reestructurado con rutas por audiencia.
- Incorporacion de `RECRUITER.md`, `INSTALL.md`, `RUNBOOK.md`, `SUPPORT.md`, `SECURITY.md`, `CONTRIBUTING.md` y `CHANGELOG.md`.
- Incorporacion de `ARCHITECTURE.md` como vista ejecutiva del sistema actual.
- Alineacion editorial con el ecosistema publico de Vladimir Acuna: Docker-first, honestidad de madurez, documentacion operativa y valor ejecutivo.

## Fase 2 - Profundizacion tecnica

Estado: en progreso

- Completar implementaciones funcionales por caso y stack con mayor logica de negocio.
- Agregar medicion reproducible donde el problema lo requiera.
- Sumar mas observabilidad compartida cuando aporte valor real.
- Casos `01` al `12` con paridad multi-stack PHP + Python + Node.js.
- Sumar Java o .NET para algun caso especifico cuando aporte contraste tecnico real.

Avance actual (multi-stack PHP + Python + Node.js):

- Caso `01`: PHP + PostgreSQL + worker + Prometheus + Grafana / Python + SQLite + worker / Node.js con worker `setInterval` y `event_loop_lag_ms`.
- Caso `02`: PHP + PostgreSQL con N+1 legacy vs lectura optimizada / Python + SQLite / Node.js con `Map`+`Set` y `event_loop_lag_ms`.
- Caso `03`: logs pobres vs telemetria util y trazabilidad en los tres stacks.
- Caso `04`: timeout chain, retry storm, circuit breaker y fallback / Node.js con `AbortController`/`AbortSignal` cooperativo.
- Caso `05`: presion progresiva de memoria / Python con `tracemalloc` / Node.js con `process.memoryUsage()` (heap V8 + RSS + external).
- Caso `06`: pipeline legacy vs controlled con preflight y rollback / Node.js con `AbortController` para cancelacion cooperativa de pasos.
- Caso `07`: modernizacion incremental, legacy vs strangler / Node.js con `Map<consumer, handler>` mutable en runtime + ACL como closure.
- Caso `08`: extraccion big bang vs compatible con proxy y cutover gradual / Node.js con `Proxy` nativo de compatibilidad de contrato + `EventEmitter` para events.
- Caso `09`: integracion directa vs adapter endurecido con cache y budget de cuota / Node.js con `AbortSignal.timeout` y circuit breaker en memoria.
- Caso `10`: solucion complex vs right-sized con costo/lead time / Node.js con CPU real medido en hops de `JSON.stringify`/`parse`.
- Caso `11`: reporting legacy vs aislado / Node.js con `monitorEventLoopDelay()` y bloqueo sincronico observable.
- Caso `12`: continuidad operacional basada en runbooks, pairing y bus factor / Node.js con optional chaining (`?.`) como runbook codificado.

## Fase 3 - Valor de portafolio

Estado: planificada

- Integrar vistas ejecutivas por caso.
- Agregar diagramas de arquitectura donde realmente ayuden.
- Documentar mas postmortems y comparaciones before/after.
- Traducir documentacion clave al ingles si el flujo de evaluacion lo exige.

## Fase 4 - Laboratorio expandido

Estado: planificada

- Nuevos casos sobre seguridad, colas, cache, costos cloud y contratos.
- CI minima para chequeos estructurales y smoke checks.
- Publicacion opcional de demos limitadas cuando tenga sentido.
- Panel visual mas rico para navegar el laboratorio completo.
