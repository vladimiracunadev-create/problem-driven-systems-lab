# 🗺️ Contexto

Una migración sobre una tabla caliente —`ALTER TABLE users ADD COLUMN ...`— toma el lock exclusivo y no lo suelta hasta terminar. Durante veinte minutos, ningún read y ningún write entran. La aplicación devuelve 503 y el negocio pierde dinero por hora.

## Justificación

Lo que hace incómodo este caso es que **el trabajo total no cambia**. Rellenar dos millones de filas cuesta lo que cuesta, se haga de una vez o en mil lotes. Lo que cambia es **cómo se reparte**: un lock de veinte minutos contra mil locks de un segundo.

Y hay un detalle que lo vuelve difícil de detectar antes de que ocurra: **el proceso sigue vivo**. El healthcheck responde, el contenedor no se reinicia, ninguna alerta de disponibilidad de proceso dispara. Lo único que falla son las peticiones — y en muchos sistemas eso se ve como «latencia alta», no como caída.

La solución tiene cuatro fases y un orden que no es negociable:

1. **Expand** — agregar la columna nullable. Es metadata: instantáneo.
2. **Backfill** — rellenar por lotes, soltando el lock entre cada uno.
3. **Switch** — un feature flag cambia lecturas y escrituras a la columna nueva.
4. **Contract** — recién ahora, en una migración posterior, se borra la vieja.

El **switch va antes del contract** porque el flag es lo único reversible en un segundo. Si se borra la columna vieja primero, volver atrás requiere otra migración — y a esa altura ya no hay a dónde volver.

<!-- catalogo -->

## 📇 Ficha del caso

| | |
|---|---|
| **Categoría** | Entrega |
| **Estado** | `OPERATIVO` |
| **Stacks operativos** | 7 de 7 |

> Un ALTER TABLE sobre una tabla caliente bloquea la aplicación entera; expand-contract reparte el mismo trabajo en lotes que nadie nota.

## 🧱 Dónde correrlo

| Stack | Versión | URL en el hub | Implementación |
|---|---|---|---|
| 🐘 PHP | `PHP 8.3` | `http://localhost:8100/17/` | [README](../php/README.md) |
| 🐍 Python | `Python 3.12` | `http://localhost:8200/17/` | [README](../python/README.md) |
| 🟢 Node.js | `Node.js 22` | `http://localhost:8300/17/` | [README](../node/README.md) |
| ☕ Java | `Java 21` | `http://localhost:8400/17/` | [README](../java/README.md) |
| 🔵 .NET | `.NET 8` | `http://localhost:8500/17/` | [README](../dotnet/README.md) |
| 🐹 Go | `Go 1.23` | `http://localhost:8600/17/` | [README](../go/README.md) |
| 🦀 Rust | `Rust 1.83` | `http://localhost:8700/17/` | [README](../rust/README.md) |

> ⚠️ **Nota de honestidad del caso:** no hay PostgreSQL detrás. El lock de la tabla se modela con el read-write lock de cada runtime — que es honesto, porque el mecanismo es el mismo: un escritor excluye a todos los lectores. La excepción es PHP, donde `flock` **sí** es un lock del sistema operativo entre procesos. El tiempo de migración es una espera, no CPU: un `ALTER TABLE` se demora esperando I/O del motor.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 17 · Migración de esquema sin downtime** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

**🗺️ Contexto** · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
