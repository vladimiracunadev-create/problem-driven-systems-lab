# 🧠 Caso 05 — Python 3.12 con presion de memoria comparada

> Implementacion operativa del caso 05 para mostrar como una fuga silenciosa degrada un proceso largo frente a una variante que controla su estado.

## 🎯 Que resuelve

Modela un proceso de lotes que recibe documentos y payloads de tamano variable:

- `batch-legacy` retiene buffers, hace crecer el estado acumulado y deja subir la presion de recursos;
- `batch-optimized` limita el cache, limpia estado tras cada item y mantiene el proceso dentro de umbrales sanos.

## 💼 Por que importa

Este caso sirve para evidenciar un problema comun en incidentes largos: no siempre hay una excepcion clara. Muchas veces el servicio solo se va degradando hasta que alguien lo reinicia. La presion de memoria es silenciosa hasta que no lo es.

## 🔬 Analisis Tecnico de la Implementacion (Python)

Python tiene recolector de basura automatico, pero no elimina el problema de fugas: una referencia activa impide que `gc` reclame el espacio, sin importar cuanto tiempo pase.

- **Problema de Fragmentacion y Fugas (`legacy`):** La funcion `run_batch_legacy()` itera sobre cada item y genera un payload con `b64encode(os.urandom(size_bytes)).decode()`, luego lo acumula en una lista global `retained_buffers` sin ningun limite. Cada llamada a la ruta agrega `items * size_kb` KB al estado del proceso. Al mantener referencias vivas en `retained_buffers`, el recolector de basura de Python no puede liberar esos bloques: el objeto existe y tiene al menos un referenciador. El `retained_kb` crece de forma `O(N*calls)` hasta que el servidor alcanza el umbral `critical` (16384 KB) y empieza a devolver HTTP 503.

- **Ciclo de Memoria Constante (`optimized`):** Implementa una politica de **Eviccion FIFO** con complejidad espacial `O(1)`. En lugar de retener los buffers base64, la funcion calcula `hashlib.sha256(payload.encode()).hexdigest()` y lo guarda como identificador compacto (64 bytes, no `size_kb` KB). Para mantener el cache acotado a 24 entradas maximas, usa `if len(digest_cache) > 24: digest_cache.pop(next(iter(digest_cache)))`. Tras procesar cada item, llama explicitamente a `del payload` para eliminar la referencia local, permitiendo que el GIL libere el bloque en el siguiente ciclo del recolector. El `retained_kb` permanece cerca de cero independientemente de cuantos batches se procesen.

## 🧱 Servicio

- `app` → API Python 3.12 con estado local acumulado por modo y metricas de presion.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `835`.

## 🔎 Endpoints

```bash
curl http://localhost:835/
curl http://localhost:835/health
curl "http://localhost:835/batch-legacy?items=50&size_kb=128"
curl "http://localhost:835/batch-optimized?items=50&size_kb=128"
curl http://localhost:835/state
curl "http://localhost:835/runs?limit=10"
curl http://localhost:835/diagnostics/summary
curl http://localhost:835/metrics
curl http://localhost:835/metrics-prometheus
curl http://localhost:835/reset-lab
```

## 🧪 Escenarios utiles

- `items=50&size_kb=128` → varias llamadas seguidas para ver como sube `retained_kb` en legacy.
- `items=100&size_kb=64` → lotes mas grandes para alcanzar el umbral `critical` y provocar HTTP 503.
- Llamar `batch-optimized` en las mismas condiciones y comparar `retained_kb` constante.

## 🧭 Que observar

- como sube `retained_kb` en `batch-legacy` tras varias ejecuciones y nunca baja;
- si `retained_kb` se mantiene cerca de 0 en `batch-optimized` sin importar cuantos batches;
- cuando `pressure_level` pasa de `healthy` a `warning` o `critical`;
- la diferencia entre `peak_request_kb` (por llamada) y el estado retenido acumulado.

## ⚖️ Nota de honestidad

Python tiene GC automatico, pero no elimina el problema de retener referencias activas. El laboratorio reproduce la senal operativa importante: crecimiento silencioso de estado, degradacion progresiva y necesidad de limites y limpieza explicita.
