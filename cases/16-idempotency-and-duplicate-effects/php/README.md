# 🐘 Caso 16 — PHP 8.3

<!-- nav-stack -->
[⬅️ Caso 16](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐘 Perfil de PHP](../../../docs/languages/php.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack PHP del caso 16. Cinco reintentos del mismo pago, con y sin clave de idempotencia.

## La versión que sobrevive a varias réplicas

PHP no comparte heap entre requests. El `ConcurrentHashMap` de Java, el `ConcurrentDictionary` de .NET, el `sync.Map` de Go y el `Map` de Node **no existen** acá: cualquier tabla en memoria se evapora al terminar la request.

Consecuencia: la clave tiene que vivir en el almacenamiento, y la operación atómica la aporta el motor.

```sql
INSERT INTO idempotency_keys (key, response)
VALUES (:key, NULL)
ON CONFLICT (key) DO NOTHING
RETURNING id;
```

Si devuelve una fila, ganaste. Si no devuelve nada, la clave ya estaba y esto es un reintento. Es exactamente `putIfAbsent`, `TryAdd`, `LoadOrStore` y `entry()` — pero garantizado por un `UNIQUE` del motor en vez de por el heap de un proceso.

## Y acá está lo incómodo del caso

**Esta es la única de las siete versiones que sigue siendo correcta con veinte réplicas.**

Las otras seis resuelven la carrera dentro de su proceso. Con dos pods, cada uno tiene su tabla y ninguno ve las claves del otro, así que el mismo pago se cobra dos veces — una por pod. El bug no aparece al escribir el código: aparece al escalar.

El stack que peor puntúa en fit de primitivas es el que tiene la respuesta que escala. Esa tensión vale más que el ranking.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `flock($fh, LOCK_EX)` | La reserva atómica entre procesos. Modela el `ON CONFLICT` del motor. |
| `finally` | Suelta el lock en todos los caminos de salida. |
| archivo JSON | El ledger, la tabla y el outbox — el almacenamiento compartido que PHP necesita. |

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
docker compose -f compose.root.yml up -d --build
curl "http://127.0.0.1:8100/16/charge-unsafe?key=order-4711&attempts=5&amount=2500"
curl "http://127.0.0.1:8100/16/reset-lab"
curl "http://127.0.0.1:8100/16/charge-idempotent?key=order-4711&attempts=5&amount=2500"
curl "http://127.0.0.1:8100/16/outbox"
```

## La segunda mitad: el outbox

El cargo va a la base y el email a una cola. Dos sistemas distintos, sin transacción que los abarque:

- si el cargo se aplica y el email falla → se pierde el aviso
- si el email sale y el cargo se revierte → se avisó de algo que no pasó

El **outbox pattern** escribe el efecto en la **misma escritura** que el cargo y deja que un worker lo entregue después. La entrega es *at-least-once*, no *exactly-once* — y es una decisión consciente: duplicar un email es visible y corregible, perderlo no.

## Nota de fidelidad

Los N reintentos se recorren en secuencia porque el servidor embebido de PHP es de un solo proceso, y la tabla se modela con `flock` sobre un archivo en vez de con PostgreSQL. La semántica es la misma: una operación atómica sobre almacenamiento compartido entre procesos.

## Dashboard

```bash
docker compose -f cases/16-idempotency-and-duplicate-effects/php/compose.yml up -d --build
# abrir http://localhost:8116/
```
