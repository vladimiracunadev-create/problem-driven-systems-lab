# 🧩 Caso 08 - Extracción de módulo crítico sin romper operación

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-green)](php/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Arquitectura-blueviolet)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda se encuentran en el link de arriba.

---

## 🔍 Qué problema representa

Hay que sacar un módulo sensible del sistema vivo, pero ese módulo sigue sirviendo a checkout, partners y backoffice. Cortarlo de una vez expone contratos, flujos y estados compartidos.

## 💡 Qué deja como evidencia en PHP

- `pricing-bigbang` muestra el costo de extraer sin proxy ni compatibilidad.
- `pricing-compatible` usa contrato estable, proxy y cutover gradual por consumidor.
- `extraction/state`, `flows` y `diagnostics/summary` dejan visible progreso, shadow traffic y presión de compatibilidad.

## 🗺️ Diagrama — Cutover gradual con proxy de compatibilidad

```text
  Big-bang (sin proxy):                    Compatible (con proxy + cutover):

  consumer (legacy contract)               consumer (legacy contract)
   {sku, cost_usd}                          {sku, cost_usd}
        │                                        │
        ▼                                        ▼
  ╔════════════════════╗                  ┌──────────────────────────┐
  ║  new module        ║                  │  Function<Old, New>      │  ← compatProxy
  ║  espera:           ║                  │  {cost_usd} → {price,    │
  ║  {price,currency}  ║                  │              currency}   │
  ╚═════════╤══════════╝                  └────────────┬─────────────┘
            │                                          │
            ▼                                          ▼
   ❌ contract_violation                  ╔════════════════════════╗
   checkout / partners / backoffice       ║  new module            ║
   TODOS rotos al unisono                 ║  recibe contrato nuevo │
                                          ╚═════════╤══════════════╝
                                                    │
                                                    ▼
                                          cutoverProgress.put(consumer, true)
                                                    │
                                                    ▼
                                        emit("cutover_done:checkout")
                                          ──▶ CopyOnWriteArrayList<Consumer>
                                              ──▶ subscriber 1, 2, ..., N

  Cutover gradual: consumers migran uno a uno. El proxy garantiza que ningun
  consumer rompa mientras esten en el contrato viejo. EventBus notifica avance.
```

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8 | `OPERATIVO` |
| 🟢 Node.js | `OPERATIVO` |
| 🐍 Python | `OPERATIVO` |
| ☕ Java 21 | `OPERATIVO` (`Function` proxy de compatibilidad + `CopyOnWriteArrayList<Consumer>` event bus) |
| 🔵 .NET 8 | 🔧 Estructura lista |

## 🚀 Cómo levantar

```bash
docker compose -f php/compose.yml up -d --build
```
