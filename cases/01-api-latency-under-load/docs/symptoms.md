# Síntomas esperados

En la ruta legacy deberían observarse síntomas como estos:

- p95 y p99 crecen más rápido que el promedio,
- el tiempo empeora con mayor concurrencia,
- la latencia empeora cuando el worker está refrescando la tabla resumen,
- el endpoint hace muchas consultas para construir una respuesta relativamente simple,
- bajo presión el sistema invita a “poner más fierro” antes de revisar diseño.

En la ruta optimizada se espera:
- menor cantidad de consultas por request,
- menos trabajo sobre la tabla transaccional,
- respuesta más estable,
- mejor convivencia con el proceso crítico.
