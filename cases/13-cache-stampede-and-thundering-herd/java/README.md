# ☕ Caso 13 — Java 21

<!-- nav-stack -->
[⬅️ Caso 13](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [☕ Perfil de Java](../../../docs/languages/java.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Java del caso 13. Ráfaga de N hilos sobre una clave que acaba de expirar, sin coordinación y con single-flight.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `ConcurrentHashMap.computeIfAbsent` | **Atómico por clave.** El mapa mantiene el bin bloqueado mientras corre la función de mapeo: exactamente un hilo crea el Future. |
| `CompletableFuture` | El resultado compartido al que se cuelgan los seguidores con `join()`. |
| `CyclicBarrier` | Solo para el laboratorio: sincroniza la largada de los N llamadores en dos fases. |
| `LongAdder` | Contadores por variante bajo contención alta. |

## Lo decisivo: no hay ventana check-then-act

```java
CompletableFuture<Boolean> flight = inflight.computeIfAbsent(key, k -> {
    leader[0] = true;
    return CompletableFuture.supplyAsync(() -> {
        if ("fresh".equals(cacheState(k))) return false;   // double check
        computeOrigin(k, rounds);
        return true;
    }, originPool).whenComplete((v, err) -> inflight.remove(k));
});
```

En Node hay que ordenar a mano el `Map.set` antes del `await`. Acá **el contrato del mapa lo garantiza**: mirar si existe y crearlo son una sola operación indivisible.

## La sutileza que el código respeta

La función de mapeo de `computeIfAbsent` **no debe bloquear**. Si lo hiciera, el bin de esa clave queda tomado mientras el origen trabaja y cualquier otra operación sobre claves que caigan en el mismo bin se frena detrás. Por eso adentro solo se crea el `CompletableFuture` —barato— y el trabajo caro corre en `originPool`.

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
docker compose -f compose.java.yml up -d --build
curl "http://127.0.0.1:8400/13/cache-naive?key=k&concurrency=16&cost=40"
curl "http://127.0.0.1:8400/13/reset-lab"
curl "http://127.0.0.1:8400/13/cache-singleflight?key=k&concurrency=16&cost=40"
```

## Cold start

La primera ráfaga después de arrancar el contenedor puede tardar bastante más que las siguientes: el JIT todavía no compiló el bucle del digest. No es ruido de medición, es el fenómeno completo del [caso 18](../../18-cold-start-and-autoscale-lag/README.md). Para medir este caso conviene descartar la primera ejecución.
