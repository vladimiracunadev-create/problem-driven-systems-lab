# 🚨 Postmortem — Caso 11: Reporte mensual de cierre tumba el checkout durante 47 min

**Severidad:** SEV-1 (operacion degradada en horario pico)
**Estado:** Resuelto · Acciones implementadas en el lab
**Documento:** retrospectiva basada en el patron de incidente que motiva este caso

> Este postmortem es **una reconstruccion narrativa del incidente** que justifica la existencia del caso `11`. No documenta un incidente real de produccion — documenta el **patron operacional** que el lab reproduce y resuelve, en formato de postmortem real para evaluacion ejecutiva.

---

## 📝 Resumen

Reporte de cierre mensual corre el lunes 09:00. Es heavy: scan completo de orders + groupby agregado. Mientras corre, el thread pool principal queda saturado. /order-write entra en queue. Checkout devuelve timeouts a clientes reales.

**Blast radius:** 47 min de checkout degradado.

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

Reporting y operacion comparten el pool principal del HTTP server. El reporte legacy ejecuta CPU sincronico durante 4-6 min y ocupa los 4 threads. Cualquier request operativo entra en queue esperando.

Causas estructurales contribuyentes documentadas en [`root-causes.md`](root-causes.md).

---

## ✅ Lo que funciono

- Las metricas `getActiveCount` mostraron el problema en 90 segundos
- El equipo identifico la solucion (pool dedicado) en la primera retro

---

## ❌ Lo que no funciono

- Reporting nunca debio compartir pool con operacion
- Sin SLO operacional por horario, nadie alerto a tiempo
- El reporte se ejecuto 4 veces antes de hacer el fix porque no habia stop-the-world plan

---

## 🛠️ Action items implementados en el lab

- Implementar `report-isolated` con `ExecutorService` dedicado (2 threads)
- `/order-write` mide `degraded` flag basado en latencia esperada
- Endpoint `/activity` expone `mainPool.getActiveCount` y `getQueue` en vivo
- Alerta de `mainPool.getActiveCount > 75%` durante > 30s

Trade-offs de cada accion documentados en [`trade-offs.md`](trade-offs.md).

---

## 📊 Antes / Despues (metrica clave)

tiempo /order-write degradado durante reporte: 47 min -> 0 · main_pool active durante reporte: 4/4 (saturado) -> 1/4 (libre)

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
