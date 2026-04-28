# Caso 11 — Python: Reportes pesados que bloquean la operacion

Implementacion Python del caso **Heavy reporting blocks operations**.

Logica funcional identica al stack PHP: mismo flujo de generacion de reporte que compite con escrituras de pedidos por el mismo lock de base de datos, misma diferencia entre modo legacy (bloqueo compartido) vs modo isolated (lock separado por dominio), mismas rutas.

## Equivalencia funcional con PHP

| Aspecto | PHP | Python |
|---|---|---|
| Rutas HTTP | `/report-legacy`, `/report-isolated`, `/order-write`, `/reporting/state`, `/activity`, `/diagnostics/summary`, `/metrics`, `/metrics-prometheus`, `/reset-lab` | Identicas |
| Modo legacy | Reporte y escrituras comparten el mismo lock; contention visible | Identico |
| Modo isolated | Lock separado por dominio; escrituras nunca bloquean por reportes | Identico |
| Escritura de pedidos | `/order-write` intenta adquirir lock; falla si legacy lo tiene | Identico |
| Lock en Python | `threading.Lock` con `acquire(blocking=False)` para simular contention | Equivalente al file lock de PHP |
| Estado persistido | `/tmp/pdsl-case11-python/` | `/tmp/pdsl-case11-python/` |
| Puerto | 821 | 841 |

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `841`.

## Endpoints

```bash
curl http://localhost:841/
curl http://localhost:841/health
curl "http://localhost:841/report-legacy?rows=500&period_days=30"
curl "http://localhost:841/report-isolated?rows=500&period_days=30"
curl "http://localhost:841/order-write?order_id=ORD-001&mode=legacy"
curl "http://localhost:841/order-write?order_id=ORD-001&mode=isolated"
curl http://localhost:841/reporting/state
curl "http://localhost:841/activity?limit=20"
curl http://localhost:841/diagnostics/summary
curl http://localhost:841/metrics
curl http://localhost:841/metrics-prometheus
curl http://localhost:841/reset-lab
```

## Parametros de carga

| Parametro | Descripcion | Default |
|---|---|---|
| `rows` | Numero de filas a procesar en el reporte | 100 |
| `period_days` | Periodo historico del reporte en dias | 7 |

## Que observar

- Llama a `/report-legacy` y en paralelo llama a `/order-write?mode=legacy`: la escritura devuelve `lock_contention: true` y `blocked_ms` alto.
- Con `/report-isolated` + `/order-write?mode=isolated`: las escrituras nunca ven contention del reporte.
- `/reporting/state` muestra `lock_contentions`, `blocked_writes`, `avg_report_ms` por modo.
- `/diagnostics/summary` cuantifica `write_blocking_rate` y `avg_contention_ms`.

## Diferencia de implementacion respecto a PHP

PHP usa file locks (`flock`) sobre archivos en `/tmp`. Python usa `threading.Lock` con `acquire(blocking=False)` para simular contention. El comportamiento observable es identico: un proceso que intenta adquirir el lock mientras otro lo retiene recibe inmediatamente un fallo de contention en lugar de esperar indefinidamente.
