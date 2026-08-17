# 🔍 Diagnóstico

1. **Contar recálculos, no requests.** La métrica que importa no es cuántas peticiones entran sino cuántas veces se ejecuta el trabajo caro. En este lab es `origin_computations`.
2. **Mirar la distribución de expiraciones.** Si mil claves tienen el mismo TTL fijo puesto por el mismo deploy, vencen juntas. Un histograma de `expires_at` debería verse plano, no como un pico.
3. **Buscar la ventana check-then-act.** Entre «miro si está en cache» y «escribo el resultado» hay una ventana. Todo llamador que entre ahí va a recalcular. El tamaño de esa ventana es la profundidad de la estampida.
4. **Distinguir soft de hard TTL.** Si el único estado posible es «vale» o «no vale», cada expiración obliga a alguien a esperar el origen. Un estado intermedio «viejo pero servible» elimina esa espera.
5. **Verificar que el lock tenga double check.** Un lock sin relectura adentro no evita la estampida: la ordena en fila. El origen recibe las mismas N consultas, una detrás de otra.

<!-- nav-case-doc -->
---

**Caso 13 · Cache stampede y thundering herd** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · **🔍 Diagnóstico** · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
