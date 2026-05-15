# ⏱️ Caso 04 - Cadena de timeouts y tormentas de reintentos

[![Estado](https://img.shields.io/badge/Estado-PHP%20%2B%20Python%20%2B%20Node%20operativos-green)](php/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Resiliencia-orange)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda se encuentran en el link de arriba.

---

## 🔍 Qué problema representa

Una integración lenta o inestable dispara retries agresivos, bloquea threads o workers y termina propagando fallas hacia otros servicios.

## 💡 Qué deja como evidencia en PHP

- `quote-legacy` amplifica el incidente con más intentos y más espera.
- `quote-resilient` usa timeout corto, backoff, circuit breaker y fallback.
- `dependency/state`, `incidents` y `diagnostics/summary` dejan visible el costo operacional de cada postura.

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8 | `OPERATIVO` (timeout corto + backoff + circuit breaker + fallback) |
| 🐍 Python 3.12 | `OPERATIVO` (misma logica con stdlib + telemetria local) |
| 🟢 Node.js 20 | `OPERATIVO` (`AbortController`/`AbortSignal` como timeout cooperativo + CB + fallback) |
| ☕ Java 21 | `OPERATIVO` (`CompletableFuture.orTimeout` + circuit breaker con `AtomicReference`) |
| 🔵 .NET 8 | 🔧 Estructura lista |

## 🚀 Cómo levantar

```bash
docker compose -f php/compose.yml up -d --build      # PHP, puerto 814
docker compose -f python/compose.yml up -d --build   # Python, puerto 834
docker compose -f node/compose.yml up -d --build     # Node.js, puerto 824
```
