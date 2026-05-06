# Extraccion de modulo critico sin romper operacion — Node.js

> Implementacion operativa del caso 08 con paridad al stack PHP. Compatibilidad de contrato implementada con `Proxy` nativo + `EventEmitter` para emitir cada avance de cutover.

## Que resuelve

Compara dos formas de extraer un modulo critico de pricing:

- `pricing-bigbang`: corta el modulo de una vez. Si los consumidores no estan alineados al nuevo contrato, rompe en el path critico (TypeError / 409 / 502 segun escenario).
- `pricing-compatible`: el llamado pasa por un Proxy de compatibilidad que traduce campos (`cost_usd` → `price`) en vuelo y avanza el cutover por consumidor; el bus de eventos publica cada avance.

Escenarios: `stable`, `rule_drift`, `shared_write`, `peak_sale`, `partner_contract`. Consumers: `checkout`, `marketplace`, `backoffice`, `partner_api`.

## Primitiva Node-especifica

```js
const compatibilityProxy = new Proxy(newPricingModule, {
  get(target, prop, receiver) {
    if (prop === 'computeFinalPrice') {
      return (payload) => {
        if (payload?.cost_usd !== undefined && payload.price === undefined) {
          payload = { ...payload, price: payload.cost_usd };
        }
        return Reflect.get(target, prop, receiver).call(target, payload);
      };
    }
    return Reflect.get(target, prop, receiver);
  },
});
```

El codigo de negocio sigue llamando `pricing.computeFinalPrice(payload)`. La traduccion vive en el Proxy, no en el negocio. Adicionalmente un `EventEmitter` (`cutoverBus`) publica cada `advance` y mantiene un log circular en memoria.

## Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `828`.

## Endpoints

```bash
curl http://localhost:828/
curl http://localhost:828/health
curl "http://localhost:828/pricing-bigbang?scenario=rule_drift&consumer=checkout"
curl "http://localhost:828/pricing-compatible?scenario=rule_drift&consumer=checkout"
curl "http://localhost:828/cutover/advance?consumer=checkout&step=25"
curl http://localhost:828/extraction/state
curl "http://localhost:828/flows?limit=10"
curl http://localhost:828/diagnostics/summary
curl http://localhost:828/metrics
curl http://localhost:828/metrics-prometheus
curl http://localhost:828/reset-lab
```

## Que observar

- `pricing-bigbang` con `rule_drift` rompe con TypeError; `pricing-compatible` lo absorbe via Proxy y devuelve 200;
- `cutover_log` muestra cada avance emitido por el EventEmitter;
- `app_consumer_cutover_progress{consumer="..."}` en Prometheus permite ver el corte gradual.
