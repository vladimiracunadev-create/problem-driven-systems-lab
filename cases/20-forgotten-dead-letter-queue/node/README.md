# 🟢 Caso 20 — Node.js 22

<!-- nav-stack -->
[⬅️ Caso 20](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🟢 Perfil de Node.js](../../../docs/languages/node.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Node del caso 20. Consumidor silencioso contra consumidor que clasifica, reintenta, mide y drena.

## Los errores son objetos comunes, sin jerarquía obligatoria

`class ErrorVenenoso extends Error` funciona, e `instanceof` funciona — **mientras el error no cruce un límite que rompa la cadena de prototipos**:

- Dos copias del mismo paquete en `node_modules` producen dos clases distintas, e `instanceof` da `false` entre ellas.
- Un error serializado a través de un `worker_thread` o de un mensaje llega como objeto plano: la clase se perdió.
- Los errores de `fs`, `net` y de bibliotecas nativas no heredan de ninguna jerarquía de dominio: traen `err.code` como string.

El resultado en producción es que la clasificación degrada a comparar strings:

```js
if (err.code === 'ETIMEDOUT' || /timeout/i.test(err.message)) reintentar();
```

Funciona hasta que alguien cambia un mensaje de error. **Es el stack más débil del set para este caso**, y la debilidad es exactamente donde el caso pega.

## Lo que sí llegó, tarde

`error.cause`, desde ES2022:

```js
throw new ErrorVenenoso('schema_mismatch', { cause: errOriginal });
```

Preserva la cadena, y es el equivalente del `%w` de Go y del `raise ... from` de Python. Go lo tiene desde 1.13 y Python desde 3.0.

## La recomendación práctica

Cuando `instanceof` no es confiable, la alternativa robusta es un **campo discriminante propio**:

```js
class ErrorProceso extends Error {
  constructor(clase, transitorio) { super(clase); this.tipo = 'ErrorProceso'; this.transitorio = transitorio; }
}
if (err?.tipo === 'ErrorProceso') { ... }   // sobrevive a los límites de paquete
```

Es menos elegante que un `enum` de Rust o una jerarquía `sealed` de Java, y es lo que de verdad aguanta en producción.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `class X extends Error` | La clasificación — frágil entre límites de paquete. |
| `error.cause` (ES2022) | La cadena de causas, llegó tarde al lenguaje. |
| `err.code` | Lo que traen los errores nativos: strings, no tipos. |
| Campo discriminante propio | Lo que de verdad aguanta cuando `instanceof` falla. |

## Transitorio contra venenoso

| Clase | Qué significa | Qué corresponde |
|---|---|---|
| **Transitorio** | El mismo mensaje funciona en el próximo intento | **Reintentar** con backoff |
| **Venenoso** | El mismo mensaje **nunca** va a funcionar | **A la DLQ**, ya mismo |

**Reintentar lo venenoso es quemar CPU. Mandar lo transitorio a la DLQ es tirar trabajo que se podía salvar.** El consumidor que no distingue hace las dos cosas mal a la vez.

## Rutas

| Ruta | Qué muestra |
|---|---|
| `/health` | estado básico del servicio |
| `/consume-silent?messages=3000` | cualquier fallo a la DLQ, sin clasificar ni reintentar |
| `/consume-observed?messages=3000` | clasificar, reintentar lo transitorio, alertar |
| `/dlq/stats` | profundidad, antigüedad del más viejo y desglose por clase |
| `/dlq/drain?limit=500` | replay: qué se recupera y qué sigue siendo veneno |
| `/diagnostics/summary` | acumulado por variante, más la nota de fidelidad |
| `/reset-lab` | vacía la DLQ y las métricas |

**Parámetros:** `messages` (10–200k), `transient_pct`, `poison_pct`, `max_retries`, `alert_threshold`, `sample_size`, `limit`.

## Lo que sale, y es idéntico en los siete

```text
  silencioso:  ok=2584  reintentos=0    a la DLQ=416  (13,87%)
               by_error_class = { unclassified: 416 }   alertas=0  muestras=0

  observado:   ok=2881  reintentos=297  a la DLQ=119  (3,97%)
               by_error_class = { schema_mismatch: 29, unknown_field: 31,
                                  null_required: 31, invalid_encoding: 28 }
               alertas=1  muestras=20
```

Y la medición que cierra el caso — drenar la DLQ del consumidor **silencioso**:

```text
  recuperados = 297 de 416  →  71,39%      ← nunca debieron estar ahí
  siguen fallando = 119                     ← veneno de verdad
```

Drenar la del consumidor **observado** recupera 0%: ahí solo hay veneno, que es exactamente lo que una DLQ debería contener.

## Cierra el arco del caso 15

En el [caso 15](../../15-message-queue-backpressure/README.md) la dead letter queue **nace**: es la política de rechazo que salva al productor de bloquearse cuando la cola se llena. Es la decisión correcta.

Acá se ve qué pasa cuando nadie vuelve a mirarla. **Los dos casos son el mismo mecanismo en dos momentos distintos** — y el segundo demuestra que la decisión del primero solo está completa cuando incluye quién la observa y cómo se sale de ella.

## Hub

```bash
docker compose -f compose.nodejs.yml up -d --build
curl "http://127.0.0.1:8300/20/consume-silent?messages=3000"
curl "http://127.0.0.1:8300/20/consume-observed?messages=3000"
curl "http://127.0.0.1:8300/20/dlq/stats"
```

