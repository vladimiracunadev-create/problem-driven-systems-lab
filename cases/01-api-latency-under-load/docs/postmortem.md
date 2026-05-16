# 🚨 Postmortem — Caso 01: API de reportes inestable bajo concurrencia operativa

**Severidad:** SEV-2 (operacion degradada, sin caida total)
**Estado:** Resuelto · Acciones implementadas en el lab
**Documento:** retrospectiva basada en el patron de incidente que motiva este caso

> Este postmortem es **una reconstruccion narrativa del incidente** que justifica la existencia del caso `01`. No documenta un incidente real de produccion — documenta el **patron operacional** que el lab reproduce y resuelve, en formato de postmortem real para evaluacion ejecutiva.

---

## 📝 Resumen

Lunes 09:18 — usuarios de reporting empiezan a ver p95 > 4s. El worker de refresh esta corriendo el batch normal de Monday recap.

**Blast radius:** 120 min.

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

Reporting consulta directo el primario; el worker de refresh corre en paralelo. Ambos compiten por DB durante la ventana de mas trafico operativo. Filtros no sargables hacen scan completo cada request.

Causas estructurales contribuyentes documentadas en [`root-causes.md`](root-causes.md).

---

## ✅ Lo que funciono

- Las metricas existentes (Prometheus) detectaron la presion en 5 min
- El worker no genero data inconsistente — solo lento

---

## ❌ Lo que no funciono

- No habia alerta de p95 > 1s en reportes (solo en checkout)
- El runbook decia 'reiniciar worker' como primera accion — no era la causa

---

## 🛠️ Action items implementados en el lab

- Crear `report-optimized` con tabla resumen mantenida por el worker
- Agregar indice covering en `orders(region, created_at)`
- Alerta de p95 reportes > 1s con paginer
- Documentar en runbook que reiniciar el worker NO ayuda

Trade-offs de cada accion documentados en [`trade-offs.md`](trade-offs.md).

---

## 📊 Antes / Despues (metrica clave)

p95 legacy: ~4.2 s · p95 optimized: ~280 ms · queries promedio por request: 14 -> 2

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
