# 🐘 PHP

> **Versión fijada:** `8.3` · **Imagen base:** `php:8.3-cli-alpine` (portal: `php:8.3-apache`) · **Hub:** `:8100` · **Casos operativos:** 15 / 15

[⬅️ Volver a los perfiles de lenguaje](README.md) · [🗺️ Mapa de stacks](../stack-map.md) · [🔄 Protocolo de actualización](../language-upgrade-protocol.md)

---

## 🪪 Identidad

PHP es un lenguaje interpretado, de tipado dinámico y gradual, diseñado específicamente para la web. Su rasgo definitorio es el modelo de ejecución: **cada request arranca un proceso limpio, ejecuta, responde y muere**. No hay estado que persista entre peticiones a menos que se lo escriba explícitamente a disco, a una base o a un almacén compartido.

PHP 8 dejó atrás buena parte de su reputación: tipos de retorno, `readonly`, enums, atributos, JIT, `match`, propiedades promovidas en el constructor y un intérprete varias veces más rápido que el de la serie 5.

**Para qué se usa en la industria:** la web de contenido y comercio. WordPress, Drupal, Magento, Laravel y Symfony sostienen una fracción enorme del tráfico público de internet. Sigue siendo la respuesta por defecto cuando el problema es "un sitio que tiene que estar arriba, ser barato de hostear y fácil de contratar personal para mantener".

**Por qué está en este laboratorio:** porque es **el stack de referencia y el que tiene el mejor sustrato de infraestructura**. Es el único que corre contra PostgreSQL 16 real en un contenedor aparte, con worker separado, Prometheus y Grafana. Los otros seis stacks usan SQLite embebido, que es honesto y reproducible pero no cruza un socket. Cuando el caso 02 necesita mostrar que un N+1 cuesta *round-trips de red*, el único lugar donde eso es literalmente cierto es PHP.

---

## ⚙️ Modelo de ejecución

**Un proceso por petición, sin estado compartido entre requests.**

| Consecuencia | Dónde se nota |
|---|---|
| **La fuga de memoria muere con el proceso** | En el caso 05 la fuga se lleva puesta al proceso al terminar la request. El runtime la **oculta**, y ese es exactamente el hallazgo del caso — [caso 05](../../cases/05-memory-pressure-and-resource-leaks/php/README.md) |
| **El estado compartido va a disco o a la base** | El breaker del caso 09 y el pipeline del caso 06 persisten en archivos: no hay memoria de proceso donde guardarlos — [caso 06](../../cases/06-broken-pipeline-and-fragile-delivery/php/README.md) |
| **El límite de concurrencia es el pool FPM** | No hay pool interno que dimensionar ni event loop que observar: el techo lo pone el gestor de procesos, fuera del código — [caso 11](../../cases/11-heavy-reporting-blocks-operations/php/README.md) |
| **Base de datos real, contención observable** | `pg_stat_activity` muestra conexiones y bloqueos desde el motor. Ningún stack con SQLite embebido puede ofrecer eso — [caso 01](../../cases/01-api-latency-under-load/php/README.md) |

---

## 🧰 Primitivas que usa el laboratorio

