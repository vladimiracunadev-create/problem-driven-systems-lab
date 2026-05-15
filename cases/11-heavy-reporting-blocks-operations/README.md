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

## 🗺️ Diagrama — Reporting bloqueando el pool principal vs aislamiento por pool

```text
  Legacy: report y order-write comparten pool                Isolated: pools separados

  ┌──────────────────────────────────┐                       ┌──────────────────────────────────┐
  │       mainPool (4 threads)       │                       │       mainPool (4 threads)       │
  │  ┌────┐ ┌────┐ ┌────┐ ┌────┐     │                       │  ┌────┐ ┌────┐ ┌────┐ ┌────┐     │
  │  │ T1 │ │ T2 │ │ T3 │ │ T4 │     │                       │  │ T1 │ │ T2 │ │ T3 │ │ T4 │     │
  │  │REP │ │REP │ │REP │ │REP │     │                       │  │ ok │ │ ok │ │ ok │ │ ok │     │
  │  │5 s │ │5 s │ │5 s │ │5 s │     │  ←─ 4 reports         │  │free│ │free│ │free│ │free│     │
  │  └────┘ └────┘ └────┘ └────┘     │      ocupan TODO      │  └────┘ └────┘ └────┘ └────┘     │
  │                                  │                       │                                  │
  │  queue: [ ORDER, ORDER, ... ]    │  ←─ /order-write      │  queue: [ ]                      │
  │         ⏳ espera turno          │      espera           │                                  │
  └──────────────────────────────────┘                       └──────────────────────────────────┘
                                                                       │
                                                                       │ supplyAsync(task, reportingPool)
                                                                       ▼
                                                            ┌──────────────────────────────────┐
                                                            │     reportingPool (2 threads)    │
                                                            │  ┌────┐ ┌────┐                   │
                                                            │  │REP │ │REP │                   │
                                                            │  │5 s │ │5 s │   ← reports vivan │
                                                            │  └────┘ └────┘     aqui          │
                                                            └──────────────────────────────────┘

           /order-write degraded=true                                 /order-write degraded=false
           getActiveCount = 4 (full)                                  getActiveCount = 1 (libre)
           getQueue = N (creciendo)                                   getQueue = 0
```

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8 | `OPERATIVO` |
| 🟢 Node.js | `OPERATIVO` |
| 🐍 Python | `OPERATIVO` |
| ☕ Java 21 | `OPERATIVO` (`ThreadPoolExecutor` saturation observable + `ExecutorService` dedicado) |
| 🔵 .NET 8 | 🔧 Estructura lista |

## 🚀 Cómo levantar

```bash
docker compose -f php/compose.yml up -d --build
```
