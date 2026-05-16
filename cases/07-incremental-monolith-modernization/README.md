# 🏗️ Caso 07 — Modernización incremental de monolito

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-success)](php/README.md)
[![Stacks](https://img.shields.io/badge/Stacks-PHP%20%C2%B7%20Python%20%C2%B7%20Node%20%C2%B7%20Java-blue)](#-stacks-disponibles)
[![Categoría](https://img.shields.io/badge/Categoría-Arquitectura-violet)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda viven en el link de arriba y en `comparison.md`.

---

## 🔍 Qué problema representa

El sistema legacy sigue siendo **crítico**, pero su evolución se vuelve lenta, riesgosa y costosa. Cada cambio "pequeño" termina tocando módulos no relacionados, despertando dependencias implícitas y exigiendo testing exhaustivo de áreas que nadie quiere mirar.

**Modernizar no es reescribir.** Muchas organizaciones no pueden permitirse el rewrite total: el negocio no para, los clientes existen, el equipo es finito. Lo que sí se puede hacer es **estrangular gradualmente** — extraer módulo por módulo, dejando que el monolito siga sirviendo lo que aún no se ha migrado, con routing inteligente decidiendo a dónde va cada request.

---

## ⚠️ Síntomas típicos

- Cambios pequeños **tardan demasiado** por miedo a romper algo no relacionado
- Acoplamientos altos y código difícil de aislar para test
- Reportes, procesos o módulos pequeños afectan **todo el sistema**
- Temor constante a tocar partes antiguas — la deuda gana cultura

---

## 🧩 Causas frecuentes

- **Arquitectura erosionada** — el diseño original ya no refleja la realidad
- Dependencias implícitas entre módulos (alguien importa algo del otro lado de la app)
- Ausencia de **límites claros de contexto** (DDD bounded contexts inexistentes)
- Falta de pruebas y observabilidad histórica → cambiar a ciegas

---

## 🔬 Estrategia de diagnóstico

- Identificar módulos **más críticos y frágiles** (matriz blast radius × frecuencia de cambio)
- Mapear dependencias funcionales y técnicas (no solo `import`/`require`)
- Definir **puntos de extracción** o encapsulamiento
- Priorizar según **riesgo, impacto y factibilidad** — no por afinidad técnica

---

## 💡 Opciones de solución

- **Strangler Fig** o **Facades**: el monolito sigue sirviendo, el routing decide qué va al nuevo módulo
- Extraer **procesos pesados** antes que pantallas enteras (más fácil, más visible)
- Agregar **contratos y pruebas** alrededor de zonas críticas antes de tocarlas
- Modernizar por **valor de negocio**, no por moda técnica

---

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

---

## 🏗️ Implementación actual

### ✅ PHP 8 (strangler con tabla de routing persistente)

`change-legacy` aplica todos los cambios al monolito; `change-strangler` consulta una tabla de routing y dispara handler nuevo o legacy según el consumer. `migration/state` muestra cobertura por módulo y `flows` el progreso real. Ver [`php/README.md`](php/README.md). Modo aislado: puerto `817`.

### Python 3.12

Misma lógica con `dict[str, Callable]` como routing table. Ver [`python/README.md`](python/README.md). Modo aislado: puerto `837`. Hub: `http://localhost:8200/07/`.

### Node.js 20

`Map<string, handler>` **mutable en runtime** — agregar un consumer migrado es `routingTable.set(...)` sin reload del proceso. ACL como closure que filtra contrato. Ver [`node/README.md`](node/README.md). Modo aislado: puerto `827`. Hub: `http://localhost:8300/07/`.

### Java 21

`ConcurrentHashMap<String, Function<Request, Response>>` como routing table — reads paralelos sin lock, writes atómicos por bucket. `Function<T,R>` como ACL closure: la firma del handler **es** el contrato; agregar consumer = registrar lambda. Ver [`java/README.md`](java/README.md). Modo aislado: puerto `847`. Hub: `http://localhost:8400/07/`.

### .NET (espacio de crecimiento)

Estructura dockerizada lista; sin paridad funcional todavía.

---

## ⚖️ Trade-offs

- **Conviven dos mundos durante un tiempo** — más complejidad operativa hasta que el legacy desaparece
- Requiere **gobierno técnico sostenido** — sin reglas, el routing se vuelve spaghetti
- **No todo módulo merece extraerse** — algunos quedan en el monolito para siempre y está bien

---

## 💼 Valor de negocio

Permite **renovar plataformas reales sin detener operación** ni asumir una reescritura total de alto riesgo. Para el negocio: cambios siguen llegando a producción mientras la modernización ocurre en background. Para ingeniería: deuda se ataca por valor, no por afinidad. Para finanzas: el costo se amortiza en meses, no en años de proyecto big-bang.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8 | `OPERATIVO` (tabla de routing persistente + migration/state) |
| 🐍 Python 3.12 | `OPERATIVO` (`dict[str, Callable]` routing + stdlib pura) |
| 🟢 Node.js 20 | `OPERATIVO` (`Map<consumer, handler>` mutable en runtime + ACL como closure) |
| ☕ Java 21 | `OPERATIVO` (`ConcurrentHashMap<String, Function>` routing + `Function` ACL) |
| 🔵 .NET 8 | 🔧 Estructura lista |

---

## 🚀 Cómo levantar

**Modo hub (recomendado):**
```bash
docker compose -f compose.root.yml    up -d --build && curl http://localhost:8100/07/health   # PHP
docker compose -f compose.python.yml  up -d --build && curl http://localhost:8200/07/health   # Python
docker compose -f compose.nodejs.yml  up -d --build && curl http://localhost:8300/07/health   # Node
docker compose -f compose.java.yml    up -d --build && curl http://localhost:8400/07/health   # Java
```

**Reproducir la decisión de routing (ejemplo Java):**
```bash
# Legacy: cambio toca shared_schema, blast_radius=4, risk=8
curl "http://localhost:8400/07/change-legacy?consumer=billing&op=change"

# Strangler: billing ya migrado, routed_to=new-billing-svc, blast=1 risk=1
curl "http://localhost:8400/07/change-strangler?consumer=billing&op=change"

# Strangler para consumer NO migrado: cae al monolito pero con ACL acotada
curl "http://localhost:8400/07/change-strangler?consumer=orders&op=change"

# Progreso de migración por módulo
curl http://localhost:8400/07/flows
```

---

## 📚 Lectura recomendada

| Documento | Qué cubre |
| --- | --- |
| [`comparison.md`](comparison.md) | Comparativa multi-stack (PHP · Python · Node.js · Java) con snippets de routing por lenguaje |
| [`docs/postmortem.md`](docs/postmortem.md) | Postmortem del incidente que motivó el caso |
| [`docs/context.md`](docs/context.md) | Por qué modernizar gradualmente vs reescribir |
| [`docs/symptoms.md`](docs/symptoms.md) | Cómo se ve un monolito erosionado en el día a día |
| [`docs/root-causes.md`](docs/root-causes.md) | Las 4 causas estructurales más frecuentes |
| [`docs/solution-options.md`](docs/solution-options.md) | Strangler, facade, contract-first |
| [`docs/trade-offs.md`](docs/trade-offs.md) | Lo que cuesta sostener dos mundos |
| [`docs/business-value.md`](docs/business-value.md) | Impacto en velocidad de entrega y riesgo |

---

## 📁 Estructura del caso

```
07-incremental-monolith-modernization/
├── README.md                    ← este archivo
├── comparison.md                ← comparativa multi-stack PHP · Python · Node · Java
├── compose.compare.yml          ← levanta los 4 stacks juntos
├── docs/                        ← análisis problem-driven + postmortem
├── shared/                      ← assets compartidos
├── 🐘 php/                      ← `OPERATIVO` — routing persistente + ACL
├── 🐍 python/                   ← `OPERATIVO` — dict[str, Callable] + stdlib
├── 🟢 node/                     ← `OPERATIVO` — Map mutable + ACL closure
├── ☕ java/                     ← `OPERATIVO` — ConcurrentHashMap<String, Function>
└── 🔵 dotnet/                   ← 🔧 estructura lista
```
