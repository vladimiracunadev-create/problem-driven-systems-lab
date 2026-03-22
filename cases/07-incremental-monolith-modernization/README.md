# 🏗️ Caso 07 — Modernización incremental de monolito

[![Estado](https://img.shields.io/badge/Estado-Base%20documental%20lista-blue)](docs/)
[![Categoría](https://img.shields.io/badge/Categoría-Arquitectura-blueviolet)](../../README.md)

---

## 🔍 Qué problema representa

El sistema legacy sigue siendo **crítico para el negocio**, pero su evolución se vuelve cada vez más lenta, riesgosa y costosa. Cambiar una parte rompe otras completamente no relacionadas. El código acumula deuda que nadie quiere tocar.

> La reescritura total es tentadora, pero en la práctica casi siempre falla. La modernización incremental es más lenta pero más segura.

---

## ⚠️ Síntomas típicos

| Síntoma | Dónde se observa |
|---------|-----------------|
| Miedo a desplegar por scope impredecible de cambios | Cultura del equipo / velocidad de entrega |
| Imposibilidad de trabajar en paralelo sin conflictos | Git conflicts frecuentes / coupling alto |
| Deuda técnica que bloquea nuevas features | Estimaciones que siempre superan el planning |
| Nadie entiende completamente el sistema | Riesgo ante ausencias / bus factor |

---

## 🧩 Causas frecuentes

- **Acoplamiento alto** — los módulos se conocen entre sí de forma circular
- **Testing insuficiente** — no hay red de seguridad para refactorizar
- **Sin separación de responsabilidades** — todo en un mismo lugar sin fronteras claras
- **Decisiones de diseño documentadas solo en la cabeza de alguien** — conocimiento implícito

---

## 🔬 Estrategia de diagnóstico

1. Mapear el mapa de módulos y sus dependencias reales
2. Identificar los límites naturales del dominio (bounded contexts)
3. Medir cobertura de testing como indicador de seguridad para refactorizar
4. Priorizar qué parte modernizar primero según valor + riesgo

---

## 💡 Opciones de solución

| Patrón | Descripción |
|--------|------------|
| **Strangler Fig** | Reemplazar gradualmente partes del monolito con nuevas implementaciones |
| **Anti-corruption layer** | Traducción entre el modelo nuevo y el legado durante la transición |
| **Modularización interna** | Separar en módulos claros sin necesidad de microservicios |
| **Expand-contract** | Agregar la nueva API, migrar clientes, retirar la vieja |

---

## ⚖️ Trade-offs

| Decisión | Ventaja | Costo |
|----------|---------|-------|
| Strangler Fig | Modernización sin downtime | Doble mantenimiento durante transición |
| Reescritura total | Deuda cero en teoría | Alto riesgo, alta duración, alta incertidumbre |
| Modularización interna | Menos riesgo, sin infra nueva | Sigue siendo un monolito, no resuelve todo |

---

## 💼 Valor de negocio

> Permite renovar plataformas reales sin detener la operación
> ni asumir una reescritura total de alto riesgo.

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
make case-up CASE=07-incremental-monolith-modernization STACK=php
make compare-up CASE=07-incremental-monolith-modernization
```

---

## 📁 Estructura

```text
07-incremental-monolith-modernization/
├── 📄 README.md
├── 🐳 compose.compare.yml
├── 📚 docs/
├── 🔗 shared/
├── 🐘 php/  🟢 node/  🐍 python/  ☕ java/  🔵 dotnet/
```
