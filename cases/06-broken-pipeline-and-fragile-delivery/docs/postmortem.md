# 🚨 Postmortem — Caso 06: Deploy a prod con secret drift deja staging y prod en estados distintos

**Severidad:** SEV-2 (deploy fallido, rollback manual)
**Estado:** Resuelto · Acciones implementadas en el lab
**Documento:** retrospectiva basada en el patron de incidente que motiva este caso

> Este postmortem es **una reconstruccion narrativa del incidente** que justifica la existencia del caso `06`. No documenta un incidente real de produccion — documenta el **patron operacional** que el lab reproduce y resuelve, en formato de postmortem real para evaluacion ejecutiva.

---

## 📝 Resumen

Deploy a prod corre el pipeline normal. Smoke test pasa parcial (60%). Por inercia el equipo lo deja en prod porque smoke parcial es mejor que rollback. Resultado: dos servicios usando configs distintas.

**Blast radius:** 180 min de inconsistencia entre ambientes + 45 min de rollback manual.

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

Pipeline aplica deploy directo sin preflight comparativo entre ambientes. No hay smoke test bloqueante; no hay rollback automatico. Drift de secrets entre staging y prod paso desapercibido.

Causas estructurales contribuyentes documentadas en [`root-causes.md`](root-causes.md).

---

## ✅ Lo que funciono

- El equipo reconocio el problema en la retrospectiva en lugar de defenderlo
- El rollback eventualmente funciono

---

## ❌ Lo que no funciono

- No habia rollback automatico — fue manual, en pleno incidente, a las 21:30
- Smoke test no era bloqueante; 'parcial OK' era ambiguo

---

## 🛠️ Action items implementados en el lab

- Implementar `deploy-controlled` con preflight + smoke + rollback automatico
- Smoke test bloqueante (1 falla = rollback)
- Comparacion automatica de config entre staging y prod en preflight
- Runbook cuando smoke test es parcial = ROLLBACK siempre

Trade-offs de cada accion documentados en [`trade-offs.md`](trade-offs.md).

---

## 📊 Antes / Despues (metrica clave)

deploys con drift sin detectar: 3/mes -> 0 · MTTR rollback: 45 min manual -> <30 s automatico

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
