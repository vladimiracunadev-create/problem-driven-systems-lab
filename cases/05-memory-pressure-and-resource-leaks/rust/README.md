# 🦀 Caso 05 — Rust 1.83

<!-- nav-stack -->
[⬅️ Caso 05](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🦀 Perfil de Rust](../../../docs/languages/rust.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Rust operativo del caso 05. `Vec` global que crece sin limite vs cache LRU acotada, con liberacion deterministica y contada.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `impl Drop for Tracked` | Destructor propio que **descuenta bytes vivos y cuenta liberaciones**. Sin GC. |
| `VecDeque` + `HashMap` | LRU construida a mano: orden de uso + indice. |
| `LazyLock<Mutex<Lru>>` | `HashMap::new()` no es `const`, asi que no puede ir en un `static`. `LazyLock` difiere la construccion al primer uso. |
| `AtomicI64` | `live_bytes` y `dropped_total` observables por `/state`. |

## Contraste

**Legacy** — la fuga:
```rust
let payload = Tracked::new(size_kb * 1024);
LEGACY_ACCUMULATOR.lock().unwrap().push(payload);   // nada lo saca nunca
```

**Optimized** — la LRU evicciona y el `Drop` corre en el acto:
```rust
cache.index.insert(key, payload);
cache.order.push_back(key);
if cache.order.len() > OPTIMIZED_CAP {
    if let Some(oldest) = cache.order.pop_front() {
        cache.index.remove(&oldest);   // el Tracked sale de scope → Drop AQUI
        OPTIMIZED_EVICTIONS.fetch_add(1, Ordering::Relaxed);
    }
}
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/batch-legacy?size_kb=64` | `retained_count` crece monotonicamente |
| `/batch-optimized?size_kb=64` | `retained_count` se estabiliza en `cap=1000` |
| `/state` | `live_bytes`, `live_mb`, `dropped_total`, retenidos por variante |
| `/diagnostics/summary` | contraste + snapshot del runtime |
| `/reset-lab` | vacia acumuladores; el `Drop` libera en el acto |

## Hub

```
docker compose -f compose.rust.yml up -d --build
for i in $(seq 1 5); do curl -s "http://127.0.0.1:8700/05/batch-legacy?size_kb=512" > /dev/null; done
curl http://127.0.0.1:8700/05/state
curl http://127.0.0.1:8700/05/reset-lab
curl http://127.0.0.1:8700/05/state
```

Verificado: tras 5 cargas de 512 KB, `live_mb: 2` y `dropped_total: 0`. Despues del reset, `live_mb: 0` y `dropped_total: 5`. La liberacion ocurre en el `reset`, no "en algun momento".

## Lo que Rust garantiza — y lo que NO

**Garantiza:** liberacion deterministica. No hay GC, no hay pausa, no hay heuristica ni hilo de fondo decidiendo cuando. El `Drop` de `Tracked` corre exactamente cuando el valor sale de scope, y este caso lo hace **observable**: `dropped_total` es contabilidad real del destructor, no una estimacion. Ningun otro stack del laboratorio puede mostrar esa cifra.

**NO garantiza:** que no haya fugas. El borrow checker **no impide esta fuga**. Meter cosas en un `Vec` global y no sacarlas nunca es codigo perfectamente seguro y perfectamente legal: compila sin un solo warning. Rust previene use-after-free, doble free y data races — no previene "guardar de mas".

Esa es la leccion cruzada del caso, y es la razon por la que existe en los siete stacks:

- En PHP, Python, Node, Java, .NET y Go la fuga es memoria **referenciada** de mas que el GC no puede tocar.
- En Rust es memoria **retenida** de mas que el programador nunca solto.

Distinto mecanismo, identico bug de diseño, identico grafico de heap subiendo hasta el OOM. Quien crea que elegir Rust lo protege de este caso, no leyo el caso.
