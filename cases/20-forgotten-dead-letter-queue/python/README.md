# 🐍 Caso 20 — Python 3.12

<!-- nav-stack -->
[⬅️ Caso 20](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐍 Perfil de Python](../../../docs/languages/python.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Python del caso 20. Consumidor silencioso contra consumidor que clasifica, reintenta, mide y drena.

## La jerarquía de excepciones clasifica sin ceremonia

```python
class ErrorTransitorio(Exception): ...
class ErrorVenenoso(Exception): ...

try:
    procesar(msg)
except ErrorTransitorio:
    reintentar()
except ErrorVenenoso as e:
    a_dlq(msg, clase=e.clase)
```

Cuatro líneas, sin anotaciones, sin declarar tipos de error en la firma. Es el `catch` por tipo de Java sin el `throws`, y para un caso cuyo núcleo es clasificar, esa economía cuenta.

## El peligro es `except Exception`

Es la causa raíz más traicionera del caso, y en Python está a una palabra de distancia:

```python
try:
    procesar(msg)
except Exception:
    a_dlq(msg)      # ← manda a la DLQ TAMBIÉN los bugs del consumidor
```

Un `KeyError` por un typo, un `AttributeError` de un refactor a medias, un `TypeError` por un cambio de firma. **Esos mensajes no son venenosos: son correctos, y el código está roto.** Terminan en la DLQ indistinguibles del resto, y cuando alguien la revisa meses después la conclusión es «datos malos» en vez de «tuvimos un bug tres semanas».

Rust no tiene esa ambigüedad porque **un `panic!` no es un `Result`**: el bug del consumidor no viaja por el mismo canal que el error de datos.

## Lo que Python sí aporta al diagnóstico

`raise ... from e` mantiene la cadena de causas en `__cause__`, y el traceback la imprime completa:

```text
ValueError: campo 'total' ausente
The above exception was the direct cause of the following exception:
ErrorVenenoso: mensaje venenoso: null_required
```

Es el equivalente del `%w` de Go y del `error.cause` de Node, y Python lo tiene desde 3.0.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| Jerarquía de excepciones | La clasificación, en cuatro líneas. |
| `except (A, B)` | «Estos dos se tratan igual». |
| `raise ... from e` | Cadena de causas visible en el traceback. |
| `except Exception` | La trampa: se traga los bugs propios junto con los datos malos. |

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
docker compose -f compose.python.yml up -d --build
curl "http://127.0.0.1:8200/20/consume-silent?messages=3000"
curl "http://127.0.0.1:8200/20/consume-observed?messages=3000"
curl "http://127.0.0.1:8200/20/dlq/stats"
```

