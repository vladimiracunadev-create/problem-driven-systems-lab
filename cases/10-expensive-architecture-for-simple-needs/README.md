# 💸 Caso 10 - Arquitectura cara para un problema simple

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-green)](php/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Arquitectura-blueviolet)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutiva. La evidencia de ingeniería, los algoritmos y la remediación profunda se encuentran en el link de arriba.

---

## 🔍 Qué problema representa

El negocio necesita resolver algo relativamente acotado, pero la solución técnica suma más servicios, hops, costo y coordinación de la que el problema realmente requiere.

## 💡 Qué deja como evidencia en PHP

- `feature-complex` muestra costo, lead time y superficie operativa inflados.
- `feature-right-sized` mantiene el foco en proporcionalidad y delivery más corto.
- `architecture/state`, `decisions` y `diagnostics/summary` hacen visible la deuda de simplificación.

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8 | `OPERATIVO` |
| 🟢 Node.js | `OPERATIVO` |
| 🐍 Python | `OPERATIVO` |
| ☕ Java 21 | `OPERATIVO` (`HashMap` O(1) vs N hops `StringBuilder`; CPU real medido) |
| 🔵 .NET 8 | 🔧 Estructura lista |

## 🚀 Cómo levantar

```bash
docker compose -f php/compose.yml up -d --build
```
