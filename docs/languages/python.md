# 🐍 Python

> **Versión fijada:** `3.12` · **Imagen base:** `python:3.12-alpine` · **Hub:** `:8200` · **Casos operativos:** 14 / 14

[⬅️ Volver a los perfiles de lenguaje](README.md) · [🗺️ Mapa de stacks](../stack-map.md) · [🔄 Protocolo de actualización](../language-upgrade-protocol.md)

---

## 🪪 Identidad

Python es un lenguaje interpretado, de tipado dinámico y fuerte, diseñado alrededor de la legibilidad. Su biblioteca estándar es de las más amplias del set —"pilas incluidas" es un lema, no una metáfora— y su ecosistema científico no tiene competencia real.

**Para qué se usa en la industria:** análisis de datos, machine learning, automatización y scripting, backend web (Django, FastAPI), DevOps y herramientas internas. Es el lenguaje que más frecuentemente se elige por *velocidad de la persona que escribe*, no por velocidad del programa — y en la mayoría de los contextos esa es la decisión correcta.

**Por qué está en este laboratorio:** por el GIL. Python es el único stack del set donde el paralelismo de CPU está limitado por diseño, y eso convierte al [caso 11](../../cases/11-heavy-reporting-blocks-operations/python/README.md) en una demostración honesta de un problema real que ningún otro runtime del laboratorio tiene. También porque su `logging` de stdlib es, según el veredicto del caso 03, **la API más difícil de violar por accidente** de los siete stacks.

---

## ⚙️ Modelo de ejecución

**Threads reales del sistema operativo, serializados por el GIL para la ejecución de bytecode.**

El *Global Interpreter Lock* permite que solo un thread ejecute bytecode de Python a la vez. No impide la concurrencia de I/O —el GIL se libera durante las esperas— pero sí impide el paralelismo de CPU.

| Consecuencia | Dónde se nota |
|---|---|
| **Concurrencia de I/O sí, paralelismo de CPU no** | Dos threads esperando a la base avanzan en paralelo; dos threads calculando, no — [caso 11](../../cases/11-heavy-reporting-blocks-operations/python/README.md) |
| **El worker en thread compite con los lectores** | En el caso 01 el worker que refresca la tabla resumen serializa trabajo de CPU con los handlers — [caso 01](../../cases/01-api-latency-under-load/python/README.md) |
| **La biblioteca estándar cubre casi todo** | `sqlite3`, `logging`, `threading`, `gc`, `tracemalloc`, `re` — ninguno de los doce casos necesita un paquete de PyPI | transversal |
| **Sin tipos en runtime** | Las anotaciones no se verifican al ejecutar. El error de firma llega con el request — [caso 07](../../cases/07-incremental-monolith-modernization/python/README.md) |

---

## 🧰 Primitivas que usa el laboratorio

