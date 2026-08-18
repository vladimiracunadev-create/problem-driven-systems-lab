# 🐘 Caso 19 — PHP 8.3

<!-- nav-stack -->
[⬅️ Caso 19](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐘 Perfil de PHP](../../../docs/languages/php.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack PHP del caso 19. Dual-write contra outbox con checkpoint y barrido, midiendo las tres caras de la deriva.

## El checkpoint durable no es una buena práctica: es la única opción

En un runtime **share-nothing** no hay proceso de larga vida donde vivir un consumidor de CDC. El consumidor es un comando que corre cada N minutos y termina:

```bash
* * * * * php bin/consumir-outbox.php    # el consumidor de CDC de PHP
*/15 * * * * php bin/reconciliar.php     # el barrido
```

Eso **obliga** a que el checkpoint sobreviva al proceso. En Java, Go o .NET el consumidor vive en memoria y es tentador dejar el checkpoint ahí — hasta el primer reinicio, cuando se descubre que reprocesa todo o que se saltea lo que ya había leído.

En PHP no hay «ahí». El estado sobrevive en almacenamiento o no sobrevive, y ese constraint del lenguaje empuja al diseño correcto sin que nadie tenga que acordarse.

## Las tres caras con `array_diff_key`

```php
$missing = array_keys(array_diff_key($dbLive, $index));
$orphan  = array_keys(array_diff_key($index, $dbLive));
$comunes = array_intersect_key($dbLive, $index);   // y filtrar por versión
```

Más corto que los recorridos a mano de Go, más largo que las tres líneas de Python.

## Lo que hay que decir en contra

**PHP es el único de los siete donde nada ayuda a no ignorar el error.** Rust avisa con `#[must_use]`, Go obliga a escribir `_ =`, y acá:

```php
@$indice->escribir($doc);              // compila, corre y calla
try { $indice->escribir($doc); } catch (Throwable) { }   // igual
```

La única defensa es disciplina, y la disciplina no aparece en el diff.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `array_diff_key` / `array_intersect_key` | Las tres caras de la deriva sin recorrer a mano. |
| `flock` sobre el archivo de estado | El checkpoint durable, coordinado entre procesos. |
| Modelo share-nothing | Obliga a que el checkpoint sea durable desde el primer día. |
| Cron | El consumidor y el barrido son comandos, que es lo que son en producción. |

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

## Nota de fidelidad

El estado vive en un archivo JSON bajo `flock`, no en PostgreSQL ni en Elasticsearch. Lo que importa del caso —que la base y el índice son **dos sistemas sin transacción común**— es igual de cierto así.

## Hub

```bash
docker compose -f compose.root.yml up -d --build
curl "http://127.0.0.1:8100/19/search-drifted?writes=2000&fail_rate=8"
curl "http://127.0.0.1:8100/19/search-reconciled?writes=2000&fail_rate=8"
curl "http://127.0.0.1:8100/19/index/state"
```

## Dashboard

```bash
docker compose -f cases/19-search-index-drift-and-broken-cdc/php/compose.yml up -d --build
# abrir http://localhost:8119/
```
