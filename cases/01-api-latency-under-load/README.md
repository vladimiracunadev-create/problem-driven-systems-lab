# ⚡ Caso 01 — API lenta bajo carga por cuellos de botella reales

[![Estado](https://img.shields.io/badge/Estado-Implementado-success)](php/README.md)
[![Stacks](https://img.shields.io/badge/Stacks-7%20operativos%20%C2%B7%20PHP%20%C2%B7%20Python%20%C2%B7%20Node%20%C2%B7%20Java%20%C2%B7%20.NET%20%C2%B7%20Go%20%C2%B7%20Rust-blue)](../../docs/languages/README.md)
[![Stack principal](https://img.shields.io/badge/Stack%20principal-PHP%208%20%2B%20PostgreSQL-777BB4?logo=php)](php/)
[![Observabilidad](https://img.shields.io/badge/Observabilidad-Prometheus%20%2B%20Grafana-orange?logo=grafana)](php/compose.yml)
[![Categoría](https://img.shields.io/badge/Categoría-Rendimiento-red)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda se encuentran en el link de arriba.

---

## 🔍 Qué problema representa este caso

Este caso documenta e implementa un **problema realista de producción**, no una simulación con `sleep` artificial:

- Una API de reportes consulta datos transaccionales directamente,
- El diseño inicial cae en **filtros no sargables** y el **patrón N+1**,
- Al mismo tiempo corre un **proceso crítico de refresco de resumen**,
- Ambos compiten por la base de datos y deterioran la latencia de respuesta.

> **Situación clásica en sistemas vivos:** los usuarios siguen usando la aplicación mientras una tarea operacional importante consume recursos en segundo plano.

---

## 🎯 Objetivo del caso

No busca demostrar que un lenguaje es "mejor" en abstracto. Busca dejar evidencia reproducible de cómo:

| # | Evidencia | Descripción |
|---|-----------|-------------|
| 1️⃣ | **Ver** | Cómo se ve un cuello de botella real en métricas y trazas |
| 2️⃣ | **Medir** | Cómo se mide latencia p95/p99 y carga de DB antes/después |
| 3️⃣ | **Distinguir** | La diferencia entre diseño defectuoso y versión mejorada |
| 4️⃣ | **Entender** | El impacto de un proceso crítico concurrente en la API |
| 5️⃣ | **Justificar** | Una corrección con datos reales, no con intuición |

---

## 🏗️ Implementación actual

### ✅ PHP 8 + PostgreSQL (implementación profunda)

El stack PHP es la implementación **funcional completa** de este caso. Incluye:

| Componente | Rol |
|-----------|-----|
| `app` — API PHP 8 | Dos endpoints: `report-legacy` (defectuoso) y `report-optimized` (mejorado) |
| `db` — PostgreSQL | Base con datos semilla realistas y tabla resumen |
| `worker` — batch PHP | Refresco periódico de resumen — simula proceso crítico concurrente |
| `postgres-exporter` | Métricas estándar de PostgreSQL para Prometheus |
| `prometheus` | Scraping de métricas de la app y la base de datos |
| `grafana` | Dashboard inicial del caso con paneles de latencia y DB |

### Python 3.12 (implementacion operativa portable)

El stack Python ahora resuelve el caso con libreria estandar y SQLite local:

- `report-legacy` reproduce agregacion transaccional + N+1.
- `report-optimized` usa tabla resumen y menos round-trips.
- `batch/status`, `job-runs`, `metrics` y `diagnostics/summary` dejan evidencia comparable.

PHP sigue siendo la version mas profunda con PostgreSQL, exporter, Prometheus y Grafana. Python queda operativo para comparar el criterio de solucion sin romper el stack principal.

### Node.js 22 (implementacion operativa)

El stack Node.js resuelve el mismo problema con primitivas naturales del runtime:

- `report-legacy` reproduce el `1 + 2N` con SQL real: una agregacion con `CAST` no sargable sobre `orders` mas dos queries dependientes por fila.
- `report-optimized` lee `customer_daily_summary` (mantenida por un worker `setInterval` con `DELETE` + `INSERT ... SELECT`) y resuelve los detalles con una sola query usando `ROW_NUMBER() OVER (PARTITION BY ...)`.
- Expone `event_loop_lag_ms` como senal Node-especifica (medida con `setImmediate`). Es la unica metrica de este tipo en el lab, y aca es genuina: `node:sqlite` (`DatabaseSync`) es sincronico, asi que cada query del N+1 bloquea el loop del proceso entero.

Ver detalles en [`node/README.md`](node/README.md). Puerto local: `821`.

### Java 21 (implementacion operativa)

Stack Java operativo con SQLite embebido via `sqlite-jdbc` (`journal_mode=WAL`, conexion por request con `try-with-resources`), `LongAdder` para contadores y `ScheduledExecutorService` para el worker `report-refresh-java`. WAL es lo que permite que el worker escriba `customer_summary` sin bloquear a los lectores — el equivalente embebido del MVCC de PostgreSQL. Mismas rutas de contraste (`/report-legacy`, `/report-optimized`, `/batch/status`, `/job-runs`). Ver [`java/README.md`](java/README.md). Hub: `http://localhost:8400/01/`. Aislado: puerto `841`.

### .NET 8 (implementacion operativa)

Stack .NET operativo con SQLite embebido via `Microsoft.Data.Sqlite` (`journal_mode=WAL`, conexion por unidad de trabajo con `using`/`IDisposable`), `Interlocked.Increment` para contadores y `Task.Delay` + `CancellationToken` para el worker `report-refresh-dotnet`. Es el espejo exacto del Java: mismo esquema, mismas queries, mismos resultados fila por fila. Ver [`dotnet/README.md`](dotnet/README.md). Hub: `http://localhost:8500/01/`. Aislado: puerto `851`.

---

## 🚀 Cómo levantar este caso

### Modo principal recomendado — solo el stack PHP real

```bash
make case-up CASE=01-api-latency-under-load STACK=php
```

Esto levanta la API, la base de datos, el worker, el exporter, Prometheus y Grafana.

### Modo de medición — generar carga real

```bash
# Contra la versión defectuosa (N+1 + filtro no sargable)
make case-load CASE=01-api-latency-under-load \
  TARGET_URL="http://php-app:8080/report-legacy?days=30&limit=20" \
  REQUESTS=40 CONCURRENCY=8

# Contra la versión optimizada (tabla resumen)
make case-load CASE=01-api-latency-under-load \
  TARGET_URL="http://php-app:8080/report-optimized?days=30&limit=20" \
  REQUESTS=40 CONCURRENCY=8
```

### Modo benchmark guiado — comparación automatizada antes/después

```bash
bash cases/01-api-latency-under-load/shared/benchmark/run-benchmark.sh
```

---

## 🌐 Rutas del caso

| Ruta | Descripción |
|------|-------------|
| `/` | Resumen del caso y endpoints disponibles |
| `/health` | Estado básico del servicio |
| `/report-legacy?days=30&limit=20` | ❌ Versión defectuosa — N+1 + filtro no sargable |
| `/report-optimized?days=30&limit=20` | ✅ Versión mejorada — tabla resumen |
| `/batch/status` | Estado actual del proceso crítico concurrente |
| `/job-runs?limit=10` | Historial reciente del batch worker |
| `/diagnostics/summary` | Comparación entre métricas, worker y base de datos |
| `/metrics` | Métricas JSON de la aplicación |
| `/metrics-prometheus` | Métricas en formato Prometheus |
| `/reset-metrics` | Reinicio de métricas locales |

---

## 📖 Cómo interpretar este caso

### ❌ Antes — El diseño defectuoso

La ruta `report-legacy` representa decisiones frecuentes en sistemas reales:

- Consultar directamente sobre la tabla transaccional
- Usar filtros que rompen el uso de índices (no sargables)
- Enriquecer la respuesta con muchas consultas adicionales (N+1)
- Devolver más datos de los necesarios
- Ignorar el efecto de procesos batch sobre la misma base

### ✅ Después — El diseño mejorado

La ruta `report-optimized` representa una corrección más madura:

- Tabla resumen de apoyo para agregaciones pesadas
- Consulta estable sin filtros problemáticos
- Menos viajes a la base de datos por request
- Payload de respuesta más pequeño y enfocado
- Convivencia más razonable con el proceso batch

---

## 📊 Qué se puede medir

Este caso deja estructura para medir y comparar:

| Métrica | Fuente |
|---------|--------|
| Latencia promedio y p95/p99 | Grafana — paneles de la app |
| Estado y duración del worker | `/batch/status` y `/job-runs` |
| Carga sobre la base de datos | `postgres-exporter` + Grafana |
| Diferencia legacy vs optimizado | `/diagnostics/summary` + benchmark guiado |

---

## 📁 Estructura del caso

```text
01-api-latency-under-load/
├── 📄 README.md                    ← Este archivo
├── 🐳 compose.compare.yml          ← Comparación multi-stack (cuando aplique)
├── 📚 docs/                        ← Documentación técnica del caso
│   ├── benchmarking.md
│   └── observability.md
├── 🔗 shared/                      ← Scripts y recursos compartidos
│   └── benchmark/
│       └── run-benchmark.sh
├── 🐘 php/                         ← Implementación completa (stack principal)
│   ├── app/                        ← Código PHP de la API
│   ├── db/init/                    ← Scripts de inicialización de PostgreSQL
│   ├── Dockerfile
│   ├── compose.yml
│   └── README.md
├── 🟢 node/                        ← `OPERATIVO` — SQLite via node:sqlite + event loop lag + worker setInterval
├── 🐍 python/                      ← `OPERATIVO` — SQLite stdlib + worker en thread
├── ☕ java/                         ← `OPERATIVO` — SQLite via sqlite-jdbc (WAL) + ScheduledExecutorService worker
├── 🔵 dotnet/                      ← `OPERATIVO` — SQLite via Microsoft.Data.Sqlite (WAL) + Task.Delay worker
├── 🐹 go/                      ← `OPERATIVO` — SQLite via modernc.org/sqlite (WAL) + goroutine worker
└── 🦀 rust/                      ← `OPERATIVO` — SQLite via rusqlite bundled (WAL) + Drop sin cierre explicito
```

---

## 📚 Documentación complementaria

- [`docs/benchmarking.md`](docs/benchmarking.md) — guía de medición antes/después
- [`docs/observability.md`](docs/observability.md) — qué se monitorea y cómo
- [`php/README.md`](php/README.md) — detalles de la implementación PHP

---

## ⚖️ Alcance honesto

> Este caso tiene implementación real orientada a un problema real, pero sigue siendo un laboratorio:
>
> - ✅ Sirve para reproducir un patrón real de degradación y corrección
> - ✅ Deja estructura para medir antes/después con datos reales
> - ❌ No reemplaza observabilidad enterprise completa
> - ❌ No replica exactamente tu producción específica
> - ❌ No modela todas las dependencias posibles del mundo real
