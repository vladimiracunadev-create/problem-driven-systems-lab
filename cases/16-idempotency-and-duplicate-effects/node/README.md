# 🟢 Caso 16 — Node.js 22

<!-- nav-stack -->
[⬅️ Caso 16](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🟢 Perfil de Node.js](../../../docs/languages/node.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Node del caso 16. Cinco reintentos del mismo pago, con y sin clave de idempotencia.

## La primitiva distintiva es una ausencia

Node **no tiene ninguna operación atómica de mapa**, porque no la necesita. No hay `putIfAbsent`, ni `TryAdd`, ni `LoadOrStore`, ni `entry()`.

```js
if (!table.has(key)) table.set(key, entry);   // atómico en Node
```

Entre esas dos líneas no puede correr nada más, porque no hay otro hilo. En Java, Go o Rust ese mismo código es un bug de concurrencia; acá es correcto.

## Y esa es exactamente la trampa del stack

**El código ingenuo funciona en un proceso y deja de funcionar en cuanto hay dos.**

Con `cluster`, con PM2 en modo fork o con dos pods de Kubernetes, cada proceso tiene su propio `Map` y ninguno ve las claves del otro. No hay error de compilación, no hay warning, no hay test que lo detecte: el bug aparece el día que alguien escala de una réplica a dos.

Es el único stack del laboratorio donde **la corrección depende de cuántos procesos hay**, y donde el código correcto para un proceso es incorrecto para dos sin cambiar una línea.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `Map` | La tabla de idempotencia. Atómica por el modelo de un solo hilo. |
| `has()` + `set()` sin `await` en el medio | La reserva, indivisible por construcción. |
| array como outbox | Los efectos escritos junto al cargo. |

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
docker compose -f compose.nodejs.yml up -d --build
curl "http://127.0.0.1:8300/16/charge-unsafe?key=order-4711&attempts=5&amount=2500"
curl "http://127.0.0.1:8300/16/reset-lab"
curl "http://127.0.0.1:8300/16/charge-idempotent?key=order-4711&attempts=5&amount=2500"
curl "http://127.0.0.1:8300/16/outbox"
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
