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

El bus-factor y el conocimiento silencioso son factores operativos abstractos, pero su efecto es tangible y físico a nivel de sintaxis cuando el código no está respaldado ("Código Tribal").

*   **Sintaxis Tribal (`legacy`):** Un equipo usa convenciones mágicas o dependencias no escritas. Al no estar presente el creador ("`owner_absent`"), el script asume tipos de arrays fijos e intenta mutar llaves anidadas (ej. `$opaqueData['config']['system']...`). Al encontrar el payload incompleto por el nuevo contexto operativo, PHP no halla la llave profunda, detonando un **Error fatal de Undefined Key** e interrumpiendo el flujo. El sistema cae con HTTP 500 puro.
*   **Distribución y Refactorización (`distributed`):** Emplea código robusto e inmersivo ("Runbook ejecutable" integrado). Implementé validadores usando mecanismos asintóticos defensivos (como el Operador *Null coalescing* `??`) que aseguran la existencia del índice antes del consumo. Si bajo los mismos escenarios oscuros el paquete carece de datos y el creador falta, el sistema decae a `safe_fallback`, previniendo que el proceso colapse y aislando el riesgo nativamente.

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
