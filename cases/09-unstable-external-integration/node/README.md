# Integracion externa inestable — Node.js

> Implementacion operativa del caso 09 con paridad al stack PHP. Hardening basado en `AbortSignal.timeout(ms)` (Node 18+) + circuit breaker de modulo en memoria.

## Que resuelve

Compara dos formas de consumir a un proveedor externo:

- `catalog-legacy`: llamado directo, sin timeout, sin adapter, sin cache. Si el proveedor se cae o cambia el schema, el sistema lo paga.
- `catalog-hardened`: el llamado pasa por `AbortSignal.timeout(250)`, un adapter que normaliza shape, cache local y un circuit breaker que se abre tras 3 fallos consecutivos y se reabre solo despues del cooldown.

Escenarios: `ok`, `schema_drift`, `rate_limited`, `partial_payload`, `maintenance_window`.

## Primitivas Node-especificas

- `AbortSignal.timeout(timeoutMs)`: marca el deadline del llamado externo sin atornillar timers manualmente. Si el proveedor tarda mas, el `signal` aborta y la promesa rechaza.
- Circuit breaker: objeto de modulo con tres estados (`closed`/`open`/`half_open`). Tras `threshold` fallos, se abre y bloquea llamados al proveedor. `setTimeout` virtual via `reopenAt` pasa a `half_open` automaticamente.
- Adapter: si el payload viene con shape distinto, normaliza `cost` -> `price_usd` antes de calcular.

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `829` (modo aislado, ver opciones abajo).

## Como consumir (dos opciones)

**Hub Node.js (recomendado, 8300 en `compose.nodejs.yml`):** este caso queda servido en `http://localhost:8300/09/...` junto a los otros 11 casos.

**Modo aislado (829 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## Endpoints

```bash
curl http://localhost:8300/09/
curl http://localhost:8300/09/health
curl "http://localhost:8300/09/catalog-legacy?scenario=rate_limited&sku=SKU-100"
curl "http://localhost:8300/09/catalog-hardened?scenario=rate_limited&sku=SKU-100"
curl "http://localhost:8300/09/sync-events?limit=10"
curl http://localhost:8300/09/diagnostics/summary
curl http://localhost:8300/09/metrics
curl http://localhost:8300/09/metrics-prometheus
curl http://localhost:8300/09/reset-lab
```

## Que observar

- En `rate_limited`/`maintenance_window`, legacy responde 429/503 mientras hardened devuelve 200 desde cache;
- en `schema_drift`, legacy explota con TypeError; hardened normaliza con adapter y devuelve 200;
- el campo `breaker.status` cambia a `open` tras varios fallos legacy seguidos y bloquea llamados al proveedor para proteger cuota;
- `app_quota_saved_total{mode="hardened"}` en Prometheus muestra el ahorro acumulado de budget.
