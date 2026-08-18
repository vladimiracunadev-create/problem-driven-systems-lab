# 🔍 Diagnóstico

## La pregunta que ordena todo

**¿Cuántos documentos están en la base y no en el índice, y hace cuánto?**

Si esa pregunta no se puede responder en un minuto, la deriva ya existe: nadie la está mirando.

## Cómo medirlo

```bash
# dual-write con 8% de fallo de índice: la deriva aparece sola
curl "http://localhost:8400/19/search-drifted?writes=2000&fail_rate=8"

# outbox + checkpoint + barrido, con el MISMO 8% de fallo
curl "http://localhost:8400/19/search-reconciled?writes=2000&fail_rate=8"

# las tres caras y la antigüedad del cambio más viejo sin aplicar
curl http://localhost:8400/19/index/state

# un barrido suelto, para ver qué encuentra y qué repara
curl http://localhost:8400/19/reconcile
```

## Qué mirar en la respuesta

| Campo | Qué dice |
|---|---|
| `missing` / `stale` / `orphan` | Las tres caras, separadas. Se arreglan distinto. |
| `drift_count` | La suma. Es el número que va al dashboard. |
| `drift_age_ms` | **Hace cuánto** que el cambio más viejo no llega al índice. |
| `silent_failures` | Escrituras al índice que fallaron y que nadie miró. |
| `search_recall_pct` | Cuánto de lo que existe encuentra la búsqueda. |
| `search_precision_pct` | Cuánto de lo que devuelve todavía existe. |
| `last_checkpoint` / `outbox_pending` | Hasta dónde llegó el consumidor y cuánto le falta. |

## Lo que sale, y por qué asusta

Con 2.000 escrituras y un 8% de fallo del índice —una tasa perfectamente normal para un cliente HTTP contra un servicio remoto— el resultado es **idéntico en los siete stacks**:

```text
  dual-write:     missing=10  stale=50  orphan=19  drift=79
                  recall 98,95%   precision 98,02%

  outbox+barrido: missing=0   stale=0   orphan=0   drift=0
                  recall 100%     precision 100%
```

**98,95% de recall no se ve como un incidente.** Se ve como una búsqueda que anda. Ese es exactamente el punto: el modo de falla de este caso es ser lo bastante bueno como para que nadie mire.

## La métrica que casi nunca existe

`drift_age_ms` es la que decide la gravedad. Un índice con 79 documentos derivados de hace treinta segundos es un hipo. Los mismos 79 de hace tres semanas son un problema de negocio, porque significa que **nada los va a reparar solo**.

> En el laboratorio esta métrica sale en milisegundos, porque el escenario entero corre en decenas de milisegundos. En producción se mide en minutos y horas — la interpretación es la misma, la escala no.

<!-- nav-case-doc -->
---

**Caso 19 · Deriva del índice de búsqueda y CDC roto** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · **🔍 Diagnóstico** · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
