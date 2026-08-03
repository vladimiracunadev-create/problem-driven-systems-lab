# Caso 09 — Rust 1.83

Stack Rust operativo del caso 09. Provider inestable sin red de contencion vs budget de cuota + snapshot cache + breaker.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `Mutex<i64>` con decremento condicional | Budget de cuota. El guard libera al salir de scope, en todos los caminos. |
| `RwLock<HashMap<String,String>>` | Snapshot cache con lecturas concurrentes. |
| `Mutex<&'static str>` | Estado del breaker. |
| `AtomicI64` | Contadores por variante. |

## Contraste

**Legacy** — cada request pega al provider; un drift de esquema es un fallo al usuario:
```rust
if is_drift(scenario) {
    LEGACY_FAILURES.fetch_add(1, Ordering::Relaxed);
    return "status":"failed";     // sin cache, sin budget
}
```

**Hardened** — primero el budget, despues el provider, y snapshot si algo falla:
```rust
fn try_acquire_budget() -> bool {
    let mut permits = PROVIDER_BUDGET.lock().unwrap();
    if *permits <= 0 { return false; }   // el guard libera aca tambien
    *permits -= 1;
    true
}

if !try_acquire_budget() { return from_snapshot(sku, "budget_exhausted", ...); }
if is_drift(scenario)    { *BREAKER.lock().unwrap() = "open";
                           return from_snapshot(sku, "provider_failing", ...); }
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/catalog-legacy?sku=widget-A&scenario=drift` | `status: failed` |
| `/catalog-hardened?sku=widget-A&scenario=drift` | `served_from_cache`, breaker `open` |
| `/catalog-hardened?...&scenario=ok` (×6) | las 5 primeras van al provider; la 6ª cae a cache por budget agotado |
| `/sync-events` | breaker, `budget_remaining`, tamaño del snapshot cache |
| `/diagnostics/summary` | llamadas, hits de cache y denegaciones por budget |
| `/reset-lab` | rellena el budget y cierra el breaker |

## Hub

```
docker compose -f compose.rust.yml up -d --build
for i in $(seq 1 6); do curl -s "http://127.0.0.1:8700/09/catalog-hardened?sku=widget-A&scenario=ok" | head -c 90; echo; done
curl http://127.0.0.1:8700/09/sync-events
```

Verificado: `budget_remaining` baja 4, 3, 2, 1, 0 y la sexta llamada devuelve `served_from_cache`.

## El unlock que no existe

`std` de Rust no tiene semaforo, igual que Go. Go usa un canal bufferizado y el `select` con `default` le da un `tryAcquire()` elegante en dos lineas. Aca el budget es mas prosaico: un `Mutex<i64>` que se decrementa si hay permisos.

En expresividad, Go gana. Pero hay una garantia que Rust da y Go no:

```rust
let mut permits = PROVIDER_BUDGET.lock().unwrap();
if *permits <= 0 {
    return false;      // el MutexGuard se libera AQUI, automaticamente
}
*permits -= 1;
true                   // y aca tambien
```

El guard libera al salir de scope **en todos los caminos de retorno**. En Go, un `mu.Lock()` cuyo `defer mu.Unlock()` falta en una rama de error es un deadlock silencioso que compila y pasa los tests felices. Esa categoria de bug no existe en este codigo, porque no hay unlock que escribir ni que olvidar.
