# Caso 04 — Python: Cadena de timeouts y tormentas de reintentos

Implementacion Python del caso **Timeout chain and retry storms**.

Logica funcional identica al stack PHP: mismas politicas de resiliencia, mismo circuit breaker, mismos escenarios de proveedor inestable, mismas rutas.

## Equivalencia funcional con PHP

| Aspecto | PHP | Python |
|---|---|---|
| Rutas HTTP | `/quote-legacy`, `/quote-resilient`, `/dependency/state`, `/incidents`, `/diagnostics/summary`, `/metrics`, `/metrics-prometheus`, `/reset-lab` | Identicas |
| Politica legacy | timeout=360ms, max_attempts=4, backoff=0, sin circuit breaker | Identica |
| Politica resilient | timeout=220ms, max_attempts=2, backoff_base=80ms, circuit breaker, fallback | Identica |
| Circuit breaker | Abre con 2 fallos consecutivos, 30 segundos abierto | Identico |
| Fallback | Quote cacheada desde ultimo exito | Identico |
| Estado persistido | `/tmp/pdsl-case04-php/` | `/tmp/pdsl-case04-python/` |
| Puerto | 814 | 834 |

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `834`.

## Endpoints

```bash
curl http://localhost:834/
curl http://localhost:834/health
curl "http://localhost:834/quote-legacy?scenario=provider_down&customer_id=42&items=3"
curl "http://localhost:834/quote-resilient?scenario=provider_down&customer_id=42&items=3"
curl http://localhost:834/dependency/state
curl "http://localhost:834/incidents?limit=10"
curl http://localhost:834/diagnostics/summary
curl http://localhost:834/metrics
curl http://localhost:834/metrics-prometheus
curl http://localhost:834/reset-lab
```

## Escenarios disponibles

| Scenario | Comportamiento |
|---|---|
| `ok` | Proveedor responde en ~130ms. Sin reintentos. |
| `slow_provider` | Proveedor tarda 640-730ms (mayor que cualquier timeout). Legacy: 4 timeouts. Resilient: 1-2 timeouts + fallback. |
| `flaky_provider` | Intento 1 falla, intento 2 recupera. Legacy: 4 intentos totales. Resilient: 2 intentos. |
| `provider_down` | Nunca responde a tiempo. Legacy: 4 timeouts completos. Resilient: circuit breaker + fallback. |
| `burst_then_recover` | 2 fallos iniciales, luego recuperacion. Muestra diferencia de backoff. |

## Que observar

- Legacy acumula `timeout_count` alto y amplifica la duracion total de la request.
- Resilient reduce el tiempo total con timeouts mas cortos y backoff exponencial.
- El circuit breaker en resilient short-circuita requests subsiguientes mientras el proveedor esta down.
- `/dependency/state` muestra `circuit_status`, `consecutive_failures` y `fallback_quote`.
- `/diagnostics/summary` compara `avg_attempts_per_flow` entre ambos modos.
