# ⏱️ Caso 04 - Cadena de timeouts y tormentas de reintentos

[![Estado](https://img.shields.io/badge/Estado-PHP%20operativo-green)](php/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Resiliencia-orange)](../../README.md)

## 🔍 Qué problema representa

Una integración lenta o inestable dispara retries agresivos, bloquea threads o workers y termina propagando fallas hacia otros servicios.

## 💡 Qué deja como evidencia en PHP

- `quote-legacy` amplifica el incidente con más intentos y más espera.
- `quote-resilient` usa timeout corto, backoff, circuit breaker y fallback.
- `dependency/state`, `incidents` y `diagnostics/summary` dejan visible el costo operacional de cada postura.

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
