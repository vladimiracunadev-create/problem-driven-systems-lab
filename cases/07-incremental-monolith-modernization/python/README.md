# Caso 07 — Python: Modernizacion incremental de monolito

Implementacion Python del caso **Incremental monolith modernization**.

Logica funcional identica al stack PHP: mismo flujo de cambio de precios con acoplamiento de god class (legacy) vs patron strangler fig con ACL y migracion incremental de consumidores (strangler), mismas rutas.

## Equivalencia funcional con PHP

| Aspecto | PHP | Python |
|---|---|---|
| Rutas HTTP | `/change-legacy`, `/change-strangler`, `/migration/state`, `/flows`, `/diagnostics/summary`, `/metrics`, `/metrics-prometheus`, `/reset-lab` | Identicas |
| Modo legacy | God class con acoplamiento directo a todos los modulos internos | Identico |
| Modo strangler | ACL desacopla el dominio; consumidores migran incrementalmente | Identico |
| Migracion | `consumers_migrated` / `consumers_total` con porcentaje de avance | Identico |
| Estado persistido | `/tmp/pdsl-case07-php/` | `/tmp/pdsl-case07-python/` |
| Puerto | 817 | 837 |

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `837`.

## Endpoints

```bash
curl http://localhost:837/
curl http://localhost:837/health
curl "http://localhost:837/change-legacy?product_id=P-001&new_price=99.99&reason=promo"
curl "http://localhost:837/change-strangler?product_id=P-001&new_price=99.99&reason=promo"
curl http://localhost:837/migration/state
curl "http://localhost:837/flows?limit=10"
curl http://localhost:837/diagnostics/summary
curl http://localhost:837/metrics
curl http://localhost:837/metrics-prometheus
curl http://localhost:837/reset-lab
```

## Que observar

- Legacy: `modules_touched` lista todos los modulos afectados por cada cambio de precio (god class coupling).
- Strangler: `acl_translations` muestra las traducciones del ACL; `consumers_migrated` incrementa con cada llamada.
- `/migration/state` refleja el progreso: `consumers_migrated`, `consumers_total`, `migration_pct`.
- `/diagnostics/summary` compara `avg_modules_touched` (legacy) vs `avg_acl_translations` (strangler).
- `/flows` historial de operaciones con `mode`, `product_id`, `modules_touched`, `elapsed_ms`.

## Patron implementado

El modo strangler implementa el patron **Strangler Fig**: los nuevos consumidores del dominio de precios se comunican a traves de un ACL (Anti-Corruption Layer) que traduce el modelo legacy al modelo nuevo. Cada llamada a `change-strangler` migra un consumidor adicional, simulando la transicion incremental del 0% al 100%.
