# ☕ Caso 19 — Java 21

<!-- nav-stack -->
[⬅️ Caso 19](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [☕ Perfil de Java](../../../docs/languages/java.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Java del caso 19. Dual-write contra outbox con checkpoint y barrido, midiendo las tres caras de la deriva.

## `@Transactional` hace que el dual-write PAREZCA atómico

Es el riesgo específico de este stack, y no es técnico sino de lectura:

```java
@Transactional
public void guardar(Documento d) {
    repo.save(d);          // participa de la transacción
    buscador.indexar(d);   // NO participa: es HTTP a otro sistema
}
```

El método completo está dentro de una transacción. El código **se lee como una unidad**. Y el índice de búsqueda no está en esa transacción, porque no puede estarlo.

Si `indexar` lanza, el `save` se revierte. Pero si `indexar` falla en silencio, o si el commit falla **después** de indexar, los dos lados quedan distintos. La anotación no miente: cubre lo que puede cubrir. Lo que engaña es que **nada en el código marca dónde termina su alcance**.

## Y lo que Java sí aporta

**`ConcurrentSkipListMap` como outbox ordenado.** El checkpoint deja de ser un filtro y pasa a ser una consulta:

```java
outbox.tailMap(checkpoint, false).values()   // exactamente lo pendiente, ya ordenado
```

No hay que recorrer la colección entera filtrando por secuencia: el `SortedMap` da la vista directamente, y es concurrente, así que el consumidor puede leer mientras los productores escriben.

Y `removeAll` / `retainAll` expresan el diff de tres caras sin escribir el recorrido — algo que Go no puede:

```java
Set<String> missing = new HashSet<>(dbLive.keySet());
missing.removeAll(index.keySet());
```

Más verboso que Python porque **muta copias** en vez de devolver conjuntos nuevos, que es una diferencia de estilo con consecuencias: hay que acordarse de copiar.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `ConcurrentSkipListMap.tailMap(cp, false)` | Lo pendiente del outbox, ordenado, sin filtrar. |
| `Set.removeAll` / `retainAll` | El diff de tres caras sin recorrer a mano. |
| `record` | `Doc`, `IdxEntry` y `Change` como valores inmutables. |
| `@Transactional` | Lo que hace parecer atómico un dual-write que no lo es. |

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
docker compose -f compose.java.yml up -d --build
curl "http://127.0.0.1:8400/19/search-drifted?writes=2000&fail_rate=8"
curl "http://127.0.0.1:8400/19/search-reconciled?writes=2000&fail_rate=8"
curl "http://127.0.0.1:8400/19/index/state"
```