| Caso | Primitiva central | Por qué esta y no otra |
|---|---|---|
| [01 · API lenta](../../cases/01-api-latency-under-load/python/README.md) | `sqlite3` stdlib + `threading.RLock` + worker en thread | Sin dependencias; el GIL hace visible la contención entre worker y lectores |
| [02 · N+1](../../cases/02-n-plus-one-and-db-bottlenecks/python/README.md) | `sqlite3` stdlib | Directo y legible: el N+1 queda a la vista en el código, sin ORM que lo disimule |
| [03 · Observabilidad](../../cases/03-poor-observability-and-useless-logs/python/README.md) | `logging.LoggerAdapter` + `JsonFormatter` | **La API más difícil de violar por accidente del set**: el adapter inyecta el contexto en cada registro |
| [04 · Timeouts](../../cases/04-timeout-chain-and-retry-storms/python/README.md) | timeouts de socket / `signal` | Wall-clock. **Limitación:** abandona el resultado sin liberar el trabajo |
| [05 · Memoria](../../cases/05-memory-pressure-and-resource-leaks/python/README.md) | `gc` + `sys.getsizeof` + `tracemalloc` | Instrumentación de memoria en la stdlib, sin agente externo |
| [06 · Pipeline](../../cases/06-broken-pipeline-and-fragile-delivery/python/README.md) | `dict` protegido por lock | Correcto; sin red de seguridad de tipos debajo |
| [07 · Monolito](../../cases/07-incremental-monolith-modernization/python/README.md) | `dict[str, Callable]` | Registrar un módulo es una línea. El error de firma aparece en runtime |
| [08 · Extracción](../../cases/08-critical-module-extraction-without-breaking-operations/python/README.md) | callbacks en lista | Simple y sin desacople: los subscribers corren en línea |
| [09 · Integración externa](../../cases/09-unstable-external-integration/python/README.md) | `threading.Semaphore` + `re.match` para SKUs + `set` de idempotencia | Semáforo de stdlib, correcto y directo |
| [10 · Sobre-arquitectura](../../cases/10-expensive-architecture-for-simple-needs/python/README.md) | `dict` O(1) | El "right-sized" del caso |
| [11 · Reportes](../../cases/11-heavy-reporting-blocks-operations/python/README.md) | `ThreadPoolExecutor` separado | Aísla el reporting; **el GIL limita el paralelismo real del trabajo CPU** |
| [12 · Punto único](../../cases/12-single-point-of-knowledge-and-operational-risk/python/README.md) | `if x is None` / `dict.get()` | Disciplina pura, cero respaldo del lenguaje |
| [13 · Cache stampede](../../cases/13-cache-stampede-and-thundering-herd/python/README.md) | dict de vuelos + `threading.Event` | `Event` **es** el «esperá a que otro termine»; no hace falta librería |
| [14 · Pool de conexiones](../../cases/14-connection-pool-exhaustion/python/README.md) | `queue.Queue` + `@contextmanager` | La stdlib trae la estructura; el `finally` del generador aporta la disciplina |

> 💡 **El patrón que solo se ve mirando la columna entera:** ninguno de los doce casos necesita instalar nada. `sqlite3`, `logging`, `threading`, `gc` y `re` alcanzan. Es el argumento más fuerte de Python en este laboratorio y no tiene que ver con rendimiento: tiene que ver con cuánto código de terceros hay que auditar para llegar a producción.

---

## 📈 Rendimiento: qué mide el laboratorio y cómo reproducirlo

> ⚠️ **Este repositorio no publica benchmarks entre lenguajes.** Se mide la pendiente dentro de cada stack: legacy contra optimized, mismo runtime, misma máquina. Comparar el tiempo absoluto de Python contra Go mediría el intérprete, no el criterio.

| Señal | De dónde sale | Qué caso la expone |
|---|---|---|
| `avg_ms` · `p95_ms` · `p99_ms` | muestras en memoria | 01, 02, 10 |
| objetos vivos y tamaño | `gc.get_objects()` + `sys.getsizeof` | 05 |
| picos de asignación | `tracemalloc` | 05 |
| threads del pool en uso | `ThreadPoolExecutor` | 11 |
| `db_hits` por request | contador alrededor de `sqlite3` | 01, 02 |

**Reproducir la medición del caso 11 (el GIL como techo):**

```bash
docker compose -f compose.python.yml up -d --build
curl -s localhost:8200/11/activity
for i in $(seq 1 8); do curl -s "localhost:8200/11/report-legacy?rows=200000" & done; wait
curl -s "localhost:8200/11/order-write"                  # degraded: true — el CPU-bound serializa
curl -s "localhost:8200/11/report-isolated?rows=200000"  # pool separado: aisla, pero el GIL sigue ahi
curl -s localhost:8200/11/diagnostics/summary
```

**Especificación de rendimiento que este stack verifica y ningún otro puede:** separar el pool de reporting **mejora el aislamiento pero no multiplica el throughput de CPU**. En Java o .NET el mismo cambio sí lo multiplica. La comparación entre esos dos resultados es el argumento completo del caso 11, y solo existe porque Python está en el laboratorio.

---

## 🚧 Límites, problemas sin solución y desafíos