| Caso | Primitiva central | Por qué esta y no otra |
|---|---|---|
| [01 · API lenta](../../cases/01-api-latency-under-load/php/README.md) | `PDO` contra **PostgreSQL 16** + worker en contenedor aparte | El mejor sustrato del laboratorio: motor real, contención observable con `pg_stat_activity` |
| [02 · N+1](../../cases/02-n-plus-one-and-db-bottlenecks/php/README.md) | `PDO` con statements preparados | **El único stack donde el N+1 cruza un socket** y el costo es de red, no de función |
| [03 · Observabilidad](../../cases/03-poor-observability-and-useless-logs/php/README.md) | contexto por request dentro del proceso FPM | El proceso *es* el alcance: no hace falta propagar nada. Tampoco hay nada que lo respalde |
| [04 · Timeouts](../../cases/04-timeout-chain-and-retry-storms/php/README.md) | `CURLOPT_TIMEOUT` + reintentos con `sleep()` | Deadline atado a la biblioteca HTTP. **Limitación:** sin cancelación real |
| [05 · Memoria](../../cases/05-memory-pressure-and-resource-leaks/php/README.md) | `memory_get_usage()` | El proceso muere y se lleva la fuga: el caso documenta **por qué eso es peligroso**, no por qué es cómodo |
| [06 · Pipeline](../../cases/06-broken-pipeline-and-fragile-delivery/php/README.md) | estado en disco entre procesos aislados | Funciona; la transacción lógica no está protegida por una sección crítica del lenguaje |
| [07 · Monolito](../../cases/07-incremental-monolith-modernization/php/README.md) | routing por configuración | El error de firma aparece cuando llega el request |
| [08 · Extracción](../../cases/08-critical-module-extraction-without-breaking-operations/php/README.md) | hooks síncronos | Simples y sin desacople |
| [09 · Integración externa](../../cases/09-unstable-external-integration/php/README.md) | `preg_match` para SKUs + `??` como adapter + cache en disco | El `??` fusiona contratos del proveedor en una línea. La idempotencia se persiste, no se recuerda |
| [10 · Sobre-arquitectura](../../cases/10-expensive-architecture-for-simple-needs/php/README.md) | `array` asociativo O(1) | El "right-sized" del caso |
| [11 · Reportes](../../cases/11-heavy-reporting-blocks-operations/php/README.md) | procesos FPM aislados | No hay nada que aislar — y tampoco nada que observar desde adentro |
| [12 · Punto único](../../cases/12-single-point-of-knowledge-and-operational-risk/php/README.md) | `isset()` / `??` | Disciplina pura, cero respaldo del lenguaje |
| [13 · Cache stampede](../../cases/13-cache-stampede-and-thundering-herd/php/README.md) | `flock(LOCK_EX)` + double-checked locking | Sin heap compartido entre requests, el single-flight tiene que vivir en el almacenamiento |
| [14 · Pool de conexiones](../../cases/14-connection-pool-exhaustion/php/README.md) | `finally` | Cubre también el `continue` del `catch`. El proceso por request tapa el bug; las persistentes de FPM lo destapan |
| [15 · Backpressure](../../cases/15-message-queue-backpressure/php/README.md) | `listen.backlog` de FPM | No hay cola en proceso: el freno vive en el transporte, no en el lenguaje |

> 💡 **El patrón que solo se ve mirando la columna entera:** PHP resuelve con **infraestructura** lo que los otros stacks resuelven con **primitivas de lenguaje**. Donde Go usa un canal y Java un `Semaphore`, PHP usa un archivo, una tabla o el gestor de procesos. No es peor ni mejor: es el modelo de ejecución llevado hasta sus consecuencias.

---

## 📈 Rendimiento: qué mide el laboratorio y cómo reproducirlo

> ⚠️ **Este repositorio no publica benchmarks entre lenguajes.** Se mide la pendiente dentro de cada stack: legacy contra optimized, mismo runtime, misma máquina.

PHP es el único stack con observabilidad **externa** completa: Prometheus recoge las métricas y Grafana las grafica, además de los endpoints propios.

| Señal | De dónde sale | Qué caso la expone |
|---|---|---|
| `avg_ms` · `p95_ms` · `max_ms` por ruta | contadores propios expuestos en `/metrics` | 01, 02, 10 |
| `avg_db_queries` · `avg_db_time_ms` | instrumentación alrededor de `PDO` | 01, 02 |
| conexiones y bloqueos del motor | `pg_stat_activity` en PostgreSQL | 01, 02 |
| memoria del proceso | `memory_get_usage()` | 05 |
| series históricas y dashboards | Prometheus + Grafana | 01 |

**Reproducir la medición del caso 01 (con evidencia gráfica):**

```bash
docker compose -f compose.root.yml up -d --build
curl -s localhost:8100/01/metrics
for i in $(seq 1 30); do curl -s "localhost:8100/01/report-legacy?limit=20" > /dev/null; done
curl -s localhost:8100/01/metrics                    # avg_db_queries = 1 + N
for i in $(seq 1 30); do curl -s "localhost:8100/01/report-optimized?limit=20" > /dev/null; done
curl -s localhost:8100/01/metrics                    # avg_db_queries constante
curl -s localhost:8100/01/diagnostics/summary        # contraste legacy vs optimized
```

Grafana queda disponible para ver la serie temporal del antes y el después — la única evidencia visual de este tipo en todo el repositorio.

**Especificación de rendimiento que este stack verifica y ningún otro puede:** en PHP el N+1 son N round-trips reales contra un motor externo, medibles desde el lado del servidor de base de datos. En los otros seis stacks el N+1 son N llamadas a una biblioteca embebida en el mismo proceso. La forma del problema es idéntica; el costo, no.

---

## 🚧 Límites, problemas sin solución y desafíos

