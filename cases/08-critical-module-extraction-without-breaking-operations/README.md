# 🧩 Caso 08 - Extracción de módulo crítico sin romper operación

[![Estado](https://img.shields.io/badge/Estado-PHP%20operativo-green)](php/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Arquitectura-blueviolet)](../../README.md)

## 🔍 Qué problema representa

Hay que sacar un módulo sensible del sistema vivo, pero ese módulo sigue sirviendo a checkout, partners y backoffice. Cortarlo de una vez expone contratos, flujos y estados compartidos.

## 💡 Qué deja como evidencia en PHP

- `pricing-bigbang` muestra el costo de extraer sin proxy ni compatibilidad.
- `pricing-compatible` usa contrato estable, proxy y cutover gradual por consumidor.
- `extraction/state`, `flows` y `diagnostics/summary` dejan visible progreso, shadow traffic y presión de compatibilidad.

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
