# ☕ Caso 16 — Java 21

<!-- nav-stack -->
[⬅️ Caso 16](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [☕ Perfil de Java](../../../docs/languages/java.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Java del caso 16. Cinco reintentos del mismo pago, con y sin clave de idempotencia.

## `putIfAbsent`: resuelve la carrera y dice quién ganó

```java
Entry winner = idempotency.putIfAbsent(key, mine);
if (winner == null) { /* soy el primero */ }
else                { /* reintento: devolver la respuesta guardada */ }
```

Devuelve `null` si ganaste y el valor existente si perdiste — o sea, en **una sola llamada** resuelve la carrera y te dice de qué lado quedaste.

El contraste con la versión rota cabe en dos líneas:

```java
if (!table.containsKey(key)) table.put(key, entry);   // dos operaciones
table.putIfAbsent(key, entry);                        // una
```

Entre el `containsKey` y el `put` hay una ventana. Con cinco reintentos concurrentes de un cliente que sufrió un timeout, esa ventana produce cinco cobros — y el código se ve razonable en la review.

## La misma operación con cuatro nombres

`putIfAbsent` (Java), `TryAdd` (.NET), `LoadOrStore` (Go) y `entry()` (Rust) son **la misma operación**. Lo interesante del caso no es cuál es mejor: es que cuatro runtimes llegaron por separado a la conclusión de que hacía falta una primitiva para esto.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `ConcurrentHashMap.putIfAbsent` | La reserva atómica. |
| `CyclicBarrier` | La largada común de los reintentos. |
| `CopyOnWriteArrayList` | El outbox: escrituras raras, lecturas frecuentes. |
| `LongAdder` | Contadores bajo contención. |

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
docker compose -f compose.java.yml up -d --build
curl "http://127.0.0.1:8400/16/charge-unsafe?key=order-4711&attempts=5&amount=2500"
curl "http://127.0.0.1:8400/16/reset-lab"
curl "http://127.0.0.1:8400/16/charge-idempotent?key=order-4711&attempts=5&amount=2500"
curl "http://127.0.0.1:8400/16/outbox"
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
