# 🧩 Caso 08 — Python 3.12 con extraccion compatible

> Implementacion operativa del caso 08 para contrastar una extraccion big bang contra una ruta segura con proxy, contratos y cutover por fases.

## 🎯 Que resuelve

Modela la separacion de un modulo critico de pricing:

- `pricing-bigbang` intenta moverlo de una vez y expone incompatibilidades de contrato;
- `pricing-compatible` conserva el contrato publico y migra consumidores gradualmente.

## 💼 Por que importa

Este caso deja visible un patron muy frecuente: el riesgo de una extraccion no esta solo en el codigo nuevo, sino en romper compatibilidad operativa mientras el sistema sigue vendiendo. Un campo renombrado o un campo nuevo sin default puede romper todos los consumidores existentes en produccion.

## 🔬 Analisis Tecnico de la Implementacion (Python)

Los cruces de contrato se implementan mediante accesos a diccionarios y el operador de fusion de nulos `or`, exponiendo la asimetria entre el esquema legacy y el nuevo.

- **Big-Bang (`legacy`):** La funcion `run_bigbang_pricing()` accede directamente a los campos del payload con `data["price"]` sin ninguna capa de adaptacion. Cuando el escenario `rule_drift` activa campos renombrados (ej. el nuevo servicio usa `unit_price` en lugar de `price`), Python lanza un `KeyError` inmediato al intentar acceder al indice inexistente. Este error se convierte en HTTP 409 Conflict, simulando una ruptura total del contrato que bloquea el checkout. No hay degradacion parcial: o funciona con el esquema exacto o falla completamente.

- **Extraccion Compatible (`proxy`):** Implementa el **Adapter Pattern** a nivel de estructura de datos. Antes de procesar la logica de negocio, un interceptor normaliza el input usando el operador de fusion: `data["price"] = data.get("price") or data.get("unit_price") or data.get("cost_usd") or data.get("legacy_val")`. Este mapeo elastico permite que Python absorba las asimetrias de esquema sin romper la firma de la funcion. El estado de cutover avanza por fases (`legacy` → `shadow` → `canary` → `parallel` → `extracted`) mediante `POST /cutover/advance`, y cada fase aumenta el porcentaje de trafico que va al nuevo servicio, manteniendo siempre un `status_code 200`.

## 🧱 Servicio

- `app` → API Python 3.12 con proxy de compatibilidad, estado de cutover en 5 fases y metricas de drift.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `838` (modo aislado, ver opciones abajo).

## Como consumir (dos opciones)

**Hub Python (recomendado, 8200 en `compose.python.yml`):** este caso queda servido en `http://localhost:8200/08/...` junto a los otros 11 casos.

**Modo aislado (838 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## 🔎 Endpoints

```bash
curl http://localhost:8200/08/
curl http://localhost:8200/08/health
curl "http://localhost:8200/08/pricing-bigbang?scenario=rule_drift&consumer=checkout"
curl "http://localhost:8200/08/pricing-compatible?scenario=rule_drift&consumer=checkout"
curl -X POST http://localhost:8200/08/cutover/advance
curl http://localhost:8200/08/extraction/state
curl "http://localhost:8200/08/flows?limit=10"
curl http://localhost:8200/08/diagnostics/summary
curl http://localhost:8200/08/metrics
curl http://localhost:8200/08/metrics-prometheus
curl http://localhost:8200/08/reset-lab
```

## 🧪 Escenarios utiles

- `rule_drift` → muestra contratos que cambian entre consumidores; bigbang falla, compatible adapta.
- `shared_write` → hace visible el peligro de estados compartidos entre modulos.
- `peak_sale` → enfatiza por que no conviene cortar compatibilidad en una ventana critica.
- `partner_contract` → muestra integracion externa dependiente del contrato legado.

## 🧭 Que observar

- avanzar la fase con `POST /cutover/advance` y observar cuando `bigbang` empieza a fallar;
- si `pricing-compatible` nunca devuelve error sin importar la fase de cutover;
- cuantos `drift_events` y `adapter_normalizations` acumula `/extraction/state`;
- como cambia `contract_errors` por modo en `/diagnostics/summary`.

## ⚖️ Nota de honestidad

No representa un rollout real con multiples servicios ni feature flags distribuidos. Si reproduce lo importante aqui: contratos, compatibilidad, cutover progresivo y proteccion operacional del cambio.
