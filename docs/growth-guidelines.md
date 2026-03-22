# 🌱 Guía de crecimiento

> Cómo agregar nuevos casos, stacks o complejidad al laboratorio sin perder coherencia.

---

## 📋 Antes de agregar un nuevo caso

> Responder estas preguntas antes de crear la carpeta:

| Pregunta | Por qué importa |
|----------|----------------|
| ✅ ¿Es un problema frecuente y valioso en producción? | Evita casos artificiales sin impacto real |
| ✅ ¿Tiene sentido multi-stack? | Verifica que haya comparación útil entre ecosistemas |
| ✅ ¿Se puede documentar con claridad? | Un caso que no se puede explicar, no aporta |
| ✅ ¿Aporta a la narrativa del laboratorio? | Debe complementar los casos existentes, no repetirlos |

---

## 🔧 Antes de agregar un nuevo stack a un caso existente

| Pregunta | Por qué importa |
|----------|----------------|
| ✅ ¿Aporta comparación útil y diferente a los stacks ya presentes? | Evita redundancia sin valor |
| ✅ ¿Existe una implementación mínima razonable que pueda levantarse? | Un stack sin Docker funcional no sirve |
| ✅ ¿Se puede mantener en el tiempo? | El mantenimiento a largo plazo importa tanto como la primera versión |

---

## ⚠️ Antes de agregar más complejidad a algo existente

> Recordar siempre:

| Principio | Descripción |
|-----------|-------------|
| 🎯 **La claridad gana** | Un caso bien explicado vale más que uno lleno de features oscuros |
| 📌 **El problema manda** | Cada decisión técnica debe responder al problema del caso, no a la curiosidad del autor |
| 🐳 **Docker aísla, no complica** | Si un `compose.yml` se vuelve imposible de leer, hay algo mal en el diseño |

---

> **Regla de oro:**
> Si al agregar algo el repositorio se vuelve más confuso, no se agrega.
