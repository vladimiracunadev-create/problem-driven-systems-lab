# 🧠 Caso 05 - Presión de memoria y fugas de recursos

[![Estado](https://img.shields.io/badge/Estado-PHP%20operativo-green)](php/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Rendimiento-red)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda se encuentran en el link de arriba.

---

## 🔍 Qué problema representa

El proceso acumula memoria y recursos de forma silenciosa hasta degradar el servicio o hacerlo caer mucho después de que empezó el problema.

## 💡 Qué deja como evidencia en PHP

- `batch-legacy` retiene buffers y deja crecer la presión entre requests.
- `batch-optimized` limita el cache, limpia estado y reduce la degradación progresiva.
- `state`, `runs` y `diagnostics/summary` muestran presión retenida, descriptores simulados y umbrales de riesgo.

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8 | `OPERATIVO` |
| 🟢 Node.js | 🔧 Estructura lista |
| 🐍 Python | 🔧 Estructura lista |
| ☕ Java | 🔧 Estructura lista |
| 🔵 .NET 8 | 🔧 Estructura lista |

## 🚀 Cómo levantar

```bash
docker compose -f php/compose.yml up -d --build
```
