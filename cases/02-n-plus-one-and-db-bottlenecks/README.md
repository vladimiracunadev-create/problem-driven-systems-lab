# 🔄 Caso 02 — N+1 queries y cuellos de botella en base de datos

[![Estado](https://img.shields.io/badge/Estado-Implementado%20PHP-success)](php/)
[![Categoría](https://img.shields.io/badge/Categoría-Rendimiento-red)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda se encuentran en el link de arriba.

---

## 🔍 Qué problema representa

La aplicación ejecuta demasiadas consultas por solicitud o usa el ORM de forma ineficiente,
generando **saturación silenciosa en la base de datos** que empeora progresivamente con el volumen.

Muchos sistemas parecen correctos funcionalmente, pero escalan mal por decisiones de acceso a datos poco visibles.

---

## ⚠️ Síntomas típicos

| Síntoma | Dónde se observa |
|---------|-----------------|
| Gran cantidad de queries por request | Profiler de base de datos / logs SQL |
| CPU alta en DB con poca carga aparente de usuarios | Métricas de base de datos |
| Respuesta lenta al consultar listas con relaciones | APM / tiempos de endpoint |
| Incidentes que empeoran al crecer el volumen de datos | Gradual degradación en staging/producción |

---

## 🧩 Causas frecuentes

- **Carga diferida sin control** — lazy loading que se dispara en bucles
- **Falta de eager loading selectivo** — el ORM consulta N veces para N entidades
- **Índices inexistentes o mal elegidos** — full table scans en queries frecuentes
- **Consultas repetidas** — falta de caché o de consolidación de acceso a datos

---

## 🔬 Estrategia de diagnóstico

1. Perfilar número de consultas por endpoint con un query logger
2. Analizar planes de ejecución con `EXPLAIN ANALYZE`
3. Revisar joins, filtros y proyecciones en consultas frecuentes
4. Medir tiempo de DB separado del tiempo total de la request

---

## 💡 Opciones de solución

| Opción | Cuándo aplica |
|--------|--------------|
| Consolidar consultas con JOINs | Cuando hay N+1 por relaciones entre entidades |
| Aplicar eager loading | Cuando el ORM soporta carga selectiva de relaciones |
| Diseñar índices orientados a consultas reales | Siempre — los índices deben reflejar los queries del negocio |
| Caché de lectura | Solo cuando el acceso a datos es repetitivo y la consistencia lo permite |
| Réplica de lectura | Para separar carga analítica de la operacional |

---

## 🏗️ Implementación actual

### ✅ PHP 8 + PostgreSQL

El stack PHP ya implementa este caso con una base relacional real y dos rutas comparables:

- `orders-legacy` -> carga pedido, cliente, items, producto y categoría dentro de bucles
- `orders-optimized` -> consolida pedidos y detalles con lecturas agrupadas
- `/metrics`, `/metrics-prometheus` y `/diagnostics/summary` -> dejan evidencia medible de la diferencia

### Python 3.12

El stack Python ahora implementa el caso con dataset local en SQLite y rutas equivalentes:

- `orders-legacy` -> carga relaciones dentro de bucles y expone el costo N+1.
- `orders-optimized` -> consolida pedidos y detalles con lecturas agrupadas.
- `/metrics`, `/metrics-prometheus` y `/diagnostics/summary` -> dejan evidencia medible de queries y latencia.

### Node.js / Java / .NET

Se mantienen como base de crecimiento para llevar el mismo caso a paridad multi-stack en una fase posterior.

---

## ⚖️ Trade-offs

| Decisión | Ventaja | Costo |
|----------|---------|-------|
| Más eager loading | Menos queries | Puede traer datos innecesarios |
| Índices adicionales | Lecturas más rápidas | Escrituras más costosas |
| Caché sin estrategia | Menos carga en DB | Puede ocultar problemas de diseño |

---

## 💼 Valor de negocio

> Una base de datos sana evita incidentes recurrentes, mejora el rendimiento transversal
> y reduce costos de hardware y licenciamiento.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
|-------|--------|
| 🐘 PHP 8 | ✅ Implementado (Docker + PostgreSQL) |
| 🟢 Node.js | 🔧 Estructura lista |
| 🐍 Python | ✅ Implementado (Docker + SQLite local + metricas) |
| ☕ Java | 🔧 Estructura lista |
| 🔵 .NET 8 | 🔧 Estructura lista |

---

## 🚀 Cómo levantar

```bash
# Levantar un stack específico
make case-up CASE=02-n-plus-one-and-db-bottlenecks STACK=php

# Comparar múltiples stacks
make compare-up CASE=02-n-plus-one-and-db-bottlenecks
```

---

## 📚 Lectura recomendada

| Archivo | Contenido |
|---------|-----------|
| `docs/context.md` | Escenario completo del sistema ficticio |
| `docs/symptoms.md` | Síntomas observables y cómo reconocerlos |
| `docs/diagnosis.md` | Herramientas y pasos para diagnosticar |
| `docs/root-causes.md` | Causas raíz documentadas |
| `docs/solution-options.md` | Opciones comparadas |
| `docs/trade-offs.md` | Costos y beneficios de cada camino |
| `docs/business-value.md` | Qué cambia al resolver esto |

---

## 📁 Estructura

```text
02-n-plus-one-and-db-bottlenecks/
├── 📄 README.md
├── 🐳 compose.compare.yml
├── 📚 docs/
├── 🔗 shared/
├── 🐘 php/
├── 🟢 node/
├── 🐍 python/
├── ☕ java/
└── 🔵 dotnet/
```
