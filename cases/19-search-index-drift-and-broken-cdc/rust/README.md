# 🦀 Caso 19 — Rust 1.83

<!-- nav-stack -->
[⬅️ Caso 19](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🦀 Perfil de Rust](../../../docs/languages/rust.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Rust del caso 19. Dual-write contra outbox con checkpoint y barrido, midiendo las tres caras de la deriva.

## El bug original no compila sin escribirlo a propósito

Este caso entero nace de **una escritura que falló y que nadie miró**. En Rust, no mirarla no es una omisión: es algo que hay que escribir.

```rust
indice.escribir(&doc);          // warning: unused `Result` that must be used
let _ = indice.escribir(&doc);  // compila — y el `let _ =` queda en el diff
indice.escribir(&doc)?;         // el error sube
```

La primera línea produce una advertencia del compilador **sin configurar nada**: `#[must_use]` está en la definición de `Result` en la `std`. Y con una línea al tope del archivo:

```rust
#![deny(unused_must_use)]
```

pasa a ser un **error de compilación**. Este archivo la tiene puesta.

Go llega parecido con `errcheck`, pero `errcheck` es una herramienta externa que alguien tiene que instalar y poner en el CI. En Python, Java, .NET, Node y PHP **no hay nada**: el `except:`, el `catch {}` y la promesa sin `await` compilan, corren y callan.

## Y la otra mitad: el álgebra de conjuntos

```rust
let missing: Vec<_> = db_ids.difference(&index_ids).collect();
let orphan:  Vec<_> = index_ids.difference(&db_ids).collect();
let stale:   Vec<_> = db_ids.intersection(&index_ids)
    .filter(|id| index[*id].version != db_live[*id].version).collect();
```

`HashSet` da el diff de tres caras sin escribirlo a mano — algo que Go no puede.

**Rust es el único stack del laboratorio que tiene las dos piezas**: el error imposible de ignorar por accidente, y el álgebra de conjuntos para el diagnóstico. Los demás tienen una o ninguna.

## El costo, y es el de siempre

El `BTreeMap` del outbox, el `HashMap<&String, &Doc>` de la vista viva y los `clone()` para sacar datos de debajo del `MutexGuard` son ceremonia que Python y JavaScript no pagan. En un caso cuyo núcleo es comparar dos colecciones, esa ceremonia es visible.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `#[must_use]` sobre `Result` | El bug original no pasa sin escribirlo a propósito. |
| `#![deny(unused_must_use)]` | Convierte esa advertencia en error de compilación. |
| `HashSet::difference` / `intersection` | Las tres caras de la deriva sin recorrer a mano. |
| `BTreeMap::range((cp+1)..)` | Lo pendiente del outbox, ordenado, sin filtrar. |

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
docker compose -f compose.rust.yml up -d --build
curl "http://127.0.0.1:8700/19/search-drifted?writes=2000&fail_rate=8"
curl "http://127.0.0.1:8700/19/search-reconciled?writes=2000&fail_rate=8"
curl "http://127.0.0.1:8700/19/index/state"
```

