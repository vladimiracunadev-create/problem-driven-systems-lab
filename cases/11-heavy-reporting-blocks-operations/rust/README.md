# Caso 11 — Rust 1.83

Stack Rust operativo del caso 11. Reporte pesado sin acotar vs reporte con concurrencia limitada, midiendo si la operacion conserva aire.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `Mutex<usize>` + `Condvar` | Limitador de concurrencia: el que no consigue slot **duerme** hasta ser despertado, sin busy-wait. |
| `thread::yield_now()` | Cede el procesador durante trabajo CPU-bound. Equivalente del `Thread.yield()` de Java. |
| `thread::available_parallelism()` | Procesadores logicos disponibles, sin dependencias. |
| `AtomicI64` sobre `IN_FLIGHT` | Requests en vuelo — la medida honesta cuando no hay pool que consultar. |

## Contraste

**Legacy** — corre sin acotar en el thread del request:
```rust
let checksum = crunch(rows);   // nada limita cuantos de estos corren a la vez
```

**Isolated** — adquiere un slot; el que no lo consigue duerme en la Condvar:
```rust
fn acquire_slot() {
    let mut used = SLOTS_USED.lock().unwrap();
    while *used >= REPORTING_SLOTS {
        used = SLOT_FREED.wait(used).unwrap();   // duerme, no gira en vacio
    }
    *used += 1;
}
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/report-legacy?rows=200000` | `ran_on_pool: request-thread (sin acotar)` |
| `/report-isolated?rows=200000` | `ran_on_pool: reporting-limiter (max 2 concurrentes)` |
| `/order-write` | `degraded: true` si la latencia supera 100 ms |
| `/activity` | `main_pool_max` (cpus), slots usados, writes degradados |
| `/diagnostics/summary` | reportes por variante + snapshot de actividad |
| `/reset-lab` | reinicia contadores |

## Hub

```
docker compose -f compose.rust.yml up -d --build
for i in $(seq 1 8); do curl -s "http://127.0.0.1:8700/11/report-legacy?rows=3000000" > /dev/null & done
curl http://127.0.0.1:8700/11/order-write
```

## Por que este caso NO se traduce literal desde Java

Java y .NET aislan con pools de threads separados: un `ThreadPoolExecutor` de 4 para trafico y otro de 2 para reporting. Ese modelo no existe ni en Go ni en Rust — ninguno de los dos tiene un `ExecutorService` en su biblioteca estandar que copiar.

Pero Go y Rust tampoco estan en el mismo lugar, y esa diferencia es el aporte de este stack:

| | Go | Rust (`std`) |
|---|---|---|
| Unidad de concurrencia | goroutine, ~2 KB inicial | thread del SO, ~8 MB de stack virtual |
| Multiplexado | el runtime reparte N goroutines sobre `GOMAXPROCS` hilos | 1:1 — cada thread es un thread del kernel |
| Escala practica | cientos de miles de goroutines | miles de threads, no cientos de miles |

El modelo thread-per-connection de este stack es honesto para un laboratorio y **seria la primera cosa a cambiar en produccion**: ahi se usa `tokio`, que multiplexa tareas igual que Go multiplexa goroutines. Escribirlo con `std::thread` mantiene el caso sin dependencias y deja el trade-off a la vista en lugar de esconderlo detras de un runtime.

El limitador con `Mutex` + `Condvar` es mas verboso que el canal de Go, pero hace explicito lo que ocurre: el thread que no consigue slot **se duerme** y otro lo despierta al liberar. No hay busy-wait quemando CPU mientras espera, que es justamente lo que este caso mide.
