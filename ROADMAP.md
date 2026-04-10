# ROADMAP - Problem-Driven Systems Lab

> Estado actual y evolucion priorizada del laboratorio.

## Fotografia actual

- Casos `01` al `12` operativos en PHP.
- Caso `03` operativo tambien en Node.js y Python.
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
- Llevar el caso `01` a mayor paridad multi-stack sin degradarlo a demo superficial.
- Llevar el caso `02` a mayor paridad multi-stack con una implementacion real fuera de PHP.
- Profundizar otros stacks sin degradar la implementacion PHP ya operativa de `01` a `12`.

Avance actual:

- Caso `01`: PHP + PostgreSQL + worker + Prometheus + Grafana.
- Caso `02`: PHP + PostgreSQL con N+1 legacy vs lectura optimizada.
- Caso `03`: PHP + Node.js + Python con logs pobres vs telemetria util y trazabilidad.
- Caso `04`: PHP con timeout chain, retry storm, circuit breaker y fallback.
- Caso `05`: PHP con presion progresiva de memoria/recursos y comparacion legacy vs optimized.
- Caso `06`: PHP con pipeline legacy vs controlled, preflight y rollback.
- Caso `07`: PHP con modernizacion incremental de monolito y comparacion legacy vs strangler.
- Caso `08`: PHP con extraccion big bang vs compatible, proxy y cutover gradual.
- Caso `09`: PHP con integracion directa vs adapter endurecido, cache y budget de cuota.
- Caso `10`: PHP con solucion complex vs right-sized y comparacion de costo/lead time.
- Caso `11`: PHP con reporting legacy vs aislado y efecto directo sobre la operacion.
- Caso `12`: PHP con continuidad operacional basada en runbooks, pairing y bus factor.

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
