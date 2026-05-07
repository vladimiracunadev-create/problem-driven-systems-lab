# 🧠 Caso 05 — Node.js 20 + V8 heap medido

> Implementacion operativa del caso 05 para estudiar presion de memoria y fugas de recursos con evidencia observable, manteniendo paridad funcional con la version Python y aprovechando la primitiva propia del runtime: `process.memoryUsage()` para heap V8 + RSS reales.

## 🎯 Que resuelve

Modela un procesamiento batch que ingiere documentos de tamano variable y los descarta despues de un hash. Dos variantes:

- `batch-legacy`: cada request agrega blobs base64 a un array de modulo. La referencia vive entre requests — fuga real cross-request acotada por `LEGACY_HARD_CAP` para no causar OOM real.
- `batch-optimized`: cada documento se hashea y solo el digest queda en un `Map` con eviction al tope `OPTIMIZED_CACHE_MAX`. Los buffers crudos salen de scope inmediatamente y el GC los reclama.

Escenarios: `cache_growth`, `descriptor_drift`, `mixed_pressure` — modulan la presion sobre memoria y descriptores.

## 💼 Por que importa

El sintoma clasico: el servicio funciona bien al deploy, degrada gradualmente, y a las pocas horas el RSS no baja aun cuando no hay trafico. Este caso muestra como pasar de "el proceso parece pesar de mas" a evidencia accionable: `heap_used`, `heap_total`, `rss`, niveles de presion y conteo de objetos retenidos por modo.

## 🔬 Analisis Tecnico de la Implementacion (Node.js)

- **Medicion real con `process.memoryUsage()`:** La api nativa expone `heapUsed` (V8 used heap), `heapTotal` (V8 reserved heap), `rss` (proceso entero) y `external` (memoria fuera del heap V8 — Buffers grandes). El laboratorio toma snapshots antes y despues de cada batch y publica el delta. No hay biblioteca extra: V8 ya sabe contar.

- **Implementacion Falla (`legacy`):** `legacyRetained` es un array de modulo. Cada request hace `legacyRetained.push(raw.toString('base64'))` y la referencia queda en el closure del modulo. V8 no puede reclamar mientras esa raiz exista. El array crece request a request hasta el cap. Resultado: `heap_used_kb` y `rss_kb` suben monotonamente, `descriptor_pressure` acumula, y el `pressure_level` escala `healthy → warning → critical`. Cuando llega a critical, el endpoint devuelve 503 — el sistema deja de aceptar carga porque no puede sostenerla.

- **Sanitizacion (`optimized`):** Los `crypto.randomBytes(...)` viven en una variable `raw` de scope local. Despues de calcular el digest, el scope termina y V8 marca el buffer como reclamable en el siguiente GC menor. El `optimizedCache: Map` solo retiene strings cortos (16 chars hex) con eviction explicita cuando supera `OPTIMIZED_CACHE_MAX`. Si Node corre con `--expose-gc`, se invoca `globalThis.gc()` para forzar reclamo y dejar la metrica estable; sin el flag, V8 reclama solo (la presion baja casi igual). El `pressure_level` se mantiene en `healthy` porque la huella retenida es bounded por diseno.

- **Por que es Node-especifico:** la metrica `external` separa los Buffers de la heap V8, algo que no existe en runtimes monoliticos. Permite distinguir "fuga de objetos JS" (heapUsed sube) de "fuga de I/O nativa" (external/rss suben sin que heapUsed lo haga).

## 🧱 Servicio

- `app` → API Node.js 20 con politicas legacy/optimized, batch real con `crypto.randomBytes`, telemetria persistida en `tmp/`.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `825` (modo aislado, ver opciones abajo).

## Como consumir (dos opciones)

**Hub Node.js (recomendado, 8300 en `compose.nodejs.yml`):** este caso queda servido en `http://localhost:8300/05/...` junto a los otros 11 casos.

**Modo aislado (825 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## 🔎 Endpoints

```bash
curl http://localhost:8300/05/
curl http://localhost:8300/05/health
curl "http://localhost:8300/05/batch-legacy?scenario=mixed_pressure&documents=24&payload_kb=64"
curl "http://localhost:8300/05/batch-optimized?scenario=mixed_pressure&documents=24&payload_kb=64"
curl http://localhost:8300/05/state
curl "http://localhost:8300/05/runs?limit=10"
curl http://localhost:8300/05/diagnostics/summary
curl http://localhost:8300/05/metrics
curl http://localhost:8300/05/metrics-prometheus
curl http://localhost:8300/05/reset-lab
```

## 🧭 Que observar

- `memory.retained_kb_after` en `batch-legacy` crece monotonamente; en `batch-optimized` se mantiene estable;
- `memory.heap_used_delta_kb` y `memory.rss_delta_kb` muestran el costo real por request;
- `pressure_level` escala `healthy → warning → critical` solo en legacy;
- al llegar a `critical`, `batch-legacy` empieza a devolver HTTP 503 — comportamiento defensivo del servicio que ya no puede sostener carga;
- `app_heap_used_kb` y `app_rss_kb` en Prometheus permiten graficar la fuga sin acoplarse a un APM externo.

## ⚖️ Nota de honestidad

El cap `LEGACY_HARD_CAP = 2000` evita un OOM real del contenedor; sin el cap, el array crecería indefinidamente. Es un compromiso explicito del laboratorio: simular una fuga sin destrozar el sandbox de pruebas. La diferencia de comportamiento (crece monotonamente vs se mantiene plano) es identica al caso real.
