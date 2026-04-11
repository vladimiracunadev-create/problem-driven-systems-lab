# 👤 Caso 12 - PHP 8.3 con comparación legacy vs conocimiento distribuido

> Implementación operativa del caso 12 para contrastar dependencia de conocimiento tribal contra una postura más resiliente.

## 🎯 Qué resuelve

Modela incidentes donde importa quién sabe qué:

- `incident-legacy` depende demasiado de una persona o de un procedimiento no compartido;
- `incident-distributed` combina runbooks, backups y drills;
- `share-knowledge` permite subir madurez del dominio para ver el cambio real.

## 💻 Interfaz Visual Nativa

Al abrir la ruta raíz en tu navegador (`Accept: text/html`), este caso inyecta automáticamente un **Dashboard visual interactivo** renderizado en Vanilla JS/CSS. Esto permite observar las métricas y efectos simulados en tiempo real sin perder la capacidad de responder a consultas JSON de CLI o Postman.

## 💼 Por qué importa

Este caso deja visible que la continuidad operacional también es una propiedad del conocimiento. Un sistema “estable” puede seguir siendo frágil si solo una persona sabe cómo operarlo bajo presión.

## 🔬 Análisis Técnico de la Implementación (PHP)

El bus-factor y el conocimiento silencioso son factores medibles (Risk Scores) independientemente de qué lenguaje se escoge para desarrollar el software.

*   **Factor "Héroe" (`legacy`):** Cuando la persona crítica no está presente (`owner_absent`) o se cae de noche (`night_shift`), el código obliga un volcado a HTTP 503 / 502 pre-configurado donde el _Mean Time to Recovery_ (MTTR) escala por ineficiencia de *Handoff* (>95 mins) forzando interrupciones operativas costosas en el ecosistema.
*   **Distribución del Conocimiento (`distributed`):** Emplea una función polinómica (`readinessScore(...)`) calculando ponderaciones a tiempo real desde arrays nativos en base al `runbook_score` (`*0.45`), `drill_score` (`*0.25`) y backups de talento (`*18`). Al golpear este endpoint bajo incidentes, el algoritmo amolda dinámicamente un MTTR degradado o salva la request con un HTTP 200 transparente siempre y cuando evalúe que la madurez de la información excede los límites exigidos, mitigando sistemáticamente bloqueos en cascada en caso de la ausencia del líder técnico en PHP.

## 🧱 Servicio

- `app` -> API PHP 8.3 con dominios operativos, puntajes de runbook, backups, drills y simulación de incidentes.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## 🔎 Endpoints

```bash
curl http://localhost:8112/
curl http://localhost:8112/health
curl "http://localhost:8112/incident-legacy?scenario=owner_absent&domain=deployments"
curl "http://localhost:8112/incident-distributed?scenario=owner_absent&domain=deployments"
curl "http://localhost:8112/share-knowledge?domain=deployments&activity=runbook"
curl http://localhost:8112/knowledge/state
curl http://localhost:8112/incidents?limit=10
curl http://localhost:8112/diagnostics/summary
curl http://localhost:8112/metrics
curl http://localhost:8112/metrics-prometheus
curl http://localhost:8112/reset-lab
```

## 🧪 Escenarios útiles

- `owner_absent` -> revela el bus factor real.
- `night_shift` -> muestra la diferencia entre memoria tribal y operación preparada.
- `recent_change` -> enfatiza contexto compartido después de cambios recientes.
- `tribal_script` -> hace visible el riesgo de procedimientos críticos fuera de runbooks.

## 🧭 Qué observar

- cómo cambia el `mttr_min` entre ambos enfoques;
- cuántos bloqueos aparecen cuando falta la persona clave;
- si sube `handoff_quality` al compartir conocimiento;
- cómo mejora el dominio después de `runbook`, `pairing` o `drill`.

## ⚖️ Nota de honestidad

No sustituye una organización real, on-call ni gestión formal de conocimiento. Sí reproduce el riesgo operativo importante: depender de memoria tribal versus construir continuidad compartida.
