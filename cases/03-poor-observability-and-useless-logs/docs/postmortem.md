# 🚨 Postmortem — Caso 03: Incidente sin causa raiz identificable en 4 horas

**Severidad:** SEV-2 (operacion afectada con MTTR fuera de SLO)
**Estado:** Resuelto · Acciones implementadas en el lab
**Documento:** retrospectiva basada en el patron de incidente que motiva este caso

> Este postmortem es **una reconstruccion narrativa del incidente** que justifica la existencia del caso `03`. No documenta un incidente real de produccion — documenta el **patron operacional** que el lab reproduce y resuelve, en formato de postmortem real para evaluacion ejecutiva.

---

## 📝 Resumen

Errores 500 esporadicos en checkout. Sin correlation_id, sin contexto en logs, sin metricas por endpoint.

**Blast radius:** 240 min (el problema en si fue 15 min, el resto fue diagnosticar a ciegas).

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

Logs en `println` sin id, sin payload, sin timestamp util. Cuando algo falla, no hay forma de seguir el rastro de una request especifica entre N concurrentes.

Causas estructurales contribuyentes documentadas en [`root-causes.md`](root-causes.md).

---

## ✅ Lo que funciono

- El bug eventualmente se encontro (race condition en sesion)
- El equipo escribio postmortem el mismo dia

---

## ❌ Lo que no funciono

- MTTR de 4 h cuando el problema real era trivial
- No existia correlation_id end-to-end
- Sin estructura JSON, los logs no eran agrupables

---

## 🛠️ Action items implementados en el lab

- Implementar `checkout-observable` con correlation_id propagado
- Logs JSON estructurados con `event`, `level`, `correlation_id`, payload relevante
- Endpoint `/logs` que devuelve los ultimos N para audit en vivo
- Alerta cuando MTTR > 60 min sin correlation_id presente

Trade-offs de cada accion documentados en [`trade-offs.md`](trade-offs.md).

---

## 📊 Antes / Despues (metrica clave)

MTTR para mismo tipo de bug: 4 h -> 18 min · correlation_id coverage: 0% -> 100%

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
