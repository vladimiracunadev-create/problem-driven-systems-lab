# 🦀 Caso 16 — Rust 1.83

<!-- nav-stack -->
[⬅️ Caso 16](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🦀 Perfil de Rust](../../../docs/languages/rust.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Rust del caso 16. Cinco reintentos del mismo pago, con y sin clave de idempotencia.

## La entry API: el compilador exige contemplar las dos ramas

```rust
match table.entry(key) {
    Entry::Occupied(e) => { /* ya estaba: es un reintento */ }
    Entry::Vacant(e)   => { e.insert(v); /* soy el primero */ }
}
```

`entry()` devuelve un enum de dos variantes y el `match` es exhaustivo. Es la misma operación que `putIfAbsent`, `TryAdd` y `LoadOrStore` — con una diferencia decisiva: **en los otros tres, ignorar el valor de retorno compila**.

```java
table.putIfAbsent(key, entry);   // ← el retorno descartado; compila
```

Ese descarte silencioso es exactamente el bug del caso: el código reserva la clave y después sigue como si hubiera ganado, sin mirar si perdió. En Rust no se puede: no hay forma de usar el `Entry` sin decidir qué rama tomás.

## Y hay algo más que solo Rust aporta

El `Entry` **toma prestado el mapa** mientras existe. Mientras se decide qué hacer con la clave, nadie más puede tocar el mapa — y eso no es una convención sino una regla del borrow checker.

La ventana check-then-act no es que sea difícil de escribir: **es inexpresable**. No hay manera de tener un `Entry` vivo y que otro hilo modifique el mapa en el medio.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `HashMap::entry` + `match` exhaustivo | La reserva atómica que obliga a decidir. |
| `Arc<IdemEntry>` con `Condvar` | El reintento espera la respuesta del líder. |
| `std::sync::Barrier` | La largada común de los reintentos. |

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
docker compose -f compose.rust.yml up -d --build
curl "http://127.0.0.1:8700/16/charge-unsafe?key=order-4711&attempts=5&amount=2500"
curl "http://127.0.0.1:8700/16/reset-lab"
curl "http://127.0.0.1:8700/16/charge-idempotent?key=order-4711&attempts=5&amount=2500"
curl "http://127.0.0.1:8700/16/outbox"
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
