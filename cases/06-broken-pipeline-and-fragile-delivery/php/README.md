# 🚚 Caso 06 - PHP 8.3 con delivery comparado

> Implementación operativa del caso 06 para mostrar la diferencia entre un pipeline frágil y un flujo de entrega con validaciones y rollback.

## 🎯 Qué resuelve

Modela despliegues hacia `dev`, `staging` y `prod` con escenarios operativos frecuentes:

- `deploy-legacy` detecta problemas tarde y puede dejar el ambiente degradado;
- `deploy-controlled` valida antes de tocar el ambiente, despliega en canary y puede hacer rollback.

## 💻 Interfaz Visual Nativa

Al abrir la ruta raíz en tu navegador (`Accept: text/html`), este caso inyecta automáticamente un **Dashboard visual interactivo** renderizado en Vanilla JS/CSS. Esto permite observar las métricas y efectos simulados en tiempo real sin perder la capacidad de responder a consultas JSON de CLI o Postman.

## 💼 Por qué importa

No todos los incidentes de entrega vienen del código. Secretos faltantes, drift de configuración o migraciones mal validadas también rompen sistemas que "en dev andaban bien".

## 🔬 Análisis Técnico de la Implementación (PHP)

Previamente concebidos como simulaciones figurativas, los pipelines aquí resueltos ahora se materializan en código estructural sólido de PHP donde los fallos **son transaccionalmente reales y detectables mediante jerarquía de excepciones**.

*   **Implementación `legacy` (Excepción Nativa):** La función `runLegacyDeployment()` ejecuta cambios de estado sin validación de pre-requisitos. Al invocar un escenario de error (`missing_secret`), el código intenta acceder a propiedades inexistentes o instanciar clases ausentes, lo que dispara un **`RuntimeException`** o un **`Error`** nativo de PHP. Estos son capturados mediante un bloque genérico `catch (\Throwable $e)`, permitiendo que el laboratorio exponga el `getTraceAsString()` real y demuestre cómo una falla no controlada deja el sistema en un estado inconsistente (`health: degraded`).
*   **Abstracción Resiliente (`controlled`):** Introduce una arquitectura de **Pruebas de Integridad Preflight**. Antes de mutar el estado global, utiliza constructos defensivos como `class_exists()` y `isset()` sobre diccionarios de configuración. Si estas aserciones fallan, el flujo se interrumpe *antes* de afectar la operación, permitiendo un rollback atómico (`$env = $previousRelease`) que garantiza la continuidad del servicio con un `status_code 200` y métricas de salud intactas.

## 🧱 Servicio

- `app` -> API PHP 8.3 con estado por ambiente, historial de despliegues y métricas de rollback/bloqueos.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## 🔎 Endpoints

```bash
curl http://localhost:816/
curl http://localhost:816/health
curl "http://localhost:816/deploy-legacy?environment=staging&release=2026.04.1&scenario=missing_secret"
curl "http://localhost:816/deploy-controlled?environment=staging&release=2026.04.1&scenario=missing_secret"
curl http://localhost:816/environments
curl http://localhost:816/deployments?limit=10
curl http://localhost:816/diagnostics/summary
curl http://localhost:816/metrics
curl http://localhost:816/metrics-prometheus
curl http://localhost:816/reset-lab
```

## 🧪 Escenarios útiles

- `missing_secret` -> el deploy falla por un secreto faltante.
- `config_drift` -> el ambiente no coincide con la configuración esperada.
- `failing_smoke` -> el problema aparece después del cambio de tráfico.
- `migration_risk` -> la migración no debía aplicarse sin validación previa.

## 🧭 Qué observar

- si el problema se detecta antes o después de tocar el ambiente;
- cuándo el flujo controlado logra hacer rollback automático;
- cómo cambia la salud de `staging` o `prod` entre ambos modos;
- cuántas fallas quedan contenidas como `blocked` versus `failed`.

## ⚖️ Nota de honestidad

No reemplaza un CI/CD real ni IaC completa. Sí reproduce la lógica que importa para conversar de delivery: preflight, canary, smoke test, rollback y drift entre ambientes.
