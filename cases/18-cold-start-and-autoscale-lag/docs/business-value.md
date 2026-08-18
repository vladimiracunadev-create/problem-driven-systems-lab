# 💼 Valor de negocio

## Qué se elimina

Los 503 durante los escalados. No una fracción: **todos** los que vienen de enrutar por liveness, porque desaparece la ventana en la que el tráfico llega a una instancia a medio levantar.

En el laboratorio, con tres instancias y 2.400 peticiones, la variante fría rechaza entre el 12% y el 42% del tráfico según el stack. La variante con pool tibio y enrutado por readiness rechaza **cero**, con 100% de disponibilidad medida durante el escalado.

## Por qué importa más de lo que parece

El arranque en frío falla **exactamente cuando el sistema está bajo presión**. Nadie escala en un valle de tráfico. Cada 503 de esta clase ocurre en el momento de mayor demanda: el lanzamiento, la campaña, el pico de la mañana.

Y falla de la forma más cara de diagnosticar: sin errores en los logs de la aplicación, sin caídas de proceso, sin nada rojo en el dashboard. Solo usuarios que ven un error y un healthcheck que insiste en que todo está bien.

## El indicador honesto

No es «cuánto tarda en arrancar». Es **`health_vs_ready_gap_ms`**: cuánto tiempo el sistema afirma estar disponible sin estarlo.

Un servicio que tarda 30 segundos en arrancar y lo anuncia correctamente no pierde una sola petición. Uno que tarda 2 segundos y miente durante esos 2 segundos, sí.

## Qué habilita

Autoescalado agresivo con confianza. Cuando la instancia nueva no rompe nada al llegar, se puede escalar más seguido y con menos margen — que es la forma de que el autoescalado ahorre dinero en vez de solo mover el problema.

<!-- nav-case-doc -->
---

**Caso 18 · Arranque en frío y retraso del autoescalado** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · **💼 Valor de negocio** · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
