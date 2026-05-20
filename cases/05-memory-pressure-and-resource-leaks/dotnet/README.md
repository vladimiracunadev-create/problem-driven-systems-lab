# Caso 05 — .NET 8

Stack .NET operativo del caso 05. Fuga real cross-request (`List<byte[]>` estatico) vs LRU acotada construida manualmente con `Dictionary + LinkedList`.

## Primitivas .NET nativas

| Primitiva | Rol |
|---|---|
| `Dictionary<int,LinkedListNode<...>>` + `LinkedList<...>` | LRU built-in del BCL — O(1) en lookup y reordenamiento, sin libreria externa. |
| `Process.GetCurrentProcess().WorkingSet64` | Medicion directa del RSS del proceso. |
| `GC.GetTotalMemory(forceFullCollection: true)` | Memoria gestionada actual. Disponible en `/reset-lab` para forzar comparacion antes/despues. |
| `lock (sync)` / `ConcurrentDictionary` | Acceso seguro desde el `ThreadPool`. |

## Contraste

**Legacy** — leak real:
```csharp
private static readonly List<byte[]> legacyAccumulator = new();
private static readonly object syncLeak = new();

var payload = new byte[sizeKb * 1024];
lock (syncLeak) { legacyAccumulator.Add(payload); }   // nunca se libera
```

**Optimized** — LRU acotada manual:
```csharp
private const int OPTIMIZED_CAP = 1000;
private static readonly Dictionary<int,LinkedListNode<(int Key,byte[] Val)>> index = new();
private static readonly LinkedList<(int Key,byte[] Val)> order = new();

lock (sync) {
    if (index.TryGetValue(k, out var node)) { order.Remove(node); order.AddFirst(node); }
    else {
        var node = order.AddFirst((k, payload));
        index[k] = node;
        if (index.Count > OPTIMIZED_CAP) {
            var last = order.Last!;
            order.RemoveLast();
            index.Remove(last.Value.Key);   // eviccion
        }
    }
}
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/batch-legacy?size_kb=64` | acumula en lista estatica — `retained_count` crece |
| `/batch-optimized?size_kb=64` | LRU con cap=1000, `evictions_total` sube cuando rebasa |
| `/state` | snapshot del proceso (`working_set_mb`, `gc_managed_mb`, retained counts) |
| `/diagnostics/summary` | contraste completo + runtime |
| `/reset-lab` | limpia acumuladores + `GC.Collect()` |

## Hub

```
docker compose -f compose.dotnet.yml up -d --build
# generar presion legacy
for i in {1..50}; do curl -s "http://127.0.0.1:8500/05/batch-legacy?size_kb=128" > /dev/null; done
curl http://127.0.0.1:8500/05/state
# vs optimized — se mantiene estable
for i in {1..5000}; do curl -s "http://127.0.0.1:8500/05/batch-optimized?size_kb=64" > /dev/null; done
curl http://127.0.0.1:8500/05/state
```

## Modo aislado

```
docker compose -f cases/05-memory-pressure-and-resource-leaks/dotnet/compose.yml up -d --build
curl http://127.0.0.1:855/health
```

## Lo que el CLR mete en la ecuacion

El heap del CLR lo maneja el GC generacional (Gen0/Gen1/Gen2 + LOH). Una "fuga" en .NET no es que el sistema operativo pierda memoria — es que el GC no puede recolectar porque las referencias siguen alcanzables desde la raiz (`static field`). Eso es exactamente lo que demuestra `legacyAccumulator`. `WorkingSet64` reporta lo que el OS ve; `GC.GetTotalMemory()` reporta lo que el CLR considera vivo. Bajo leak los dos crecen; bajo LRU acotada los dos se estabilizan.
