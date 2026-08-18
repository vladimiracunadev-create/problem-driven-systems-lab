# 🐹 Caso 16 — Go 1.23

<!-- nav-stack -->
[⬅️ Caso 16](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐹 Perfil de Go](../../../docs/languages/go.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Go del caso 16. Cinco reintentos del mismo pago, con y sin clave de idempotencia.

## `LoadOrStore`: valor y bandera en una sola operación

```go
actual, loaded := idempotency.LoadOrStore(key, mine)
if !loaded { /* soy el primero */ } else { /* reintento */ }
```

Si `loaded` es `false`, la clave se acaba de reservar y sos el primero; si es `true`, alguien llegó antes y te llevás su valor. Una sola operación resuelve la carrera y dice de qué lado quedaste — el mismo contrato del comma-ok que Go usa en todas partes.

## Lo distintivo acá es el *cuándo*, no el *qué*

`sync.Map` está documentado para dos casos de uso, y este es exactamente el segundo: **claves que se escriben una vez y se leen muchas**.

Es lo contrario del [caso 13](../../13-cache-stampede-and-thundering-herd/go/README.md), donde un `map` bajo mutex era la elección correcta porque cada entrada se creaba y se borraba en **cada expiración** — el patrón en el que `sync.Map` va peor que un map normal.

El mismo laboratorio, dos casos, dos respuestas opuestas. Y la regla que las separa no es la preferencia: es el patrón de escritura.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `sync.Map.LoadOrStore` | La reserva atómica, para claves escritas una vez. |
| `chan struct{}` cerrado | La largada común de los reintentos. |
| `sync.Mutex` | El ledger y el outbox, que sí se escriben seguido. |

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
docker compose -f compose.go.yml up -d --build
curl "http://127.0.0.1:8600/16/charge-unsafe?key=order-4711&attempts=5&amount=2500"
curl "http://127.0.0.1:8600/16/reset-lab"
curl "http://127.0.0.1:8600/16/charge-idempotent?key=order-4711&attempts=5&amount=2500"
curl "http://127.0.0.1:8600/16/outbox"
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
