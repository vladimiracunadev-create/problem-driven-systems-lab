# 🏗️ Caso 07 - Modernización incremental de monolito

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-green)](php/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Arquitectura-blueviolet)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda se encuentran en el link de arriba.

---

## 🔍 Qué problema representa

El sistema legacy sigue siendo crítico, pero cambiar una parte rompe otras no relacionadas y modernizar sin estrategia vuelve cada release más riesgoso.

## 💡 Qué deja como evidencia en PHP

- `change-legacy` muestra blast radius alto y riesgo por acoplamiento.
- `change-strangler` mueve consumidores gradualmente y sube cobertura/contratos.
- `migration/state`, `flows` y `diagnostics/summary` dejan visible el progreso real de la modernización.

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8 | `OPERATIVO` |
| 🟢 Node.js | `OPERATIVO` |
| 🐍 Python | `OPERATIVO` |
| ☕ Java | 🔧 Estructura lista |
| 🔵 .NET 8 | 🔧 Estructura lista |

## 🚀 Cómo levantar

```bash
docker compose -f php/compose.yml up -d --build
```
