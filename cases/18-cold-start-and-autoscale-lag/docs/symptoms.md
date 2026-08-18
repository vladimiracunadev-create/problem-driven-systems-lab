# 🩺 Síntomas

## Lo que se ve desde afuera

- **503 justo después de escalar**, y solo por unos segundos. Cuando alguien mira, ya pasó.
- El **healthcheck en verde durante todo el incidente**. El proceso nunca murió.
- Latencia p99 que **empeora al agregar instancias**, en vez de mejorar.
- El autoescalador **rebota**: suma instancias, la latencia sube, suma más.
- El primer usuario de la mañana espera segundos; el resto, milisegundos.
- Después de un despliegue, unos minutos de p99 alto que se «arregla solo».

## Lo que se ve en las métricas

- `readiness` en falso mientras `liveness` está en verdadero — el hueco.
- Errores concentrados en las instancias con menos de un minuto de vida.
- p99 por edad de instancia: las jóvenes son visiblemente peores.
- Reinicios de contenedor por timeout de readiness durante picos de tráfico.

## Lo que hace difícil verlo

El incidente **dura menos que el intervalo de scrape**. Un Prometheus cada 30 segundos puede no ver un hueco de 8 segundos, y el dashboard queda plano mientras los usuarios reciben 503.

Y hay una confusión que retrasa el diagnóstico: como el proceso está vivo, todo el mundo busca el problema *dentro* de la aplicación. El problema está en el enrutado, que es infraestructura.

<!-- nav-case-doc -->
---

**Caso 18 · Arranque en frío y retraso del autoescalado** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · **🩺 Síntomas** · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
