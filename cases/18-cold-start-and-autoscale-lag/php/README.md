# 🐘 Caso 18 — PHP 8.3

<!-- nav-stack -->
[⬅️ Caso 18](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐘 Perfil de PHP](../../../docs/languages/php.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack PHP del caso 18. Instancias frías con el tráfico ya encima contra un pool tibio, midiendo la disponibilidad **durante** el escalado.

## El único stack que arranca en frío en cada petición, por diseño

PHP es share-nothing: la petición termina, el proceso descarta todo el estado, y la siguiente empieza de cero. Lo que en Java es un problema de despliegue, en PHP sería un problema de **cada request**.

Si no fuera por `opcache`:

```ini
opcache.enable=1                  ; compila cada .php a opcodes UNA vez
opcache.preload=/app/preload.php  ; y los deja cargados antes del primer request
```

Es el equivalente exacto de `PublishReadyToRun` de .NET o de AppCDS de Java, con dos diferencias que importan:

- **Viene activado de fábrica** en cualquier imagen oficial. No hay que decidirlo.
- **Su caché la comparten los procesos**, no los hilos. Un worker nuevo de FPM nace con los opcodes ya compilados por los que arrancaron antes.

El corolario incómodo: cada worker nuevo vuelve a pagar **lo que opcache no cubre** — construir el contenedor de servicios, leer configuración, abrir el pool. El pool tibio de PHP no es código, es configuración:

```ini
pm.start_servers = 8       ; el pool tibio de PHP
pm.min_spare_servers = 4   ; los que se mantienen listos por si sube el tráfico
```

## Sobre el JIT

PHP 8.3 tiene uno (`opcache.jit`), pero **viene apagado** por defecto y solo paga en código CPU-bound. Por eso `warmup_speedup_x` sale ≈1,1x acá: no hay curva de calentamiento que medir, igual que en Python y por la misma razón de fondo.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `opcache` | La caché de opcodes compartida entre procesos. El AOT de PHP. |
| `opcache.preload` | Carga las clases antes del primer request. |
| `pm.start_servers` | El pool tibio, en configuración de FPM. |
| Modelo share-nothing | Ningún estado sobrevive a la petición: cold start estructural. |

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

### Nota de fidelidad específica de PHP

El servidor embebido es de un solo proceso, así que el arranque no puede correr en paralelo con el tráfico. Se modela con un **instante de disponibilidad**: la instancia declara su `ready_at` y toda petición anterior se rechaza. El costo de CPU de la inicialización sí se ejecuta de verdad; lo que se modela es el solapamiento.

## Hub

```bash
docker compose -f compose.root.yml up -d --build
curl "http://127.0.0.1:8100/18/boot-cold?requests=2400&instances=3"
curl "http://127.0.0.1:8100/18/boot-warmed?requests=2400&instances=3"
curl "http://127.0.0.1:8100/18/ready"
```

## Dashboard

```bash
docker compose -f cases/18-cold-start-and-autoscale-lag/php/compose.yml up -d --build
# abrir http://localhost:8118/
```

## Lo que ningún stack cambia

La inicialización cuesta lo que cuesta. **El trabajo no desaparece: se adelanta.**

Lo que decide si la aplicación devuelve 503 no es cuánto tarda en arrancar, sino `health_vs_ready_gap_ms` — cuánto tiempo el sistema afirma estar disponible sin estarlo.
