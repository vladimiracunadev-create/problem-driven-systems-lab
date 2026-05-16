# 🚨 Postmortem — Caso 07: Cambio en modulo de billing rompe pricing, inventory y reporting

**Severidad:** SEV-2 (3 modulos afectados, ventana de 4 h)
**Estado:** Resuelto · Acciones implementadas en el lab
**Documento:** retrospectiva basada en el patron de incidente que motiva este caso

> Este postmortem es **una reconstruccion narrativa del incidente** que justifica la existencia del caso `07`. No documenta un incidente real de produccion — documenta el **patron operacional** que el lab reproduce y resuelve, en formato de postmortem real para evaluacion ejecutiva.

---

## 📝 Resumen

Cambio en `shared_schema` requerido por billing. Pasa CR. Se aplica. Se descubre que pricing y reporting tambien consumen ese schema con expectativas distintas. Inventory tenia un bug que dependia del comportamiento viejo.

**Blast radius:** 240 min (incluyendo retroceder y aplicar fix individual a 3 modulos).

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

Monolito con shared_schema sin contratos por consumer. El cambio era valido para billing pero invalido para los demas. No habia routing — todos usaban el mismo path.

Causas estructurales contribuyentes documentadas en [`root-causes.md`](root-causes.md).

---

## ✅ Lo que funciono

- El cambio se detecto antes de afectar revenue (caught en pre-prod parcial)
- El equipo decidio invertir en strangler en lugar de seguir parchando

---

## ❌ Lo que no funciono

- No habia routing por consumer
- blast_radius_score era invisible — nadie lo midio antes del cambio
- Tests por modulo, sin tests de integracion cross-modulo

---

## 🛠️ Action items implementados en el lab

- Implementar `change-strangler` con routing table por consumer
- Medir `blast_radius_score` antes de cualquier change request
- Migracion gradual: billing primero (mas critico), orders despues
- Contract tests entre modulos antes de aplicar cualquier cambio en shared_schema

Trade-offs de cada accion documentados en [`trade-offs.md`](trade-offs.md).

---

## 📊 Antes / Despues (metrica clave)

blast_radius para cambio de billing: 4 modulos -> 1 modulo · risk_score: 8 -> 1

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
