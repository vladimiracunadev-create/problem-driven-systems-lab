# 💸 Caso 10 - PHP 8.3 con comparación complex vs right-sized

> Implementación operativa del caso 10 para contrastar sobrearquitectura contra una solución proporcional.

## 🎯 Qué resuelve

Modela decisiones de arquitectura sobre necesidades acotadas:

- `feature-complex` reparte el problema entre demasiados servicios y coordinaciones;
- `feature-right-sized` resuelve el mismo caso con menos piezas, menos costo y menos demora.

## 💻 Interfaz Visual Nativa

Al abrir la ruta raíz en tu navegador (`Accept: text/html`), este caso inyecta automáticamente un **Dashboard visual interactivo** renderizado en Vanilla JS/CSS. Esto permite observar las métricas y efectos simulados en tiempo real sin perder la capacidad de responder a consultas JSON de CLI o Postman.

## 💼 Por qué importa

Este caso deja visible que decir “no” a complejidad innecesaria también es una habilidad de arquitectura. El riesgo no es solo pagar más: también se retrasa delivery y se abren más puntos de falla.

## 🔬 Análisis Técnico de la Implementación (PHP)

Demostrar "sobre-arquitectura" en un solo framework requiere modelar el costo de la redención y coordinación de recursos.

*   **Red de Microservicios Simulada (`complex`):** PHP evalúa la carga simulando cómo los límites de red afectan el tiempo. Utiliza factores de escala, calculando el costo en delays multiplicando (`$servicesTouched * 22 ms + jitter`). Expone que por más veloz que sea la ejecución de PHP de manera asincrónica pura, la *Coreografía* de la metadata compleja (`coordination: 7`) inevitablemente degrada el *Problem-Fit Score* e infla el costo mensual simulado que consume presupuesto por request.
*   **Diseño Proporcional (`right_sized`):** Muestra que frente a la misma aserción estructural (`basic_crud`), una aproximación monolítica o unificada (con un footprint de apenas 2 o 3 servicios) baja la complejidad de código base en PHP permitiendo que la respuesta sincrónica caiga enormemente en *Lead Time* (calculado en las simulaciones pasando la solicitud base con un factor costo bajo). La solución proporcional rinde hasta un 75% menos de MTTR al achicar radicalmente las invocaciones `usleep` derivadas del I/O inter-servicios.

## 🧱 Servicio

- `app` -> API PHP 8.3 con comparación de costo mensual, servicios tocados, lead time y coordinación requerida.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## 🔎 Endpoints

```bash
curl http://localhost:8110/
curl http://localhost:8110/health
curl "http://localhost:8110/feature-complex?scenario=basic_crud&accounts=120"
curl "http://localhost:8110/feature-right-sized?scenario=basic_crud&accounts=120"
curl http://localhost:8110/architecture/state
curl http://localhost:8110/decisions?limit=10
curl http://localhost:8110/diagnostics/summary
curl http://localhost:8110/metrics
curl http://localhost:8110/metrics-prometheus
curl http://localhost:8110/reset-lab
```

## 🧪 Escenarios útiles

- `basic_crud` -> deja claro el descalce entre complejidad y necesidad real.
- `small_campaign` -> muestra costo extra por una solución innecesariamente distribuida.
- `audit_needed` -> ayuda a ver que auditable no significa obligatoriamente complejo.
- `seasonal_peak` -> hace visible cómo la coordinación excesiva puede fallar en momentos críticos.

## 🧭 Qué observar

- cómo cambia el costo mensual entre ambos enfoques;
- cuántos servicios toca realmente cada variante;
- cuánto sube el lead time por coordinación extra;
- si el backlog de simplificación baja cuando se toma una decisión proporcional.

## ⚖️ Nota de honestidad

No pretende reemplazar un análisis financiero real ni una plataforma distribuida completa. Sí reproduce el trade-off central: complejidad operacional y costo versus adecuación real al problema de negocio.
