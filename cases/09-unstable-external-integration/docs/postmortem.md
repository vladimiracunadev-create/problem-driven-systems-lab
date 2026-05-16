# 🚨 Postmortem — Caso 09: Provider externo cambia schema sin aviso y rompe catalogo durante 6 horas

**Severidad:** SEV-2 (catalogo degradado, no caido)
**Estado:** Resuelto · Acciones implementadas en el lab
**Documento:** retrospectiva basada en el patron de incidente que motiva este caso

> Este postmortem es **una reconstruccion narrativa del incidente** que justifica la existencia del caso `09`. No documenta un incidente real de produccion — documenta el **patron operacional** que el lab reproduce y resuelve, en formato de postmortem real para evaluacion ejecutiva.

---

## 📝 Resumen

Provider de catalogo aplica un cambio de schema (campo `costUsd` renombrado a `price`). Nuestro codigo asume el campo viejo. Catalogo deja de cargar productos correctamente. Tomo 6 h en identificar que el provider fue el origen.

**Blast radius:** 360 min (la mayoria diagnosticando algo que parecia bug interno).

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

Codigo asume que el contrato del provider es estable. Sin schema validation, sin cache de last-known-good, sin breaker. Cuando el provider cambia, somos rehenes de su decision.

Causas estructurales contribuyentes documentadas en [`root-causes.md`](root-causes.md).

---

## ✅ Lo que funciono

- Cuando se identifico al provider como causa, el fix tomo 30 min
- El equipo aprendio a no asumir contratos externos

---

## ❌ Lo que no funciono

- No tenemos influencia sobre el provider
- Sin cache, durante 6 h servimos data inconsistente o vacia
- Sin budget de cuota — un fix mal pensado pudo haber rate-limited el lab entero

---

## 🛠️ Action items implementados en el lab

- Implementar adapter `catalog-hardened` con schema mapping defensivo
- Snapshot cache con TTL para servir last-known-good cuando provider falla
- Semaphore como budget de cuota (5 req/window) para no agotar rate limit
- Breaker con CAS para no martillar al provider cuando ya se sabe que falla

Trade-offs de cada accion documentados en [`trade-offs.md`](trade-offs.md).

---

## 📊 Antes / Despues (metrica clave)

tiempo bajo cambio no anunciado del provider: 6 h degradado -> < 1 min (servido desde cache) · rate limit violations: 14/dia -> 0

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
