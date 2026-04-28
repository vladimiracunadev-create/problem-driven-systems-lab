# Caso 05 — Python: Presion de memoria y fugas de recursos

Implementacion Python del caso **Memory pressure and resource leaks**.

Logica funcional identica al stack PHP: mismo flujo de procesamiento por lotes, misma simulacion de retencion de buffers vs liberacion controlada, mismos umbrales de presion, mismas rutas.

## Equivalencia funcional con PHP

| Aspecto | PHP | Python |
|---|---|---|
| Rutas HTTP | `/batch-legacy`, `/batch-optimized`, `/state`, `/runs`, `/diagnostics/summary`, `/metrics`, `/metrics-prometheus`, `/reset-lab` | Identicas |
| Modo legacy | Retiene todos los buffers base64 en memoria durante el batch | Identico |
| Modo optimized | Libera buffers tras procesar cada item; guarda solo hash sha256 | Identico |
| Umbrales | Warning: 8192 KB / 60 descriptores. Critical: 16384 KB / 120 descriptores | Identicos |
| HTTP 503 | Cuando legacy alcanza presion critica | Identico |
| Estado persistido | `/tmp/pdsl-case05-php/` | `/tmp/pdsl-case05-python/` |
| Puerto | 815 | 835 |

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `835`.

## Endpoints

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

## Parametros de carga

| Parametro | Descripcion | Default |
|---|---|---|
| `items` | Numero de items a procesar en el batch | 20 |
| `size_kb` | Tamano del buffer simulado por item (KB) | 64 |

## Que observar

- `retained_kb` en `/state` crece linealmente con cada llamada a `batch-legacy` y nunca baja.
- `retained_kb` en `batch-optimized` permanece cerca de 0 (decae tras cada batch).
- Cuando `retained_kb` supera 16384 KB, `batch-legacy` devuelve HTTP 503 con `pressure: critical`.
- `/diagnostics/summary` muestra `avg_retained_kb` y `pressure_events` por modo.
- `/runs` historial de ejecuciones con `items_processed`, `retained_kb`, `elapsed_ms`.

## Diferencias de implementacion respecto a PHP

Ninguna diferencia funcional. La simulacion de presion de memoria en Python usa listas de strings base64 en lugar de arrays PHP; el comportamiento observable (escalada de `retained_kb`, umbrales, 503) es identico.
