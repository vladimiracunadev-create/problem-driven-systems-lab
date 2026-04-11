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

Los pipelines no son cajas negras; se materializan en código de estado que valida las aserciones en vivo. Este caso muestra el contraste entre actuar al final y verificar antes.

*   **Implementación `legacy`:** La función `runLegacyDeployment()` procesa pasos de configuración como migraciones o secretos *sin condicionales previos*. Si ocurre un "Missing Secret", el ambiente cambia ciegamente el estado a `$env['health'] = 'degraded'` y aborta en pleno bloque. Como el entorno general ya mutó, restaurarlo requiere paradas manuales y tiempo.
*   **Abstracción Resiliente (`controlled`):** El flujo controlado (`runControlledDeployment`) inyecta aserciones *Preflight*. Usa validaciones con base estructural, por ejemplo chequeos de array estricto para secretos anticipados (que en lo real sería una prueba de integridad contra Vault). Si falla *smoke-test* tras cambios (simulado con demoras o inyecciones de datos), un sistema estático de salvaguarda intercepta la caída programada y recupera inmediatamente la versión `$previousRelease` hacia la capa general de configuración (`$state['environments'][$environment] = $env`), ejecutando un *rollback* que mantiene la métrica de salud en estado `ok`.

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
