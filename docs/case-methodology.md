# 🔬 Metodología de casos

> Cómo se documenta y estructura cada caso del laboratorio.

---

## 📋 Las 7 preguntas que responde cada caso

Cada caso del laboratorio debe poder responder estas siete preguntas con claridad:

| # | Pregunta | Por qué importa |
|---|----------|----------------|
| 1️⃣ | **¿Cuál es el problema?** | Define el punto de partida sin ambigüedad |
| 2️⃣ | **¿Por qué importa?** | Justifica el valor de resolverlo |
| 3️⃣ | **¿Cómo se manifiesta?** | Describe los síntomas observables |
| 4️⃣ | **¿Cómo se diagnostica?** | Explica cómo identificar la causa raíz |
| 5️⃣ | **¿Qué soluciones son razonables?** | Presenta opciones reales, no solo "la correcta" |
| 6️⃣ | **¿Qué trade-offs existen?** | Distingue costos, riesgos y beneficios de cada opción |
| 7️⃣ | **¿Qué cambia según el stack?** | Muestra cómo el tooling afecta la resolución |

---

## 📁 Plantilla mínima de documentación por caso

Cada carpeta `cases/<caso>/docs/` debe incluir estos archivos:

| Archivo | Contenido esperado |
|---------|-------------------|
| `context.md` | Contexto del caso: sistema ficticio, escenario y condiciones de entrada |
| `symptoms.md` | Síntomas observables: qué ve el usuario, qué ve el equipo técnico |
| `diagnosis.md` | Cómo diagnosticar el problema: herramientas, métricas y señales |
| `root-causes.md` | Causas raíz documentadas con evidencia técnica |
| `solution-options.md` | Opciones de solución comparadas: no solo la ideal |
| `trade-offs.md` | Trade-offs explícitos: qué ganas y qué sacrificas con cada opción |
| `business-value.md` | Valor de negocio: qué cambia al resolver este problema |

---

## ✅ Reglas de calidad de cada caso

> Un caso bien documentado cumple todas estas condiciones:

- 🚫 **No habla solo de sintaxis** — habla de sistemas, decisiones y consecuencias
- ✅ **Justifica el valor del caso** — explica por qué vale la pena estudiarlo
- 🔍 **Distingue entre problema, síntoma y causa** — no los mezcla
- 🤝 **No oculta limitaciones** — menciona qué queda fuera del alcance del laboratorio
- 📝 **Documenta decisiones** — especialmente las que parecen obvias y no lo son
