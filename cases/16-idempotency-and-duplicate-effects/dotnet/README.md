# 🔵 Caso 16 — .NET 8

<!-- nav-stack -->
[⬅️ Caso 16](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🔵 Perfil de .NET](../../../docs/languages/dotnet.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack .NET del caso 16. Cinco reintentos del mismo pago, con y sin clave de idempotencia.

## `TryAdd`: el `if` se lee como la pregunta del negocio

```csharp
if (Idempotency.TryAdd(key, mine))   // "es la primera vez que veo esto"
```

Devuelve `true` si la clave se agregó y `false` si ya estaba. Es la misma operación que `putIfAbsent` de Java, `LoadOrStore` de Go y `entry()` de Rust, con la diferencia de forma de que acá el resultado es un `bool` directo.

## El contraste con el caso 13, en la misma clase

En el [caso 13](../../13-cache-stampede-and-thundering-herd/dotnet/README.md), `GetOrAdd` **no** garantizaba fábrica única y hubo que envolver el trabajo en `Lazy<T>`. Acá `TryAdd` **sí** es atómico.

La diferencia: `TryAdd` no ejecuta ninguna fábrica — solo intenta insertar un valor ya construido. `GetOrAdd` con factory puede invocarla varias veces, y solo una instancia gana el puesto.

**Las dos APIs viven en la misma clase y tienen garantías distintas**, y saber cuál es cuál es la diferencia entre cobrar una vez y cobrar cinco. Es el tipo de detalle que no se ve en un autocompletado.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `ConcurrentDictionary.TryAdd` | La reserva atómica de verdad. |
| `AddOrUpdate` | El ledger, sin lock explícito. |
| `TaskCompletionSource` | La compuerta asíncrona de largada. |
| `record` con `with` | Las filas del outbox, inmutables. |

## Rutas

| Ruta | Qué muestra |
|---|---|
| `/health` | liveness |
| `/charge-unsafe?key=order-4711&attempts=5&amount=2500` | `charges_applied` = `attempts`; `overcharged_cents` es plata real |
| `/charge-idempotent?key=order-4711&attempts=5&amount=2500` | `charges_applied` = 1; `duplicates_prevented` = `attempts - 1` |
| `/idempotency/state` | claves guardadas, edad, ventana de dedupe y saldo por cuenta |
| `/outbox?limit=20` | efectos pendientes y entregados |
| `/diagnostics/summary` | acumulado por variante |
| `/reset-lab` | vacía ledger, claves y outbox |

**Parámetros:** `key` (la `Idempotency-Key`), `account`, `attempts` (1–64 reintentos), `amount` (centavos).

## Hub

```bash
docker compose -f compose.dotnet.yml up -d --build
curl "http://127.0.0.1:8500/16/charge-unsafe?key=order-4711&attempts=5&amount=2500"
curl "http://127.0.0.1:8500/16/reset-lab"
curl "http://127.0.0.1:8500/16/charge-idempotent?key=order-4711&attempts=5&amount=2500"
curl "http://127.0.0.1:8500/16/outbox"
```

## La segunda mitad: el outbox

El cargo va a la base y el email a una cola. Dos sistemas distintos, sin transacción que los abarque:

- si el cargo se aplica y el email falla → se pierde el aviso
- si el email sale y el cargo se revierte → se avisó de algo que no pasó

El **outbox pattern** escribe el efecto en la **misma escritura** que el cargo y deja que un worker lo entregue después. La entrega es *at-least-once*, no *exactly-once* — y es una decisión consciente: duplicar un email es visible y corregible, perderlo no.

## El límite honesto de esta implementación

La tabla de idempotencia vive en el heap de **este** proceso. Es correcta con una réplica y deja de serlo con dos: cada pod tiene la suya, ninguno ve las claves del otro, y el mismo pago se cobra una vez por pod.

Ese bug no aparece al escribir el código ni al testearlo. Aparece al escalar — que es el peor momento para descubrirlo.

La versión que sobrevive a varias réplicas necesita almacenamiento compartido: `SET NX` en Redis, o un `UNIQUE` en la base con `INSERT ... ON CONFLICT DO NOTHING`. Es exactamente lo que hace el [stack PHP](../php/README.md) de este caso, por obligación más que por virtud.
