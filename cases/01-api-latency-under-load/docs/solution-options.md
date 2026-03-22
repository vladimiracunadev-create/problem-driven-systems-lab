# Opciones de solución

## Opción 1 — Seguir con la tabla transaccional
Ventaja: menos componentes.
Desventaja: muy sensible a crecimiento, concurrencia y procesos batch.

## Opción 2 — Agregar índices solamente
Puede ayudar, pero no corrige por sí sola:
- filtros no sargables,
- N+1,
- payload excesivo,
- refrescos concurrentes.

## Opción 3 — Tabla resumen + lectura más compacta
Es la estrategia aplicada en este caso:
- resumir datos por día y cliente,
- refrescar en background,
- consultar resumen en vez de recalcular todo en línea,
- reducir viajes a la base.

## Opción 4 — Separar reporting a otro almacén
Más robusto, pero más caro y complejo. Puede ser evolución futura del caso.