| Límite | Por qué importa | Dónde se ve |
|---|---|---|
| **El proceso oculta la fuga de memoria** | La fuga muere con la request. En producción eso significa que un problema real es invisible hasta que el patrón de tráfico cambia | [caso 05](../../cases/05-memory-pressure-and-resource-leaks/comparison.md) |
| **Sin cancelación real** | `sleep()` y `CURLOPT_TIMEOUT` abandonan el resultado; nada se libera del otro lado | [caso 04](../../cases/04-timeout-chain-and-retry-storms/comparison.md) |
| **Sin estado compartido en memoria** | Todo lo que tiene que sobrevivir a la request va a disco o a la base, con el costo y la latencia que eso implica | [casos 06 y 09](../../cases/06-broken-pipeline-and-fragile-delivery/php/README.md) |
| **Nada que observar desde adentro** | No hay pool ni event loop que consultar: la saturación se mide desde afuera o no se mide | [caso 11](../../cases/11-heavy-reporting-blocks-operations/comparison.md) |
| **Ausencia sin respaldo del lenguaje** | `isset()` y `??` son disciplina. Nada obliga a manejar el caso vacío | [caso 12](../../cases/12-single-point-of-knowledge-and-operational-risk/comparison.md) |
| **Concurrencia fuera del lenguaje** | No hay threads en el modelo estándar. La concurrencia la administra FPM, y eso deja al código sin herramientas para hablar del tema | transversal |

**Desafío abierto del stack en este laboratorio:** PHP es el mejor sustrato de infraestructura y a la vez el peor rankeado en fit de primitivas. Esa tensión es real y el repositorio la deja escrita en vez de resolverla a favor de una de las dos lecturas. El caso 02 es el ejemplo exacto: PHP promedia último en todo el laboratorio y aun así queda 🥉 en ese caso, porque el sustrato importa más que la sintaxis cuando lo que hay que demostrar es el costo de una consulta.

---

## 🏆 Dónde gana y dónde pierde en el laboratorio

Agregado de los veredictos de las 14 comparativas que rankean: **0 primeros puestos, media 5.9** — el promedio más bajo del set.

- 🥉 **Tercero en 02** — el único stack donde el N+1 cruza un socket real contra PostgreSQL. El sustrato le gana a la primitiva.
- **4º en 01** — el mejor sustrato del laboratorio: motor real, worker separado, contención observable desde el motor.
- **6º en otros nueve casos y 7º en el 13, el 14 y el 15** — en el 15 el último puesto viene con premio: al no tener cola en proceso, es el stack que mejor enseña que el freno vive en el sistema entero, no en la cola. — el modelo de proceso por request deja al lenguaje sin nada que aportar a problemas de concurrencia, cancelación o tipos. En el 13 el último puesto viene con premio: es el único stack que no puede esconder el double check dentro del lock.

**Lectura honesta:** el promedio de PHP mide *fit de primitivas con el problema*, y en eso queda último con claridad. No mide calidad del lenguaje, ni idoneidad para la web, ni la calidad de la evidencia que produce — donde es el mejor del repositorio. Un lector que se quede solo con el promedio se pierde la mitad de la historia.

---

## 🔄 Ciclo de versiones

| | |
|---|---|
| **Versión fijada hoy** | `8.3` (`php:8.3-cli-alpine`; el portal usa `php:8.3-apache`) |
| **Cadencia upstream** | Una release menor por año, en noviembre |
| **Política de soporte** | 2 años de soporte activo + 2 de seguridad por versión menor |
| **Producto en endoflife.date** | `php` |

> 📌 **Nota de coherencia:** este stack usa **dos variantes** de la misma versión — `cli-alpine` para los doce casos y `apache` para el portal, porque el portal necesita servidor web y los casos no. Las variantes pueden diferir; **la versión no**. Si el portal quedara en `8.2` mientras los casos van en `8.3`, `scripts/language_drift.py` lo reporta como drift y hace bien: sería el mismo repositorio afirmando dos verdades.

**Qué revisar en el próximo salto:**

1. **El dashboard del portal y el pool FPM del caso 01** — son las dos piezas que más dependen de la configuración de la imagen.
2. **Cambios en `PDO`** — es la base de los casos 01 y 02, los dos más profundos del stack.
3. **Evolución del JIT** — afecta la lectura de la pendiente legacy/optimized en el caso 10.
4. **Cambios de comportamiento en `??` e `isset()`** — sostienen los casos 09 y 12.
5. **Que ambas variantes suban juntas** — `cli-alpine` y `apache` tienen que quedar en la misma versión menor.

El detalle del procedimiento está en [docs/language-upgrade-protocol.md](../language-upgrade-protocol.md).

---

## 🚀 Levantar el stack

```bash
docker compose -f compose.root.yml up -d --build
```

Levanta el portal, los 15 casos PHP en `http://localhost:8100/NN/`, PostgreSQL, Prometheus y Grafana. Es la entrada más completa del laboratorio y la recomendada para una primera evaluación.

Para el portal solo, sin base ni observabilidad:

```bash
docker compose -f compose.portal.yml up -d --build
```
