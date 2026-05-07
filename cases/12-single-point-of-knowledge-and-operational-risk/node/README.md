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

Puerto local: `8212` (modo aislado, ver opciones abajo).

## Como consumir (dos opciones)

**Hub Node.js (recomendado, 8300 en `compose.nodejs.yml`):** este caso queda servido en `http://localhost:8300/12/...` junto a los otros 11 casos.

**Modo aislado (8212 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## Endpoints

```bash
curl http://localhost:8300/12/
curl http://localhost:8300/12/health
curl "http://localhost:8300/12/incident-legacy?scenario=owner_absent&domain=deployments"
curl "http://localhost:8300/12/incident-distributed?scenario=owner_absent&domain=deployments"
curl "http://localhost:8300/12/share-knowledge?domain=deployments&activity=runbook"
curl "http://localhost:8300/12/incidents?limit=10"
curl http://localhost:8300/12/diagnostics/summary
curl http://localhost:8300/12/metrics
curl http://localhost:8300/12/metrics-prometheus
curl http://localhost:8300/12/reset-lab
```

## Que observar

- Bajo `owner_absent`/`tribal_script`, legacy devuelve 500 con stacktrace; distributed devuelve 200 o 409 segun madurez;
- tras ejecutar `share-knowledge` repetidamente sobre un dominio, `coverage[domain]` sube y `mttr_min` baja;
- `bus_factor_min` se calcula como `min(backup_people + 1)` por dominio — refleja el peor caso operativo.
