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

Al extraer módulos en PHP no basta con aislar las clases, hay que proteger la operabilidad en vivo (el *Cutover*).

*   **Big-Bang (`legacy`):** Mover el módulo "de una vez" provoca que cualquier asimetría de reglas (`rule_drift`), concurrencia agresiva (`peak_sale`) o partición de escritura (`shared_write`) quiebre agresivamente la compatibilidad pública, disparando errores fatales (409/500/502).
*   **Extracción Compatible (`strangler / proxy`):** Se introduce una estructura puente en PHP en la que se manipulan flujos *Shadow Traffic*. A medida que `advanceCutover()` se ejecuta, no se enruta a ciegas; el "proxy compatible" eleva gradualmente (`$step = 25`) el enrutado de consumo, absorbiendo picos y diferencias estructurales y preservando un `status_code 200` garantizado por el contrato frontal, independientemente de qué motor de fondo (viejo o nuevo) esté resolviendo la aserción tras bambalinas.

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
