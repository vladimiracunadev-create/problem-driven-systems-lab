# ⚖️ Trade-offs

## El pool tibio cuesta dinero todo el tiempo

Capacidad ociosa es capacidad pagada. El intercambio es explícito: se paga infraestructura constante para no pagar latencia durante los escalados. Vale la pena cuando los picos son frecuentes o caros; no vale cuando el tráfico es plano.

## Calentar alarga el arranque

Ejercitar los caminos calientes antes de anunciarse lista mejora la primera petición real y **empeora** el tiempo hasta estar disponible. En un escalado de emergencia, eso puede ser exactamente al revés de lo que hace falta.

## AOT elimina la curva y se lleva el pico

GraalVM `native-image` y `PublishAot` borran el calentamiento — y también el JIT que, después de miles de peticiones, produce código **mejor** que el AOT porque tiene el perfil real de ejecución. Para un servicio de larga vida, la JVM caliente puede ganarle a su propia versión nativa.

## Readiness estricto reduce capacidad justo cuando falta

Si `/ready` exige que todas las dependencias respondan, una dependencia lenta saca de rotación a instancias que podrían haber servido en modo degradado. El chequeo tiene que verificar lo que la instancia **necesita para servir**, no todo lo que puede tocar.

## Escalar por métricas adelantadas es escalar con menos certeza

La profundidad de cola avisa antes que la CPU, y también se equivoca antes. Reaccionar temprano significa reaccionar a veces a picos que no eran.

## Menos dependencias arranca más rápido y se escribe más lento

Recortar el grafo de `require` o de `import` acelera el arranque de forma medible. También significa escribir a mano lo que una biblioteca resolvía.

<!-- nav-case-doc -->
---

**Caso 18 · Arranque en frío y retraso del autoescalado** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · **⚖️ Trade-offs** · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
