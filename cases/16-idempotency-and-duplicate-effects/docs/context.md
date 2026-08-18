# 🗺️ Contexto

Un cliente reintenta una petición porque el primer intento dio timeout, hubo un corte de red, o alguien apretó el botón dos veces. El resultado es un cobro duplicado, un email enviado dos veces, un mensaje publicado dos veces.

## Justificación

Lo que hace este caso difícil de ver es que **el primer intento sí llegó**. Lo que se perdió fue la respuesta.

El cliente no tiene forma de distinguir «no llegó al servidor» de «llegó y no me enteré», así que reintenta — y hace bien, porque la alternativa es perder operaciones legítimas. El problema está del otro lado: **el servidor tampoco puede distinguir «es la primera vez que veo esto» de «ya procesé esto»**, salvo que el cliente le dé una forma de saberlo.

Esa forma es la `Idempotency-Key`. Y la operación que la hace funcionar tiene un requisito que parece menor y no lo es: **reservar la clave tiene que ser una sola operación indivisible**. Un `if (!existe) { crear }` tiene una ventana entre las dos líneas, y con cinco reintentos concurrentes esa ventana produce cinco cobros.

La segunda mitad del problema aparece cuando el efecto cruza un boundary. El cargo va a la base de datos y el email a una cola: dos sistemas distintos, sin transacción que los abarque. Si el cargo se aplica y el email falla, se pierde el aviso; si el email sale y el cargo se revierte, se avisó de algo que no pasó. El **outbox pattern** resuelve eso escribiendo el efecto en la misma transacción local que el cargo, y dejando que un worker lo entregue después.

<!-- catalogo -->

## 📇 Ficha del caso

| | |
|---|---|
| **Categoría** | Resiliencia |
| **Estado** | `OPERATIVO` |
| **Stacks operativos** | 7 de 7 |

> Un reintento por timeout se convierte en un segundo cobro salvo que el servidor pueda distinguir «primera vez» de «ya procesado».

## 🧱 Dónde correrlo

| Stack | Versión | URL en el hub | Implementación |
|---|---|---|---|
| 🐘 PHP | `PHP 8.3` | `http://localhost:8100/16/` | [README](../php/README.md) |
| 🐍 Python | `Python 3.12` | `http://localhost:8200/16/` | [README](../python/README.md) |
| 🟢 Node.js | `Node.js 22` | `http://localhost:8300/16/` | [README](../node/README.md) |
| ☕ Java | `Java 21` | `http://localhost:8400/16/` | [README](../java/README.md) |
| 🔵 .NET | `.NET 8` | `http://localhost:8500/16/` | [README](../dotnet/README.md) |
| 🐹 Go | `Go 1.23` | `http://localhost:8600/16/` | [README](../go/README.md) |
| 🦀 Rust | `Rust 1.83` | `http://localhost:8700/16/` | [README](../rust/README.md) |

> ⚠️ **Nota de honestidad del caso:** el ledger, la tabla de idempotencia y el outbox son estructuras en memoria (un archivo con `flock` en PHP), no una base con `UNIQUE` real. Lo que se demuestra con fidelidad es la **operación atómica de reserva** y el contraste de `charges_applied`. Y hay una asimetría de fondo que el caso documenta en vez de esconder: seis de las siete versiones resuelven la carrera **dentro de su proceso**, así que con dos réplicas dejan de ser correctas.
<!-- /catalogo -->

<!-- nav-case-doc -->
---

**Caso 16 · Idempotencia y efectos duplicados** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

**🗺️ Contexto** · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
