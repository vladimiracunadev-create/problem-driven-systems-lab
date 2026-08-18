# 🗺️ Contexto

El tráfico sube. El autoescalador lo nota, decide sumar instancias, y las instancias arrancan. Hasta ahí, todo funcionó como está documentado.

El problema es lo que pasa **entre** que el proceso arranca y que la instancia puede servir.

## Justificación

Un proceso está **vivo** en el milisegundo cero: el kernel lo mapeó, el puerto está abierto, `/health` responde 200. Pero todavía no leyó la configuración, no abrió el pool de conexiones, no resolvió DNS, no negoció TLS con sus dependencias y —en los runtimes con máquina virtual— no compiló una sola línea de su propio código a nativo.

Si el balanceador enruta por *liveness*, le manda tráfico a ese hueco. Y ese tráfico no falla con un error interesante: falla con 503 desde una instancia que **ninguna alerta considera caída**.

Hay una segunda mitad, y es la que casi nadie mide: en Java, .NET y Node el código **sigue siendo lento después** de que la instancia se declaró lista. La JVM arranca interpretando; recién a los miles de llamados C2 recompila con el perfil recolectado. La instancia que el autoescalador acaba de sumar atiende, pero atiende mal — y esa lentitud vuelve a disparar al autoescalador.

## Los dos remedios, y por qué son dos

1. **Enrutar por readiness, no por liveness.** `/health` responde «el proceso vive». `/ready` responde «puedo servir». Son dos preguntas distintas y en Kubernetes son dos sondas distintas por una razón.
2. **Tener el pool tibio antes de que llegue el tráfico.** Escalar cuando la métrica ya se disparó es escalar tarde por definición: la instancia nueva llega cuando el pico ya está.

El primero evita el 503. El segundo evita la espera. Hacen falta los dos.

## El experimento

Este caso **mide** la curva de calentamiento en vez de simularla. El trabajo por petición es un lazo entero puro, idéntico en los siete stacks, sin un solo `sleep`. Lo que se compara es `p99_first_100_ms` contra `p99_after_1000_ms`: qué hace ese runtime con el mismo código repetido mil veces.

La parte de I/O de la inicialización sí está modelada con un `sleep` de `io_ms` —esperar a la red no quema CPU, y fijarla es lo que vuelve comparables a los siete—. La parte de CPU es trabajo real.

<!-- nav-case-doc -->
---

**Caso 18 · Arranque en frío y retraso del autoescalado** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

**🗺️ Contexto** · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
