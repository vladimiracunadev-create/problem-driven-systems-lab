# 🐍 Caso 16 — Python 3.12

<!-- nav-stack -->
[⬅️ Caso 16](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐍 Perfil de Python](../../../docs/languages/python.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Python del caso 16. Cinco reintentos del mismo pago, con y sin clave de idempotencia.

## Una operación en vez de dos

```python
existing = table.setdefault(key, placeholder)
leader = existing is placeholder
```

`setdefault` hace en una sola llamada lo que `if key not in d: d[key] = v` hace en dos — y esa diferencia es todo el caso. Con dos operaciones hay una ventana entre el chequeo y la escritura por la que se cuelan los reintentos concurrentes; con una sola, no la hay.

## El detalle incómodo: atómico por el GIL, no por contrato

CPython garantiza que un `dict.setdefault` no se interrumpe a la mitad. Pero eso es una propiedad de **la implementación**, no del lenguaje: no está en la especificación, y una implementación sin GIL —PyPy con STM, o el propio CPython con el free-threading de PEP 703— no tiene por qué mantenerlo.

Por eso este código toma igual un `Lock` explícito. Lo que se quiere expresar es «esta operación es indivisible», y apoyarse en el GIL para eso es escribir código que depende de un detalle que puede cambiar.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `dict.setdefault` | La reserva en una sola operación. |
| `threading.Lock` | La garantía explícita, en vez de la implícita del GIL. |
| `threading.Barrier` | La largada común de los reintentos, que llegan casi juntos. |

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
docker compose -f compose.python.yml up -d --build
curl "http://127.0.0.1:8200/16/charge-unsafe?key=order-4711&attempts=5&amount=2500"
curl "http://127.0.0.1:8200/16/reset-lab"
curl "http://127.0.0.1:8200/16/charge-idempotent?key=order-4711&attempts=5&amount=2500"
curl "http://127.0.0.1:8200/16/outbox"
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
