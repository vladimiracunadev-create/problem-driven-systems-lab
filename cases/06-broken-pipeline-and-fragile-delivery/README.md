# 🚚 Caso 06 - Pipeline roto y entrega frágil

[![Estado](https://img.shields.io/badge/Estado-PHP%20operativo-green)](php/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Entrega-green)](../../README.md)

## 🔍 Qué problema representa

El software funciona en desarrollo, pero falla al promover cambios, validar configuración o revertir incidentes con seguridad en ambientes reales.

## 💡 Qué deja como evidencia en PHP

- `deploy-legacy` detecta varios riesgos demasiado tarde y puede dejar ambientes degradados.
- `deploy-controlled` bloquea fallas en preflight, usa canary y hace rollback cuando corresponde.
- `environments`, `deployments` y `diagnostics/summary` muestran el efecto real por ambiente.

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
