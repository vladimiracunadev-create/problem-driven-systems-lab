# 🌐 Caso 09 - Integración externa inestable

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-green)](php/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Resiliencia-blueviolet)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutiva. La evidencia de ingeniería, los algoritmos y la remediación profunda se encuentran en el link de arriba.

---

## 🔍 Qué problema representa

Una dependencia externa cambia contrato, limita cuota o entra en mantenimiento, y el sistema propio queda demasiado expuesto porque consume al tercero como si fuera estable.

## 💡 Qué deja como evidencia en PHP

- `catalog-legacy` deja el flujo pegado al proveedor y a sus fallas.
- `catalog-hardened` usa adapter, normalización de payload y cache operativa.
- `integration/state`, `sync-events` y `diagnostics/summary` muestran cuota, mappings y eventos de cuarentena.

## 🗺️ Diagrama — Adapter endurecido: budget → cache → breaker → schema mapping

```text
                       request: /catalog-hardened?sku=widget-A
                                          │
                                          ▼
                            ┌─────────────────────────┐
                            │ providerBudget.tryAcquire()│   ← Semaphore (max N/window)
                            └────────────┬────────────┘
                       permits == 0       │      permit obtenido
                  ┌─────────┘             │              └─────────────────────────┐
                  ▼                                                                 ▼
        ╔════════════════════════╗                              ┌────────────────────────────────┐
        ║ served_from: cache     ║                              │ breaker.get() == "open"?       │
        ║ reason: budget_exhausted║                              └──────┬──────────────────┬──────┘
        ╚════════════════════════╝                                     │ si               │ no
                  ▲                                                     ▼                  ▼
                  │                                       ╔════════════════════╗   call provider real
                  │                                       ║ served_from: cache ║   (simulado en lab)
                  │                                       ║ breaker:open       ║          │
                  │                                       ╚════════════════════╝          ▼
                  │                                                                ┌─────────────────┐
                  │   ┌────────────────────────────────────────────────────────────┤  provider falla?│
                  │   │                                                            └────┬────────────┘
                  │   │                                                                 │
                  │   │                                                       no        │ si
                  │   │                                                ┌────────────────┴────────────┐
                  │   │                                                ▼                              ▼
                  │   │                                       ┌─────────────────┐         ┌──────────────────────┐
                  │   │                                       │ snapshotCache   │         │ breaker.set("open")  │
                  │   │                                       │   .put(fresh)   │         │ AtomicReference CAS  │
                  │   │                                       │ breaker.set     │         └──────────┬───────────┘
                  │   │                                       │   ("closed")    │                    │
                  │   │                                       └────────┬────────┘                    │
                  │   │                                                │                              │
                  │   │                                                ▼                              ▼
                  │   │                                       ╔══════════════════╗      ╔════════════════════╗
                  │   │                                       ║ served_from:     ║      ║ served_from: cache ║
                  │   │                                       ║   provider       ║      ║ snapshot (stale)   ║
                  │   │                                       ╚══════════════════╝      ╚═══════╤════════════╝
                  │   │                                                                          │
                  └───┴──────────────────────────────────────────────────────────────────────────┘

  Legacy: cualquier fallo del provider = falla visible al cliente.
  Hardened: budget protege cuota; cache protege disponibilidad; breaker protege al provider.
```

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8 | `OPERATIVO` |
| 🟢 Node.js | `OPERATIVO` |
| 🐍 Python | `OPERATIVO` |
| ☕ Java 21 | `OPERATIVO` (`Semaphore` budget + snapshot cache + `AtomicReference` breaker) |
| 🔵 .NET 8 | 🔧 Estructura lista |

## 🚀 Cómo levantar

```bash
docker compose -f php/compose.yml up -d --build
```
