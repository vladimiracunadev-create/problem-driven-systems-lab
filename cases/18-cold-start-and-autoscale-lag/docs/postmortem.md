# 🚨 Postmortem — «Escalamos y empeoró»

> Reconstrucción a partir de un patrón recurrente. Los nombres son ficticios; la secuencia no.

## Resumen

Durante un lanzamiento de campaña, el tráfico se multiplicó por seis en once minutos. El autoescalado funcionó: pasó de 6 a 22 instancias. **La tasa de error subió con cada instancia agregada.** El incidente duró 34 minutos y se cerró solo, cuando el tráfico se estabilizó y dejaron de arrancar instancias nuevas.

## Línea de tiempo

| Hora | Evento |
|---|---|
| 09:00 | Se abre la campaña. Tráfico ×2 en dos minutos. |
| 09:04 | El HPA suma 4 instancias. Aparecen los primeros 503. |
| 09:06 | Guardia entra. Todas las instancias en verde. `kubectl get pods`: todo `Running`. |
| 09:09 | El HPA suma 6 más. **La tasa de error sube.** |
| 09:14 | Se descarta la base de datos: latencia normal, sin locks. |
| 09:21 | Se sospecha del despliegue de la mañana. Se prepara un rollback. |
| 09:27 | Alguien nota que los 503 vienen **solo de pods con menos de 40 segundos de vida**. |
| 09:31 | Se confirma: el `Service` no tiene `readinessProbe`. El `livenessProbe` apunta a `/health`, que responde 200 desde el primer milisegundo. |
| 09:34 | El tráfico se estabiliza, dejan de arrancar pods, los errores paran. Sin cambios aplicados. |

## Qué pasó

La aplicación —Java 21 sobre Spring— tarda unos 18 segundos entre arrancar el proceso y poder atender: contexto de Spring, pool de conexiones, cliente de un servicio de identidad con TLS.

`/health` es el endpoint por defecto del framework y responde 200 en cuanto el servidor HTTP levanta, **antes** del contexto. Sin `readinessProbe`, Kubernetes trata a un pod como listo apenas el contenedor arranca. El `Service` empezó a enrutarle tráfico a los 19 segundos de distancia de poder atenderlo.

Con 10 pods nuevos en ventanas superpuestas, una fracción grande del tráfico total cayó en pods que no podían responder.

**La segunda mitad, que costó más entender:** los pods que sí pasaron los 18 segundos atendían con p99 diez veces peor de lo normal durante los primeros minutos. Era la JVM interpretando bytecode antes de que C2 recompilara. Esa latencia mantuvo la CPU alta, lo que mantuvo al HPA escalando, lo que produjo más pods fríos. **El sistema se estaba realimentando.**

## Causas raíz

1. **Ausencia de `readinessProbe`.** Causa directa de los 503.
2. **`/health` respondiendo antes que la aplicación.** El default del framework mide el servidor HTTP, no la aplicación.
3. **Compilación en capas sin calentar.** Los pods nuevos atendían lento y realimentaban el escalado.
4. **Escalado por CPU.** La métrica sube después del pico y la lentitud del arranque la mantiene arriba.

## Qué se cambió

- `readinessProbe` apuntando a un endpoint que verifica el pool y las dependencias que hacen falta para servir.
- `livenessProbe` con `initialDelaySeconds` suficiente para no matar pods que solo estaban arrancando.
- Calentamiento explícito antes de anunciar readiness: 2.000 peticiones sintéticas contra los caminos calientes.
- `minReplicas` subido de 4 a 8, y escalado por RPS de entrada en vez de CPU.
- AppCDS activado: 18 segundos de arranque bajaron a 11.

## La lección

**El healthcheck en verde durante todo un incidente no es una anomalía: es lo que ese healthcheck fue diseñado para hacer.** «El proceso vive» y «puedo servir» son preguntas distintas, y responder la primera cuando alguien preguntó la segunda es la forma más silenciosa de mentirle a un balanceador.

La segunda lección es menos obvia: **un sistema que escala por una métrica que su propio arranque empeora se realimenta**. La CPU alta produjo pods fríos, los pods fríos produjeron CPU alta. Ninguna de las dos partes estaba rota.

<!-- nav-case-doc -->
---

**Caso 18 · Arranque en frío y retraso del autoescalado** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · **🚨 Postmortem**
<!-- /nav-case-doc -->
