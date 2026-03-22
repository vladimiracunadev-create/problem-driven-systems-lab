# 📋 ADR 0001 — Estructura orientada a problemas

| Campo | Valor |
|-------|-------|
| **Estado** | ✅ Aceptada |
| **Fecha** | 2025 |

---

## 🔍 Contexto

Organizar un repositorio técnico por lenguaje (ej. `/php`, `/node`, `/python`) es el enfoque habitual. Sin embargo, esta estructura invisibiliza lo más importante: **el problema real que se quiere estudiar y resolver**.

Un repositorio organizado por lenguajes tiende a convertirse en una colección de sintaxis sin narrativa técnica coherente.

---

## ✅ Decisión

Organizar el repositorio **por casos/problemas** como unidad principal.

Los lenguajes viven **dentro** de cada caso como implementaciones o variantes de análisis del mismo problema.

```
cases/
└── 01-api-latency-under-load/
    ├── php/        ← implementación PHP del problema
    ├── node/       ← implementación Node.js del problema
    └── python/     ← implementación Python del problema
```

---

## ⚖️ Consecuencias

| Consecuencia | Detalle |
|-------------|---------|
| ✅ Mejor narrativa | El lector ve el problema primero; el lenguaje es un detalle técnico |
| ✅ Mayor claridad | Cada caso tiene contexto, síntomas, diagnóstico y valor propio |
| ✅ Comparación multi-stack más útil | Se puede comparar cómo el mismo problema se resuelve distinto por ecosistema |
| ⚠️ Mayor esfuerzo inicial | Cada caso requiere documentación propia antes de una línea de código |
