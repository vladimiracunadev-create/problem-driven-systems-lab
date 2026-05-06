# 📊 Caso 11 - Reportes pesados que bloquean la operación

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-green)](php/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Operaciones-blueviolet)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutiva. La evidencia de ingeniería, los algoritmos y la remediación profunda se encuentran en el link de arriba.

---

## 🔍 Qué problema representa

La analítica y el reporting compiten contra el flujo transaccional sobre los mismos recursos, y consultas pesadas terminan castigando también las escrituras del negocio.

## 💡 Qué deja como evidencia en PHP

- `report-legacy` carga el primario y hace subir locks y latencia operativa.
- `report-isolated` desplaza la presión hacia cola, replica o snapshot.
- `order-write`, `reporting/state` y `diagnostics/summary` dejan visible si la operación conserva aire o ya está sufriendo.

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
