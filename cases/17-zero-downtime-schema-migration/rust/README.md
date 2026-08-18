# 🦀 Caso 17 — Rust 1.83

<!-- nav-stack -->
[⬅️ Caso 17](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🦀 Perfil de Rust](../../../docs/languages/rust.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Rust del caso 17. Un `ALTER TABLE` bloqueante contra expand-contract, con lectores golpeando la tabla mientras tanto.

## El caso donde la respuesta de Rust es la peor de las siete

`std::sync::RwLock` **no tiene deadline de ninguna clase**. Hay `read()`, que espera para siempre, y `try_read()`, que no espera nada. No existe `try_read_for(Duration)` — eso vive en `parking_lot`, que es una crate externa, y este lab compila sin red.

Java tiene `tryLock(timeout, unit)`, .NET `TryEnterReadLock(ms)`, Python se lo construye con `Condition.wait`, Go lo arma con goroutine y `select`, PHP lo tiene de fábrica con `LOCK_NB`. Rust, en la `std`, no ofrece ninguna de las cinco cosas.

Así que el deadline se arma con un spin acotado:

```rust
loop {
    if let Ok(guard) = TABLE.try_read() { return true; }
    if Instant::now() >= deadline { return false; }
    thread::sleep(Duration::from_micros(200));
}
```

Funciona, es honesto, y **es peor**: consume CPU mientras espera en vez de dormir en el kernel.

Vale decirlo con el mismo énfasis con el que se dicen sus ventajas en los casos [12](../../12-single-point-of-knowledge-and-operational-risk/rust/README.md), [14](../../14-connection-pool-exhaustion/rust/README.md) y [16](../../16-idempotency-and-duplicate-effects/rust/README.md). Un laboratorio que solo muestra dónde gana un lenguaje no es un laboratorio, es publicidad.

## Lo que Rust sí aporta acá

Los **guards**. `RwLockReadGuard` y `RwLockWriteGuard` sueltan el lock en su `Drop`, así que **no existe el camino de salida que olvida el unlock**.

En Go hay que escribir el `defer`, en Java el `finally`, en .NET el `try/finally`. Acá no hay línea que olvidar — igual que en el caso 14 con el pool de conexiones.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `std::sync::RwLock` | El lock. Sin deadline de ningún tipo. |
| `try_read()` en spin acotado | El deadline armado a mano, con su costo de CPU. |
| `RwLockWriteGuard` | Suelta el lock en su `Drop`: no hay unlock que olvidar. |
| `std::sync::Barrier` | La largada común de lectores y migración. |

## Las cuatro fases, y por qué ese orden

1. **Expand** — agregar la columna nullable. Es metadata: instantáneo.
2. **Backfill** — rellenar por lotes, soltando el lock entre cada uno.
3. **Switch** — un feature flag cambia lecturas y escrituras a la columna nueva.
4. **Contract** — recién ahora, en un despliegue posterior, se borra la vieja.

**El switch va antes del contract** porque el flag es lo único reversible en un segundo. Si se borra la columna vieja primero, volver atrás requiere otra migración — y a esa altura ya no hay a dónde volver.

## Rutas

| Ruta | Qué muestra |
|---|---|
| `/health` | liveness |
| `/migrate-blocking?rows=20000&readers=8` | `readers_failed` > 0 y `longest_single_lock_ms` = la migración entera |
| `/migrate-expand-contract?rows=20000&readers=8&batch=2000&pause_ms=5` | `readers_failed` = 0 y el lock más largo = un lote |
| `/migration/state` | fase actual, progreso del backfill y estado del feature flag |
| `/backfill?batch=2000` | un lote suelto, para ver el efecto de a uno |
| `/diagnostics/summary` | acumulado por variante |
| `/reset-lab` | vuelve la tabla al esquema viejo |

**Parámetros:** `rows` (1k–500k), `readers` (1–64 lectores concurrentes), `ms_per_1k` (costo de migrar mil filas), `batch` (tamaño de lote), `pause_ms` (pausa entre lotes).

## Hub

```bash
docker compose -f compose.rust.yml up -d --build
curl "http://127.0.0.1:8700/17/migrate-blocking?rows=20000&readers=8"
curl "http://127.0.0.1:8700/17/migrate-expand-contract?rows=20000&readers=8&batch=2000&pause_ms=5"
curl "http://127.0.0.1:8700/17/migration/state"
```

## Lo que ningún stack cambia

`lock_held_ms` es prácticamente el mismo en las dos variantes. **El trabajo no desaparece: se reparte.**

Lo que decide si la aplicación se cae no es el tiempo total sino `longest_single_lock_ms` — y esa es la métrica que casi nunca está en el plan de migración.
