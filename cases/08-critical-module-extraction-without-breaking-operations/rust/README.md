# 🦀 Caso 08 — Rust 1.83

<!-- nav-stack -->
[⬅️ Caso 08](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🦀 Perfil de Rust](../../../docs/languages/rust.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Rust operativo del caso 08. Cutover big-bang que rompe consumers vs proxy de compatibilidad + bus de eventos.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `mpsc::channel` | Bus de eventos. **multi-producer, single-consumer** impuesto por el tipo. |
| `Sender` clonable / `Receiver` unico | El `Receiver` no implementa `Clone`: no puede haber dos consumidores. |
| structs separados para cada contrato | `PriceRequestOld` y `PriceRequestNew` son tipos distintos, no un mapa con claves opcionales. |
| `LazyLock<Mutex<HashMap>>` | Progreso del cutover por consumer. |

## Contraste

**Big-bang** — el modulo nuevo solo entiende `{price, currency}`:
```rust
"reason":"new module expects {price, currency}; consumer sent {sku, cost_usd}"
```

**Compatible** — el ACL es una funcion cuya firma documenta la traduccion:
```rust
fn compat_proxy(old: PriceRequestOld) -> PriceRequestNew {
    PriceRequestNew { sku: old.sku, price: old.cost_usd, currency: "USD" }
}
```

Y el avance del cutover se publica sin bloquear al consumer:
```rust
fn emit(name: &str) {
    if let Some(tx) = BUS_TX.lock().unwrap().as_ref() {
        let _ = tx.send(BusEvent { at: rfc3339_now(), event: name.to_string() });
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
docker compose -f compose.rust.yml up -d --build
curl "http://127.0.0.1:8700/08/pricing-compatible?consumer=checkout&sku=ABC&cost_usd=100"
curl http://127.0.0.1:8700/08/flows
```

## `mpsc`: el tipo dice cuantos consumidores hay

Go y Rust resuelven el bus igual en espiritu —canal + hilo suscriptor, publicacion desacoplada del consumo— pero el tipo dice cosas distintas:

```go
ch := make(chan busEvent, 256)   // Go: cualquiera puede enviar Y recibir
```
```rust
let (tx, rx) = mpsc::channel();  // Rust: tx se clona, rx es UNICO
```

`mpsc` significa multi-producer, **single-consumer**, y el compilador lo impone: `Receiver` no implementa `Clone`. Si alguien intentara consumir el bus desde dos threads, no compila.

En Go, dos goroutines leyendo el mismo canal se reparten los mensajes en silencio. A veces es exactamente lo que querias —un pool de workers— y a veces es la razon por la que la mitad de tus eventos de auditoria terminaron en el consumidor equivocado. El canal no distingue una intencion de la otra; el tipo de Rust si.

**Diferencia honesta con el stack Go:** alli el `emit()` usa `select` con `default` y descarta el evento si el buffer esta lleno, declarando explicitamente que se prefiere perder telemetria antes que frenar trafico. Aca el canal de `std` no es acotado, asi que `send` no bloquea ni descarta: la cola crece. Es una eleccion distinta con un riesgo distinto —memoria en vez de latencia— y el caso 15 del roadmap es el que estudia esa decision a fondo.