| Límite | Por qué importa | Dónde se ve |
|---|---|---|
| **El GIL impide el paralelismo de CPU** | Separar pools aísla, pero no acelera el trabajo CPU-bound. Es el techo estructural del runtime | [caso 11](../../cases/11-heavy-reporting-blocks-operations/python/README.md) |
| **Timeouts sin cancelación real** | El wall-clock abandona el resultado; el trabajo del otro lado sigue corriendo | [caso 04](../../cases/04-timeout-chain-and-retry-storms/comparison.md) |
| **Ausencia sin respaldo del lenguaje** | `is None` y `dict.get()` son disciplina. Nada obliga a manejar el caso vacío | [caso 12](../../cases/12-single-point-of-knowledge-and-operational-risk/comparison.md) |
| **Sin verificación de tipos en runtime** | Las anotaciones documentan; no ejecutan. El error de contrato llega con el request | [caso 07](../../cases/07-incremental-monolith-modernization/comparison.md) |
| **Callbacks síncronos sin desacople** | La lista de callbacks del caso 08 notifica en línea: no hay buffer ni consumidor independiente | [caso 08](../../cases/08-critical-module-extraction-without-breaking-operations/comparison.md) |
| **Costo del intérprete** | Para trabajo CPU-bound puro, el orden de magnitud contra un compilado es real y no se cierra optimizando el código | transversal |

**Desafío abierto del stack en este laboratorio:** el caso 11 está construido sobre el supuesto de que el GIL serializa el trabajo de CPU. **Python 3.13 introduce free-threading (PEP 703) como build opcional sin GIL.** Si el laboratorio adoptara ese build, el argumento del caso 11 se invierte por completo — dejaría de ser "el techo del runtime" para pasar a ser "una decisión de configuración". Está anotado como disparador explícito en `scripts/language_drift.py`.

---

## 🏆 Dónde gana y dónde pierde en el laboratorio

Agregado de los veredictos de las 13 comparativas que rankean: **0 primeros puestos, media 5.0**.

- 🥉 **Tercero en 03** — `LoggerAdapter` + `JsonFormatter`: la API más difícil de violar por accidente del set completo. Es el mejor resultado de Python en el laboratorio y no tiene nada que ver con rendimiento.
- **4º en 02, 06, 09 y 14** — `sqlite3` y `threading.Semaphore` de stdlib, correctos y directos.
- **6º en 04, 05, 07, 08, 12 y 13** — los casos donde el lenguaje no respalda la corrección con nada. En el 13 se suma el GIL: sin una barrera explícita, la estampida ni siquiera se deja observar.

**Lectura honesta:** Python queda sexto en cinco casos, y el laboratorio no lo maquilla. Lo que aporta es distinto: es el único stack que expone un límite estructural real —el GIL— y el que llega más lejos sin instalar nada. En un repositorio que compara criterio y no velocidad, ambas cosas cuentan.

---

## 🔄 Ciclo de versiones

| | |
|---|---|
| **Versión fijada hoy** | `3.12` (`python:3.12-alpine`) |
| **Cadencia upstream** | Una release menor por año, en octubre |
| **Política de soporte** | 5 años por versión: 3.12 recibe parches de seguridad hasta octubre de 2028 |
| **Producto en endoflife.date** | `python` |

**Qué revisar en el próximo salto:**

1. **🚨 Free-threading (3.13+, PEP 703)** — si el laboratorio pasa a un build sin GIL, **el argumento del [caso 11](../../cases/11-heavy-reporting-blocks-operations/python/README.md) deja de ser cierto**. Es el disparador de revisión más importante de este stack: no es un bump, es reescribir la narrativa del caso.
2. **Cambios en `sqlite3`** — es la base de los casos 01 y 02.
3. **Cambios en `logging`** — el caso 03 depende de `LoggerAdapter` y de un formatter propio.
4. **Subinterpretes (PEP 684)** — abren un modelo de aislamiento nuevo que el caso 11 podría contrastar contra el `ThreadPoolExecutor` actual.

El detalle del procedimiento está en [docs/language-upgrade-protocol.md](../language-upgrade-protocol.md).

---

## 🚀 Levantar el stack

```bash
docker compose -f compose.python.yml up -d --build
```

Los 14 casos quedan servidos en `http://localhost:8200/NN/`. Cada caso trae además su propio `compose.yml` para correrlo aislado.
