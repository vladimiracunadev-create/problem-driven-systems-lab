# 🟢 Caso 19 — Node.js 22

<!-- nav-stack -->
[⬅️ Caso 19](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🟢 Perfil de Node.js](../../../docs/languages/node.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Node del caso 19. Dual-write contra outbox con checkpoint y barrido, midiendo las tres caras de la deriva.

## El único stack donde el bug se produce por NO escribir algo

En los otros seis, ignorar el resultado de la escritura al índice requiere **escribir el silencio**: `_ =` en Go, `let _ =` en Rust, `except:` en Python, `catch {}` en Java o .NET, `@` en PHP. En Node basta con no escribir cuatro letras:

```js
await indice.escribir(doc);   // el error sube y se maneja
indice.escribir(doc);         // el error se va a un rechazo sin dueño
```

**Las dos líneas compilan. Las dos parecen correctas en una revisión rápida.** Y la segunda produce exactamente este caso.

## Qué cambió, y por qué sigue sin alcanzar

Hasta Node 15, una promesa rechazada sin `catch` emitía un warning y el proceso seguía: silencio total. Desde Node 15 el comportamiento por defecto de `unhandledRejection` es `throw`, lo que **mata el proceso**.

Es mejor que el silencio y sigue siendo peor que un error manejado: un crash en un momento arbitrario, con un stack que apunta a la promesa y no a quien la creó.

La única herramienta que lo atrapa antes de producción es la regla `no-floating-promises` de typescript-eslint — y no viene puesta.

## Lo que Node sí hace bien acá

El modelo de un solo hilo elimina una clase entera de problemas del caso: **la escritura a la base y la anotación en el outbox son atómicas sin ningún lock**, porque nada puede intercalarse entre dos sentencias síncronas. En Java, Go, .NET y Rust eso requiere un mutex explícito.

Es el mismo argumento del [caso 16](../../16-idempotency-and-duplicate-effects/node/README.md), con la misma limitación: deja de valer con dos procesos.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `Map` / `Set` | El índice, la base y los conjuntos del diff. |
| Ejecución de un solo hilo | Base y outbox se escriben atómicamente sin lock. |
| `unhandledRejection` | Desde Node 15 mata el proceso en vez de callarse. |
| `no-floating-promises` | La única defensa real, y hay que activarla. |

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
docker compose -f compose.nodejs.yml up -d --build
curl "http://127.0.0.1:8300/19/search-drifted?writes=2000&fail_rate=8"
curl "http://127.0.0.1:8300/19/search-reconciled?writes=2000&fail_rate=8"
curl "http://127.0.0.1:8300/19/index/state"
```

