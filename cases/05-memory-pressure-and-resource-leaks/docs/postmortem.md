# 🚨 Postmortem — Caso 05: OOM kill cada 18 horas en produccion

**Severidad:** SEV-2 (reinicios programados, sin perdida de datos)
**Estado:** Resuelto · Acciones implementadas en el lab
**Documento:** retrospectiva basada en el patron de incidente que motiva este caso

> Este postmortem es **una reconstruccion narrativa del incidente** que justifica la existencia del caso `05`. No documenta un incidente real de produccion — documenta el **patron operacional** que el lab reproduce y resuelve, en formato de postmortem real para evaluacion ejecutiva.

---

## 📝 Resumen

Pod reiniciado por OOM. Grafica de memoria muestra crecimiento monotono lineal desde el ultimo deploy. Sin spikes, sin GC pressure visible — heap simplemente sube.

**Blast radius:** Mes y medio detectandolo (parecia normal que se reiniciara).

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

Cache de batch results en lista estatica de modulo. Cada request agregaba elementos; nada los liberaba. El GC funcionaba, pero las referencias seguian alcanzables desde la raiz estatica.

Causas estructurales contribuyentes documentadas en [`root-causes.md`](root-causes.md).

---

## ✅ Lo que funciono

- El SRE marco el patron al ver el grafico de 7 dias
- El equipo agrego soak test antes de hacer el fix

---

## ❌ Lo que no funciono

- Nadie corria load test prolongado en CI (los tests duraban 60s)
- El bug existio en produccion ~6 semanas sin ser detectado

---

## 🛠️ Action items implementados en el lab

- Reescribir como cache acotado con eviction (`batch-optimized`)
- Soak test en CI de 30 min antes de release
- Alerta de heap growth lineal sostenido (no solo de OOM)
- Documentar el patron leak en lenguaje con GC en docs/

Trade-offs de cada accion documentados en [`trade-offs.md`](trade-offs.md).

---

## 📊 Antes / Despues (metrica clave)

heap retained tras 1 h de load: 1.2 GB -> 64 MB (cap fijo) · OOMs/semana: 9 -> 0

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
