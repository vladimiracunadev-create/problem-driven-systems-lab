# Caso 09 — Python: Integracion externa inestable

Implementacion Python del caso **Unstable external integration**.

Logica funcional identica al stack PHP: mismo flujo de sincronizacion de catalogo con proveedor externo inestable, misma diferencia entre integracion naive (legacy) vs integracion reforzada (hardened) con sanitizacion, idempotencia y reintentos selectivos, mismas rutas.

## Equivalencia funcional con PHP

| Aspecto | PHP | Python |
|---|---|---|
| Rutas HTTP | `/catalog-legacy`, `/catalog-hardened`, `/integration/state`, `/sync-events`, `/diagnostics/summary`, `/metrics`, `/metrics-prometheus`, `/reset-lab` | Identicas |
| Modo legacy | Acepta respuestas malformadas, no valida SKUs, no es idempotente | Identico |
| Modo hardened | Sanitiza SKUs (regex `^[A-Z0-9-]{4,20}$`), validacion de schema, idempotencia por `event_id` | Identico |
| Snapshot de producto | Deterministico por SKU via `sum(ord(c) for c in sku) % N` | Identico |
| Estado persistido | `/tmp/pdsl-case09-php/` | `/tmp/pdsl-case09-python/` |
| Puerto | 819 | 839 |

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `839`.

## Endpoints

```bash
curl http://localhost:839/
curl http://localhost:839/health
curl "http://localhost:839/catalog-legacy?scenario=malformed_sku&batch_size=10"
curl "http://localhost:839/catalog-hardened?scenario=malformed_sku&batch_size=10"
curl http://localhost:839/integration/state
curl "http://localhost:839/sync-events?limit=10"
curl http://localhost:839/diagnostics/summary
curl http://localhost:839/metrics
curl http://localhost:839/metrics-prometheus
curl http://localhost:839/reset-lab
```

## Escenarios disponibles

| Scenario | Comportamiento |
|---|---|
| `ok` | Proveedor envia datos validos. Ambos modos procesan sin errores. |
| `malformed_sku` | SKUs con caracteres invalidos o longitud incorrecta. Legacy: los acepta. Hardened: sanitiza o rechaza. |
| `schema_drift` | Campos renombrados o ausentes en la respuesta. Legacy: falla con KeyError. Hardened: schema validation detecta y descarta. |
| `duplicate_events` | El proveedor reenvia eventos ya procesados. Legacy: procesa duplicados. Hardened: idempotencia por `event_id`. |
| `partial_failure` | Algunos items del batch fallan. Legacy: rollback total. Hardened: procesa items validos, descarta invalidos. |

## Que observar

- Legacy: `corrupted_items` acumula productos con datos invalidos en el catalogo.
- Hardened: `sanitized_skus` y `rejected_items` en `/integration/state` muestran que la validacion funciona.
- `/integration/state` compara `total_processed`, `corrupted_items`, `duplicate_events_skipped`.
- `/diagnostics/summary` cuantifica `data_quality_score` por modo.
- Con `duplicate_events`: hardened muestra `idempotent_skips` creciendo sin `total_processed` repetido.
