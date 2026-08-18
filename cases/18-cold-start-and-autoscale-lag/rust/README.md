# 🦀 Caso 18 — Rust 1.83

<!-- nav-stack -->
[⬅️ Caso 18](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🦀 Perfil de Rust](../../../docs/languages/rust.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Rust del caso 18. Instancias frías con el tráfico ya encima contra un pool tibio, midiendo la disponibilidad **durante** el escalado.

## La curva más plana de los siete

`warmup_speedup_x` mide **1,00x exacto**. Rust compila AOT a código máquina: sin máquina virtual, sin JIT, sin recolector de basura que inicializar y sin runtime que levantar. El proceso arranca prácticamente en el tiempo que tarda el kernel en mapear el binario.

Después del [caso 17](../../17-zero-downtime-schema-migration/rust/README.md), donde la respuesta de Rust fue la peor de los siete, este es el reverso: acá el modelo de compilación es exactamente lo que el problema pide.

## `OnceLock`: lo que solo Rust ofrece

```rust
static TABLA: OnceLock<Vec<u32>> = OnceLock::new();
TABLA.get_or_init(|| construir());     // corre una vez, aunque la pidan 20 hilos

static CONFIG: LazyLock<Config> = LazyLock::new(|| Config::cargar());
```

`OnceLock` es el equivalente exacto de `sync.Once` de Go y de `Lazy<T>` de .NET, con una diferencia que ninguno de los dos tiene: **el tipo garantiza que el valor no se puede leer antes de estar inicializado**.

No hay un `null` intermedio que alguien pueda desreferenciar por accidente. `get()` devuelve `Option`, así que el estado «todavía no está lista» es **inalcanzable**, no solo improbable.

En este caso está explícito: la instancia guarda su tabla en un `OnceLock`, y el único camino para leerla pasa por el `Option`. **Olvidar el chequeo de readiness deja de ser un bug de runtime y pasa a ser un error de compilación.**

## Por qué queda segundo y no primero

Empata con Go en lo medido —1,00 contra 1,02— y le gana en garantías de tipo. Queda detrás por el otro lado del ciclo: **el tiempo de compilación**. En un caso que trata sobre la velocidad del bucle desplegar-escalar-desplegar, tardar varias veces más en producir el artefacto es un costo real, aunque no aparezca en ninguna métrica de runtime.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| Compilación AOT sin runtime | La curva plana, y sin GC que inicializar. |
| `OnceLock<T>` | Inicialización perezosa donde «no inicializada» es inalcanzable. |
| `LazyLock<T>` | Lo mismo, con el inicializador en la declaración. |
| `AtomicBool` | El flag de readiness sin mutex. |

## Rutas

| Ruta | Qué muestra |
|---|---|
| `/health` | **liveness**: responde 200 apenas el proceso arranca |
| `/ready` | **readiness**: responde 200 recién cuando la instancia puede servir |
| `/boot-cold?requests=2400&instances=3` | `rejected_cold_start` > 0 con el proceso vivo todo el tiempo |
| `/boot-warmed?requests=2400&instances=3` | `rejected_cold_start` = 0 y 100% de disponibilidad |
| `/warmup?instances=3&prime=1500` | construye el pool tibio antes de que llegue el tráfico |
| `/diagnostics/summary` | acumulado por variante, más la nota de fidelidad |
| `/reset-lab` | vacía la flota, el pool tibio y las métricas |

**Parámetros:** `requests` (100–20k), `instances` (1–32), `clients` (1–64), `io_ms` (parte de I/O del arranque), `pace_ms` (ritmo de llegada), `work_iters` (trabajo por petición), `prime` (peticiones de calentamiento del pool).

## Qué se mide y qué se modela

- **Se mide, no se simula:** la curva de calentamiento. El trabajo por petición es un lazo entero puro, idéntico en los siete stacks, sin un solo `sleep`. `p99_first_100_ms` contra `p99_after_1000_ms` es lo que ese runtime hace de verdad con el mismo código repetido.
- **Se modela:** la parte de I/O de la inicialización —abrir el pool, resolver DNS, negociar TLS— es un `sleep` de `io_ms`. Esperar a la red no quema CPU, y fijarla es lo que vuelve comparables a los siete stacks.
- **Es real:** la parte de CPU de la inicialización construye una tabla de configuración. Ese costo sí depende del runtime.

> ⚠️ En la variante fría, `p99_first_100_ms` mezcla dos efectos reales: el calentamiento del runtime **y** la contención con las instancias que están inicializando en paralelo. Los dos ocurren de verdad durante un arranque en frío de producción.

## Hub

```bash
docker compose -f compose.rust.yml up -d --build
curl "http://127.0.0.1:8700/18/boot-cold?requests=2400&instances=3"
curl "http://127.0.0.1:8700/18/boot-warmed?requests=2400&instances=3"
curl "http://127.0.0.1:8700/18/ready"
```

## Lo que ningún stack cambia

La inicialización cuesta lo que cuesta. **El trabajo no desaparece: se adelanta.**

Lo que decide si la aplicación devuelve 503 no es cuánto tarda en arrancar, sino `health_vs_ready_gap_ms` — cuánto tiempo el sistema afirma estar disponible sin estarlo.
