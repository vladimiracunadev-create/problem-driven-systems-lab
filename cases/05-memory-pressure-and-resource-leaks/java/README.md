# Caso 05 — Java 21

Stack Java operativo del caso 05. Fuga real cross-request (`ArrayList<byte[]>` estatico) vs LRU acotada built-in (`LinkedHashMap.removeEldestEntry`).

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `LinkedHashMap` con `removeEldestEntry` | LRU built-in del JDK — sin libreria, una linea para eviccion automatica. |
| `Runtime.getRuntime().totalMemory()/freeMemory()/maxMemory()` | Medicion directa del heap del proceso. Sin agente, sin JFR. |
| `System.gc()` (hint, no garantia) | Disponible en `/reset-lab` para forzar comparacion antes/despues. |
| `Collections.synchronizedList/Map` | Acceso seguro desde el pool de handlers. |

## Contraste

**Legacy** — leak real:
```java
private static final List<byte[]> legacyAccumulator =
    Collections.synchronizedList(new ArrayList<>());

byte[] payload = new byte[sizeKb * 1024];
legacyAccumulator.add(payload);   // nunca se libera
```

**Optimized** — LRU acotada built-in:
```java
private static final Map<Integer, byte[]> optimizedCache =
    Collections.synchronizedMap(new LinkedHashMap<>(OPTIMIZED_CAP, 0.75f, true) {
        @Override protected boolean removeEldestEntry(Map.Entry<Integer, byte[]> e) {
            return size() > OPTIMIZED_CAP;  // eviccion automatica
        }
    });
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/batch-legacy?size_kb=64` | acumula en lista estatica — `retained_count` crece |
| `/batch-optimized?size_kb=64` | LRU con cap=1000, `evictions_total` sube cuando rebasa |
| `/state` | snapshot del heap (`heap_used_mb`, `heap_max_mb`, retained counts) |
| `/diagnostics/summary` | contraste completo + runtime |
| `/reset-lab` | limpia acumuladores + `System.gc()` |

## Hub

```
docker compose -f compose.java.yml up -d --build
# generar presion legacy
for i in {1..50}; do curl -s "http://127.0.0.1:8400/05/batch-legacy?size_kb=128" > /dev/null; done
curl http://127.0.0.1:8400/05/state
# vs optimized — se mantiene estable
for i in {1..5000}; do curl -s "http://127.0.0.1:8400/05/batch-optimized?size_kb=64" > /dev/null; done
curl http://127.0.0.1:8400/05/state
```

## Lo que el JVM mete en la ecuacion

El heap del JVM lo maneja el GC. `Runtime.totalMemory()` retorna el heap actual asignado por la JVM (puede crecer hasta `maxMemory()`); `freeMemory()` es lo libre dentro de ese heap. Una "fuga" en Java no es que el sistema operativo pierda memoria — es que el GC no puede recolectar porque las referencias siguen alcanzables desde la raiz (`static field`). Eso es exactamente lo que demuestra `legacyAccumulator`.
