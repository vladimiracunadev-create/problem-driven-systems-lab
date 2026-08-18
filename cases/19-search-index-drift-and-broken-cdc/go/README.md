# 🐹 Caso 19 — Go 1.23

<!-- nav-stack -->
[⬅️ Caso 19](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐹 Perfil de Go](../../../docs/languages/go.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Go del caso 19. Dual-write contra outbox con checkpoint y barrido, midiendo las tres caras de la deriva.

## A favor: descartar el error hay que escribirlo, y se ve

La escritura al índice devuelve `error`, y la única forma de ignorarlo deja rastro:

```go
if err := indice.Escribir(doc); err != nil { ... }   // manejado
_ = indice.Escribir(doc)                              // descartado, y VISIBLE
indice.Escribir(doc)                                  // errcheck lo marca
```

El guion bajo no es azúcar: es una **declaración de intención que queda en el diff** y que cualquiera puede buscar con `grep`. Y `errcheck` —que está en casi todos los CI de Go— convierte la tercera línea en un build rojo.

Es la segunda mejor defensa de los siete contra el bug de este caso. La mejor es la de Rust, y la diferencia es que `#[must_use]` está en la biblioteca estándar mientras que `errcheck` es una herramienta externa que alguien tiene que instalar.

## En contra: Go no tiene tipo conjunto

La deriva de tres caras —tres líneas de álgebra en Python, tres llamadas LINQ en .NET— acá son tres recorridos escritos a mano:

```go
for id, d := range dbLive {
    cur, ok := index[id]
    if !ok { missing = append(missing, id) } else if cur.Version != d.Version { stale = append(stale, id) }
}
for id := range index {
    if _, ok := dbLive[id]; !ok { orphan = append(orphan, id) }
}
```

Más código, más superficie para equivocarse en un caso borde, y ninguna biblioteca estándar que lo evite. `map[string]struct{}` es el conjunto de Go, y es una convención, no un tipo.

Es exactamente la simetría del [caso 17](../../17-zero-downtime-schema-migration/python/README.md), donde Python no tenía read-write lock: **la ausencia de una primitiva se paga en el mismo lugar** — código propio donde debería haber biblioteca.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `error` como valor de retorno | Descartarlo requiere escribir `_ =`, y eso es auditable. |
| `errcheck` | Marca la llamada cuyo error se ignora sin decirlo. |
| `map[string]struct{}` | El conjunto de Go: una convención, no un tipo. |
| `sync.Mutex` | La escritura a la base y la anotación en el outbox, bajo el mismo lock. |

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
docker compose -f compose.go.yml up -d --build
curl "http://127.0.0.1:8600/19/search-drifted?writes=2000&fail_rate=8"
curl "http://127.0.0.1:8600/19/search-reconciled?writes=2000&fail_rate=8"
curl "http://127.0.0.1:8600/19/index/state"
```

