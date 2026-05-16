# 🚨 Postmortem — Caso 12: Caida en pre-prod de 6 horas porque la persona que sabia estaba de vacaciones

**Severidad:** SEV-2 (no afecto a clientes pero bloqueo equipo)
**Estado:** Resuelto · Acciones implementadas en el lab
**Documento:** retrospectiva basada en el patron de incidente que motiva este caso

> Este postmortem es **una reconstruccion narrativa del incidente** que justifica la existencia del caso `12`. No documenta un incidente real de produccion — documenta el **patron operacional** que el lab reproduce y resuelve, en formato de postmortem real para evaluacion ejecutiva.

---

## 📝 Resumen

Pre-prod cae viernes 17:00. Alice (unica persona que sabe el procedimiento) esta de vacaciones por 2 semanas. Equipo no encuentra runbook actualizado. Lunes recien se logra resolver, pero el incidente se conoce ya hace 3 dias.

**Blast radius:** 6 horas de pre-prod caida + bloqueo de releases por 3 dias.

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

Conocimiento del procedimiento de `db_failover` vive solo en la cabeza de alice. No hay runbook escrito. El codigo de incident-legacy hace acceso ciego asumiendo que owner siempre esta — cuando no lo esta, NPE inmediato.

Causas estructurales contribuyentes documentadas en [`root-causes.md`](root-causes.md).

---

## ✅ Lo que funciono

- Cuando alice volvio, resolvio en 15 min y escribio el runbook que faltaba
- El equipo empezo a hacer pairing en oncall sistematico

---

## ❌ Lo que no funciono

- Bus factor = 1 estaba documentado como riesgo pero nunca se ataco
- Vacaciones de personal clave deberian disparar revision de coverage

---

## 🛠️ Action items implementados en el lab

- Implementar `incident-distributed` con `Optional<Owner>` defensivo
- `/share-knowledge` para subir coverage progresivamente
- Pairing obligatorio en oncall (2 personas por shift)
- Revisar bus_factor por modulo cada quarter como parte de retro

Trade-offs de cada accion documentados en [`trade-offs.md`](trade-offs.md).

---

## 📊 Antes / Despues (metrica clave)

mttr para incidente similar con owner ausente: 6 h crashed -> 35 min handled · bus_factor para `db_failover`: 1 -> 4

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
