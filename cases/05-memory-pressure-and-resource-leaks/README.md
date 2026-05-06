# 🧠 Caso 05 - Presión de memoria y fugas de recursos

[![Estado](https://img.shields.io/badge/Estado-PHP%20%2B%20Python%20%2B%20Node%20operativos-green)](php/README.md)
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
| 🐘 PHP 8 | `OPERATIVO` (memory_get_usage + retencion explicita en modulo) |
| 🐍 Python 3.12 | `OPERATIVO` (`tracemalloc` + `gc.collect()` + cache acotado) |
| 🟢 Node.js 20 | `OPERATIVO` (`process.memoryUsage()` con heap V8 + RSS + external) |
| ☕ Java | 🔧 Estructura lista |
| 🔵 .NET 8 | 🔧 Estructura lista |

## 🚀 Cómo levantar

```bash
docker compose -f php/compose.yml up -d --build      # PHP, puerto 815
docker compose -f python/compose.yml up -d --build   # Python, puerto 835
docker compose -f node/compose.yml up -d --build     # Node.js, puerto 825
```
