# 🐘 Caso 20 — PHP 8.3

<!-- nav-stack -->
[⬅️ Caso 20](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐘 Perfil de PHP](../../../docs/languages/php.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack PHP del caso 20. Consumidor silencioso contra consumidor que clasifica, reintenta, mide y drena.

## Los tipos union en `catch`

PHP 8 permite decir «estos dos se tratan igual» sin duplicar el bloque ni inventar una clase base artificial:

```php
catch (ErrorTransitorio | ErrorDeRed $e) { reintentar(); }
catch (ErrorVenenoso $e)                 { aDlq($msg, $e->clase); }
```

Java lo tiene también (`catch (A | B e)`); Python lo escribe con una tupla; Go, Rust y .NET lo resuelven de otra forma. En PHP es especialmente cómodo porque la jerarquía de excepciones del ecosistema tiende a ser plana.

## `Throwable` hace explícito lo que Java esconde

En Java, `Error` está **fuera** de `Exception`: un `catch (Exception)` no atrapa un `StackOverflowError`. En PHP, `Throwable` es la raíz común de `Exception` y `Error`, así que:

```php
catch (Throwable $e) { aDlq($msg); }   // atrapa TAMBIÉN un TypeError
```

Eso es peor por defecto y **mejor como advertencia**: la jerarquía dice en voz alta que capturar todo incluye capturar los bugs propios. Un `TypeError` de un refactor a medias termina en la DLQ como si fuera un mensaje corrupto — y ese mensaje no era venenoso: era correcto, y el código estaba roto.

## El drenaje como comando de cron

```bash
* * * * * php bin/dlq:drain --limit=500
# y en un incidente, a mano:
php bin/dlq:drain --limit=500 --dry-run
```

Es la forma nativa de PHP y también la que más se parece a cómo se opera de verdad. **Un comando ejecutable a mano en un incidente vale más que un consumidor embebido que hay que redesplegar para tocar.**

## Lo que hay que decir en contra

PHP **no tiene exhaustividad de ninguna clase**. Una clase de error nueva cae en el `catch` de más abajo y termina en la DLQ como `unclassified`, sin que nada avise. Rust rompe la compilación; PHP ni siquiera emite un warning.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `catch (A \| B $e)` | «Estos dos se tratan igual», sin duplicar el bloque. |
| `Throwable` | Raíz común de `Exception` y `Error`: capturar todo incluye los bugs propios. |
| `readonly` en la clase de error | La clase de veneno es inmutable desde su construcción. |
| Comando de cron | El drenaje que se ejecuta a mano en un incidente. |

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

## Nota de fidelidad

La DLQ vive en un archivo JSON bajo `flock`, no en SQS ni en RabbitMQ. Lo que define el caso no es el broker: es que **un mensaje que falla tiene que ir a algún lado**, y que ese lado necesita profundidad, antigüedad, clasificación y una salida.

## Hub

```bash
docker compose -f compose.root.yml up -d --build
curl "http://127.0.0.1:8100/20/consume-silent?messages=3000"
curl "http://127.0.0.1:8100/20/consume-observed?messages=3000"
curl "http://127.0.0.1:8100/20/dlq/stats"
```

## Dashboard

```bash
docker compose -f cases/20-forgotten-dead-letter-queue/php/compose.yml up -d --build
# abrir http://localhost:8120/
```
