# 🧭 ¿Qué es esto? — Explicación en lenguaje simple

> Para cualquier persona que llegó acá sin ser programadora. No hace falta saber nada de tecnología para entender este documento. Cero jerga, y cuando aparece una palabra rara, se explica.

---

## 🏥 La idea en una frase

> Esto es un **hospital de práctica para sistemas informáticos**: doce enfermedades típicas, cada una diagnosticada y tratada de siete maneras distintas, con los estudios médicos a la vista para que cualquiera pueda comprobar que el tratamiento funcionó.

Nadie usa este repositorio para trabajar. Se usa para **demostrar criterio**: que quien lo escribió sabe distinguir un síntoma de una causa, y sabe probar que su arreglo sirvió.

---

## 🤔 ¿Qué es un "repositorio"?

Un **repositorio** es una carpeta de archivos con historial: guarda no solo cómo están las cosas hoy, sino cada cambio que se hizo, cuándo y por qué. Como un documento de Word con "control de cambios", pero para miles de archivos a la vez.

Cuatro palabras más que vas a ver, y nada más:

| Palabra | Qué significa, sin vueltas |
|---|---|
| **Código** | Instrucciones escritas que le dicen a una computadora qué hacer, paso a paso |
| **Lenguaje de programación** | El idioma en que se escriben esas instrucciones. Hay muchos, como hay muchos idiomas humanos |
| **Docker** | Una forma de empaquetar un programa con todo lo que necesita, para que funcione igual en cualquier computadora |
| **Caso** | Acá: un problema real de sistemas, con su explicación, su código y su evidencia |

---

## 🚗 La analogía que hace todo más fácil

Imaginá un **taller mecánico de enseñanza**.

- Hay **fallas típicas** de auto: el motor se recalienta, el auto no arranca en frío, el freno chilla, gasta más nafta de la cuenta.
- Cada falla está **reproducida a propósito** en un auto del taller. No se cuenta la falla: se provoca, para que se pueda ver.
- Para cada falla hay **dos autos al lado**: uno con el problema y otro ya arreglado. Se pueden encender los dos y comparar.
- Y la parte interesante: la misma falla está reproducida en **siete marcas distintas de auto**. Un motor japonés y uno alemán se recalientan igual, pero se arreglan diferente, porque están construidos diferente.

Este repositorio es ese taller. Las "marcas de auto" son siete lenguajes de programación: **PHP, Python, Node.js, Java, .NET, Go y Rust**.

---

## 🧩 Los 18 problemas, en palabras de todos los días

![Los 18 problemas agrupados por naturaleza](assets/case-map.svg)

