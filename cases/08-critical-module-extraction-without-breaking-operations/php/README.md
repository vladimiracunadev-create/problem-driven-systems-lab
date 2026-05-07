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

Este caso implementa cruces de contratos mediante manipulaciones de arreglos asociativos y excepciones en vivo, emulando la comunicación asimétrica entre código arcaico y módulos extraídos.

*   **Big-Bang (`legacy`):** Representa un redireccionamiento crudo sin capa de compatibilidad. Si un cliente envía un payload con llaves legadas (ej: `cost_usd` en lugar de la nueva clave `price`), el motor moderno de PHP dispara un **Warning** de "Undefined Array Key" al intentar acceder al índice inexistente. El sistema detecta esta colisión y lanza un **`InvalidArgumentException`**, simulando una ruptura total del contrato que detiene la operación de precios y bloquea el checkout.
*   **Extracción Compatible (`strangler / proxy`):** Implementa el **Adapter Pattern** a nivel de estructura de datos. Antes de procesar la lógica de negocio, un interceptor normaliza el input utilizando el operador de fusión de nulidad (`??`): `$data['price'] = $data['price'] ?? $data['cost_usd'] ?? $data['legacy_val']`. Este algoritmo de mapeo elástico permite que PHP absorba las asimetrías de los esquemas sin romper la firma de la función, asegurando un `status_code 200` y permitiendo una migración de clientes ("Shadow Traffic" style) sin afectar la disponibilidad del servicio.

## 🧱 Servicio

- `app` -> API PHP 8.3 con consumidores sensibles, proxy de compatibilidad y estado de cutover persistido localmente.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## Como consumir (dos opciones)

**Hub PHP (recomendado, 8100 en `compose.root.yml`):** este caso queda servido en `http://localhost:8100/08/...` junto a los otros 11 casos.

**Modo aislado (818 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## 🔎 Endpoints

```bash
curl http://localhost:8100/08/
curl http://localhost:8100/08/health
curl "http://localhost:8100/08/pricing-bigbang?scenario=rule_drift&consumer=checkout"
curl "http://localhost:8100/08/pricing-compatible?scenario=rule_drift&consumer=checkout"
curl "http://localhost:8100/08/cutover/advance?consumer=checkout"
curl http://localhost:8100/08/extraction/state
curl http://localhost:8100/08/flows?limit=10
curl http://localhost:8100/08/diagnostics/summary
curl http://localhost:8100/08/metrics
curl http://localhost:8100/08/metrics-prometheus
curl http://localhost:8100/08/reset-lab
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
