# 🐍 Caso 19 — Python 3.12

<!-- nav-stack -->
[⬅️ Caso 19](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐍 Perfil de Python](../../../docs/languages/python.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Python del caso 19. Dual-write contra outbox con checkpoint y barrido, midiendo las tres caras de la deriva.

## El diagnóstico completo en tres líneas

El álgebra de conjuntos de la stdlib expresa la deriva de tres caras leyéndose como su propia definición:

```python
missing = db_ids - index_ids
orphan  = index_ids - db_ids
stale   = {i for i in db_ids & index_ids if index[i].version != db[i].version}
```

**Ningún otro stack del laboratorio lo escribe tan corto.** Go no tiene tipo conjunto y los recorre a mano; Java los tiene pero mutando copias con `removeAll` y `retainAll`; .NET los expresa con LINQ, tipado y elegante, en más caracteres.

Es el mismo patrón que el [caso 17](../../17-zero-downtime-schema-migration/python/README.md) al revés: allá la stdlib **no** traía la primitiva y había que construirla; acá la trae y es la mejor de las siete.

## Y la contracara, que hay que decir

**Un `except:` desnudo se traga la falla del índice sin dejar rastro**, y es exactamente la forma en que este bug llega a producción:

```python
try:
    indice.escribir(doc)
except Exception:
    pass          # el caso entero, en tres palabras
```

Python hace el **diagnóstico** fácil y el **bug** también. Rust avisa con `#[must_use]`, Go obliga a escribir `_ =`; en Python no hay nada entre el desarrollador y el silencio.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `set` con `-`, `&`, `\|` | Las tres caras de la deriva en tres líneas. |
| Comprensión de conjuntos | El filtro por versión sin bucle explícito. |
| `dict` ordenado por inserción | El outbox mantiene el orden de secuencia sin estructura extra. |
| `threading.Lock` | La escritura a la base y la anotación en el outbox, bajo el mismo lock. |

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
docker compose -f compose.python.yml up -d --build
curl "http://127.0.0.1:8200/19/search-drifted?writes=2000&fail_rate=8"
curl "http://127.0.0.1:8200/19/search-reconciled?writes=2000&fail_rate=8"
curl "http://127.0.0.1:8200/19/index/state"
```

