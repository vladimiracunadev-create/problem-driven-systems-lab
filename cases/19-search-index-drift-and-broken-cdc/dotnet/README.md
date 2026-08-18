# 🔵 Caso 19 — .NET 8

<!-- nav-stack -->
[⬅️ Caso 19](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🔵 Perfil de .NET](../../../docs/languages/dotnet.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack .NET del caso 19. Dual-write contra outbox con checkpoint y barrido, midiendo las tres caras de la deriva.

## LINQ convierte el diagnóstico en una consulta

```csharp
var missing = dbLive.Keys.Except(index.Keys);
var orphan  = index.Keys.Except(dbLive.Keys);
var stale   = dbLive.Join(index, d => d.Key, i => i.Key, (d, i) => new { d, i })
                    .Where(p => p.d.Value.Version != p.i.Value.Version);
```

Go no tiene tipo conjunto y lo escribe a mano; Java lo tiene pero mutando copias; Python lo dice más corto, sin el `Join` tipado. **.NET es el único que expresa las tres caras como una sola forma** —consultas sobre secuencias— con el compilador verificando los tipos de las claves en cada paso.

Es el mismo argumento del [caso 11](../../11-heavy-reporting-blocks-operations/dotnet/README.md): cuando el problema es una transformación de datos, LINQ deja de ser azúcar y pasa a ser el modelo.

## Y la trampa que viene con eso: LINQ es perezoso

`Except` no ejecuta nada hasta que alguien enumera. Un diagnóstico calculado bajo un lock y enumerado después **puede leer un estado distinto del que comparó**:

```csharp
var pending = outbox.Where(kv => kv.Key > checkpoint);   // no ejecutó nada
foreach (var e in pending) { ...; checkpoint = e.Key; } // ← el filtro cambia mientras itera
```

Los `.ToList()` de este archivo no son adorno: son lo que fija el resultado mientras el estado todavía es consistente. Es un modo de falla que Python, Go y Rust no tienen en la forma equivalente.

## Sobre ignorar el error

.NET comparte el problema de Java y agrega uno propio: `_ = IndexarAsync(doc)` sin `await` manda la excepción a un `Task` que nadie observa. Desde .NET Core, `TaskScheduler.UnobservedTaskException` ni siquiera termina el proceso por defecto — es más silencioso que Node.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `Except` / `Join` | Las tres caras de la deriva como consultas tipadas. |
| `SortedDictionary` | El outbox ordenado por secuencia. |
| `record` | `Doc`, `IdxEntry` y `Change` con igualdad estructural. |
| `.ToList()` | Lo que fija la evaluación perezosa antes de mutar. |

## Las tres caras de la deriva

| Cara | Qué es | Qué ve el usuario |
|---|---|---|
| `missing` | Está en la base, no en el índice | **No lo encuentra** |
| `stale` | Está en los dos, con versión vieja | **Lo encuentra mal** |
| `orphan` | Está en el índice, borrado en la base | **Fantasmas** — clic que da 404 |

Se ven igual desde afuera —«la búsqueda anda rara»— y se arreglan distinto. Un reindexado que no borra arregla las dos primeras y deja la tercera intacta.

## Rutas

| Ruta | Qué muestra |
|---|---|
| `/health` | estado básico del servicio |
| `/search-drifted?writes=2000&fail_rate=8` | dual-write: `drift_count` > 0 y recall por debajo de 100 |
| `/search-reconciled?writes=2000&fail_rate=8` | outbox + checkpoint + barrido: deriva cero |
| `/reconcile` | un barrido suelto, para ver qué encuentra y qué repara |
| `/index/state` | las tres caras de la deriva y `drift_age_ms` |
| `/diagnostics/summary` | acumulado por variante, más la nota de fidelidad |
| `/reset-lab` | vacía la base, el índice, el outbox y las métricas |

**Parámetros:** `writes` (10–200k), `fail_rate` (% de escrituras al índice que fallan), `delete_pct` (% de borrados), `queries` (consultas para medir recall y precisión).

## Lo que sale, y es idéntico en los siete

```text
  dual-write:     missing=10  stale=50  orphan=19  drift=79
                  recall 98,95%   precision 98,02%   silent_failures=158

  outbox+barrido: missing=0   stale=0   orphan=0   drift=0
                  recall 100%     precision 100%     retries=157   checkpoint=2000
```

**98,95% de recall no se ve como un incidente.** Se ve como una búsqueda que anda. Ese es el modo de falla: ser lo bastante bueno como para que nadie mire.

## Hub

```bash
docker compose -f compose.dotnet.yml up -d --build
curl "http://127.0.0.1:8500/19/search-drifted?writes=2000&fail_rate=8"
curl "http://127.0.0.1:8500/19/search-reconciled?writes=2000&fail_rate=8"
curl "http://127.0.0.1:8500/19/index/state"
```