| # | El nombre técnico | Qué significa en la vida real |
|---|---|---|
| **01** | API lenta bajo carga | La página funciona bien cuando hay poca gente y se arrastra cuando llegan todos juntos. Como una caja de supermercado que va bien hasta que se forma la fila |
| **02** | N+1 y cuellos de botella en base de datos | Para armar una lista de 100 clientes, el sistema pregunta 101 veces en vez de 1. Como ir al depósito a buscar los productos de uno en uno en lugar de llevar un carrito |
| **03** | Observabilidad deficiente y logs inútiles | Cuando algo falla, nadie puede saber qué pasó. Es una caja negra sin cámaras de seguridad |
| **04** | Cadena de timeouts y tormentas de reintentos | Un proveedor externo tarda, el sistema reintenta muchas veces, y los reintentos terminan tumbando todo. Como llamar 20 veces seguidas a un teléfono ocupado y saturar la central |
| **05** | Presión de memoria y fugas de recursos | El programa va acumulando cosas que ya no usa y nunca las suelta. Un escritorio que junta papeles hasta que no queda lugar para trabajar |
| **06** | Pipeline roto y entrega frágil | Cada vez que se publica una versión nueva, algo se rompe y nadie sabe cómo volver atrás |
| **07** | Modernización incremental de monolito | Hay un sistema viejo y enorme que hay que renovar **sin apagarlo**. Como reformar una casa mientras la familia sigue viviendo adentro |
| **08** | Extracción de módulo crítico sin romper la operación | Sacar una pieza central del sistema para convertirla en algo independiente, sin que los que la usan se enteren |
| **09** | Integración externa inestable | Se depende de un servicio de otra empresa que falla, cambia sin avisar o limita cuántas consultas se le pueden hacer |
| **10** | Arquitectura cara para un problema simple | Se construyó algo enorme y costoso para resolver algo que necesitaba mucho menos. Un camión para llevar el pan |
| **11** | Reportes pesados que bloquean la operación | Alguien pide un informe grande y, mientras se genera, el resto del sistema se frena |
| **12** | Punto único de conocimiento y riesgo operacional | Una sola persona sabe cómo funciona algo importante. Si se va de vacaciones, nadie puede resolverlo |
| **13** | Cache stampede y thundering herd | El sistema guarda respuestas listas para no repetir trabajo. Cuando esa copia vence, todos los pedidos que la estaban usando van a buscarla a la vez y tumban lo que había detrás |
| **14** | Agotamiento del pool de conexiones | El sistema tiene un número fijo de líneas para hablar con la base de datos. Cada vez que una llamada falla, esa línea queda descolgada para siempre. Un día no queda ninguna libre |
| **15** | Backpressure en colas de mensajes | Llegan pedidos más rápido de lo que se pueden atender. La fila de espera crece sin límite hasta que la memoria se acaba — y decidir qué hacer cuando la fila se llena siempre cuesta algo |
| **16** | Idempotencia y efectos duplicados | Pagás, la app se queda pensando y volvés a apretar. El primer pago sí había llegado: lo que se perdió fue el aviso. Si el sistema no distingue «es el mismo pago» de «es un pago nuevo», te cobra dos veces |
| **17** | Migración de esquema sin downtime | Hay que agregarle una columna a una tabla enorme. Si se hace de una vez, el sistema queda cerrado veinte minutos. Si se hace de a poco, nadie se entera — y el trabajo total es el mismo |
| **18** | Arranque en frío y retraso del autoescalado | Entra mucha gente de golpe y el sistema abre más cajas. Pero la caja nueva tiene el cartel de «abierta» antes de tener el vuelto en la gaveta: los primeros que van a esa fila se quedan sin poder pagar |

Ninguno de estos problemas es inventado. Todos son cosas que pasan todas las semanas en empresas reales.

---

## 🔬 Por qué está hecho así (y no como un currículum)

La mayoría de los portfolios técnicos muestran **cosas construidas**: una tienda online, una app de tareas. Eso responde a *"¿sabe programar?"*.

Este repositorio responde a una pregunta distinta y bastante más difícil:

> **"Cuando algo se rompe y nadie sabe por qué, ¿esta persona sabe encontrar la causa, arreglarla y demostrar que la arregló?"**

Por eso cada caso tiene siempre las mismas piezas, en el mismo orden:

| Pieza | Qué contiene | La pregunta que responde |
|---|---|---|
| 🩺 **Síntomas** | Qué se ve desde afuera | "¿Qué está pasando?" |
| 🔍 **Diagnóstico** | Cómo se buscó la causa | "¿Por qué está pasando?" |
| 🧠 **Causas raíz** | Qué lo provoca de verdad | "¿Cuál es el problema real, no el aparente?" |
| 🛠️ **Opciones de solución** | Los caminos posibles | "¿Qué se podía hacer?" |
| ⚖️ **Trade-offs** | Qué se gana y qué se pierde con cada camino | "¿Por qué este y no otro?" |
| 💼 **Valor de negocio** | Por qué le importa a la empresa, no solo al equipo técnico | "¿Y esto qué plata ahorra?" |
| 📋 **Postmortem** | Qué se aprendió después | "¿Cómo evitamos que vuelva a pasar?" |

Esa secuencia —síntoma, causa, opciones, decisión, evidencia— es exactamente cómo trabaja un buen médico. Y es exactamente cómo debería trabajar un buen ingeniero.

---

## 🌍 ¿Por qué siete lenguajes y no uno?

Porque el mismo problema **no se resuelve igual** en cada uno, y comparar es donde está el aprendizaje.

Un ejemplo real del caso 11 (los reportes pesados que frenan todo):

