# 👤 Caso 12 — Python 3.12 con comparacion legacy vs conocimiento distribuido

> Implementacion operativa del caso 12 para contrastar dependencia de conocimiento tribal contra una postura mas resiliente.

## 🎯 Que resuelve

Modela incidentes donde importa quien sabe que:

- `incident-legacy` depende demasiado de una persona o de un procedimiento no compartido;
- `incident-distributed` combina runbooks, backups y drills para resolver sin el heroe;
- `share-knowledge` permite subir la madurez del dominio y ver el cambio real en `readiness_score`.

## 💼 Por que importa

Este caso deja visible que la continuidad operacional tambien es una propiedad del conocimiento. Un sistema "estable" puede seguir siendo fragil si solo una persona sabe como operarlo bajo presion. El bus factor no es una metrica de equipo: es un riesgo operacional medible.

## 🔬 Analisis Tecnico de la Implementacion (Python)

El conocimiento silencioso y la dependencia tribal tienen un impacto tangible en la resolucion de incidentes que se puede modelar con logica determinista.

- **Sintaxis Tribal (`legacy`):** La funcion `run_legacy_incident()` modela la dependencia del heroe con una variable booleana `hero_available = random.random() > 0.35`, que simula que el experto no siempre esta disponible. Cuando `hero_available` es `False`, el codigo intenta acceder a estructuras de conocimiento implicitas que no existen (`opaque_config.get("system_v2_override")` retorna `None`) y cae en un bloque de escalacion: `escalation=True`, `mttr_minutes` alto, `resolution_path: "manual_intervention"`. No hay fallback, no hay runbook, no hay segunda persona. El incidente queda bloqueado hasta que el heroe responde.

- **Aseguramiento Distribuido (`distributed`):** Implementa un `readiness_score` calculado con `runbook_score * 0.45 + (backup_people + 1) * 18 + drill_score * 0.25` que refleja la madurez real del dominio. Cuando el score es suficientemente alto, la funcion puede resolver el incidente sin el heroe usando `runbook_steps` documentados y personas de backup registradas en `state["knowledge"]["backup_people"]`. El acceso a cualquier propiedad de configuracion usa el operador de fusion `config.get("key") or safe_default` para garantizar que el codigo se degrade a un `safe_fallback` en lugar de colapsar con `KeyError`. Cada llamada a `POST /share-knowledge` incrementa `runbook_score`, `backup_people` o `drill_score`, reduciendo el `mttr_minutes` esperado de forma determinista.

## 🧱 Servicio

- `app` → API Python 3.12 con dominios operativos, puntajes de runbook, backups, drills y simulacion de incidentes.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `842`.

## 🔎 Endpoints

```bash
curl http://localhost:842/
curl http://localhost:842/health
curl "http://localhost:842/incident-legacy?severity=high&service=payments"
curl "http://localhost:842/incident-distributed?severity=high&service=payments"
curl -X POST "http://localhost:842/share-knowledge?type=runbook&detail=payments-runbook-v2"
curl http://localhost:842/knowledge/state
curl "http://localhost:842/incidents?limit=10"
curl http://localhost:842/diagnostics/summary
curl http://localhost:842/metrics
curl http://localhost:842/metrics-prometheus
curl http://localhost:842/reset-lab
```

## 🧪 Escenarios utiles

- `severity=high&service=payments` → revela el bus factor real en legacy.
- Ejecutar `POST /share-knowledge?type=runbook` varias veces y ver como baja `mttr_minutes` en distributed.
- `type=backup_person` → agregar personas al equipo y observar la reduccion de `escalation_rate`.
- `type=drill` → simular un simulacro y ver como sube `drill_score` en `/knowledge/state`.

## 🧭 Que observar

- como cambia `mttr_minutes` entre legacy y distributed con el mismo escenario;
- cuantas veces `hero_available: false` provoca `escalation: true` en legacy;
- si sube `handoff_quality` y baja `hero_dependency_rate` al compartir conocimiento;
- como mejora `readiness_score` en `/knowledge/state` despues de runbooks, backups y drills.

## ⚖️ Nota de honestidad

No sustituye una organizacion real, on-call ni gestion formal de conocimiento. Si reproduce el riesgo operativo importante: depender de memoria tribal versus construir continuidad compartida con evidencia observable en `readiness_score` y `mttr_minutes`.
