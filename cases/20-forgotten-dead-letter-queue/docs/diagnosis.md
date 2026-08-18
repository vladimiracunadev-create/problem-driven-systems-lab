# 🔍 Diagnóstico

## Las dos preguntas

1. **¿Cuántos mensajes hay en la DLQ?**
2. **¿Hace cuánto está el más viejo?**

Si la primera no tiene respuesta en un dashboard, la DLQ es un agujero. Si la segunda da un número en semanas, ya nadie la va a drenar.

## Cómo medirlo

```bash
# consumidor silencioso: cualquier fallo a la DLQ, sin clasificar
curl "http://localhost:8700/20/consume-silent?messages=3000"

# consumidor observado: clasificar, reintentar lo transitorio, alertar
curl "http://localhost:8700/20/consume-observed?messages=3000"

# profundidad, antigüedad y desglose por clase de error
curl http://localhost:8700/20/dlq/stats

# replay: qué se recupera y qué sigue siendo veneno
curl "http://localhost:8700/20/dlq/drain?limit=500"
```

## Qué mirar en la respuesta

| Campo | Qué dice |
|---|---|
| `dlq_depth` | La profundidad. Debería estar en un dashboard, no en un `curl`. |
| `dlq_oldest_msg_age_ms` | **Hace cuánto está el más viejo.** La que decide la gravedad. |
| `by_error_class` | El desglose. Convierte un número en un diagnóstico. |
| `dead_letter_rate_pct` | Qué porcentaje del tráfico se está perdiendo. |
| `retried` | Cuánto se recuperó **sin** llegar a la DLQ. |
| `alerts_fired` | Si alguien se enteró. |
| `sampled` | Cuántos payloads quedaron guardados para poder depurar. |

## Lo que sale, y es idéntico en los siete stacks

```text
  silencioso:  ok=2584  reintentos=0    a la DLQ=416  (13,87%)
               by_error_class = { unclassified: 416 }
               alertas=0   muestras=0

  observado:   ok=2881  reintentos=297  a la DLQ=119  (3,97%)
               by_error_class = { schema_mismatch: 29, unknown_field: 31,
                                  null_required: 31, invalid_encoding: 28 }
               alertas=1   muestras=20
```

## La medición que cierra el caso

Drenar la DLQ del consumidor **silencioso**:

```text
  recuperados = 297 de 416   →   71,39%
  siguen fallando = 119      →   veneno de verdad
```

**El 71,39% de esa DLQ nunca debería haber estado ahí.** Eran errores transitorios que un reintento habría resuelto, y el consumidor los tiró junto con el veneno porque no miró qué error era.

Drenar la DLQ del consumidor **observado** recupera 0%: ahí solo hay veneno, que es precisamente lo que una DLQ debería contener.

<!-- nav-case-doc -->
---

**Caso 20 · La dead letter queue olvidada** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · **🔍 Diagnóstico** · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
