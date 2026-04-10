# 🧠 Caso 05 - PHP 8.3 con presión de memoria comparada

> Implementación operativa del caso 05 para mostrar cómo una fuga silenciosa degrada un proceso largo frente a una variante que controla su estado.

## 🎯 Qué resuelve

Modela un proceso de lotes que recibe documentos y payloads de tamaño variable:

- `batch-legacy` retiene buffers, hace crecer cache y deja subir la presión de recursos;
- `batch-optimized` limita el cache, limpia estado y mantiene el proceso dentro de umbrales sanos.

## 💼 Por qué importa

Este caso sirve para evidenciar un problema común en incidentes largos: no siempre hay una excepción clara. Muchas veces el servicio solo se va degradando hasta que alguien lo reinicia.

## 🧱 Servicio

- `app` -> API PHP 8.3 con estado local acumulado por modo y métricas de presión.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## 🔎 Endpoints

```bash
curl http://localhost:815/
curl http://localhost:815/health
curl "http://localhost:815/batch-legacy?scenario=mixed_pressure&documents=24&payload_kb=64"
curl "http://localhost:815/batch-optimized?scenario=mixed_pressure&documents=24&payload_kb=64"
curl http://localhost:815/state
curl http://localhost:815/runs?limit=10
curl http://localhost:815/diagnostics/summary
curl http://localhost:815/metrics
curl http://localhost:815/metrics-prometheus
curl http://localhost:815/reset-lab
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
