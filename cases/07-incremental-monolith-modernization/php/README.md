# 🏗️ Caso 07 - PHP 8.3 con modernización incremental comparada

> Implementación operativa del caso 07 para contrastar un cambio sobre un monolito acoplado contra una ruta strangler con migración gradual.

## 🎯 Qué resuelve

Modela cambios sobre un dominio crítico con dos enfoques:

- `change-legacy` toca demasiados módulos y mantiene alto el blast radius;
- `change-strangler` usa ACL, contratos y migración progresiva por consumidor.

## 💻 Interfaz Visual Nativa

Al abrir la ruta raíz en tu navegador (`Accept: text/html`), este caso inyecta automáticamente un **Dashboard visual interactivo** renderizado en Vanilla JS/CSS. Esto permite observar las métricas y efectos simulados en tiempo real sin perder la capacidad de responder a consultas JSON de CLI o Postman.

## 💼 Por qué importa

La modernización incremental no es solo una preferencia arquitectónica: es una forma de bajar riesgo real mientras el negocio sigue operando.

## 🔬 Análisis Técnico de la Implementación (PHP)

Previamente una simulación de variables, este caso ahora implementa topología real de memoria de PHP exponiendo el caos que genera el acoplamiento global.

*   **Impacto Expandido (`legacy`):** Un simple cambio se modela impactando una red completa instanciando una `God Class` (`$monolithApp = new \stdClass()`). Sin barreras, al simular que un módulo migra soltando la dependencia compartida (`unset()`), cualquier otro módulo nativo que la llame a fondo arroja un `Crash` real de PHP interrumpiendo la compilación. El acoplamiento es físico.
*   **Progresión por Consumidor (`strangler`):** Introduce una frontera dura programática aislando la inyección mediante un *Anti-Corruption Layer (ACL)*. Utilizando Facades/Adapters (`$billingAdapter = new \stdClass(); $billingAdapter->fetchData = ...`), evitamos la colisión de ramas y dependencias faltantes en PHP, tolerando el esquema nuevo sin infectar al núcleo.

## 🧱 Servicio

- `app` -> API PHP 8.3 con progreso por consumidor, cobertura del módulo extraído y métricas de blast radius.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## 🔎 Endpoints

```bash
curl http://localhost:817/
curl http://localhost:817/health
curl "http://localhost:817/change-legacy?scenario=shared_schema&consumer=web"
curl "http://localhost:817/change-strangler?scenario=shared_schema&consumer=web"
curl http://localhost:817/migration/state
curl http://localhost:817/flows?limit=10
curl http://localhost:817/diagnostics/summary
curl http://localhost:817/metrics
curl http://localhost:817/metrics-prometheus
curl http://localhost:817/reset-lab
```

## 🧪 Escenarios útiles

- `billing_change` -> cambio frecuente con alto acoplamiento en legacy.
- `shared_schema` -> evidencia por qué el ACL importa en una transición.
- `parallel_work` -> muestra el costo de coordinar todo el monolito frente a una frontera más clara.

## 🧭 Qué observar

- cuántos módulos toca cada enfoque;
- cómo cambia el `blast_radius_score`;
- si sube el progreso por consumidor cuando se usa la ruta incremental;
- cómo evolucionan contratos y cobertura del módulo extraído.

## ⚖️ Nota de honestidad

No reemplaza un monolito real ni un programa completo de replatforming. Sí reproduce lo importante para discutir modernización segura: acoplamiento, ACL, migración por consumidor y reducción gradual del radio de impacto.
