# 🚨 Postmortem — Caso 02: Round-trips por request de ordenes inflados x10

**Severidad:** SEV-3 (latencia visible, sin caida)
**Estado:** Resuelto · Acciones implementadas en el lab
**Documento:** retrospectiva basada en el patron de incidente que motiva este caso

> Este postmortem es **una reconstruccion narrativa del incidente** que justifica la existencia del caso `02`. No documenta un incidente real de produccion — documenta el **patron operacional** que el lab reproduce y resuelve, en formato de postmortem real para evaluacion ejecutiva.

---

## 📝 Resumen

Ticket de soporte: la pagina de ordenes pega lenta. Profiling muestra 50+ queries por request en pico.

**Blast radius:** 60 min en diagnosticar.

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

Lectura de ordenes hace `SELECT * FROM items WHERE order_id=?` dentro del loop. Patron N+1 clasico amplificado por la cantidad de items por order.

Causas estructurales contribuyentes documentadas en [`root-causes.md`](root-causes.md).

---

## ✅ Lo que funciono

- El profiler de la DB lo marco apenas se conecto
- El equipo identifico el patron sin ayuda externa

---

## ❌ Lo que no funciono

- El patron N+1 existia hace 8 meses y nadie lo midio en CI
- No habia query budget como SLO

---

## 🛠️ Action items implementados en el lab

- Reescribir como `IN(...)` batch + ensamblado en codigo (`orders-optimized`)
- Agregar query budget en CI (max queries por endpoint)
- Documentar el patron en `comparison.md` para los 4 stacks

Trade-offs de cada accion documentados en [`trade-offs.md`](trade-offs.md).

---

## 📊 Antes / Despues (metrica clave)

queries por request: 41 -> 2 · p95: 850 ms -> 95 ms · DB CPU peak: 78% -> 12%

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
