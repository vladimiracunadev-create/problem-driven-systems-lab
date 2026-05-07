# 🧠 Caso 05 - PHP 8.3 con presión de memoria comparada

> Implementación operativa del caso 05 para mostrar cómo una fuga silenciosa degrada un proceso largo frente a una variante que controla su estado.

## 🎯 Qué resuelve

Modela un proceso de lotes que recibe documentos y payloads de tamaño variable:

- `batch-legacy` retiene buffers, hace crecer cache y deja subir la presión de recursos;
- `batch-optimized` limita el cache, limpia estado y mantiene el proceso dentro de umbrales sanos.

## 💻 Interfaz Visual Nativa

Al abrir la ruta raíz en tu navegador (`Accept: text/html`), este caso inyecta automáticamente un **Dashboard visual interactivo** renderizado en Vanilla JS/CSS. Esto permite observar las métricas y efectos simulados en tiempo real sin perder la capacidad de responder a consultas JSON de CLI o Postman.

## 💼 Por qué importa

Este caso sirve para evidenciar un problema común en incidentes largos: no siempre hay una excepción clara. Muchas veces el servicio solo se va degradando hasta que alguien lo reinicia.

## 🔬 Análisis Técnico de la Implementación (PHP)

A diferencia de lenguajes como Node.js, PHP "nace para morir" bajo el modelo clásico FPM. Sin embargo, en procesos persistentes o ciclos intensivos de procesamiento de bultos, una fuga de memoria es devastadora.

*   **Problema de Fragmentación y Fugas (`legacy`):** El algoritmo `legacy` acumula buffers masivos en cada iteración. Utiliza `str_repeat()` para generar payloads de prueba y luego los duplica en memoria mediante `base64_encode()`, insertándolos en un arreglo global sin límites. Esto crea un crecimiento lineal `O(N)` de la memoria heap. Al no liberar las referencias internas, el recolector de basura de PHP no puede reclamar el espacio, provocando el agotamiento del `memory_limit` configurado en el `php.ini`.
*   **Ciclo de Memoria Constante (`optimized`):** Implementa una política de **Evicción de Cache (FIFO)** con complejidad espacial `O(1)`. En lugar de retener los payloads binarios, el código calcula un identificador único mediante `hash('sha256', $payload)`. Para mantener la estabilidad del proceso, utiliza `if (count($buffers) > 24) { array_shift($buffers); }`, garantizando que el consumo de RAM sea determinista independientemente de cuántos documentos se procesen. Finalmente, el uso explícito de `unset($payload)` y la sugerencia de `gc_collect_cycles()` aseguran que el motor de PHP libere los bloques de memoria inmediata al terminar cada bulto.

## 🧱 Servicio

- `app` -> API PHP 8.3 con estado local acumulado por modo y métricas de presión.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## Como consumir (dos opciones)

**Hub PHP (recomendado, 8100 en `compose.root.yml`):** este caso queda servido en `http://localhost:8100/05/...` junto a los otros 11 casos.

**Modo aislado (815 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## 🔎 Endpoints

```bash
curl http://localhost:8100/05/
curl http://localhost:8100/05/health
curl "http://localhost:8100/05/batch-legacy?scenario=mixed_pressure&documents=24&payload_kb=64"
curl "http://localhost:8100/05/batch-optimized?scenario=mixed_pressure&documents=24&payload_kb=64"
curl http://localhost:8100/05/state
curl http://localhost:8100/05/runs?limit=10
curl http://localhost:8100/05/diagnostics/summary
curl http://localhost:8100/05/metrics
curl http://localhost:8100/05/metrics-prometheus
curl http://localhost:8100/05/reset-lab
```

## 🧪 Escenarios útiles

- `cache_growth` -> enfatiza retención de buffers y copias.
- `descriptor_drift` -> enfatiza recursos que no se cierran.
- `mixed_pressure` -> combina ambas tensiones para mostrar degradación más realista.

## 🧭 Qué observar

- cómo sube `retained_kb` en `legacy` tras varias ejecuciones;
- si `descriptor_pressure` se mantiene acotado en `optimized`;
- cuándo `pressure_level` pasa de `healthy` a `warning` o `critical`;
- la diferencia entre `peak_request_kb` y el estado retenido acumulado.

## ⚖️ Nota de honestidad

PHP no mantiene exactamente el mismo modelo de proceso largo que otros runtimes. Aun así, el laboratorio reproduce la señal operativa importante: crecimiento silencioso de estado, degradación progresiva y necesidad de límites y limpieza.
