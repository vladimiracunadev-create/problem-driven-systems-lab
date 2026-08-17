# 🐹 Caso 13 — Go 1.23

<!-- nav-stack -->
[⬅️ Caso 13](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐹 Perfil de Go](../../../docs/languages/go.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Go del caso 13. Ráfaga de N goroutines sobre una clave que acaba de expirar, sin coordinación y con single-flight.

## La respuesta canónica cabe en 25 líneas de stdlib

Go tiene la solución oficial a este problema en `golang.org/x/sync/singleflight`. Pero eso es un módulo externo, y este lab compila sin red. Resulta que no hace falta: el patrón entero se escribe con la biblioteca estándar, y escribirlo a mano es más didáctico que importarlo.

```go
type call struct {
    wg  sync.WaitGroup
    did bool
}

func do(key string, fn func() bool) (bool, bool) {
    flightMu.Lock()
    if c, ok := flights[key]; ok {
        flightMu.Unlock()      // soltar ANTES de esperar
        c.wg.Wait()
        return c.did, false
    }
    c := new(call)
    c.wg.Add(1)
    flights[key] = c
    flightMu.Unlock()

    c.did = fn()
    c.wg.Done()

    flightMu.Lock()
    delete(flights, key)
    flightMu.Unlock()
    return c.did, true
}
```

La pieza es `sync.WaitGroup` usada al revés de como se usa normalmente. En vez de «el coordinador espera a los trabajadores», acá **el líder** hace `Add(1)` antes de empezar y `Done()` al terminar, y los seguidores hacen `Wait()`. Un WaitGroup es un contador con espera; eso es exactamente un single-flight con una sola operación pendiente.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `sync.WaitGroup` | El contador con espera que hace de single-flight. |
| `sync.Mutex` + `map` | El registro de vuelos en curso. |
| `chan struct{}` cerrado | Largada común de las N goroutines: `close(start)` las libera a todas de una vez. |
| `sync/atomic` + `CompareAndSwap` | `stampede_depth` sin lock. |

## Por qué `map` con mutex y no `sync.Map`

La regla práctica: `sync.Map` gana cuando las claves se escriben una vez y se leen muchas. Acá cada entrada se crea y se borra en **cada expiración** — justo el patrón en el que `sync.Map` va peor que un map normal bajo mutex.

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
docker compose -f compose.go.yml up -d --build
curl "http://127.0.0.1:8600/13/cache-naive?key=k&concurrency=16&cost=40"
curl "http://127.0.0.1:8600/13/reset-lab"
curl "http://127.0.0.1:8600/13/cache-singleflight?key=k&concurrency=16&cost=40"
```

