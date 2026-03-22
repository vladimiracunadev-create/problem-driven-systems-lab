# Trade-offs

La versión optimizada mejora latencia y estabilidad, pero introduce costos reales:

- tabla extra,
- proceso worker adicional,
- posible desfase temporal entre transacción y resumen,
- más disciplina operativa.

Eso es intencional: el laboratorio no pretende vender magia, sino mostrar que casi toda mejora seria también trae decisiones de mantenimiento y consistencia.
