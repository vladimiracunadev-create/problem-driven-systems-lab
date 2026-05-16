# 🚨 Postmortem — Caso 08: Extraccion de pricing rompe checkout, partners y backoffice al unisono

**Severidad:** SEV-1 (caida transversal de 38 min)
**Estado:** Resuelto · Acciones implementadas en el lab
**Documento:** retrospectiva basada en el patron de incidente que motiva este caso

> Este postmortem es **una reconstruccion narrativa del incidente** que justifica la existencia del caso `08`. No documenta un incidente real de produccion — documenta el **patron operacional** que el lab reproduce y resuelve, en formato de postmortem real para evaluacion ejecutiva.

---

## 📝 Resumen

Decision de extraer modulo de pricing del monolito. Plan: big-bang el viernes 22:00 (ventana baja). Resultado: el nuevo modulo espera `{price, currency}` pero los 3 consumers seguian mandando `{cost_usd}`. 3 servicios criticos rotos al mismo tiempo.

**Blast radius:** 38 min de caida transversal + 6 h hasta primer cutover correcto.

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

El team de pricing libero el nuevo contrato. Los consumers no fueron coordinados a tiempo. Sin proxy de compatibilidad, el primer request ya rompio.

Causas estructurales contribuyentes documentadas en [`root-causes.md`](root-causes.md).

---

## ✅ Lo que funciono

- Rollback al monolito viejo funciono en 12 min (afortunadamente lo dejamos disponible)
- Postmortem se hizo el mismo fin de semana

---

## ❌ Lo que no funciono

- Big-bang sin compat layer es siempre riesgo alto
- Sin shadow mode previo, no pudimos detectar el problema en pre-prod

---

## 🛠️ Action items implementados en el lab

- Implementar `pricing-compatible` con `Function<Old, New>` como proxy de traduccion
- Event bus que publica cada cutover_done por consumer
- Shadow mode obligatorio antes de cutover real (1 semana minimo)
- Plan de extraccion ahora siempre incluye fase compat antes que fase cutover

Trade-offs de cada accion documentados en [`trade-offs.md`](trade-offs.md).

---

## 📊 Antes / Despues (metrica clave)

consumers rotos al lanzar cambio: 3 -> 0 · MTTR de re-extraccion: 38 min caida -> 0 (zero downtime)

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
