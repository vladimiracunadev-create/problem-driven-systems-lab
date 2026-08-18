# 🐹 Caso 18 — Go 1.23

<!-- nav-stack -->
[⬅️ Caso 18](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐹 Perfil de Go](../../../docs/languages/go.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Go del caso 18. Instancias frías con el tráfico ya encima contra un pool tibio, midiendo la disponibilidad **durante** el escalado.

## La curva sale plana, y ese es el resultado

`warmup_speedup_x` mide **≈1,0x**. No es que el experimento haya fallado: es la respuesta.

Go compila ahead-of-time a un binario estático. No hay máquina virtual que levantar, no hay JIT que calentar, no hay classloader, no hay opcache. El proceso arranca en el orden de milisegundos y **la petición número 1 corre exactamente el mismo código máquina que la número 100.000**.

Es el stack que gana este caso, y no por ser rápido: por **no tener nada que calentar**.

## La parte honesta: `sync.Once`

Lo que sí cuesta en Go es la inicialización perezosa, y la biblioteca estándar la hace explícita:

```go
var once sync.Once
once.Do(func() {
    pool = abrirPool()    // corre una vez, por más goroutines que la pidan
})
```

El primer llamador la ejecuta, el resto espera, y nunca corre dos veces. Es la forma idiomática de decir «esto cuesta, y cuesta una sola vez».

**Y también es la trampa.** Una `sync.Once` en el camino de la petición convierte a la primera petición de cada proceso en la más lenta de todas. La primitiva es correcta; dispararla con tráfico en vez de con el arranque es lo que no lo es.

## Lo que Go no resuelve

Nada de esto elimina el tiempo de abrir el pool de conexiones, resolver DNS o negociar TLS. **La ventaja de Go es sobre la mitad de CPU del arranque, no sobre la mitad de I/O** — y en un servicio real con cinco dependencias, la mitad de I/O suele ser la más grande.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| Compilación AOT a binario estático | La curva plana. |
| `sync.Once` | La inicialización perezosa, explícita y segura. |
| `atomic.Bool` | El flag de readiness sin mutex. |
| `sync.WaitGroup` | Arranque en paralelo y largada común de clientes. |

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
docker compose -f compose.go.yml up -d --build
curl "http://127.0.0.1:8600/18/boot-cold?requests=2400&instances=3"
curl "http://127.0.0.1:8600/18/boot-warmed?requests=2400&instances=3"
curl "http://127.0.0.1:8600/18/ready"
```

## Lo que ningún stack cambia

La inicialización cuesta lo que cuesta. **El trabajo no desaparece: se adelanta.**

Lo que decide si la aplicación devuelve 503 no es cuánto tarda en arrancar, sino `health_vs_ready_gap_ms` — cuánto tiempo el sistema afirma estar disponible sin estarlo.
