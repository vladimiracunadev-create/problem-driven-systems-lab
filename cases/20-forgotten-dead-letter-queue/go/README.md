# 🐹 Caso 20 — Go 1.23

<!-- nav-stack -->
[⬅️ Caso 20](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐹 Perfil de Go](../../../docs/languages/go.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Go del caso 20. Consumidor silencioso contra consumidor que clasifica, reintenta, mide y drena.

## `errors.Is`, `errors.As` y la cadena de `%w`

La clasificación en Go no viaja por una jerarquía de tipos: viaja por una **cadena** de errores que cada capa envuelve sin perder lo de abajo.

```go
return fmt.Errorf("procesando msg-%d: %w", id, ErrTransitorio)
...
if errors.Is(err, ErrTransitorio) { reintentar() }

var pe *ErrorVenenoso
if errors.As(err, &pe) { aDLQ(msg, pe.Clase) }
```

Dos ventajas concretas para este caso:

- **El contexto se acumula sin borrar la causa.** Cada capa agrega su mensaje con `%w`, y `errors.Is` sigue encontrando el sentinel al fondo. Es exactamente lo que hace falta en un registro de DLQ: saber **qué** falló y también **dónde**.
- **`errors.Is` compara por valor, no por tipo.** No se rompe cuando el error cruza un límite de paquete — que es donde el `instanceof` de Node deja de funcionar.

## Lo que Go no da: exhaustividad

Nada obliga a manejar una clase de error nueva. Agregar `ErrCorrupto` compila perfecto, y el `if`/`else` existente lo manda al camino por defecto — que en un consumidor mal escrito significa la DLQ como `unclassified`.

Ahí Rust gana con su `match` exhaustivo: **una variante nueva no compila hasta que alguien decida qué hacer con ella.**

## La diferencia con el caso 19

En el [caso 19](../../19-search-index-drift-and-broken-cdc/go/README.md), Go queda segundo porque el `_ =` hace visible el descarte del error. Acá el problema es otro: no es **ignorar** el error, es **no mirarlo bien**. Y para eso el error-como-valor no alcanza — hace falta que el compilador exija cubrir los casos, que es lo único que Go no ofrece.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `%w` en `fmt.Errorf` | Envuelve sin perder la causa. |
| `errors.Is` | Compara por valor: sobrevive a los límites de paquete. |
| `errors.As` | Extrae el tipo concreto con sus datos (la clase de veneno). |
| Sentinel `var ErrX = errors.New(...)` | La clase de error como valor, comparable. |

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
docker compose -f compose.go.yml up -d --build
curl "http://127.0.0.1:8600/20/consume-silent?messages=3000"
curl "http://127.0.0.1:8600/20/consume-observed?messages=3000"
curl "http://127.0.0.1:8600/20/dlq/stats"
```

