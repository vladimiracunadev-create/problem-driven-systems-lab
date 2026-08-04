# 🐹 Caso 08 — Go 1.23

<!-- nav-stack -->
[⬅️ Caso 08](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐹 Perfil de Go](../../../docs/languages/go.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Go operativo del caso 08. Cutover big-bang que rompe consumers vs proxy de compatibilidad + bus de eventos.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `chan busEvent` bufferizado | Bus de eventos. Publicacion **asincrona y desacoplada** del request. |
| `select` con `default` | Publicar sin bloquear: si el buffer esta lleno, se descarta el evento en vez de frenar trafico. |
| goroutine suscriptora | Consume a su ritmo, en su propio hilo de ejecucion. |
| funcion como ACL | `compatProxy(old) new` — el adapter de contrato es una funcion, no una clase. |

## Contraste

**Big-bang** — el modulo nuevo solo entiende `{price, currency}`:
```go
// consumer manda {sku, cost_usd} → contract_violation
"reason": "new module expects {price, currency}; consumer sent {sku, cost_usd}"
```

**Compatible** — el proxy traduce en vuelo:
```go
func compatProxy(old priceRequestOld) priceRequestNew {
    return priceRequestNew{SKU: old.SKU, Price: old.CostUSD * 1.0, Currency: "USD"}
}
```

Y el avance del cutover se publica sin bloquear al consumer:
```go
func emit(name string) {
    select {
    case cutoverBus <- busEvent{At: ..., Event: name}:
    default:                                   // buffer lleno → se descarta
    }
}
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/pricing-bigbang?consumer=checkout&sku=ABC&cost_usd=100` | `contract_violation` |
| `/pricing-compatible?consumer=checkout&sku=ABC&cost_usd=100` | traduce a `{price, currency}`, `cutover_done: true` |
| `/flows` | progreso del cutover por consumer + eventos recientes |
| `/diagnostics/summary` | llamadas, proxy hits y contract tests por variante |
| `/reset-lab` | reinicia progreso y eventos |

## Hub

```
docker compose -f compose.go.yml up -d --build
curl "http://127.0.0.1:8600/08/pricing-compatible?consumer=checkout&sku=ABC&cost_usd=100"
curl http://127.0.0.1:8600/08/flows
```

## Canal en vez de EventEmitter

Java modela el bus con `CopyOnWriteArrayList<Consumer<Event>>`; .NET con un `event` del CLR; Node con `EventEmitter`. Los tres comparten una propiedad que rara vez se nota hasta que duele: **el `emit()` corre los subscribers en el thread del request**. Un subscriber lento penaliza al consumer que disparo el evento.

Aca `emit()` empuja al canal y vuelve. La goroutine suscriptora consume despues, por su cuenta. La publicacion queda desacoplada del consumo sin montar una cola externa.

El `select` con `default` agrega una decision explicita que los otros stacks suelen dejar implicita: **si el buffer se llena, se pierde telemetria en vez de frenar trafico**. Esta escrita en dos lineas y es auditable. Es el mismo trade-off de backpressure que el caso 15 del roadmap estudia a fondo.
