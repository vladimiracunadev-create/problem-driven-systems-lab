# 📊 Caso 11 — Reportes pesados que bloquean la operación

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-success)](php/README.md)
[![Stacks](https://img.shields.io/badge/Stacks-PHP%20%C2%B7%20Python%20%C2%B7%20Node%20%C2%B7%20Java-blue)](#-stacks-disponibles)
[![Categoría](https://img.shields.io/badge/Categoría-Operaciones-darkgreen)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda viven en el link de arriba y en `comparison.md`.

---

## 🔍 Qué problema representa

Consultas y procesos de **reporting compiten con la operación transaccional** y degradan el sistema completo. Aparece tarde en la madurez de un producto: el negocio pide más información, alguien arma un reporte que junta tablas grandes, y termina corriendo sobre el mismo motor que sirve el checkout.

**El síntoma es predecible:** todos los lunes a las 9 AM el sistema se pone lento, "y nadie sabe por qué". La causa raíz suele ser un job de reporting que se levantó a esa hora. La solución no es prohibir reportes — es **aislar las cargas** para que reporting y operación dejen de competir.

---

## ⚠️ Síntomas típicos

- Horarios específicos con **degradación por reportes** (lunes 9 AM, fin de mes, cierre)
- Consultas largas y **bloqueos** sobre tablas que también sirven al tráfico operativo
- Usuarios operativos **afectados por tareas analíticas** sin contexto
- Procesos manuales para **reintentar o dividir** reportes que reventaron

---

## 🧩 Causas frecuentes

- **Mismo motor y mismas tablas** para operación y análisis
- Falta de colas o tareas batch bien diseñadas
- Consultas poco optimizadas o sin índices adecuados
- **Exportaciones síncronas** acopladas al request web

---

## 🔬 Estrategia de diagnóstico

- Separar **métricas de carga operacional y analítica**
- Identificar jobs, horarios y consultas más costosas (top-N por tiempo y locks)
- Medir **locking y tiempo de espera** real (no solo CPU)
- Revisar diseño de **generación de archivos/reportes** — si el HTTP request espera la generación, ya hay problema

---

## 💡 Opciones de solución

- **Desacoplar reporting** mediante colas o procesos batch
- **Réplicas o stores de lectura** cuando convenga (read replica, OLAP, DW)
- Particionar o **precalcular** resultados recurrentes (materialized views)
- Mover **exportaciones pesadas fuera del request web** (background jobs)

---

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

---

## 🏗️ Implementación actual

### ✅ PHP 8

`report-legacy` corre sobre el primario; `report-isolated` lo desplaza a cola/replica/snapshot. `/order-write` mide si la operación conserva aire. `reporting/state` y `diagnostics/summary` exponen `primary_load`, `lock_pressure`, `replica_lag_s`, `queue_depth`. Ver [`php/README.md`](php/README.md). Modo aislado: puerto `8111`.

### Python 3.12

Mismo contraste con threading. Ver [`python/README.md`](python/README.md). Modo aislado: puerto `8311`. Hub: `http://localhost:8200/11/`.

### Node.js 20

`monitorEventLoopDelay()` (perf_hooks) mide lag real del event loop. `report-legacy` ejecuta CPU sincrónico que castiga el loop entero (visible en `event_loop_lag_ms_p99`); `report-isolated` cede control con `setImmediate` para que el loop respire. Ver [`node/README.md`](node/README.md). Modo aislado: puerto `8211`. Hub: `http://localhost:8300/11/`.

### Java 21

`ThreadPoolExecutor` acotado a 4 threads como pool principal (saturación realista); `ExecutorService` dedicado de 2 threads para reporting. `CompletableFuture.supplyAsync(task, executor)` para submission explícita al pool correcto. `getActiveCount()` y `getQueue().size()` son la señal nativa de saturación, equivalente al `monitorEventLoopDelay` de Node. Ver [`java/README.md`](java/README.md). Modo aislado: puerto `8411`. Hub: `http://localhost:8400/11/`.

### .NET (espacio de crecimiento)

Estructura dockerizada lista; sin paridad funcional todavía.

---

## ⚖️ Trade-offs

- Más **separación implica más pipeline de datos** (extracts, sync, schemas duplicados)
- **Precalcular consume almacenamiento** y requiere invalidación cuidadosa
- **No todo reporte justifica un mini data warehouse** — el costo de ops es real

---

## 💼 Valor de negocio

**Protege la operación diaria** y permite crecer en analítica sin romper el sistema transaccional. El indicador honesto: latencia de `/order-write` mientras corre el reporte mensual. Si sube 10x, hay problema; si se mantiene, el aislamiento funciona. Para el negocio: SLO operacional independiente del calendario de reporting.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8 | `OPERATIVO` (locks vs cola/replica) |
| 🐍 Python 3.12 | `OPERATIVO` (threading + métricas locales) |
| 🟢 Node.js 20 | `OPERATIVO` (`monitorEventLoopDelay()` + `setImmediate` para ceder) |
| ☕ Java 21 | `OPERATIVO` (`ThreadPoolExecutor` saturation observable + `ExecutorService` dedicado) |
| 🔵 .NET 8 | 🔧 Estructura lista |

---

## 🚀 Cómo levantar

**Modo hub (recomendado):**
```bash
docker compose -f compose.root.yml    up -d --build && curl http://localhost:8100/11/health   # PHP
docker compose -f compose.python.yml  up -d --build && curl http://localhost:8200/11/health   # Python
docker compose -f compose.nodejs.yml  up -d --build && curl http://localhost:8300/11/health   # Node
docker compose -f compose.java.yml    up -d --build && curl http://localhost:8400/11/health   # Java
```

**Modo aislado (recomendado para este caso — medir saturación sin contaminación):**
```bash
docker compose -f cases/11-heavy-reporting-blocks-operations/java/compose.yml up -d --build  # :8411
```

**Reproducir saturación y aislamiento (ejemplo Java):**
```bash
# Saturar con reports legacy: 5 paralelos en pool de 4 threads
for i in 1 2 3 4 5; do curl -s "http://localhost:8400/11/report-legacy?rows=1000000" > /dev/null & done
sleep 1
curl http://localhost:8400/11/order-write    # degraded:true
curl http://localhost:8400/11/activity       # main_pool_active=4, queue creciendo

# Reset + misma carga pero isolated: corre en reportingPool dedicado
curl http://localhost:8400/11/reset-lab
for i in 1 2 3 4 5; do curl -s "http://localhost:8400/11/report-isolated?rows=1000000" > /dev/null & done
sleep 1
curl http://localhost:8400/11/order-write    # degraded:false
curl http://localhost:8400/11/activity       # main_pool_active bajo
```

---

## 📚 Lectura recomendada

| Documento | Qué cubre |
| --- | --- |
| [`comparison.md`](comparison.md) | Comparativa multi-stack con señal de saturación por runtime |
| [`docs/postmortem.md`](docs/postmortem.md) | Postmortem del incidente que motivó el caso |
| [`docs/context.md`](docs/context.md) | Por qué reporting y operación se pelean en plataformas maduras |
| [`docs/symptoms.md`](docs/symptoms.md) | Cómo se ve la degradación periódica |
| [`docs/root-causes.md`](docs/root-causes.md) | Las 4 causas más frecuentes |
| [`docs/solution-options.md`](docs/solution-options.md) | Cola, replica, precálculo, batch async |
| [`docs/trade-offs.md`](docs/trade-offs.md) | Lo que cuesta sostener un pipeline de datos paralelo |
| [`docs/business-value.md`](docs/business-value.md) | Impacto en SLO operacional |

---

## 📁 Estructura del caso

```
11-heavy-reporting-blocks-operations/
├── README.md                    ← este archivo
├── comparison.md                ← comparativa multi-stack
├── compose.compare.yml          ← los 4 stacks juntos
├── docs/                        ← análisis + postmortem
├── shared/                      ← assets compartidos
├── 🐘 php/                      ← `OPERATIVO` — locks vs cola/replica
├── 🐍 python/                   ← `OPERATIVO` — threading + métricas
├── 🟢 node/                     ← `OPERATIVO` — monitorEventLoopDelay + setImmediate
├── ☕ java/                     ← `OPERATIVO` — ThreadPoolExecutor + reportingPool
└── 🔵 dotnet/                   ← 🔧 estructura lista
```
