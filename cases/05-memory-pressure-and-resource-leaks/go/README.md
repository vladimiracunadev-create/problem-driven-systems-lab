# 🐹 Caso 05 — Go 1.23

<!-- nav-stack -->
[⬅️ Caso 05](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐹 Perfil de Go](../../../docs/languages/go.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Go operativo del caso 05. Slice global que crece sin limite vs cache LRU acotada.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `runtime.ReadMemStats` | Presion de heap real sin agente externo ni JMX. Expone `HeapAlloc`, `HeapSys`, `NumGC`. |
| `runtime/debug.FreeOSMemory` | Corre un GC y devuelve paginas al SO. Equivalente honesto del `System.gc()` de Java. |
| `container/list` + `map[int64]*list.Element` | LRU construida a mano. Go no trae un `LinkedHashMap` con `removeEldestEntry`. |
| `runtime.NumGoroutine` | Goroutines vivas: la fuga de concurrencia que ningun otro stack del lab puede reportar asi. |

## Contraste

**Legacy** — la fuga:
```go
legacyAccumulator = append(legacyAccumulator, payload)  // nada lo saca nunca
```

**Optimized** — LRU con cap fijo:
```go
func (c *lru) put(key int64, payload []byte) bool {
    el := c.ll.PushFront(&lruEntry{key: key, payload: payload})
    c.index[key] = el
    if c.ll.Len() > c.cap {
        oldest := c.ll.Back()
        c.ll.Remove(oldest)
        delete(c.index, oldest.Value.(*lruEntry).key)
        return true                                       // evictado
    }
    return false
}
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/batch-legacy?size_kb=64` | `retained_count` crece monotonicamente |
| `/batch-optimized?size_kb=64` | `retained_count` se estabiliza en `cap=1000` |
| `/state` | `heap_used_mb`, `gc_cycles`, `goroutines`, retenidos por variante |
| `/diagnostics/summary` | contraste + snapshot del runtime |
| `/reset-lab` | limpia acumuladores e invoca `FreeOSMemory()` |

## Hub

```
docker compose -f compose.go.yml up -d --build
for i in $(seq 1 50); do curl -s "http://127.0.0.1:8600/05/batch-legacy?size_kb=256" > /dev/null; done
curl http://127.0.0.1:8600/05/state
```

## El malentendido que este caso corrige

Go tiene GC, igual que Java y .NET. La fuga de este caso **no es memoria sin liberar** — es memoria *referenciada de mas*. Un recolector no salva de guardar cosas para siempre: mientras el slice global apunte al payload, el GC hace exactamente lo que debe y no lo toca.

Es el mismo bug de diseño en los tres stacks con GC, y es el que el lector suele confundir con "leak = falta un `free()`". Los lenguajes sin GC (el stack Rust, cuando este) fuerzan a mirarlo desde el otro lado: alli el compilador impide la fuga de *ownership*, pero no impide meter cosas en un `Vec` global.

Lo que Go aporta de propio es el instrumento: `runtime.ReadMemStats` y `runtime.NumGoroutine()` estan en la biblioteca estandar, sin agente. La segunda metrica ademas cubre una fuga que este caso no dispara pero que en Go es habitual — goroutines bloqueadas para siempre en un canal sin lector.
