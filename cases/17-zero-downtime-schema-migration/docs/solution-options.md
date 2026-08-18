# 🛠️ Opciones de solución

| Opción | Qué resuelve | Qué cuesta |
|---|---|---|
| **Expand-contract en 4 fases** | La app nunca espera más que un lote | Cuatro despliegues en vez de uno, y convivir un tiempo con dos columnas |
| **Backfill por lotes con pausa** | El motor recupera aire entre lote y lote | La migración tarda más en total — y está bien que así sea |
| **Feature flag para el switch** | Volver atrás es un toggle, no otra migración | Código que lee de las dos columnas mientras dure la transición |
| **DDL online del motor** (`CONCURRENTLY`, `pt-online-schema-change`) | Evita el lock largo sin cambiar la aplicación | Depende del motor y de la operación; no todas lo soportan |
| **Ventana de mantenimiento** | Simple y honesto con el usuario | Downtime programado, que para muchos negocios no es una opción |

Las tres primeras son el patrón que este caso ejecuta. La cuarta lo complementa; la quinta es lo que se hace cuando ninguna de las otras está disponible.

<!-- nav-case-doc -->
---

**Caso 17 · Migración de esquema sin downtime** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · **🛠️ Opciones de solución** · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
