# 🏗️ Caso 07 - Modernización incremental de monolito

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-green)](php/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Arquitectura-blueviolet)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda se encuentran en el link de arriba.

---

## 🔍 Qué problema representa

El sistema legacy sigue siendo crítico, pero cambiar una parte rompe otras no relacionadas y modernizar sin estrategia vuelve cada release más riesgoso.

## 💡 Qué deja como evidencia en PHP

- `change-legacy` muestra blast radius alto y riesgo por acoplamiento.
- `change-strangler` mueve consumidores gradualmente y sube cobertura/contratos.
- `migration/state`, `flows` y `diagnostics/summary` dejan visible el progreso real de la modernización.

## 🗺️ Diagrama — Strangler Fig: routing decide consumer por consumer

```text
                    request: consumer=billing, op=change
                                    │
                                    ▼
                       ┌────────────────────────────┐
                       │  routingTable.get(key)     │   ←─ ConcurrentHashMap
                       │  key = "billing:change"    │      (mutable en runtime)
                       └─────────────┬──────────────┘
                                     │
                       ┌─────────────┴──────────────┐
                       ▼                             ▼
                   handler != null               handler == null
                       │                             │
                       ▼                             ▼
               ┌──────────────┐              ┌──────────────────┐
               │  new module  │              │  legacy monolith │
               │  blast=1     │              │  con ACL acotada │
               │  risk=1      │              │  blast=2 risk=4  │
               └──────┬───────┘              └────────┬─────────┘
                      │                                │
                      └────────────┬───────────────────┘
                                   ▼
                       diagnostics/summary
                       flows: migration_progress por consumer

  Legacy (sin strangler): TODO request va al monolito → blast=4 risk=8
  Strangler: consumers migrados van al new module; el resto al legacy SIN bloquear.
  Migrar un consumer = 1 linea: routingTable.put("orders:change", newOrdersHandler);
```

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8 | `OPERATIVO` |
| 🟢 Node.js | `OPERATIVO` |
| 🐍 Python | `OPERATIVO` |
| ☕ Java 21 | `OPERATIVO` (`ConcurrentHashMap<String,Function>` routing mutable + `Function` como ACL) |
| 🔵 .NET 8 | 🔧 Estructura lista |

## 🚀 Cómo levantar

```bash
docker compose -f php/compose.yml up -d --build
```
