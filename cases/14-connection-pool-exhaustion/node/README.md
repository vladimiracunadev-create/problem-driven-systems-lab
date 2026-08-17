# 🟢 Caso 14 — Node.js 22

<!-- nav-stack -->
[⬅️ Caso 14](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🟢 Perfil de Node.js](../../../docs/languages/node.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Node del caso 14. Un pool que se achica en silencio contra uno con devolución garantizada.

## Por qué el modo de falla de Node es el peor de los siete

En Java o Go, un hilo bloqueado esperando una conexión sigue siendo un objeto que un thread dump muestra. En Node **no hay hilo**: el que espera es una `Promise` que nadie va a resolver nunca.

No aparece en ningún stack trace. No consume CPU. No dispara ninguna alarma. El request simplemente no responde, y el cliente se queda colgado hasta su propio timeout. Es un leak de memoria y un request perdido a la vez, y el proceso se ve perfectamente sano desde afuera.

Por eso `AbortSignal.timeout()` no es un lujo acá: **es la única forma de que la espera tenga un final observable**.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `AbortSignal.timeout(ms)` | El deadline de adquisición. No necesita `clearTimeout` manual: el runtime libera el temporizador al abortar. |
| `finally` en `async` | La devolución garantizada, incluida la ruta de `throw` y la de `return` temprano. |
| Cola de waiters | Cuando el pool está vacío, el que llega se encola como una Promise pendiente que el `release` resuelve. |
| `setTimeout` | El tiempo de retención de la conexión. |

## Contraste

**Leaky** — sin `finally`, el `return` del `catch` se lleva la conexión:
```js
const conn = await pool.acquire(LEAKY_WATCHDOG_MS);
try {
  await runQuery(conn, queryMs, fails(idx, failRate));
} catch {
  return { outcome: 'failed_query', waitMs };   // ← la conexión no vuelve
}
pool.release(conn);
```

**Managed** — deadline + `finally`:
```js
const conn = await pool.acquire(ACQUIRE_TIMEOUT_MS);
if (!conn) return { outcome: 'failed_timeout', waitMs };   // falla rápido y contable
try {
  await runQuery(conn, queryMs, fails(idx, failRate));
  return { outcome: 'completed', waitMs };
} catch {
  return { outcome: 'failed_query', waitMs };
} finally {
  pool.release(conn);          // corre en los tres caminos
}
```

## Rutas

| Ruta | Qué muestra |
|---|---|
| `/health` | liveness |
| `/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25` | `leaked` > 0 y `hung` creciente: el pool se vacía y no vuelve |
| `/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25` | `leaked` = 0 y `pool_available_after` = `pool_size` |
| `/pool/state` | tamaño, disponibles, adquiridas, devueltas y fugadas |
| `/diagnostics/summary` | acumulado por variante + ley de Little |
| `/reset-lab` | reconstruye el pool y limpia contadores |

**Parámetros:** `requests` (1–200 llamadores), `pool` (1–64 conexiones), `query_ms` (1–500, cuánto retiene cada query), `fail_rate` (0–100 %, porcentaje de queries que lanzan).

## Hub

```bash
docker compose -f compose.nodejs.yml up -d --build
curl "http://127.0.0.1:8300/14/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25"
curl "http://127.0.0.1:8300/14/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25"
curl "http://127.0.0.1:8300/14/pool/state"
```

## Por qué acá el trabajo sí es un `sleep`

En el [caso 13](../../13-cache-stampede-and-thundering-herd/README.md) un `sleep` habría escondido el punto: lo que duele en una estampida es que el origen **hace** el trabajo N veces, así que hubo que quemar CPU de verdad.

Acá es al revés. Una conexión se retiene mientras se **espera a la red**, no mientras se calcula. Dormir es el modelo fiel del tiempo de retención; quemar CPU mediría otra cosa y además competiría con los propios hilos del laboratorio.

La misma decisión, tomada en sentidos opuestos, por la misma razón: modelar el recurso que realmente escasea.

