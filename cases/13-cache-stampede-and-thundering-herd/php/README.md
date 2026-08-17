# 🐘 Caso 13 — PHP 8.3

<!-- nav-stack -->
[⬅️ Caso 13](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐘 Perfil de PHP](../../../docs/languages/php.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack PHP del caso 13. Ráfaga de N llamadores sobre una clave que acaba de expirar, sin coordinación y con single-flight.

## La diferencia que aporta este stack

PHP **no tiene heap compartido entre requests**. Cada petición arranca un proceso limpio, corre y muere. El `Map<key, Promise>` de Node, el `ConcurrentHashMap` de Java y el `Mutex<HashMap>` de Rust no existen acá: cualquier estructura en memoria se evapora al terminar la request y el siguiente proceso no la ve.

Consecuencia directa, y es la lección del stack: **en PHP el single-flight no puede vivir en el proceso, tiene que vivir en el almacenamiento**. Un lock de archivo con `flock()`, un `apcu_add()`, un `SET NX` de Redis. Este caso usa `flock()` porque es lo único disponible sin extensiones ni servicios extra.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `flock($fh, LOCK_EX)` | Lock exclusivo entre procesos. Es el equivalente PHP del mutex que los otros stacks tienen en memoria. |
| `flock($fh, LOCK_EX \| LOCK_NB)` | Intento sin bloquear: sirve para detectar que otro ya está refrescando y devolver el valor stale sin esperar. |
| `random_int()` | Jitter del TTL. |

## El patrón completo — double-checked locking

```php
// 1. leer la cache (sin lock)
[, $state] = cacheLookup($key);
if ($state === 'fresh') { return; }

// 2. tomar el lock exclusivo
flock($lock, LOCK_EX);

// 3. VOLVER a leer: otro proceso pudo llenarla mientras esperábamos el lock
[, $recheck] = cacheLookup($key);
if ($recheck === 'fresh') {
    $waiters++;              // no hay que recalcular nada
} else {
    computeOrigin($key, $rounds);
}

// 4. soltar
flock($lock, LOCK_UN);
```

**El paso 3 es el que la gente omite.** Un lock sin double check no evita la estampida: la convierte en una estampida secuencial. El origen recibe las mismas N consultas, solo que ordenadas — y el `origin_computations` lo delata.

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
docker compose -f compose.root.yml up -d --build
curl "http://127.0.0.1:8100/13/cache-naive?key=k&concurrency=16&cost=40"
curl "http://127.0.0.1:8100/13/reset-lab"
curl "http://127.0.0.1:8100/13/cache-singleflight?key=k&concurrency=16&cost=40"
```

## Nota de fidelidad

El servidor embebido de PHP corre en un solo proceso, así que los N llamadores se recorren en secuencia y no en paralelo. Lo que se demuestra igual —y es lo que importa— es la primitiva: bajo PHP-FPM con N procesos reales, el lock de almacenamiento más el double check son exactamente lo que evita que el origen reciba la ráfaga completa.

`origin_computations` da el mismo número en los dos modelos de ejecución. `wall_ms` no, y por eso no se compara entre stacks.

## Dashboard

Con `Accept: text/html`, la raíz devuelve un panel para lanzar ambas variantes y ver el contraste lado a lado sin `curl`:

```bash
docker compose -f cases/13-cache-stampede-and-thundering-herd/php/compose.yml up -d --build
# abrir http://localhost:8113/
```
