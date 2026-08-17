# 🩺 Síntomas

- La base de datos cae **90 segundos exactos** a las 03:00, todas las noches, sin que el tráfico haya subido
- El hit rate de cache marca 99% y aun así el origen recibe picos de miles de consultas idénticas
- Latencia p99 que se dispara en escalón, no en rampa: pasa de 20 ms a 4 segundos en un solo intervalo
- Los gráficos de conexiones a la base muestran una pared vertical, no una curva
- Reiniciar el servicio de cache **provoca** la caída en vez de arreglarla
- Todas las claves de un mismo tipo expiran en el mismo minuto (síntoma de TTL fijo sin jitter)

<!-- nav-case-doc -->
---

**Caso 13 · Cache stampede y thundering herd** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · **🩺 Síntomas** · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
