# 🛠️ Opciones de solución

| Opción | Qué resuelve | Qué cuesta |
|---|---|---|
| **Single-flight** (un recálculo, el resto espera) | Elimina la estampida de raíz: `origin_computations` baja a 1 | Los que esperan pagan la latencia del origen la primera vez |
| **TTL con jitter** (`base ± rand(0, base/4)`) | Desincroniza expiraciones masivas | Ninguno real; son dos líneas de código |
| **Soft TTL + refresh asincrónico** | Nadie espera al origen: se sirve el valor viejo mientras uno refresca | Se sirve información desactualizada durante la ventana soft |
| **Precalentado programado** | La clave nunca llega a expirar bajo tráfico | Hay que saber de antemano cuáles son las claves calientes |
| **Lock distribuido** (Redis `SET NX`, `flock`) | Coordina entre procesos y entre máquinas, no solo entre hilos | Introduce una dependencia externa en el camino de lectura |

Las tres primeras se combinan, y es lo que hace la variante `singleflight` de este caso. No son alternativas entre sí.

<!-- nav-case-doc -->
---

**Caso 13 · Cache stampede y thundering herd** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · **🛠️ Opciones de solución** · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
