# 👤 Caso 12 - Punto único de conocimiento y riesgo operacional

[![Estado](https://img.shields.io/badge/Estado-PHP%20operativo-green)](php/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Operaciones-blueviolet)](../../README.md)

## 🔍 Qué problema representa

Una parte crítica de la operación depende demasiado de una sola persona, de scripts fuera de runbooks o de memoria tribal. Cuando esa ayuda no está, el sistema revela su fragilidad real.

## 💡 Qué deja como evidencia en PHP

- `incident-legacy` muestra el costo de depender de conocimiento concentrado.
- `incident-distributed` aprovecha runbooks, backups y práctica operativa.
- `share-knowledge`, `knowledge/state` y `diagnostics/summary` dejan visible cobertura, handoff y bus factor.

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
