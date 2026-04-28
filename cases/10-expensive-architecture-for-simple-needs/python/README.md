# Caso 10 — Python: Arquitectura cara para un problema simple

Implementacion Python del caso **Expensive architecture for simple needs**.

Logica funcional identica al stack PHP: mismo flujo de resolucion de features con arquitectura sobre-ingenierizada (multi-hop, serializacion costosa, hidratacion ORM) vs acceso directo right-sized (O(1) lookup), mismas rutas.

## Equivalencia funcional con PHP

| Aspecto | PHP | Python |
|---|---|---|
| Rutas HTTP | `/feature-complex`, `/feature-right-sized`, `/architecture/state`, `/decisions`, `/diagnostics/summary`, `/metrics`, `/metrics-prometheus`, `/reset-lab` | Identicas |
| Modo complex | Multi-hop: event bus → rule engine → ORM hydration → serialization pipeline | Identico |
| Modo right_sized | Dict lookup directo con override por contexto; O(1) | Identico |
| Overhead simulado | Latencia artificial por cada capa de la arquitectura compleja | Identico |
| Estado persistido | `/tmp/pdsl-case10-python/` | `/tmp/pdsl-case10-python/` |
| Puerto | 820 | 840 |

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `840`.

## Endpoints

```bash
curl http://localhost:840/
curl http://localhost:840/health
curl "http://localhost:840/feature-complex?feature=dark_mode&user_id=42&context=mobile"
curl "http://localhost:840/feature-right-sized?feature=dark_mode&user_id=42&context=mobile"
curl http://localhost:840/architecture/state
curl "http://localhost:840/decisions?limit=10"
curl http://localhost:840/diagnostics/summary
curl http://localhost:840/metrics
curl http://localhost:840/metrics-prometheus
curl http://localhost:840/reset-lab
```

## Features disponibles

| Feature | Descripcion |
|---|---|
| `dark_mode` | Modo oscuro de la interfaz |
| `beta_checkout` | Flujo de checkout experimental |
| `ai_recommendations` | Recomendaciones por ML |
| `advanced_analytics` | Dashboard de analitica avanzada |
| `legacy_export` | Exportacion en formato legacy |

## Que observar

- `feature-complex` acumula `hops` (event bus, rule engine, ORM, serializer) con latencia proporcional al numero de capas.
- `feature-right-sized` devuelve en <5ms con un lookup directo en dict.
- `/architecture/state` muestra `avg_hops_complex` vs `avg_hops_right_sized` y la diferencia de latencia.
- `/diagnostics/summary` cuantifica el overhead por capa y el `complexity_ratio` entre ambos modos.
- La respuesta funcional es identica: ambos modos resuelven el mismo valor de feature.

## Por que este caso importa

El modo `complex` no es incorrecto por ser complejo: es incorrecto porque la complejidad no aporta valor. Un feature flag es un lookup de configuracion; no necesita event sourcing, ORM ni pipeline de serializacion. El caso demuestra que la arquitectura debe ser proporcional al problema.
