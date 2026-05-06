# Pipeline roto y entrega fragil — Node.js

> Implementacion operativa del caso 06 con paridad funcional al stack PHP, usando primitivas nativas de Node 20: `AbortController` + `AbortSignal` para cancelacion cooperativa del pipeline.

## Que resuelve

Compara dos formas de desplegar:

- `deploy-legacy`: ejecuta los pasos del pipeline sin validacion previa. Si el secreto falta o la migracion no esta lista, el sistema lo descubre **despues** de cambiar trafico — el ambiente queda degradado.
- `deploy-controlled`: hace `preflight_validation` antes de tocar el ambiente, despliega canary, hace `smoke_test` y, si el smoke falla, ejecuta `rollback` automatico al ultimo release sano.

Escenarios: `ok`, `missing_secret`, `config_drift`, `failing_smoke`, `migration_risk`.

## Primitiva Node-especifica

Cada paso del pipeline corre dentro de un `AbortController`. El handler engancha `req.once('close', ...)` para abortar si el cliente desconecta, y cada `stepDelay()` escucha el `AbortSignal` con `signal.addEventListener('abort', ...)` — los pasos restantes nunca se ejecutan. Es limpieza cooperativa de pipeline en pocas lineas, sin polling de un flag global.

## Servicio

`app` — API Node.js 20 con dos rutas de deploy, telemetria persistida en `tmp/`, metricas Prometheus.

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `826`.

## Endpoints

```bash
curl http://localhost:826/
curl http://localhost:826/health
curl "http://localhost:826/deploy-legacy?environment=staging&release=2026.04.1&scenario=missing_secret"
curl "http://localhost:826/deploy-controlled?environment=staging&release=2026.04.1&scenario=missing_secret"
curl http://localhost:826/environments
curl "http://localhost:826/deployments?limit=10"
curl http://localhost:826/diagnostics/summary
curl http://localhost:826/metrics
curl http://localhost:826/metrics-prometheus
curl http://localhost:826/reset-lab
```

## Que observar

- En `deploy-legacy` con `missing_secret`/`migration_risk`, la respuesta es 500 y el ambiente queda en `degraded`;
- en `deploy-controlled` el mismo escenario corta en `preflight_validation` con HTTP 409, sin tocar el ambiente;
- en `failing_smoke`, controlled emite el step `rollback` y devuelve 502 con `previous_release` intacto;
- el campo `app_deploy_rollbacks_total{mode="controlled"}` en Prometheus permite cuantificar la diferencia.
