# 🧩 Caso 08 - PHP 8.3 con extracción compatible

> Implementación operativa del caso 08 para contrastar una extracción big bang contra una ruta segura con proxy, contratos y cutover por consumidor.

## 🎯 Qué resuelve

Modela la separación de un módulo crítico de pricing:

- `pricing-bigbang` intenta moverlo de una vez y expone incompatibilidades;
- `pricing-compatible` conserva el contrato público y migra consumidores gradualmente.

## 💻 Interfaz Visual Nativa

Al abrir la ruta raíz en tu navegador (`Accept: text/html`), este caso inyecta automáticamente un **Dashboard visual interactivo** renderizado en Vanilla JS/CSS. Esto permite observar las métricas y efectos simulados en tiempo real sin perder la capacidad de responder a consultas JSON de CLI o Postman.

## 💼 Por qué importa

Este caso deja visible un patrón muy frecuente: el riesgo de una extracción no está solo en el código nuevo, sino en romper compatibilidad operativa mientras el sistema sigue vendiendo.

## 🔬 Análisis Técnico de la Implementación (PHP)

Previamente un ejercicio matemático, ahora este caso implementa verdaderos cruces de contratos mediante Arrays y Exceptions en vivo, emulando la comunicación entre código arcaico y módulos extraídos.

*   **Big-Bang (`legacy`):** Mover el módulo "de una vez" y redireccionar ciegamente sin un Adapter rompe la firma de la interfaz. Si un cliente no migrado envía un payload Legacy (por ejemplo `cost_usd` en lugar de la nueva key `price`), la función moderna dispara inmediatamente un **Warning nativo de "Undefined Array Key"**. Implementé un estricto validador que detecta esto y lo propaga destructivamente usando `InvalidArgumentException`.
*   **Extracción Compatible (`strangler / proxy`):** Se introduce una estructura puente en PHP implementando el Patrón Adapter (*Adapter Pattern*). Antes de llegar al procesador de negocio, interceptamos el payload asimétrico, y de forma silenciosa e instantánea usamos constructos modernos de PHP (`$data['price'] = $data['cost_usd'] ?? 0;`) mitigando la caída. El proxy absorbe las diferencias y protege a la capa, sin romper producción, manteniendo tu API viva (200 OK) mientras gradualmente refactorizas tus clientes.

## 🧱 Servicio

- `app` -> API PHP 8.3 con consumidores sensibles, proxy de compatibilidad y estado de cutover persistido localmente.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## 🔎 Endpoints

```bash
curl http://localhost:818/
curl http://localhost:818/health
curl "http://localhost:818/pricing-bigbang?scenario=rule_drift&consumer=checkout"
curl "http://localhost:818/pricing-compatible?scenario=rule_drift&consumer=checkout"
curl "http://localhost:818/cutover/advance?consumer=checkout"
curl http://localhost:818/extraction/state
curl http://localhost:818/flows?limit=10
curl http://localhost:818/diagnostics/summary
curl http://localhost:818/metrics
curl http://localhost:818/metrics-prometheus
curl http://localhost:818/reset-lab
```

## 🧪 Escenarios útiles

- `rule_drift` -> muestra contratos que cambian entre consumidores.
- `shared_write` -> hace visible el peligro de estados compartidos.
- `peak_sale` -> enfatiza por qué no conviene cortar compatibilidad en una ventana crítica.
- `partner_contract` -> muestra integración externa dependiente del contrato legado.

## 🧭 Qué observar

- cuánto blast radius deja cada estrategia;
- si suben los contract tests y el progreso por consumidor;
- cuándo el proxy de compatibilidad absorbe el riesgo;
- cómo cambia el riesgo de corte entre una extracción total y una gradual.

## ⚖️ Nota de honestidad

No representa un rollout real con múltiples servicios ni feature flags distribuidos. Sí reproduce lo importante aquí: contratos, compatibilidad, cutover progresivo y protección operacional del cambio.