| Lenguaje | Cómo lo resuelve | La analogía |
|---|---|---|
| ☕ **Java** y 🔵 **.NET** | Separan a los empleados en dos grupos: unos atienden clientes, otros hacen informes | Dos equipos con tareas asignadas |
| 🐹 **Go** | No hay equipos fijos; se limita a cuántos informes pueden hacerse a la vez | Un cupo de dos personas por vez en la sala de informes |
| 🟢 **Node.js** | Hay **un solo empleado**. Si se pone a hacer un informe, nadie atiende. Hay que contratar a otro aparte | El kiosco de una sola persona |
| 🐘 **PHP** | Cada cliente trae su propio empleado, que se va al terminar. No hay nada que separar | Personal temporario por cliente |

Cuatro soluciones distintas para un solo problema. **Ese contraste es el contenido.** No se trata de coronar un ganador, sino de mostrar que la herramienta correcta depende de cómo está construida la casa.

---

## 📊 ¿Hay un lenguaje mejor que los otros?

No, y el repositorio lo dice con datos en vez de opinión:

![Qué stack expresa mejor cada problema](assets/fit-ranking.svg)

Cada fila es un lenguaje, cada columna uno de los problemas. Verde oscuro = ese lenguaje resolvió ese problema de la forma más directa. Gris = fue el más torpe para ese problema en particular.

Lo importante de ese cuadro: **ninguna fila es toda verde**. Cada lenguaje gana en unos casos y pierde en otros. Si uno ganara siempre, no habría nada que enseñar — y probablemente el cuadro estaría mal hecho.

---

## 👀 Cómo mirar esto sin instalar nada

No hace falta ejecutar nada para sacar conclusiones. Se puede leer, en este orden:

1. **[El resumen ejecutivo](executive-summary.md)** — los 18 casos en una página, con el valor de negocio de cada uno.
2. **Un caso cualquiera** — por ejemplo [el 02](../cases/02-n-plus-one-and-db-bottlenecks/README.md), que es el más fácil de entender sin saber programar. Está la explicación del problema antes que cualquier código.
3. **Una comparativa** — el archivo `comparison.md` de ese mismo caso, que muestra los siete lenguajes lado a lado y termina con un veredicto razonado.
4. **[La guía para reclutadores](recruiter-guide.md)** — qué señales mirar y, sobre todo, **qué no** conviene concluir.

---

## 🚀 Y si querés verlo funcionando

Hace falta **Docker**, un programa gratuito que empaqueta y ejecuta todo por vos. Se instala una vez y no hay que configurar nada más.

```bash
docker compose -f compose.root.yml up -d --build
```

Ese comando levanta el laboratorio completo en tu computadora. Después se abre `http://localhost:8100` en el navegador y ahí hay un portal para recorrer los casos con el mouse, sin escribir un solo comando más.

Los pasos detallados están en [INSTALL.md](../INSTALL.md). Si algo no arranca, [RUNBOOK.md](../RUNBOOK.md) tiene las soluciones a los tropiezos más comunes.

> 💡 **Consejo:** no intentes levantar los siete lenguajes a la vez para "ver si funciona". Uno por vez se entiende mucho mejor y la computadora te lo va a agradecer.

---

## ✅ Lo que sí podés concluir de este repositorio

- Que quien lo escribió sabe **explicar un problema técnico a alguien que no es técnico** — este documento es parte de la prueba.
- Que trabaja con **evidencia**, no con afirmaciones: cada arreglo tiene un antes y un después medibles.
- Que sabe **comparar herramientas con criterio** en lugar de defender su favorita.
- Que documenta **también lo que no funciona**, que es la parte que casi nadie escribe.

## 🚫 Lo que no podés concluir

- Que sea experto de años en los siete lenguajes. El repositorio lo dice explícitamente y no lo esconde.
- Que estos números sirvan para elegir un lenguaje para tu empresa. Miden **qué tan bien encaja cada herramienta con cada problema**, no cuál es más rápido ni cuál conviene contratar.
- Que sea un producto listo para usar. Es un laboratorio de demostración, y está construido para eso.

---

## 📚 Si querés seguir

| Documento | Para qué |
|---|---|
| [BEGINNERS_GUIDE.md](BEGINNERS_GUIDE.md) | Ruta para quien está empezando a programar |
| [executive-summary.md](executive-summary.md) | Los 18 casos en una página |
| [recruiter-guide.md](recruiter-guide.md) | Qué mirar si tu trabajo es evaluar perfiles técnicos |
| [languages/](languages/README.md) | Qué es cada lenguaje y para qué sirve, uno por uno |
| [../README.md](../README.md) | La puerta de entrada técnica completa |
