# Arquitectura cara para un problema simple — Node.js

> Implementacion operativa del caso 10 con paridad al stack PHP. La sobrearquitectura se mide como CPU real: el modo `complex` realiza N rondas de `JSON.stringify`/`JSON.parse` sobre arrays grandes, castigando el event loop. El `right-sized` accede al mismo dato en O(1).

## Que resuelve

Compara dos formas de resolver el mismo requerimiento de negocio:

- `feature-complex`: 8-10 servicios coordinandose, hops serializados, cost mensual alto, lead time largo. Bajo `seasonal_peak` rompe con timeout.
- `feature-right-sized`: 2-4 servicios proporcionales al problema, acceso directo, cost / lead time / coordination todos significativamente menores.

Escenarios: `basic_crud`, `small_campaign`, `audit_needed`, `seasonal_peak`.

## Primitiva Node-especifica

El "costo" de la sobrearquitectura no es ficcion — es CPU real:

```js
if (mode === 'complex') {
  for (let hop = 0; hop < servicesTouched; hop++) {
    const json = JSON.stringify(entities);
    entities = JSON.parse(json);              // overhead de serializacion entre hops
    entities = entities.map((e) => Object.assign(Object.create(null), e));  // hidratacion
  }
}
```

Cada hop de servicio inter-proceso cuesta una ronda de stringify+parse+map. En `right_sized`, simplemente accedemos el primer elemento del array. La diferencia se observa en latencia y, bajo `seasonal_peak`, en un timeout duro.

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `8210`.

## Endpoints

```bash
curl http://localhost:8210/
curl http://localhost:8210/health
curl "http://localhost:8210/feature-complex?scenario=basic_crud&accounts=120"
curl "http://localhost:8210/feature-right-sized?scenario=basic_crud&accounts=120"
curl "http://localhost:8210/decisions?limit=10"
curl http://localhost:8210/diagnostics/summary
curl http://localhost:8210/metrics
curl http://localhost:8210/metrics-prometheus
curl http://localhost:8210/reset-lab
```

## Que observar

- `monthly_cost_usd` y `lead_time_days` son sustancialmente mayores en `complex`;
- `problem_fit_score` baja en `complex`: la solucion no acompana al problema;
- bajo `seasonal_peak` con `accounts >= 200`, complex cruza el umbral interno y devuelve 502;
- `simplification_backlog` baja con cada `right_sized` ejecutado.
