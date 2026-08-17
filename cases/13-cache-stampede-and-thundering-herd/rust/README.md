# 🦀 Caso 13 — Rust 1.83

<!-- nav-stack -->
[⬅️ Caso 13](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🦀 Perfil de Rust](../../../docs/languages/rust.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Rust del caso 13. Ráfaga de N hilos sobre una clave que acaba de expirar, sin coordinación y con single-flight.

## Lo que este stack no tiene, y por qué importa

Node resuelve esto con una Promise compartida, Java con un `CompletableFuture`, .NET con un `Lazy<Task<T>>`. Los tres apoyan el patrón en un objeto «resultado futuro» que el runtime ya trae.

La `std` de Rust **no tiene ninguno**: no hay executor, no hay `Future` ejecutable sin un runtime externo como tokio. Lo que sí trae es la pieza de más abajo — `Condvar` — que es el mecanismo que los otros runtimes tienen escondido adentro de su primitiva de alto nivel.

```rust
struct Flight {
    result: Mutex<Option<bool>>,
    ready:  Condvar,
}

// seguidor
let guard = flight.result.lock().unwrap();
let done  = flight.ready.wait_while(guard, |r| r.is_none()).unwrap();

// líder
*flight.result.lock().unwrap() = Some(did_compute);
flight.ready.notify_all();
```

## Lo que el compilador aporta y ningún otro stack del lab tiene

El `Arc<Flight>` es **obligatorio**. En Go o Java uno puede quedarse con un puntero a una entrada que otro hilo ya borró del mapa y el código compila igual; acá no hay forma de expresar eso. El seguidor se lleva su propio `Arc` clonado y el vuelo vive exactamente mientras alguien lo mire, sin que nadie tenga que acordarse de nada.

`wait_while` en vez de `wait` tampoco es cosmético: protege del *spurious wakeup*, el despertar sin notificación que el sistema operativo puede producir. Con `wait` a secas el seguidor podría leer un `None` y seguir de largo.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `Condvar` + `wait_while` | La espera del seguidor, inmune a spurious wakeups. |
| `Arc<Flight>` | Garantiza que el vuelo sobreviva a su propia entrada en el mapa. |
| `Mutex<HashMap>` | Registro de vuelos en curso. |
| `std::sync::Barrier` | Largada del laboratorio. Es reutilizable, así que el mismo objeto sirve para las dos fases. |
| `AtomicI64::fetch_max` | `stampede_depth` sin lock. |

## Rutas

| Ruta | Qué muestra |
|---|---|
| `/health` | liveness |
| `/cache-naive?key=report-alpha&concurrency=16&cost=40` | `origin_computations` = `concurrency`: el origen recibe la ráfaga entera |
| `/cache-singleflight?key=report-alpha&concurrency=16&cost=40` | `origin_computations` = 1, `coalesced_waiters` = `concurrency - 1` |
| `/cache/state` | edad, soft TTL, hard TTL y jitter aplicado por clave |
| `/diagnostics/summary` | acumulado por variante y `origin_total_computations` |
| `/reset-lab` | vacía cache y contadores |

**Parámetros:** `key` (clave a golpear), `concurrency` (1–128 llamadores simultáneos), `cost` (1–400 rondas de trabajo del origen; cada ronda son 2.000 iteraciones de CPU real).

## Hub

```bash
docker compose -f compose.rust.yml up -d --build
curl "http://127.0.0.1:8700/13/cache-naive?key=k&concurrency=16&cost=40"
curl "http://127.0.0.1:8700/13/reset-lab"
curl "http://127.0.0.1:8700/13/cache-singleflight?key=k&concurrency=16&cost=40"
```

