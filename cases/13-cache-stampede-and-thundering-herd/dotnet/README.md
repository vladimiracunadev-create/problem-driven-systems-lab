# 🔵 Caso 13 — .NET 8

<!-- nav-stack -->
[⬅️ Caso 13](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🔵 Perfil de .NET](../../../docs/languages/dotnet.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack .NET del caso 13. Ráfaga de N llamadores sobre una clave que acaba de expirar, sin coordinación y con single-flight.

## El matiz que hace interesante a este stack

**`ConcurrentDictionary.GetOrAdd` no garantiza que la fábrica corra una sola vez.** La documentación lo dice explícitamente: si varios hilos entran a la vez, la fábrica puede ejecutarse N veces y solo una de las instancias gana el puesto en el diccionario.

Para una cache de valores eso es apenas desperdicio. Para un single-flight es **el bug entero**: el origen recibe la estampida igual, y el código parece correcto.

El arreglo idiomático es envolver el trabajo en `Lazy<Task<T>>`:

```csharp
var mine = new Lazy<Task<bool>>(
    () => Task.Run(() => { /* double check + origen */ }),
    LazyThreadSafetyMode.ExecutionAndPublication);

var flight   = Inflight.GetOrAdd(key, mine);
var isLeader = ReferenceEquals(flight, mine);
```

Aunque `GetOrAdd` construya varios `Lazy`, solo el que quedó en el diccionario recibe `.Value` — y el `Lazy` garantiza que su fábrica corre exactamente una vez.

Es el contraste directo con Java, donde `computeIfAbsent` **sí** es atómico y no hace falta la envoltura. Misma estructura de datos aparente, garantía distinta, y en .NET la garantía hay que traerla uno.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `Lazy<T>` con `ExecutionAndPublication` | La garantía real de ejecución única. |
| `ConcurrentDictionary` | El mapa de vuelos en curso. |
| `Interlocked` + `CompareExchange` | Contadores y el máximo de `stampede_depth` sin lock. |
| `TaskCompletionSource` | La compuerta asíncrona del laboratorio (ver abajo). |

## Por qué la compuerta es asíncrona y no un `Barrier`

`System.Threading.Barrier` bloquea el hilo que espera. Con 128 llamadores sobre el ThreadPool eso es un deadlock esperando a ocurrir: la barrera exige 128 hilos simultáneos y el pool los inyecta de a uno cada ~500 ms. Esperar con `await` sobre un `TaskCompletionSource` libera el hilo mientras tanto.

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
docker compose -f compose.dotnet.yml up -d --build
curl "http://127.0.0.1:8500/13/cache-naive?key=k&concurrency=16&cost=40"
curl "http://127.0.0.1:8500/13/reset-lab"
curl "http://127.0.0.1:8500/13/cache-singleflight?key=k&concurrency=16&cost=40"
```

