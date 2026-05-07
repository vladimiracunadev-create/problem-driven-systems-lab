# 🚚 Caso 06 — Python 3.12 con delivery comparado

> Implementacion operativa del caso 06 para mostrar la diferencia entre un pipeline fragil y un flujo de entrega con validaciones y rollback.

## 🎯 Que resuelve

Modela despliegues hacia `dev`, `staging` y `prod` con escenarios operativos frecuentes:

- `deploy-legacy` detecta problemas tarde y puede dejar el ambiente degradado;
- `deploy-controlled` valida antes de tocar el ambiente, y puede hacer rollback automatico.

## 💼 Por que importa

No todos los incidentes de entrega vienen del codigo. Secretos faltantes, drift de configuracion o migraciones mal validadas tambien rompen sistemas que "en dev andaban bien". La diferencia entre detectar tarde y bloquear en preflight puede ser la diferencia entre un deploy fallido y un incidente de produccion.

## 🔬 Analisis Tecnico de la Implementacion (Python)

Los pipelines de entrega se modelan aqui como mutaciones de estado controladas por validaciones previas, donde los fallos son detectables mediante jerarquia de excepciones Python.

- **Implementacion `legacy` (Falla Post-Trafico):** La funcion `run_legacy_deployment()` ejecuta las etapas secuencialmente sin validar precondiciones. Cuando el escenario activa un fallo (ej. `missing_secret`), el codigo intenta acceder a una clave inexistente en el diccionario de configuracion, lo que lanza un `KeyError` nativo de Python. Este error se captura con un bloque generico `except Exception as e`, pero el ambiente ya fue mutado antes del fallo: `current_release` fue actualizado y el trafico fue redirigido. El ambiente queda en estado `degraded` sin rollback automatico.

- **Abstraccion Resiliente (`controlled`):** Introduce una arquitectura de **Preflight Checks** antes de cualquier mutacion de estado. Usa `if scenario_config.get("missing_secret")` y validaciones con `isinstance()` sobre los diccionarios de configuracion para detectar anomalias antes de tocar el ambiente. Si la validacion falla, el flujo lanza una excepcion controlada `DeploymentBlockedError` que es capturada antes de ejecutar `switch_traffic`, garantizando que `current_release` y `health` del ambiente no sean modificados. Si el fallo ocurre post-switch (smoke test), ejecuta un rollback atomico restaurando `current_release = last_good_release` y marcando `health: rollback`.

## 🧱 Servicio

- `app` → API Python 3.12 con estado por ambiente, historial de despliegues y metricas de rollback/bloqueos.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `836` (modo aislado, ver opciones abajo).

## Como consumir (dos opciones)

**Hub Python (recomendado, 8200 en `compose.python.yml`):** este caso queda servido en `http://localhost:8200/06/...` junto a los otros 11 casos.

**Modo aislado (836 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## 🔎 Endpoints

```bash
curl http://localhost:8200/06/
curl http://localhost:8200/06/health
curl "http://localhost:8200/06/deploy-legacy?environment=staging&release=2026.04.1&scenario=missing_secret"
curl "http://localhost:8200/06/deploy-controlled?environment=staging&release=2026.04.1&scenario=missing_secret"
curl http://localhost:8200/06/environments
curl "http://localhost:8200/06/deployments?limit=10"
curl http://localhost:8200/06/diagnostics/summary
curl http://localhost:8200/06/metrics
curl http://localhost:8200/06/metrics-prometheus
curl http://localhost:8200/06/reset-lab
```

## 🧪 Escenarios utiles

- `missing_secret` → el deploy falla por un secreto faltante; controlled bloquea en preflight.
- `config_drift` → divergencia de configuracion entre entornos.
- `failing_smoke` → smoke test falla tras el cambio de trafico; controlled hace auto-rollback.
- `migration_risk` → migracion con riesgo de incompatibilidad; controlled bloquea en staging.

## 🧭 Que observar

- si el problema se detecta antes o despues de tocar el ambiente (`stage_failed`);
- cuando el flujo controlado logra hacer rollback automatico;
- como cambia el `health` de `staging` o `prod` entre ambos modos;
- cuantas fallas quedan contenidas como `blocked` versus `degraded`.

## ⚖️ Nota de honestidad

No reemplaza un CI/CD real ni IaC completa. Si reproduce la logica que importa para conversar de delivery: preflight, smoke test, rollback y drift entre ambientes.
