# 💸 Caso 10 — Arquitectura cara para un problema simple

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-success)](php/README.md)
[![Stacks](https://img.shields.io/badge/Stacks-PHP%20%C2%B7%20Python%20%C2%B7%20Node%20%C2%B7%20Java-blue)](#-stacks-disponibles)
[![Categoría](https://img.shields.io/badge/Categoría-Arquitectura-violet)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda viven en el link de arriba y en `comparison.md`.

---

## 🔍 Qué problema representa

La solución técnica consume **más servicios, complejidad y costo** del que el problema de negocio realmente necesita. Microservicios para flujos que aún no escalaron; event sourcing para CRUDs; Kafka para 100 mensajes por hora; Kubernetes para servir 50 RPS.

**No es un error técnico, es uno de criterio.** Se copian patrones de empresas mucho más grandes sin medir si aplican; se diseña para una escala que aún no existe; se confunde **complejidad técnica con madurez**. El costo aparece más tarde: lead time alto para cambios triviales, factura cloud inflada, equipo que pasa más tiempo coordinando servicios que entregando valor.

---

## ⚠️ Síntomas típicos

- **Demasiados servicios** para un flujo pequeño (5 hops para una lectura)
- Costos cloud **difíciles de justificar** frente al revenue
- Tiempo alto para entender o cambiar algo simple
- Múltiples **puntos de falla sin beneficio equivalente**

---

## 🧩 Causas frecuentes

- **Sobrediseño por aspiración futura** (escala que aún no existe)
- Copiar patrones de empresas mucho más grandes
- **No medir costo operativo total** (servicios extra = AWS bill + coordinación + ops)
- Confundir complejidad técnica con madurez senior

---

## 🔬 Estrategia de diagnóstico

- Comparar **costo, riesgo y valor** del diseño actual
- Medir qué partes **realmente escalan** y cuáles no
- Identificar **servicios con poco valor aportado** vs su costo de mantenimiento
- Cuestionar cada componente por **necesidad real**, no por afinidad técnica

---

## 💡 Opciones de solución

- **Simplificar topología** y reducir capas innecesarias
- **Consolidar servicios** cuando el contexto lo permita (no todo merece ser microservicio)
- Optimizar por **costo/beneficio** en vez de moda
- Diseñar **escalabilidad gradual**, no anticipada en exceso (YAGNI a nivel arquitectónico)

---

## 🗺️ Diagrama — Complex vs Right-Sized: qué cuesta cada hop

```text
  Complex (8 hops, lead_time=16 días, cost=200/mes):

    request ──▶ API gateway ──▶ auth-svc ──▶ feature-svc ──▶ cache-svc
                                                              │
                                                              ▼
                                                         metrics-svc ──▶ event-bus
                                                              │              │
                                                              ▼              ▼
                                                         analytics-svc   db-read-svc
                                                              │              │
                                                              └──────┬───────┘
                                                                     ▼
                                                                response

      Cada hop = serializacion + traversal + alocacion. Bajo seasonal_peak (>20 hops):
      internal_timeout. Lead time para cambiar 1 campo: tocar 5 services + coord.

  Right-sized (1 hop, lead_time=1 día, cost=3/mes):

    request ──▶ HashMap.get(key) ──▶ response

      Mismo problema de negocio. Cero overhead. Cambiar 1 campo: 1 commit.
```

---

## 🏗️ Implementación actual

### ✅ PHP 8

`feature-complex` simula la cadena cara: cada hop construye payload, serializa, parsea, mide; `feature-right-sized` resuelve con un lookup directo. `decisions` registra los ADRs explícitos del lab y `diagnostics/summary` deja visible el contraste de costo y lead time. Ver [`php/README.md`](php/README.md). Modo aislado: puerto `8110`.

### Python 3.12

Misma lógica con stdlib + JSON serialization manual. Ver [`python/README.md`](python/README.md). Modo aislado: puerto `8310`. Hub: `http://localhost:8200/10/`.

### Node.js 20

CPU real medido como N rondas de `JSON.stringify`/`parse` sobre arrays grandes en `complex` vs acceso O(1) en `right_sized`. Bajo `seasonal_peak`, complex devuelve 502 por timeout interno. Ver [`node/README.md`](node/README.md). Modo aislado: puerto `8210`. Hub: `http://localhost:8300/10/`.

### Java 21

CPU real medido en `StringBuilder` loops por hop (alocación + traversal). `HashMap.get(key)` O(1) en right-sized. `System.nanoTime()` para medición directa; el JIT optimiza el lookup pero no el StringBuilder, mostrando el costo real. Ver [`java/README.md`](java/README.md). Modo aislado: puerto `8410`. Hub: `http://localhost:8400/10/`.

### .NET (espacio de crecimiento)

Estructura dockerizada lista; sin paridad funcional todavía.

---

## ⚖️ Trade-offs

- **Consolidar demasiado** puede limitar independencia futura — hay que entender el contexto
- **Simplicidad hoy** no debe bloquear crecimiento real (diseñar para que se pueda extender después)
- Reducir componentes **exige entender dependencias** antes — no es solo borrar

---

## 💼 Valor de negocio

**Mejora adaptabilidad, reduce costos y acelera la entrega** manteniendo el foco en el problema real. El indicador honesto es **lead time para cambios pequeños**: si tocar una validación toma 3 días por coordinación entre servicios, la arquitectura te está costando velocidad. Para el negocio: time-to-market real. Para finanzas: factura cloud proporcional al tráfico, no a la aspiración.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8 | `OPERATIVO` (simulación de hops + ADRs registrados) |
| 🐍 Python 3.12 | `OPERATIVO` (stdlib + serialization manual) |
| 🟢 Node.js 20 | `OPERATIVO` (`JSON.stringify`/`parse` cycles como CPU real) |
| ☕ Java 21 | `OPERATIVO` (`StringBuilder` loops vs `HashMap` O(1); CPU real medido) |
| 🔵 .NET 8 | 🔧 Estructura lista |

---

## 🚀 Cómo levantar

**Modo hub (recomendado):**
```bash
docker compose -f compose.root.yml    up -d --build && curl http://localhost:8100/10/health   # PHP
docker compose -f compose.python.yml  up -d --build && curl http://localhost:8200/10/health   # Python
docker compose -f compose.nodejs.yml  up -d --build && curl http://localhost:8300/10/health   # Node
docker compose -f compose.java.yml    up -d --build && curl http://localhost:8400/10/health   # Java
```

**Medir el costo de cada hop (ejemplo Java):**
```bash
# Complex con 8 hops: elapsed_ms alto, cost_usd_month=200, lead_time=16
curl "http://localhost:8400/10/feature-complex?key=feature-1&hops=8"

# Complex con 25 hops: internal_timeout (seasonal_peak rompe la sobre-arquitectura)
curl "http://localhost:8400/10/feature-complex?key=feature-1&hops=25"

# Right-sized: elapsed_ms minimo, cost=3, lead_time=1
curl "http://localhost:8400/10/feature-right-sized?key=feature-1"

# ADRs del lab que justifican empezar simple
curl http://localhost:8400/10/decisions
```

---

## 📚 Lectura recomendada

| Documento | Qué cubre |
| --- | --- |
| [`comparison.md`](comparison.md) | Comparativa multi-stack con snippets del costo CPU por hop |
| [`docs/postmortem.md`](docs/postmortem.md) | Postmortem del incidente que motivó el caso |
| [`docs/context.md`](docs/context.md) | Por qué sobre-diseñar también es un anti-patrón |
| [`docs/symptoms.md`](docs/symptoms.md) | Cómo se ve una arquitectura desproporcionada |
| [`docs/root-causes.md`](docs/root-causes.md) | Las 4 causas más frecuentes de over-engineering |
| [`docs/solution-options.md`](docs/solution-options.md) | Simplificar, consolidar, escalar gradual |
| [`docs/trade-offs.md`](docs/trade-offs.md) | Lo que cuesta des-consolidar después |
| [`docs/business-value.md`](docs/business-value.md) | Impacto en costo y lead time |

---

## 📁 Estructura del caso

```
10-expensive-architecture-for-simple-needs/
├── README.md                    ← este archivo
├── comparison.md                ← comparativa multi-stack
├── compose.compare.yml          ← los 4 stacks juntos
├── docs/                        ← análisis + postmortem + ADRs
├── shared/                      ← assets compartidos
├── 🐘 php/                      ← `OPERATIVO` — simulación de hops + ADRs
├── 🐍 python/                   ← `OPERATIVO` — stdlib + serialization manual
├── 🟢 node/                     ← `OPERATIVO` — JSON.stringify/parse cycles
├── ☕ java/                     ← `OPERATIVO` — StringBuilder loops vs HashMap O(1)
└── 🔵 dotnet/                   ← 🔧 estructura lista
```
