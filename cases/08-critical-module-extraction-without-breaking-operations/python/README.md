# Caso 08 — Python: Extraccion de modulo critico sin romper la operacion

Implementacion Python del caso **Critical module extraction without breaking operations**.

Logica funcional identica al stack PHP: mismo flujo de extraccion del modulo de pricing con estrategia big bang (rompe ante drift de contrato) vs proxy de compatibilidad (adapta y continua), mismo mecanismo de avance de cutover, mismas rutas.

## Equivalencia funcional con PHP

| Aspecto | PHP | Python |
|---|---|---|
| Rutas HTTP | `/pricing-bigbang`, `/pricing-compatible`, `/cutover/advance`, `/extraction/state`, `/flows`, `/diagnostics/summary`, `/metrics`, `/metrics-prometheus`, `/reset-lab` | Identicas |
| Modo bigbang | Falla con HTTP 409 ante cualquier drift de contrato | Identico |
| Modo compatible | Proxy adapter normaliza el contrato; nunca falla por drift | Identico |
| Cutover | 5 fases: legacy → shadow → canary → parallel → extracted | Identico |
| `/cutover/advance` | Avanza la fase; NO registra telemetria de request | Identico |
| Estado persistido | `/tmp/pdsl-case08-php/` | `/tmp/pdsl-case08-python/` |
| Puerto | 818 | 838 |

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `838`.

## Endpoints

```bash
curl http://localhost:838/
curl http://localhost:838/health
curl "http://localhost:838/pricing-bigbang?product_id=P-001&quantity=5&context=checkout"
curl "http://localhost:838/pricing-compatible?product_id=P-001&quantity=5&context=checkout"
curl -X POST http://localhost:838/cutover/advance
curl http://localhost:838/extraction/state
curl "http://localhost:838/flows?limit=10"
curl http://localhost:838/diagnostics/summary
curl http://localhost:838/metrics
curl http://localhost:838/metrics-prometheus
curl http://localhost:838/reset-lab
```

## Fases de cutover

| Fase | Descripcion |
|---|---|
| `legacy` | Todo el trafico va al modulo monolitico |
| `shadow` | El nuevo servicio recibe trafico en paralelo (sin afectar respuestas) |
| `canary` | 10% del trafico va al nuevo servicio |
| `parallel` | 50% del trafico va al nuevo servicio |
| `extracted` | 100% del trafico va al nuevo servicio extraido |

## Que observar

- `pricing-bigbang` en fases avanzadas devuelve HTTP 409 cuando el contrato del nuevo servicio difiere.
- `pricing-compatible` nunca falla: el proxy adapter normaliza campos faltantes o renombrados.
- `/extraction/state` muestra la fase actual, `drift_events` acumulados y `adapter_normalizations`.
- Avanza la fase con `POST /cutover/advance` y observa como `bigbang` empieza a fallar antes que `compatible`.
- `/diagnostics/summary` compara `contract_errors` entre ambos modos.
