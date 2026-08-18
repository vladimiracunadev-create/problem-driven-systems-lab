# 🟢 Caso 18 — Node.js 22

<!-- nav-stack -->
[⬅️ Caso 18](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🟢 Perfil de Node.js](../../../docs/languages/node.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Node del caso 18. Instancias frías con el tráfico ya encima contra un pool tibio, midiendo la disponibilidad **durante** el escalado.

## V8 sí tiene JIT, y en capas

```
Ignition   → interpreta el bytecode
Sparkplug  → compila rápido, sin optimizar
Maglev     → optimiza con el perfil temprano
TurboFan   → optimiza en serio, y desoptimiza si el tipo cambia
```

La misma función se vuelve más rápida **solo por repetirse**. Y si el tipo de un argumento cambia, TurboFan desoptimiza y vuelve a empezar — un modo de falla que ni Go ni Rust tienen.

## Pero el cold start de Node no está ahí

`warmup_speedup_x` mide ≈1,1x en este caso: para un lazo entero simple, V8 llega a código optimizado casi de inmediato. **Es un resultado honesto y también incompleto**, porque el arranque en frío de Node no vive en el JIT: vive en el **grafo de `require`**.

```js
require('express')   // lee del disco, parsea y EJECUTA cada módulo del árbol
```

Un servicio con 800 dependencias transitivas tarda cientos de milisegundos leyendo, parseando y ejecutando módulos antes de la primera línea de código propio. Este caso no lo mide —el servidor no tiene dependencias— y por eso hay que decirlo en vez de dejar que el número lo tape.

## La salida existe, pero está fuera del camino

```bash
node --build-snapshot app.js    # serializa el heap ya inicializado
node --snapshot-blob snap.blob  # arranca desde ahí
```

Los snapshots y los SEA (*single executable applications*) resuelven buena parte del problema. No son el camino por defecto, y esa es la diferencia con .NET, donde `PublishReadyToRun` es una línea del `.csproj`.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `Promise.all` | Las instancias arrancan en paralelo sin hilos. |
| `await` entre peticiones | Le devuelve el event loop a los timers del arranque. |
| `--build-snapshot` | El AOT parcial de Node: el heap ya inicializado. |
| `Math.imul` | Multiplicación entera de 32 bits: el lazo idéntico a los otros seis. |

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
docker compose -f compose.nodejs.yml up -d --build
curl "http://127.0.0.1:8300/18/boot-cold?requests=2400&instances=3"
curl "http://127.0.0.1:8300/18/boot-warmed?requests=2400&instances=3"
curl "http://127.0.0.1:8300/18/ready"
```

## Lo que ningún stack cambia

La inicialización cuesta lo que cuesta. **El trabajo no desaparece: se adelanta.**

Lo que decide si la aplicación devuelve 503 no es cuánto tarda en arrancar, sino `health_vs_ready_gap_ms` — cuánto tiempo el sistema afirma estar disponible sin estarlo.
