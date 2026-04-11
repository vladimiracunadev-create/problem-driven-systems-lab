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

El bus-factor y el conocimiento silencioso son factores operativos abstractos con un impacto tangible en la sintaxis y ejecución de hilos en PHP bajo presión.

*   **Sintaxis Tribal (`legacy`):** Representa código que depende de convenciones implícitas y tipado débil. Al intentar mutar estructuras de datos profundas sin validación asertiva (ej: `$opaqueData['config']['system'][2]`), PHP dispara un **`ErrorException`** de tipo "Undefined array key" si el payload de entrada es asimétrico. En este modo, el script carece de control de errores granular, provocando una detención inmediata del hilo con **HTTP 500**, lo que imposibilita la resolución del incidente sin intervención manual del experto original.
*   **Aseguramiento y Tipado Fuerte (`distributed`):** Implementa el uso de **`declare(strict_types=1)`** y validadores de esquema defensivos. Utiliza mecanismos del lenguaje como el **Null Coalescing Operator (`??`)** y `isset()` para garantizar que el acceso a propiedades sea determinista: `$active = $data['system'][2]['is_active'] ?? false`. Este enfoque algorítmico permite que el sistema se degrade a un `safe_fallback` en lugar de colapsar, distribuyendo la capacidad de resolución entre cualquier operador mediante lógica de código que se auto-documenta y protege.

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
