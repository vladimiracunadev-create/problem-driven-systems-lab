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

Este caso implementa la topología de memoria y dependencias de PHP para exponer el caos del acoplamiento global frente a una estrategia de estrangulamiento controlada.

*   **Impacto Expandido (`legacy`):** El monolito se modela como una **God Class** instanciada mediante `stdClass`. El acoplamiento es físico: al simular que un equipo migra una dependencia y libera el puntero (`unset($monolithApp->sharedSessionDb)`), cualquier otro módulo que intente invocar métodos sobre dicho objeto lanza un **Fatal Error** inmediato. Esto demuestra el radio de explosión atomizado donde un cambio local interrumpe la ejecución de todo el proceso PHP-FPM debido a la falta de interfaces defensivas.
*   **Progresión por Consumidor (`strangler`):** Aplica los patrones **Facade** y **Adapter**. Se introduce un objeto mediador (`billingAdapter`) que encapsula la lógica de acceso a datos, implementando un **Anti-Corruption Layer (ACL)**. El progreso de la migración se gestiona de forma granular por invocador (`$state['migration']['consumers'][$consumer]`), permitiendo que PHP desvíe el tráfico hacia el nuevo módulo solo para consumidores validados mediante tests de contrato, manteniendo la compatibilidad hacia atrás y eliminando las regresiones en cadena.

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
