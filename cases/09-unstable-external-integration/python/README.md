# 🌐 Caso 09 — Python 3.12 con adapter y cache defensiva

> Implementacion operativa del caso 09 para contrastar una integracion externa directa contra una variante endurecida.

## 🎯 Que resuelve

Modela un consumo de catalogo externo donde el proveedor puede:

- cambiar esquema sin aviso;
- responder con payload parcial o malformado;
- reenviar eventos duplicados;
- fallar en un subconjunto de items del batch.

La variante `catalog-hardened` agrega sanitizacion de SKU, validacion de esquema, idempotencia por `event_id` y procesamiento parcial tolerante a fallos.

## 💼 Por que importa

Este caso deja visible que la resiliencia frente a terceros no depende solo del timeout. También importa la estabilidad del contrato, la validez de los identificadores y la capacidad de operar con informacion parcialmente valida sin contaminar el catalogo interno.

## 🔬 Analisis Tecnico de la Implementacion (Python)

Las APIs de terceros fallan en formas sutiles. Este caso implementa integracion defensiva usando expresiones regulares, operadores de fusion y gestion de idempotencia en memoria.

- **Llamado Vulnerable (`legacy`):** La funcion `run_legacy_sync()` acepta la respuesta del proveedor sin ninguna validacion. Si el proveedor envia un SKU con caracteres invalidos (`SKU 100` con espacio, o `X`), el codigo lo acepta y lo inserta directamente en el catalogo interno. Si el proveedor omite un campo esperado (ej. `price` ausente), el acceso `item["price"]` lanza un `KeyError` que derrumba el batch completo. Si el mismo evento llega dos veces, se procesa dos veces sin deteccion de duplicado. El resultado es un catalogo potencialmente corrupto y un comportamiento no deterministico bajo condiciones de red reales.

- **Aislado Robusto (`hardened`):** Introduce tres capas defensivas. Primero, `sanitize_sku(sku)` valida el SKU con `re.match(r"^[A-Z0-9-]{4,20}$", sku)` y retorna un default determinista si no pasa. Segundo, `validate_schema(item)` verifica la presencia de campos requeridos antes de procesar, descartando items invalidos en lugar de fallar el batch completo. Tercero, la idempotencia se gestiona con un `set` de `processed_event_ids`: `if event_id in processed_event_ids: continue`. Esto garantiza que eventos duplicados del proveedor sean silenciosamente ignorados sin afectar `total_processed`. El procesamiento parcial permite que items validos del batch sean aceptados aunque otros sean invalidos.

## 🧱 Servicio

- `app` → API Python 3.12 con proveedor externo simulado, adapter de contrato, cache de idempotencia y metricas de calidad de datos.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `839`.

## 🔎 Endpoints

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

## 🧪 Escenarios utiles

- `schema_drift` → muestra normalizacion de contrato versus ruptura directa por `KeyError`.
- `malformed_sku` → legacy acepta SKUs invalidos; hardened sanitiza o rechaza.
- `duplicate_events` → legacy procesa dos veces; hardened detecta por `event_id`.
- `partial_failure` → legacy falla todo el batch; hardened procesa los items validos.

## 🧭 Que observar

- cuantos `corrupted_items` acumula legacy vs cuantos `rejected_items` registra hardened;
- si `idempotent_skips` sube en hardened con el escenario `duplicate_events`;
- como cambia `data_quality_score` entre modos en `/diagnostics/summary`;
- si el batch completo falla en legacy ante un solo item invalido.

## ⚖️ Nota de honestidad

No reemplaza una integracion real con colas, DLQ ni proveedores de terceros. Si reproduce las decisiones operativas que importan aqui: adapter, contrato defensivo, idempotencia y tolerancia a fallos parciales.
