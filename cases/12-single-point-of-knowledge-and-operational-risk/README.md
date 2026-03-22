# 🧑‍💼 Caso 12 — Punto único de conocimiento y riesgo operacional

[![Estado](https://img.shields.io/badge/Estado-Base%20documental%20lista-blue)](docs/)
[![Categoría](https://img.shields.io/badge/Categoría-Operaciones-teal)](../../README.md)

---

## 🔍 Qué problema representa

Una persona, módulo o procedimiento concentra tanto conocimiento que **el sistema se vuelve frágil ante ausencias, rotación o vacaciones**. Solo una persona sabe cómo desplegar. Solo una persona entiende ese módulo. Solo una persona sabe qué hace ese script.

> Un sistema técnicamente correcto puede convertirse en un riesgo organizacional si el conocimiento no está distribuido.

---

## ⚠️ Síntomas típicos

| Síntoma | Dónde se observa |
|---------|-----------------|
| Incidentes que solo puede resolver una persona | Escalación en mitad de la noche |
| Documentación de operación inexistente o muy desactualizada | Onboarding de nuevos miembros del equipo |
| Flujos críticos que nadie fuera de una persona comprende completamente | Conversaciones de equipo / postmortems |
| Miedo a que esa persona se vaya o se enferme | Cultura del equipo / gestión de riesgos |

---

## 🧩 Causas frecuentes

- **Cultura de "preguntar a X"** en vez de documentar el proceso
- **Documentación creada para cumplir, no para usar** — nunca actualizada
- **Sin runbooks operacionales** — los procedimientos viven en la cabeza, no en texto
- **Sin rotación de guardia** — siempre la misma persona resuelve los incidentes
- **Módulo creado por una persona sin code review** — conocimiento implícito no transferido

---

## 🔬 Estrategia de diagnóstico

1. Identificar quién es el único que puede resolver los incidentes más frecuentes
2. Mapear qué procesos operacionales no están documentados
3. Preguntarse: "¿si X no está disponible mañana, qué no podemos hacer?"
4. Revisar si existen runbooks actualizados para las operaciones críticas

---

## 💡 Opciones de solución

| Opción | Descripción |
|--------|------------|
| **Runbooks operacionales** | Documentar paso a paso cómo ejecutar cada operación crítica |
| **Rotación de guardia** | Distribuir el conocimiento de incidentes entre múltiples personas |
| **Code review obligatorio** | Ningún módulo crítico sin revisión de al menos otro par |
| **Documentación como parte del Definition of Done** | No se cierra una tarea sin su documentación operacional |
| **Pair programming en módulos críticos** | Transferencia de conocimiento durante el desarrollo |

---

## ⚖️ Trade-offs

| Decisión | Ventaja | Costo |
|----------|---------|-------|
| Runbooks detallados | Operación más resiliente | Tiempo inicial de escritura y mantenimiento |
| Rotación de guardia | Conocimiento distribuido | Más personas con curva de aprendizaje |
| Code review estricto | Conocimiento compartido + mejor calidad | Más tiempo en el ciclo de desarrollo |

---

## 💼 Valor de negocio

> Reduce el riesgo organizacional, mejora la continuidad del servicio
> y hace al producto y al equipo más sostenibles a largo plazo.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
|-------|--------|
| 🐘 PHP 8 | 🔧 Estructura lista |
| 🟢 Node.js | 🔧 Estructura lista |
| 🐍 Python | 🔧 Estructura lista |
| ☕ Java | 🔧 Estructura lista |
| 🔵 .NET 8 | 🔧 Estructura lista |

---

## 🚀 Cómo levantar

```bash
make case-up CASE=12-single-point-of-knowledge-and-operational-risk STACK=php
make compare-up CASE=12-single-point-of-knowledge-and-operational-risk
```

---

## 📁 Estructura

```text
12-single-point-of-knowledge-and-operational-risk/
├── 📄 README.md
├── 🐳 compose.compare.yml
├── 📚 docs/
├── 🔗 shared/
├── 🐘 php/  🟢 node/  🐍 python/  ☕ java/  🔵 dotnet/
```
