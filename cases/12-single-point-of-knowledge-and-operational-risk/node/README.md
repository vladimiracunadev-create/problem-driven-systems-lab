# Punto unico de conocimiento y riesgo operacional — Node.js

> Implementacion operativa del caso 12 con paridad al stack PHP. El "runbook" esta codificado en el lenguaje: el modo distribuido usa **optional chaining** (`a?.b?.c ?? default`) para evitar el crash que sufre el modo legacy con acceso ciego.

## Que resuelve

Compara dos formas de manejar un incidente:

- `incident-legacy`: depende de la persona clave. Ante `owner_absent`/`tribal_script`, el codigo accede a una estructura anidada sin validar y rompe con `TypeError: Cannot read properties of undefined`.
- `incident-distributed`: usa optional chaining + nullish coalescing. El mismo escenario degrada con HTTP 409 si la madurez es muy baja, pero nunca explota.

Escenarios: `owner_available`, `owner_absent`, `night_shift`, `recent_change`, `tribal_script`.

Domains: `billing`, `deployments`, `integrations`, `reporting`. Activities: `runbook`, `pairing`, `drill`.

## Primitiva Node-especifica

```js
// Legacy: acceso ciego — equivalente a memoria tribal sin validacion
const _ = opaque.config.system[2].is_active;          // TypeError

// Distributed: el runbook codificado en el lenguaje
const _ = opaque?.config?.system?.[2]?.is_active ?? false;  // safe
```

Optional chaining no es un patch — es la encarnacion en codigo del runbook "si no esta documentado, asume el default seguro y reporta". Cuando se combina con `share-knowledge` (sumar runbook/pairing/drill por dominio), `mttr_min` baja y `bus_factor_min` sube de forma medible.

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `8212`.

## Endpoints

```bash
curl http://localhost:8212/
curl http://localhost:8212/health
curl "http://localhost:8212/incident-legacy?scenario=owner_absent&domain=deployments"
curl "http://localhost:8212/incident-distributed?scenario=owner_absent&domain=deployments"
curl "http://localhost:8212/share-knowledge?domain=deployments&activity=runbook"
curl "http://localhost:8212/incidents?limit=10"
curl http://localhost:8212/diagnostics/summary
curl http://localhost:8212/metrics
curl http://localhost:8212/metrics-prometheus
curl http://localhost:8212/reset-lab
```

## Que observar

- Bajo `owner_absent`/`tribal_script`, legacy devuelve 500 con stacktrace; distributed devuelve 200 o 409 segun madurez;
- tras ejecutar `share-knowledge` repetidamente sobre un dominio, `coverage[domain]` sube y `mttr_min` baja;
- `bus_factor_min` se calcula como `min(backup_people + 1)` por dominio — refleja el peor caso operativo.
