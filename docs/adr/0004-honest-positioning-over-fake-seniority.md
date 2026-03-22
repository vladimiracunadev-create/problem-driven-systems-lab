# 📋 ADR 0004 — Posicionamiento honesto sobre el seniority por lenguaje

| Campo | Valor |
|-------|-------|
| **Estado** | ✅ Aceptada |
| **Fecha** | 2025 |

---

## 🔍 Contexto

El mercado técnico suele medir seniority por años en un lenguaje específico. Al construir un repositorio multi-stack con 5 lenguajes, existe el riesgo de que el repositorio sea interpretado como una afirmación de experiencia profunda en todos los ecosistemas.

Esa interpretación sería:
- deshonesta con el lector,
- contraproducente para la credibilidad,
- un mensaje que el laboratorio no quiere transmitir.

---

## ✅ Decisión

La documentación debe:

1. **Explicitar** que el valor del laboratorio es demostrar **criterio técnico transferible**, no inventar trayectoria exacta por stack.
2. **Distinguir** entre profundidad funcional (el caso 01 en PHP tiene implementación real) y presencia estructural (los demás stacks están listos para crecer).
3. **Ser honesta** sobre lo que está implementado profundamente y lo que es base estructural.

---

## ⚖️ Consecuencias

| Consecuencia | Detalle |
|-------------|---------|
| ✅ Mensaje más sólido | El lector entiende qué se ofrece sin exageraciones |
| ✅ Mayor credibilidad | La honestidad sobre el alcance genera más confianza que la sobreventa |
| ✅ Menor riesgo de fricción en entrevistas | Las expectativas quedan correctamente calibradas |
| ⚠️ Puede parecer "incompleto" a primera vista | Se compensa con documentación clara del estado actual y el ROADMAP |
