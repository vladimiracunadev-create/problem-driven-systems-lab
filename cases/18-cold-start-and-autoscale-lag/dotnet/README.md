# 🔵 Caso 18 — .NET 8

<!-- nav-stack -->
[⬅️ Caso 18](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🔵 Perfil de .NET](../../../docs/languages/dotnet.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack .NET del caso 18. Instancias frías con el tráfico ya encima contra un pool tibio, midiendo la disponibilidad **durante** el escalado.

## El mismo problema que Java, con la respuesta en la caja

`warmup_speedup_x` mide **≈2,3x**: hay curva, es real y es mucho menor que la de la JVM.

```
Tier 0  → compila rápido, sin optimizar
Tier 1  → recompila optimizado a los ~30 llamados
OSR     → cambia de nivel sin salir de un lazo largo
```

RyuJIT llega a Tier 1 mucho antes que C2 —treinta llamados contra diez mil—, así que la penalización de arranque existe pero es de otro orden.

## Y después están las tres líneas

```xml
<PublishReadyToRun>true</PublishReadyToRun>   <!-- precompila a nativo -->
<TieredPGO>true</TieredPGO>                   <!-- el perfil sobrevive al arranque -->
<PublishAot>true</PublishAot>                 <!-- AOT nativo: curva eliminada -->
```

Eso es todo. Sin cambiar de distribución, sin un toolchain aparte, sin renunciar a la reflexión salvo en el caso de AOT completo. Es el mismo abanico que Java resuelve con AppCDS y GraalVM, con una diferencia de fricción que en la práctica decide si alguien lo usa o no.

**Esa es la razón por la que .NET queda tercero y Java séptimo en este caso**, aunque las herramientas de Java sean más potentes: la diferencia entre «existe» y «está puesto».

## Lo que NO salva de esto

`Lazy<T>`, `SemaphoreSlim`, `ReaderWriterLockSlim`, `AsyncLocal`. Todas son primitivas correctas para otras cosas — pero el costo del arranque en frío **no está en la sincronización**, está en que el código todavía no está compilado. Ninguna primitiva de concurrencia arregla eso.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `PublishReadyToRun` | El AOT parcial: Tier 0 arranca ya compilado. |
| `TieredPGO` | El perfil de ejecución sobrevive entre arranques. |
| `PublishAot` | Nativo completo: sin JIT y sin curva. |
| `Task.WhenAll` | Las instancias arrancan en paralelo sin bloquear hilos. |
| `volatile bool` | El flag de readiness, visible entre hilos sin lock. |

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
docker compose -f compose.dotnet.yml up -d --build
curl "http://127.0.0.1:8500/18/boot-cold?requests=2400&instances=3"
curl "http://127.0.0.1:8500/18/boot-warmed?requests=2400&instances=3"
curl "http://127.0.0.1:8500/18/ready"
```

## Lo que ningún stack cambia

La inicialización cuesta lo que cuesta. **El trabajo no desaparece: se adelanta.**

Lo que decide si la aplicación devuelve 503 no es cuánto tarda en arrancar, sino `health_vs_ready_gap_ms` — cuánto tiempo el sistema afirma estar disponible sin estarlo.
