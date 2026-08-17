# 🔵 Caso 14 — .NET 8

<!-- nav-stack -->
[⬅️ Caso 14](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🔵 Perfil de .NET](../../../docs/languages/dotnet.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack .NET del caso 14. Un pool que se achica en silencio contra uno con devolución garantizada.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `SemaphoreSlim.WaitAsync(timeout)` | La adquisición con deadline. **Devuelve `false`, no lanza.** |
| `Lease : IDisposable` + `using var` | La devolución garantizada. |
| `ConcurrentBag<Conn>` | Las conexiones libres. |
| `Task.Delay` | El tiempo de retención de la conexión. |

## Dos detalles que distinguen a este stack

**1. El timeout es un valor de retorno, no una excepción.**

```csharp
if (!await _permits.WaitAsync(timeoutMs)) return null;
```

Eso hace que «no había conexión» y «la conexión falló» sean dos caminos distintos en el código — que es exactamente la distinción que el llamador necesita para decidir si reintentar o rendirse. En Python el timeout llega como `queue.Empty`, y hay que capturarlo para no confundirlo con un error de la query.

**2. `using var` hace que el código correcto sea más corto que el incorrecto.**

```csharp
using var held = lease;        // sin bloque anidado
try { ... } catch { ... }
```

El compilador genera el `finally` que llama a `Dispose()` en todos los caminos de salida — la misma garantía que try-with-resources en Java. La diferencia es de forma: `using var` no necesita anidar. **Es el único stack del laboratorio donde hacer lo correcto ahorra líneas.**

## Rutas

| Ruta | Qué muestra |
|---|---|
| `/health` | liveness |
| `/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25` | `leaked` > 0 y `hung` creciente: el pool se vacía y no vuelve |
| `/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25` | `leaked` = 0 y `pool_available_after` = `pool_size` |
| `/pool/state` | tamaño, disponibles, adquiridas, devueltas y fugadas |
| `/diagnostics/summary` | acumulado por variante + ley de Little |
| `/reset-lab` | reconstruye el pool y limpia contadores |

**Parámetros:** `requests` (1–200 llamadores), `pool` (1–64 conexiones), `query_ms` (1–500, cuánto retiene cada query), `fail_rate` (0–100 %, porcentaje de queries que lanzan).

## Hub

```bash
docker compose -f compose.dotnet.yml up -d --build
curl "http://127.0.0.1:8500/14/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25"
curl "http://127.0.0.1:8500/14/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25"
curl "http://127.0.0.1:8500/14/pool/state"
```

## Por qué acá el trabajo sí es un `sleep`

En el [caso 13](../../13-cache-stampede-and-thundering-herd/README.md) un `sleep` habría escondido el punto: lo que duele en una estampida es que el origen **hace** el trabajo N veces, así que hubo que quemar CPU de verdad.

Acá es al revés. Una conexión se retiene mientras se **espera a la red**, no mientras se calcula. Dormir es el modelo fiel del tiempo de retención; quemar CPU mediría otra cosa y además competiría con los propios hilos del laboratorio.

La misma decisión, tomada en sentidos opuestos, por la misma razón: modelar el recurso que realmente escasea.

