# ROADMAP - Problem-Driven Systems Lab

> Estado actual y evolucion priorizada del laboratorio.

## Fotografia actual

- Casos `01`, `02` y `03` operativos en PHP.
- Docker por caso y por stack definido como ruta oficial.
- Familia documental profesional incorporada en la raiz del repo.
- Casos `04` al `12` documentados y listos para profundizacion funcional.

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
- Alineacion editorial con el ecosistema publico de Vladimir Acuna: Docker-first, honestidad de madurez, documentacion operativa y valor ejecutivo.

## Fase 2 - Profundizacion tecnica

Estado: en progreso

- Completar implementaciones funcionales por caso y stack con mayor logica de negocio.
- Agregar medicion reproducible donde el problema lo requiera.
- Sumar mas observabilidad compartida cuando aporte valor real.
- Llevar el caso `01` a mayor paridad multi-stack sin degradarlo a demo superficial.
- Continuar con casos `04` al `12`, priorizando problemas con mayor impacto operativo.

Avance actual:

- Caso `01`: PHP + PostgreSQL + worker + Prometheus + Grafana.
- Caso `02`: PHP + PostgreSQL con N+1 legacy vs lectura optimizada.
- Caso `03`: PHP con logs pobres vs telemetria util y trazabilidad.

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
