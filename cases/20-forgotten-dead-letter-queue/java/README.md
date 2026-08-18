# ☕ Caso 20 — Java 21

<!-- nav-stack -->
[⬅️ Caso 20](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [☕ Perfil de Java](../../../docs/languages/java.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Java del caso 20. Consumidor silencioso contra consumidor que clasifica, reintenta, mide y drena.

## La jerarquía sellada: el mecanismo de clasificación más expresivo del set

```java
abstract static sealed class ErrorProceso extends RuntimeException
        permits ErrorTransitorio, ErrorVenenoso { }

catch (ErrorTransitorio e) { reintentar(); }
catch (ErrorVenenoso e)    { aDLQ(msg, e.clase); }
```

`sealed` (Java 17) es lo que acerca a Java a la exhaustividad de Rust: **la jerarquía queda cerrada, y una clase nueva tiene que declararse en el `permits`**. Con `switch` sobre patrones de tipo, el compilador exige que se cubran todas las ramas.

Y el multi-catch —`catch (A | B e)`— dice «estos dos se tratan igual» sin duplicar el bloque.

## Lo que Java pierde contra .NET

**Para clasificar hay que capturar, y capturar desenrolla la pila.**

```java
try { procesar(msg); }
catch (ErrorProceso e) {
    if (!esNuestro(e)) throw e;   // ← la pila original ya se acortó
    ...
}
```

Cuando se relanza para que el caller decida, el stack trace original ya perdió los frames de abajo. .NET tiene filtros de excepción —`catch (Ex e) when (...)`— que deciden **antes** de desenrollar, y eso es exactamente el dato que un registro de DLQ necesita para ser útil: sin el punto de falla original, la entrada de la DLQ no sirve para depurar.

## Un detalle de rendimiento que este caso hace visible

Construir una excepción captura el stack trace, y en un lazo caliente eso domina el costo. El constructor de cuatro argumentos lo desactiva:

```java
super(mensaje, null, /* enableSuppression */ false, /* writableStackTrace */ false);
```

Es lo que hace este caso, y es la razón por la que un consumidor con 4% de mensajes venenosos no paga un precio absurdo por clasificarlos.

## El otro riesgo, que es cultural

`catch (Exception e)` en el consumidor manda a la DLQ **también los bugs del propio código** — un `NullPointerException` de un refactor a medias. Esos mensajes no son venenosos: son correctos, y el código está roto.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `sealed ... permits` | Jerarquía cerrada: una clase nueva debe declararse. |
| `catch (A \| B e)` | «Estos dos se tratan igual». |
| `switch` sobre patrones de tipo | Lo que acerca a Java a la exhaustividad de Rust. |
| `super(m, null, false, false)` | Excepción sin stack trace, para el lazo caliente. |

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
docker compose -f compose.java.yml up -d --build
curl "http://127.0.0.1:8400/20/consume-silent?messages=3000"
curl "http://127.0.0.1:8400/20/consume-observed?messages=3000"
curl "http://127.0.0.1:8400/20/dlq/stats"
```

