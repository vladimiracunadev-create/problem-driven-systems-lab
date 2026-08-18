# 🧬 Perfiles de lenguaje

> Qué es cada runtime, para qué sirve, qué primitivas usa el laboratorio, qué **no** puede resolver, y qué hay que revisar cuando publique una versión nueva.

---

## 🎯 Por qué existe esta carpeta

El laboratorio resuelve **los mismos 18 problemas en 7 lenguajes**. Eso obliga a una pregunta que un repositorio de un solo stack nunca se hace:

> ¿La primitiva que este caso enseña sigue siendo *la forma idiomática actual*, o el lenguaje ya incorporó algo que la reemplaza?

Un perfil por lenguaje es donde esa pregunta tiene respuesta escrita. Sin esto, cuando Java 25 saque `ScopedValue` de preview, el caso 03 seguiría enseñando `ThreadLocal` como si nada hubiera pasado — y nadie sabría dónde mirar.

Cada perfil documenta seis cosas:

| Sección | Qué responde |
|---|---|
| 🪪 **Identidad** | Qué es el lenguaje y para qué se usa fuera de este laboratorio |
| ⚙️ **Modelo de ejecución** | Cómo corre el código, porque de ahí sale qué primitiva es la correcta |
| 🧰 **Primitivas en el lab** | Qué usa cada uno de los 18 casos, con enlace al código |
| 📈 **Rendimiento** | Qué mide el laboratorio en este stack y cómo reproducirlo |
| 🚧 **Límites y problemas sin solución** | Lo que este runtime **no** puede hacer, y qué caso lo deja visible |
| 🔄 **Ciclo de versiones** | Versión fijada, cadencia upstream y qué revisar en el próximo salto |

---

## 🗺️ Los siete perfiles

| Stack | Versión fijada | Modelo de ejecución | Perfil |
|---|---|---|---|
| 🐘 **PHP** | `8.3` | Proceso por petición, sin estado compartido | [php.md](php.md) |
| 🐍 **Python** | `3.12` | Threads reales con GIL | [python.md](python.md) |
| 🟢 **Node.js** | `22` | Event loop de un solo hilo | [node.md](node.md) |
| ☕ **Java** | `21` | Threads del SO, paralelismo real, JVM | [java.md](java.md) |
| 🔵 **.NET** | `8.0` | ThreadPool con `async/await` | [dotnet.md](dotnet.md) |
| 🐹 **Go** | `1.23` | Goroutines multiplexadas por el runtime | [go.md](go.md) |
| 🦀 **Rust** | `1.83` | Threads del SO sin GC, `Drop` determinista | [rust.md](rust.md) |

La versión fijada no se escribe a mano en esta tabla ni en los perfiles: sale de [`shared/catalog/cases.json`](../../shared/catalog/cases.json) y [`scripts/check-language-versions.sh`](../../scripts/check-language-versions.sh) falla el PR si algún `Dockerfile` dice otra cosa.

---

## ⚙️ Un problema, siete modelos de ejecución

![Modelos de ejecución comparados por stack](../assets/execution-models.svg)

El modelo de ejecución no es trivia: es lo que decide qué primitiva es correcta. El caso 11 —reportes pesados que bloquean la operación— lo deja claro:

- En **Java** y **.NET** el problema *es* el pool de threads, así que la solución es separar pools y observar `getActiveCount()`.
- En **Go** no hay pool que agotar, así que la solución es un semáforo de concurrencia con un canal.
- En **Node.js** el trabajo CPU-bound bloquea el proceso entero, así que la única salida real es `worker_threads`.
- En **PHP** los procesos FPM están aislados: no hay nada que aislar, y tampoco nada que observar desde adentro.

Cuatro soluciones distintas, un solo problema. Eso es lo que este laboratorio intenta hacer visible.

---

## 🏆 Dónde gana cada lenguaje (evidencia, no opinión)

![Ranking de fit por caso y stack](../assets/fit-ranking.svg)

Este mapa de calor **no se escribe a mano**: [`scripts/generate_diagrams.py`](../../scripts/generate_diagrams.py) lo deriva de la sección *Veredicto* de los once `comparison.md` que la tienen. Si mañana un veredicto cambia, el diagrama cambia con él.

Cómo leerlo, y cómo **no** leerlo:

> ⚠️ Es un ranking de **fit con el problema**, no de calidad de lenguaje. Mide qué tan directamente las primitivas nativas del runtime expresan la solución de *ese caso concreto*. El orden cambia — a veces se invierte — de un caso a otro.

Tres lecturas que el agregado deja a la vista:

- **Go promedia mejor que Rust** (1.7 vs 1.9) pero **Rust gana más casos** (6 oros vs 4). Go es consistentemente bueno; Rust es excepcional donde el sistema de tipos aporta, y flojo donde no (caso 04: `mpsc::recv_timeout` tiene la misma limitación que Java — corta la espera, no el trabajo).
- **Java y .NET empatan en 3.3** y ganan exactamente el mismo caso: el 11, donde el problema *es* el pool de threads. Cuando el problema es el pool, tener pool explícito deja de ser ceremonia y pasa a ser la herramienta.
- **PHP promedia último (5.5) y aun así es 🥉 en el caso 02**, porque es el único stack donde el N+1 cruza un socket real contra PostgreSQL. El promedio esconde el caso donde el stack "peor rankeado" es el que produce la mejor evidencia.

Si un solo lenguaje ganara todos los casos, el laboratorio no tendría nada que enseñar.

---

## 🔄 Cuando un lenguaje publica versión nueva

![Protocolo de actualización por versión de lenguaje](../assets/language-upgrade-flow.svg)

La detección es automática y semanal; la decisión es humana y queda escrita. El procedimiento completo —qué revisar, en qué orden, y cómo cerrar el issue cuando la respuesta es "no aplica"— está en **[docs/language-upgrade-protocol.md](../language-upgrade-protocol.md)**.

Cada perfil termina con una sección `🔄 Ciclo de versiones` que dice, para *ese* lenguaje, qué está en juego en el próximo salto.

---

## 📚 Documentación relacionada

| Documento | Qué agrega sobre esto |
|---|---|
| [docs/language-upgrade-protocol.md](../language-upgrade-protocol.md) | El procedimiento de actualización, paso a paso |
| [docs/stack-map.md](../stack-map.md) | Por qué hay múltiples lenguajes y qué se estudia al comparar |
| [docs/case-catalog.md](../case-catalog.md) | Los 18 casos con su estado real y stacks operativos |
| [docs/case-methodology.md](../case-methodology.md) | Cómo se construye un caso antes de escribir código |
| `cases/NN-*/comparison.md` | La comparativa de los 7 stacks para un caso concreto |
