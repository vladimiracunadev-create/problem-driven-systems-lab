# Contexto

La lentitud de una API casi nunca se explica solo por “el lenguaje”.
En producción suele aparecer una mezcla de factores:

- consultas mal diseñadas,
- filtros que invalidan índices,
- enriquecimiento innecesario de respuestas,
- payloads demasiado grandes,
- procesos batch o críticos ejecutándose al mismo tiempo,
- ausencia de tablas resumen o estrategias de lectura.

Este caso modela ese escenario: una API de reportes convive con una tarea operacional que refresca agregados para lectura. La degradación no es decorativa; nace de competir por recursos sobre la misma base de datos.
