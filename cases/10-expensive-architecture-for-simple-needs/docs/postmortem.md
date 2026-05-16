# 🚨 Postmortem — Caso 10: Tiempo para agregar un boolean a una feature: 16 dias

**Severidad:** SEV-3 (no es urgencia operativa, pero costo acumulado alto)
**Estado:** Resuelto · Acciones implementadas en el lab
**Documento:** retrospectiva basada en el patron de incidente que motiva este caso

> Este postmortem es **una reconstruccion narrativa del incidente** que justifica la existencia del caso `10`. No documenta un incidente real de produccion — documenta el **patron operacional** que el lab reproduce y resuelve, en formato de postmortem real para evaluacion ejecutiva.

---

## 📝 Resumen

Producto pide agregar un boolean simple a una feature. La implementacion toca 5 microservicios (api gateway, auth, feature service, cache, metrics). Cada equipo coordina cuando puede. Total: 16 dias para 4 lineas de codigo reales.

**Blast radius:** 16 dias por feature simple.

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

Sobre-arquitectura por aspiracion futura. La feature original se construyo con 5 hops cuando un HashMap hubiera bastado. Cada hop = un service, un repo, un equipo, una review.

Causas estructurales contribuyentes documentadas en [`root-causes.md`](root-causes.md).

---

## ✅ Lo que funciono

- El producto eventualmente espero y la feature se libero
- Se hizo retro y se reconocio el problema

---

## ❌ Lo que no funciono

- Lead time de 16 dias para 4 lineas es senal estructural
- Estabamos pagando AWS para mantener 5 servicios que podrian ser 1
- Onboarding de nuevos developers tomaba semanas por la cantidad de servicios

---

## 🛠️ Action items implementados en el lab

- Implementar `feature-right-sized` como ejemplo: HashMap O(1), 1 service, 1 dia
- Registrar ADRs explicitos (`decisions`) justificando empezar simple
- Revisar cada microservicio actual: si aporta valor > su costo de mantenimiento, queda; si no, consolidar
- Politica nueva: agregar microservicio requiere ADR con costo/beneficio

Trade-offs de cada accion documentados en [`trade-offs.md`](trade-offs.md).

---

## 📊 Antes / Despues (metrica clave)

lead time feature simple: 16 dias -> 1 dia · cost mensual del flujo: USD 200 -> USD 3

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
