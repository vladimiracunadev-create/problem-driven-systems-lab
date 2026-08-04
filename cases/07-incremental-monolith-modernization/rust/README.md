# 🦀 Caso 07 — Rust 1.83

<!-- nav-stack -->
[⬅️ Caso 07](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🦀 Perfil de Rust](../../../docs/languages/rust.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Rust operativo del caso 07. Cambio acoplado en el monolito vs strangler con tabla de routing por consumer.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `Box<dyn Fn(&Request) -> Response + Send + Sync>` | Handler con despacho dinamico **y** garantia de thread-safety verificada por el compilador. |
| `LazyLock<RwLock<HashMap<..>>>` | Tabla de routing: lecturas concurrentes, escritura exclusiva al registrar una migracion. |
| `AtomicI64` | Contadores por variante. |

## Contraste

**Legacy** — el cambio toca el `shared_schema` y propaga a los 4 modulos:
```rust
"blast_radius_score":4,"risk_score":8
```

**Strangler** — la tabla decide; el monolito no se toca:
```rust
table.insert("billing:change".to_string(), Box::new(|_req: &Request| Response {
    routed_to: "new-billing-svc", blast_radius_score: 1, risk_score: 1,
}));

if let Some(handler) = table.get(&key) {
    let r = handler(&req);          // modulo nuevo
} else {
    // fallback al monolito, acotado por ACL: blast radius 2 en vez de 4
}
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/change-legacy?consumer=billing&op=change` | `blast_radius_score: 4`, `risk_score: 8` |
| `/change-strangler?consumer=billing&op=change` | `routed_to: new-billing-svc`, blast radius `1` |
| `/change-strangler?consumer=orders&op=change` | `routed_to: legacy-monolith`, blast radius `2` (aun no migrado) |
| `/flows` | progreso de migracion por modulo + tamaño de la tabla |
| `/diagnostics/summary` | llamadas por variante y cuantas fueron al modulo nuevo |
| `/reset-lab` | reinicia contadores |

## Hub

```
docker compose -f compose.rust.yml up -d --build
curl "http://127.0.0.1:8700/07/change-strangler?consumer=billing&op=change"
curl "http://127.0.0.1:8700/07/change-strangler?consumer=orders&op=change"
```

## `Send + Sync` en la firma del handler

La tabla guarda `Box<dyn Fn(&Request) -> Response + Send + Sync>`. Esos dos marcadores al final no son decoracion:

- `Send` → el valor puede moverse entre threads.
- `Sync` → puede compartirse por referencia entre threads.

El compilador los **verifica en el punto de registro**. Si alguien intenta registrar un closure que captura algo no thread-safe —un `Rc`, un `RefCell`— el codigo no compila.

En Java, un `Function<Request,Response>` guardado en un `ConcurrentHashMap` puede capturar estado mutable no sincronizado sin que nadie avise: el mapa es concurrente, el closure no. En un strangler eso importa mas que en otros contextos, porque los handlers nuevos se registran mientras hay trafico y son justo el codigo menos probado del sistema.

`RwLock` y no `Mutex` porque el patron de acceso es asimetrico: se lee en cada request y se escribe una vez por migracion.
