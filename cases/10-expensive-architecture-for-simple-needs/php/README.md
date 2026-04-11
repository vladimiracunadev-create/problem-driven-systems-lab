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

Demostrar "sobre-arquitectura" y diseño DTO desmedido tiene repercusiones físicas puras sobre la Unidad de Procesamiento (CPU) y la Memoria de PHP. Se abandonó el uso de matemática de retrasos para demostrar fallos mecánicos verdaderos por desgaste de RAM.

*   **Red de Microservicios Simulada (`complex`):** Recrea el overhead inter-servicios de una arquitectura inflada. En lugar de retrasos artificiales, PHP genera un array masivo de miles de entidades e itera profundamente (`for ($hop = 0; $hop < $servicesTouched; $hop++)`), provocando deliberadamente una serialización severa con `json_encode` y mapeo por objetos (`(object)$v`) en cada vuelta. Este ciclo devora los recursos forzando a PHP a estancarse y levantar una excepción real en los picos (`Gateway Timeout`).
*   **Diseño Proporcional (`right_sized`):** Muestra que frente a la misma aserción estructural, el código monolítico estructurado omite todo el mapeo DTO intermedio y localiza el vector directamente (`$val = $directData[0]['id']`). Logra amortizar la misma respuesta de milisegundos con una asintótica algorítmica `O(1)`, validando por qué es mejor evitar particiones excesivas.

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
