# 🚨 Postmortem — Caso 04: Retry storm tumba al proveedor de pricing y se propaga

**Severidad:** SEV-1 (caida total de checkout durante 22 min)
**Estado:** Resuelto · Acciones implementadas en el lab
**Documento:** retrospectiva basada en el patron de incidente que motiva este caso

> Este postmortem es **una reconstruccion narrativa del incidente** que justifica la existencia del caso `04`. No documenta un incidente real de produccion — documenta el **patron operacional** que el lab reproduce y resuelve, en formato de postmortem real para evaluacion ejecutiva.

---

## 📝 Resumen

Proveedor externo sufre degradacion. Nuestro servicio reintenta 5 veces por cada request en cola. M requests x 5 retries x 800 ms = saturacion masiva.

**Blast radius:** 22 min de caida + 90 min de recuperacion.

---

## 🕒 Timeline (resumido)

| Hora | Evento |
|---|---|
| T+00m | Sintoma reportado: ver `docs/symptoms.md` para senales tipicas |
| T+05m | Equipo on-call confirma el patron |
| T+15m | Diagnostico inicial — herramientas activadas: ver `docs/diagnosis.md` |
| T+N min | Mitigacion aplicada — opciones evaluadas en `docs/solution-options.md` |
| Post | Postmortem, action items, fix de mediano plazo (este documento) |

---

## 🎯 Causa raiz

Codigo legacy hace `for (i in 1..5) callProvider()` sin backoff ni breaker. Bajo carga concurrente con M requests, generamos 5M roundtrips al provider degradado, amplificando su problema y matando nuestro pool de threads.

Causas estructurales contribuyentes documentadas en [`root-causes.md`](root-causes.md).

---

## ✅ Lo que funciono

- El provider tuvo su propio postmortem y reconocio el rol que jugamos
- El equipo identifico el patron en 10 min

---

## ❌ Lo que no funciono

- Sin circuit breaker, sin backoff, sin fallback cacheado
- Multiplicamos por 5 el load del proveedor que ya estaba caido

---

## 🛠️ Action items implementados en el lab

- Implementar circuit breaker en estado persistente (`quote-resilient`)
- Timeout de 300 ms cooperativo + backoff exponencial
- Fallback cacheado con TTL para mantener pricing en degradado
- Alerta de breaker_state == open por mas de 5 min

Trade-offs de cada accion documentados en [`trade-offs.md`](trade-offs.md).

---

## 📊 Antes / Despues (metrica clave)

tiempo bajo provider degradado: 22 min caida -> 0 (fallback cacheado) · retries totales en mismo escenario: 5M -> 3

---

## 🔗 Documentos relacionados

- [`../README.md`](../README.md) — vista ejecutiva del caso
- [`context.md`](context.md) — por que este patron aparece
- [`symptoms.md`](symptoms.md) — senales tipicas
- [`diagnosis.md`](diagnosis.md) — estrategia de diagnostico
- [`root-causes.md`](root-causes.md) — causas estructurales frecuentes
- [`solution-options.md`](solution-options.md) — opciones evaluadas
- [`trade-offs.md`](trade-offs.md) — costos de cada opcion
- [`business-value.md`](business-value.md) — impacto en negocio
- [`../comparison.md`](../comparison.md) — comparativa multi-stack del fix

---

## 🧭 Para evaluador / reclutador

Este postmortem sirve como **vista de criterio operacional**: como se piensa un incidente, no solo como se resuelve. El [`../README.md`](../README.md) muestra el problema y la solucion; este documento muestra el **proceso de razonamiento** sobre el incidente.
