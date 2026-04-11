# 🌐 Caso 09 - PHP 8.3 con adapter y cache defensiva

> Implementación operativa del caso 09 para contrastar una integración externa directa contra una variante endurecida.

## 🎯 Qué resuelve

Modela un consumo de catálogo externo donde el proveedor puede:

- cambiar esquema sin aviso;
- limitar cuota;
- responder con payload parcial;
- entrar en mantenimiento.

La variante `catalog-hardened` agrega adapter, validación y snapshot cacheado.

## 💻 Interfaz Visual Nativa

Al abrir la ruta raíz en tu navegador (`Accept: text/html`), este caso inyecta automáticamente un **Dashboard visual interactivo** renderizado en Vanilla JS/CSS. Esto permite observar las métricas y efectos simulados en tiempo real sin perder la capacidad de responder a consultas JSON de CLI o Postman.

## 💼 Por qué importa

Este caso deja visible que la resiliencia frente a terceros no depende solo del timeout. También importa la estabilidad del contrato, la cuota y la posibilidad de operar con información ya conocida.

## 🔬 Análisis Técnico de la Implementación (PHP)

Las APIs de terceros fallan en formas sutiles que van más allá de un socket caído. Este caso implementa integraciones físicas usando interceptores de error y normalización de esquemas.

*   **Llamado Vulnerable (`legacy`):** Ejecuta código asumiendo "Happy Path" absoluto. Al no implementar límites de tiempo explícitos (`timeouts`), el proceso PHP-FPM queda bloqueado en una llamada sincrónica hasta que se agote el `max_execution_time`. Adicionalmente, si el proveedor omite una llave esperada en su JSON (ej: `price_usd`), el script falla al intentar acceder al índice inexistente, provocando un crasheo de la transacción por ruptura de contrato físico en memoria.
*   **Aislado Robusto (`hardened`):** Introduce un interceptor de excepciones **`try/catch (\Throwable $e)`** y simula la configuración defensiva de `CURLOPT_TIMEOUT`. Cuando ocurre un timeout físico, la excepción es atrapada para disparar un flujo de **Degradación Segura**, recuperando la última respuesta conocida del sistema. Para la estabilidad del esquema, implementa un **Adapter** que utiliza el operador de fusión de nulidad (`??`) para asegurar la existencia de propiedades críticas: `$data['price'] = $data['price_usd'] ?? $data['cost'] ?? 0.0`. Esto garantiza que la lógica de negocio consuma siempre un objeto válido, independientemente de la inestabilidad del tercero.

## 🧱 Servicio

- `app` -> API PHP 8.3 con proveedor externo simulado, quota budget, adapter de contrato y cache local.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## 🔎 Endpoints

```bash
curl http://localhost:819/
curl http://localhost:819/health
curl "http://localhost:819/catalog-legacy?scenario=rate_limited&sku=SKU-100"
curl "http://localhost:819/catalog-hardened?scenario=rate_limited&sku=SKU-100"
curl http://localhost:819/integration/state
curl http://localhost:819/sync-events?limit=10
curl http://localhost:819/diagnostics/summary
curl http://localhost:819/metrics
curl http://localhost:819/metrics-prometheus
curl http://localhost:819/reset-lab
```

## 🧪 Escenarios útiles

- `schema_drift` -> muestra normalización de contrato versus ruptura directa.
- `rate_limited` -> deja visible el valor del ahorro de cuota y el cache.
- `partial_payload` -> contrasta validación y degradación segura.
- `maintenance_window` -> muestra continuidad con snapshot local.

## 🧭 Qué observar

- si el flujo puede seguir con cache cuando el tercero no está disponible;
- cuándo aparecen nuevos schema mappings en el adapter;
- cómo cambia el budget restante de cuota;
- si la integración directa dispara eventos de cuarentena y errores más rápido.

## ⚖️ Nota de honestidad

No reemplaza una integración real con colas, DLQ ni proveedores de terceros. Sí reproduce las decisiones operativas que importan aquí: adapter, contrato defensivo, cache y amortiguación frente a cambios externos.
