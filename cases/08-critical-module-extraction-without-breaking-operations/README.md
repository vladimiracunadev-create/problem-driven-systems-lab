# 🧩 Caso 08 — Extracción de módulo crítico sin romper operación

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-success)](php/README.md)
[![Stacks](https://img.shields.io/badge/Stacks-PHP%20%C2%B7%20Python%20%C2%B7%20Node%20%C2%B7%20Java-blue)](#-stacks-disponibles)
[![Categoría](https://img.shields.io/badge/Categoría-Arquitectura-violet)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda viven en el link de arriba y en `comparison.md`.

---

## 🔍 Qué problema representa

Se necesita **desacoplar una parte clave** del sistema, pero esa parte participa en flujos sensibles (checkout, partners, backoffice) y **no admite quiebres**. Cualquier cambio mal hecho deja consumers rotos; cualquier extracción big-bang sin compatibilidad rompe contratos sin aviso.

**El reto es operacional, no técnico:** la extracción tiene que ser invisible para los consumers existentes. Mientras se reemplaza el motor, las APIs deben seguir respondiendo el contrato viejo; mientras se migra cada consumer, los demás siguen funcionando.

---

## ⚠️ Síntomas típicos

- Un módulo **concentra carga, reglas y dependencias** críticas
- Cambiarlo afecta **varias áreas** del sistema sin razón clara
- Existe **temor de inconsistencia funcional** post-cambio
- El módulo no tiene **límites claramente definidos** — todo el mundo lo toca

---

## 🧩 Causas frecuentes

- **Acoplamiento temporal y de datos** (todos los flujos comparten el mismo path)
- Contratos **implícitos o no documentados** (el contrato es "lo que devuelve el código actual")
- Dependencias transversales ocultas (otros módulos esperan side-effects)
- Baja cobertura de pruebas sobre el comportamiento actual → cualquier cambio es a ciegas

---

## 🔬 Estrategia de diagnóstico

- Descubrir entradas, salidas y **consumidores reales** (el log de prod manda)
- Crear **mapa de contratos** actuales (request shape, response shape, side-effects)
- Definir estrategia de **shadow traffic** o **doble escritura** si aplica
- Medir riesgo funcional **antes** de extraer — no descubrirlo en producción

---

## 💡 Opciones de solución

- **Fachada estable** antes de mover implementación (consumers no notan)
- Extraer **en fases con verificación paralela** (shadow mode)
- **Colas/eventos** cuando el acoplamiento temporal lo permita
- **Observabilidad específica de transición** — métricas dedicadas al cutover

---

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

---

## 🏗️ Implementación actual

### ✅ PHP 8

`pricing-bigbang` rompe el contrato y deja a los consumers fallando; `pricing-compatible` mantiene contrato estable con proxy de traducción y registra progreso de cutover por consumer. `extraction/state`, `flows` y `diagnostics/summary` exponen `compatibility_proxy_hits`, `contract_tests`, shadow traffic. Ver [`php/README.md`](php/README.md). Modo aislado: puerto `818`.

### Python 3.12

Función de adaptación + lista de listeners para eventos. Sin frameworks externos. Ver [`python/README.md`](python/README.md). Modo aislado: puerto `838`. Hub: `http://localhost:8200/08/`.

### Node.js 20

`Proxy` nativo intercepta `computeFinalPrice` y traduce `cost_usd` → `price` en vuelo. `EventEmitter` (`cutoverBus`) publica cada avance del cutover a N subscribers. Ver [`node/README.md`](node/README.md). Modo aislado: puerto `828`. Hub: `http://localhost:8300/08/`.

### Java 21

`Function<PriceRequestOld, PriceRequestNew>` como proxy compat (tipado, sin reflection). `CopyOnWriteArrayList<Consumer<String>>` como event bus thread-safe: reads paralelos sin lock, writes raros copian el array — exactamente el patrón "muchos suscriptores, raras altas/bajas". Ver [`java/README.md`](java/README.md). Modo aislado: puerto `848`. Hub: `http://localhost:8400/08/`.

### .NET (espacio de crecimiento)

Estructura dockerizada lista; sin paridad funcional todavía.

---

## ⚖️ Trade-offs

- La transición puede **durar más de lo estimado** — los consumers no migran tan rápido como uno cree
- Doble escritura o shadow mode **agregan complejidad** durante la ventana
- Sin gobernanza, **el módulo puede duplicarse mal** y quedar peor que antes

---

## 💼 Valor de negocio

Reduce **riesgo operacional** y habilita **evolución controlada** de piezas críticas del negocio. Lo que el caso protege no es "el módulo" — es el **revenue del checkout** y los partners durante la migración. Hacerlo bien significa que el negocio nunca sabe que se hizo cirugía.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8 | `OPERATIVO` (proxy de traducción + registro de cutover) |
| 🐍 Python 3.12 | `OPERATIVO` (función adapter + lista de listeners) |
| 🟢 Node.js 20 | `OPERATIVO` (`Proxy` nativo + `EventEmitter` para eventos) |
| ☕ Java 21 | `OPERATIVO` (`Function` proxy + `CopyOnWriteArrayList<Consumer>` event bus) |
| 🔵 .NET 8 | 🔧 Estructura lista |

---

## 🚀 Cómo levantar

**Modo hub (recomendado):**
```bash
docker compose -f compose.root.yml    up -d --build && curl http://localhost:8100/08/health   # PHP
docker compose -f compose.python.yml  up -d --build && curl http://localhost:8200/08/health   # Python
docker compose -f compose.nodejs.yml  up -d --build && curl http://localhost:8300/08/health   # Node
docker compose -f compose.java.yml    up -d --build && curl http://localhost:8400/08/health   # Java
```

**Reproducir el contraste big-bang vs compatible (ejemplo Java):**
```bash
# Big-bang: nuevo modulo no entiende {cost_usd}, contract_violation
curl "http://localhost:8400/08/pricing-bigbang?consumer=checkout&sku=ABC&cost_usd=100"

# Compatible: proxy traduce {cost_usd}→{price,currency}, consumer no rompe
curl "http://localhost:8400/08/pricing-compatible?consumer=checkout&sku=ABC&cost_usd=100"

# Estado del cutover por consumer + ultimos eventos del bus
curl http://localhost:8400/08/flows
```

---

## 📚 Lectura recomendada

| Documento | Qué cubre |
| --- | --- |
| [`comparison.md`](comparison.md) | Comparativa multi-stack con snippets de proxy de compat por lenguaje |
| [`docs/postmortem.md`](docs/postmortem.md) | Postmortem del incidente que motivó el caso |
| [`docs/context.md`](docs/context.md) | Por qué desacoplar piezas críticas sin parar la operación |
| [`docs/symptoms.md`](docs/symptoms.md) | Cómo se ve un módulo "que toca todo el mundo" |
| [`docs/root-causes.md`](docs/root-causes.md) | Las 4 causas estructurales más frecuentes |
| [`docs/solution-options.md`](docs/solution-options.md) | Fachada, shadow mode, doble escritura |
| [`docs/trade-offs.md`](docs/trade-offs.md) | Lo que cuesta sostener la ventana de cutover |
| [`docs/business-value.md`](docs/business-value.md) | Impacto en revenue y continuidad |

---

## 📁 Estructura del caso

```
08-critical-module-extraction-without-breaking-operations/
├── README.md                    ← este archivo
├── comparison.md                ← comparativa multi-stack
├── compose.compare.yml          ← los 4 stacks juntos
├── docs/                        ← análisis + postmortem
├── shared/                      ← assets compartidos
├── 🐘 php/                      ← `OPERATIVO` — proxy de traducción
├── 🐍 python/                   ← `OPERATIVO` — adapter + listeners
├── 🟢 node/                     ← `OPERATIVO` — Proxy nativo + EventEmitter
├── ☕ java/                     ← `OPERATIVO` — Function proxy + CopyOnWriteArrayList
└── 🔵 dotnet/                   ← 🔧 estructura lista
```
