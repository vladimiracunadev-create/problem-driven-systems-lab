# 🟢 Caso 13 — Node.js 22

<!-- nav-stack -->
[⬅️ Caso 13](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🟢 Perfil de Node.js](../../../docs/languages/node.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Node del caso 13. Ráfaga de N llamadores sobre una clave que acaba de expirar, sin coordinación y con single-flight.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `Map<string, Promise>` | El single-flight entero. Una Promise ya es «un resultado que todavía no está, al que cualquiera puede suscribirse». |
| `setImmediate` | Cede el turno al event loop para que los N llamadores lleguen a la cache antes de que ninguno escriba. |
| `Math.imul` | Multiplicación entera de 32 bits para el digest del origen sin salir a `BigInt`. |

## Contraste

**Single-flight en tres líneas** — la versión más corta del patrón en todo el lab:
```js
const flight = computeOriginIfNeeded(key, rounds);
inflight.set(key, flight);          // ← el orden importa
try { didCompute = await flight; } finally { inflight.delete(key); }
```

Y por eso mismo la más fácil de escribir mal. Si el `Map.set` ocurre **después** del primer `await`, la ventana entre ambos deja pasar la estampida entera. En Java `computeIfAbsent` es atómico y no hay ventana que ordenar; acá la garantía la pone quien escribe el código.

## Dos honestidades sobre este stack

**1. El origen es CPU real, no `setTimeout`.** Con un timer el event loop absorbe N esperas sin costo y el caso no probaría nada. Lo que duele en una estampida real es que el origen **hace** el trabajo N veces.

**2. `stampede_depth` no cuenta núcleos.** Node tiene un solo hilo: los N digests se ejecutan en fila, no en paralelo. El daño no es contención de CPU sino que el event loop queda bloqueado N veces más tiempo — y con él, todo lo demás que el proceso tenía que atender.

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
docker compose -f compose.nodejs.yml up -d --build
curl "http://127.0.0.1:8300/13/cache-naive?key=k&concurrency=16&cost=40"
curl "http://127.0.0.1:8300/13/reset-lab"
curl "http://127.0.0.1:8300/13/cache-singleflight?key=k&concurrency=16&cost=40"
```

