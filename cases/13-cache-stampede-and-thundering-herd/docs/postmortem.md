# 🚨 Postmortem — Caso 13: la base cae 94 segundos a las 03:00 sin que suba el tráfico

**Severidad:** SEV-1 (indisponibilidad total del origen)
**Estado:** Resuelto · Acciones implementadas en el lab
**Documento:** retrospectiva basada en el patrón de incidente que motiva este caso

> Este postmortem es **una reconstrucción narrativa del incidente** que justifica la existencia del caso `13`. No documenta un incidente real de producción — documenta el **patrón operacional** que el lab reproduce y resuelve, en formato de postmortem real para evaluación ejecutiva.

---

## 📝 Resumen

El endpoint de resumen de cuenta se sirve desde cache con TTL de 6 horas. Un deploy a las 21:00 vació la cache; las claves se repoblaron entre 21:00 y 21:05 y quedaron con vencimiento entre 03:00 y 03:05. A las 03:00:12 vencieron ~1.400 claves calientes. Los 2.100 requests que las estaban usando en ese momento fueron todos al origen.

La base agotó su pool de conexiones en 3 segundos y dejó de responder durante 94 segundos, hasta que la cache volvió a llenarse.

**Blast radius:** 94 segundos de indisponibilidad total del origen; 2.100 recálculos idénticos para producir 1.400 valores distintos.

---

## 🕒 Timeline

| Hora | Evento |
|---|---|
| 21:00 | Deploy rutinario. La cache se invalida entera. |
| 21:00–21:05 | La cache se repuebla con tráfico normal. Todas las claves reciben el mismo TTL fijo de 6 h. |
| 03:00:12 | Vencen ~1.400 claves calientes en la misma ventana de 3 segundos. |
| 03:00:12 | 2.100 requests en vuelo encuentran el hueco. Ninguno sabe de los otros. |
| 03:00:15 | El pool de conexiones del origen se agota. Empiezan los timeouts. |
| 03:00:18 | Los clientes reintentan. La carga sobre el origen sube, no baja. |
| 03:01:46 | La cache termina de repoblarse. El origen se recupera solo. |
| 03:05 | La alerta de disponibilidad se cierra. Nadie entiende qué pasó: el tráfico fue normal toda la noche. |

---

## 🎯 Causa raíz

Tres causas que se necesitan mutuamente. Ninguna sola habría producido el incidente:

1. **Sin single-flight.** El código era `v = cache.get(k); if (v == null) { v = origin(k); cache.set(k, v); }`. Correcto para un llamador, catastrófico para 2.100.
2. **TTL fijo sin jitter.** El deploy definió, seis horas antes y sin que nadie lo notara, el segundo exacto de la caída.
3. **Sin soft TTL.** No existía el estado «viejo pero servible», así que no había forma de responder sin ir al origen.

El punto incómodo: **la cache funcionaba**. El hit rate esa noche fue 99,3%. El incidente vivió entero en el 0,7% restante.

---

## ✅ Lo que funcionó

- El origen se recuperó solo, sin intervención. La estampida es autolimitada: termina cuando la cache se llena.
- Las alertas de disponibilidad dispararon en 40 segundos.

## ❌ Lo que no funcionó

- El dashboard de cache no tenía ninguna métrica de recálculos concurrentes. Solo hit rate.
- El primer intento de arreglo — agregar un lock — no cambió nada medible. Faltaba el double check adentro: el lock ordenó la estampida en fila, pero el origen recibió las mismas 2.100 consultas.
- Los reintentos automáticos del cliente **empeoraron** la carga durante la ventana.

---

## 🔧 Acciones

| Acción | Estado |
|---|---|
| Single-flight por clave con double check dentro del vuelo | ✅ Implementado (`/cache-singleflight` en los 7 stacks) |
| TTL con jitter `base ± 25%` | ✅ Implementado |
| Soft TTL al 60% del hard, con refresco por un solo llamador | ✅ Implementado |
| Métricas `origin_computations` y `stampede_depth` expuestas | ✅ Implementado (`/diagnostics/summary`) |
| Backoff exponencial en el reintento del cliente | ⛔ Fuera del alcance de este caso — se cubre en el [caso 04](../../04-timeout-chain-and-retry-storms/README.md) |

---

## 📚 Lección

> Un lock sin double check no evita una estampida: la pone en fila.

Y la que duele más: el hit rate de cache **no mide** este problema. Un sistema con 99,9% de hit rate puede caerse por el 0,1%, porque lo que importa no es la proporción de aciertos sino cuántos fallos coinciden en el tiempo.

<!-- nav-case-doc -->
---

**Caso 13 · Cache stampede y thundering herd** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · **🚨 Postmortem**
<!-- /nav-case-doc -->
