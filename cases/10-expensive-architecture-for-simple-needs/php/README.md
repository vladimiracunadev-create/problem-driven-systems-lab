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

La sobre-arquitectura y el abuso de patrones DTO tienen repercusiones físicas sobre la latencia y el procesamiento de PHP. Este caso modela el desgaste de CPU mediante operaciones de serialización masiva.

*   **Complejidad Innecesaria (`complex`):** Recrea el overhead inter-servicios mediante un algoritmo de **hidratación profunda**. El script genera arreglos masivos de miles de entidades e itera profundamente ejecutando ciclos de `json_encode` y `json_decode` seguidos de conversiones a objeto `(object)$v` en cada salto de "coordinación". Esta redundancia algorítmica de complejidad **`O(N * Hops)`** devora los tiempos de CPU del worker y aumenta el footprint de memoria, provocando latencias artificiales que escalan linealmente con el número de capas, simulando los retardos de red de una arquitectura fragmentada.
*   **Diseño Proporcional (`right_sized`):** Aplica una estrategia de acceso directo a datos con **complejidad asintótica `O(1)`**. Omite el mapeo redundante de objetos y las transformaciones JSON innecesarias, extrayendo el valor directamente desde la fuente en memoria: `$val = $directData[0]['id']`. Esto permite que PHP despache la misma aserción de negocio utilizando una fracción mínima de recursos, optimizando el *Lead Time* y reduciendo el costo operacional por request.

## 🧱 Servicio

- `app` -> API PHP 8.3 con comparación de costo mensual, servicios tocados, lead time y coordinación requerida.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## Como consumir (dos opciones)

**Hub PHP (recomendado, 8100 en `compose.root.yml`):** este caso queda servido en `http://localhost:8100/10/...` junto a los otros 11 casos.

**Modo aislado (8110 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## 🔎 Endpoints

```bash
curl http://localhost:8100/10/
curl http://localhost:8100/10/health
curl "http://localhost:8100/10/feature-complex?scenario=basic_crud&accounts=120"
curl "http://localhost:8100/10/feature-right-sized?scenario=basic_crud&accounts=120"
curl http://localhost:8100/10/architecture/state
curl http://localhost:8100/10/decisions?limit=10
curl http://localhost:8100/10/diagnostics/summary
curl http://localhost:8100/10/metrics
curl http://localhost:8100/10/metrics-prometheus
curl http://localhost:8100/10/reset-lab
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
