# 💼 Valor de negocio

## Qué se elimina

La categoría entera de «el producto existe pero no aparece». En el laboratorio, con un 8% de fallo de escritura al índice, el dual-write deja **79 documentos derivados sobre 951**: recall 98,95%, precisión 98,02%. Con outbox más barrido: **cero**, con recall y precisión en 100%.

Esos dos puntos porcentuales son, en un catálogo, productos que no se pueden comprar.

## Por qué se subestima

Porque **no se ve como un incidente**. La búsqueda responde, responde rápido y devuelve resultados verosímiles. No hay alerta que dispare, no hay guardia que se despierte, no hay postmortem.

El costo aparece en otro lado y con otro nombre: conversión que baja sin explicación, tickets de soporte sobre «un producto que no encuentro», un vendedor que jura que cargó el artículo. Ninguno de esos se rastrea hasta un índice desincronizado, así que el problema puede durar años.

## El indicador honesto

No es el error rate del índice — ese ya es cero, porque el error se tragó.

Es **`drift_age_ms`**: hace cuánto que el cambio más viejo no llega al índice. Un índice con setenta documentos derivados de hace treinta segundos está funcionando. Los mismos setenta de hace tres semanas significan que **nada los va a reparar solo**, y que el número de mañana va a ser más alto.

## Qué habilita

Confiar en la búsqueda como canal de negocio. Cuando el índice tiene una deriva medida y acotada, se puede construir encima: recomendaciones, filtros, alertas de stock, catálogos para terceros. Sobre un índice cuya exactitud nadie conoce, cada una de esas cosas hereda un error que no se puede cuantificar.

<!-- nav-case-doc -->
---

**Caso 19 · Deriva del índice de búsqueda y CDC roto** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · **💼 Valor de negocio** · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
