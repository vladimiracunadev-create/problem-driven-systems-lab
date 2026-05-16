# 👤 Caso 12 — Punto único de conocimiento y riesgo operacional

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-success)](php/README.md)
[![Stacks](https://img.shields.io/badge/Stacks-PHP%20%C2%B7%20Python%20%C2%B7%20Node%20%C2%B7%20Java-blue)](#-stacks-disponibles)
[![Categoría](https://img.shields.io/badge/Categoría-Operaciones-darkgreen)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda viven en el link de arriba y en `comparison.md`.

---

## 🔍 Qué problema representa

Una persona, módulo o procedimiento concentra tanto conocimiento que el sistema se vuelve **frágil ante ausencias o rotación**. El clásico **bus factor = 1**: si la persona X se va de vacaciones, hay procesos que nadie sabe ejecutar; si renuncia, hay riesgo operativo serio.

**No es solo problema humano** — es problema arquitectónico, documental y operativo. El código sin documentación implícita "lo entiende solo quien lo escribió". El runbook que vive en la cabeza de una persona no es runbook, es **memoria tribal**. La continuidad operacional depende de externalizar ese conocimiento.

---

## ⚠️ Síntomas típicos

- **Nadie sabe bien** cómo funciona una parte crítica
- Cambios dependen de **una sola persona**
- Falta de documentación y de **procedimientos claros**
- Incidentes se resuelven por **memoria, no por método**

---

## 🧩 Causas frecuentes

- **Conocimiento no externalizado** (vive en la cabeza, no en el repo)
- Código acoplado y poco **explicativo** (nombres genéricos, lógica implícita)
- Procesos manuales sin estandarización
- **Baja cultura** de documentación y postmortems

---

## 🔬 Estrategia de diagnóstico

- Mapear zonas de **dependencia humana o técnica**
- Revisar runbooks, ADRs, READMEs y procedimientos (¿existen? ¿están al día?)
- Detectar pasos que **solo una persona ejecuta**
- Analizar **historial de incidentes** y cuellos de soporte

---

## 💡 Opciones de solución

- Crear **documentación accionable** y runbooks (no solo descriptivos, ejecutables)
- Reducir acoplamientos y **modularizar** donde aporte
- Formalizar despliegues, soporte y troubleshooting
- Hacer **shadowing, pairing y transferencia explícita** — no solo escribir, transferir

---

## 🗺️ Diagrama — Bus factor: dependencia humana vs runbook codificado

```text
  Legacy: acceso ciego                       Distributed: Optional<T> + chaining seguro

  scenario: owner_absent                      scenario: owner_absent
        │                                            │
        ▼                                            ▼
   ┌──────────────────────┐               ┌────────────────────────────────┐
   │ Owner owner =        │               │ Optional<Owner> ownerOpt =     │
   │   pickOwner(scenario)│               │   pickOwner(scenario)          │
   │  → null              │               │  → Optional.empty()            │
   └─────────┬────────────┘               └─────────────┬──────────────────┘
             │                                          │
             ▼                                          ▼
   ┌──────────────────────┐               ┌────────────────────────────────┐
   │ owner.runbook()      │               │ ownerOpt.map(o -> o.runbook()  │
   │  → NullPointerException│              │              .get(runbookKey))│
   └─────────┬────────────┘               │  .orElse(null)                 │
             ▼                              └─────────────┬──────────────────┘
   ╔═════════════════════╗                              │
   ║   CRASH             ║                              ▼
   ║   mttr = 120 min    ║                  ┌────────────────────────────────┐
   ║   bus_factor = 1    ║                  │ if script == null: usa runbook │
   ╚═════════════════════╝                  │ compartido por equipo          │
                                            └─────────────┬──────────────────┘
                                                          ▼
                                              ╔═════════════════════════════╗
                                              ║ degradacion controlada      ║
                                              ║ mttr = 35-50 min            ║
                                              ║ bus_factor = N (compartido) ║
                                              ╚═════════════════════════════╝

  /share-knowledge sube coverage +15 y bus_factor +1 cada vez.
  El tipo Optional<Owner> obliga a manejar el caso vacio: el lenguaje
  codifica el runbook que el legacy "olvido escribir".
```

---

## 🏗️ Implementación actual

### ✅ PHP 8

`incident-legacy` falla con owner ausente; `incident-distributed` chequea y degrada controlado. `/share-knowledge` sube cobertura. `knowledge/state` y `diagnostics/summary` exponen `mttr_min`, `blocker_count`, `handoff_quality`, `bus_factor_min`. Ver [`php/README.md`](php/README.md). Modo aislado: puerto `8112`.

### Python 3.12

Misma lógica con `Optional`-like via try/except + diccionario de owners. Ver [`python/README.md`](python/README.md). Modo aislado: puerto `8312`. Hub: `http://localhost:8200/12/`.

### Node.js 20

Optional chaining (`a?.b?.c ?? default`) como **runbook codificado en el lenguaje** — `distributed` evita el crash que sufre legacy con acceso ciego a estructuras anidadas. `share-knowledge` sube `coverage` y baja `mttr_min` de forma medible. Ver [`node/README.md`](node/README.md). Modo aislado: puerto `8212`. Hub: `http://localhost:8300/12/`.

### Java 21

`Optional<T>` + `map`/`flatMap`/`orElse` como runbook codificado en el sistema de tipos. El crash legacy no es falla de Java — es falla de no usar las herramientas que Java ya ofrece. `record Owner/Incident` inmutables; `AtomicInteger` para coverage y bus_factor. Ver [`java/README.md`](java/README.md). Modo aislado: puerto `8412`. Hub: `http://localhost:8400/12/`.

### .NET (espacio de crecimiento)

Estructura dockerizada lista; sin paridad funcional todavía.

---

## ⚖️ Trade-offs

- **Documentar bien toma tiempo sostenido** — no es una semana, es un hábito
- Modularizar sin criterio puede **fragmentar de más**
- La transferencia de conocimiento **requiere hábito, no solo archivos** — los archivos viejos engañan

---

## 💼 Valor de negocio

Reduce **riesgo organizacional**, mejora continuidad y hace al producto más sostenible a largo plazo. El indicador honesto: ¿qué pasa cuando la persona clave toma 2 semanas de vacaciones? Si la respuesta es "todo bien", el bus factor subió. Si la respuesta es "esperamos a que vuelva", la deuda sigue. Lab demuestra esa diferencia con métricas.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8 | `OPERATIVO` (runbook checks + share-knowledge) |
| 🐍 Python 3.12 | `OPERATIVO` (try/except + dict owners) |
| 🟢 Node.js 20 | `OPERATIVO` (optional chaining `?.` como runbook codificado) |
| ☕ Java 21 | `OPERATIVO` (`Optional<T>` + `map/flatMap/orElse` + `record` types) |
| 🔵 .NET 8 | 🔧 Estructura lista |

---

## 🚀 Cómo levantar

**Modo hub (recomendado):**
```bash
docker compose -f compose.root.yml    up -d --build && curl http://localhost:8100/12/health   # PHP
docker compose -f compose.python.yml  up -d --build && curl http://localhost:8200/12/health   # Python
docker compose -f compose.nodejs.yml  up -d --build && curl http://localhost:8300/12/health   # Node
docker compose -f compose.java.yml    up -d --build && curl http://localhost:8400/12/health   # Java
```

**Demostrar el bus factor (ejemplo Java):**
```bash
# Legacy: owner ausente → NullPointerException → crashed, mttr 120 min
curl "http://localhost:8400/12/incident-legacy?scenario=owner_absent&runbook=db_failover"

# Distributed: Optional<T> + orElse → degradacion controlada, mttr 35-50 min
curl "http://localhost:8400/12/incident-distributed?scenario=owner_absent&runbook=db_failover"

# Compartir conocimiento sube cobertura y bus_factor
curl "http://localhost:8400/12/share-knowledge?owner=bob&runbook=db_failover"
curl http://localhost:8400/12/diagnostics/summary    # coverage 45, bus_factor 2

# Nuevo incidente con owner_absent: ahora hay alterno → mttr aún más bajo
curl "http://localhost:8400/12/incident-distributed?scenario=owner_absent&runbook=db_failover"
```

---

## 📚 Lectura recomendada

| Documento | Qué cubre |
| --- | --- |
| [`comparison.md`](comparison.md) | Comparativa multi-stack con runbook codificado por lenguaje |
| [`docs/postmortem.md`](docs/postmortem.md) | Postmortem del incidente que motivó el caso |
| [`docs/context.md`](docs/context.md) | Por qué el bus factor no es solo problema de RR.HH. |
| [`docs/symptoms.md`](docs/symptoms.md) | Cómo se ve la dependencia tribal en el oncall |
| [`docs/root-causes.md`](docs/root-causes.md) | Las 4 causas más frecuentes |
| [`docs/solution-options.md`](docs/solution-options.md) | Documentación accionable, shadowing, modularización |
| [`docs/trade-offs.md`](docs/trade-offs.md) | Lo que cuesta sostener la cultura |
| [`docs/business-value.md`](docs/business-value.md) | Impacto en continuidad y rotación |

---

## 📁 Estructura del caso

```
12-single-point-of-knowledge-and-operational-risk/
├── README.md                    ← este archivo
├── comparison.md                ← comparativa multi-stack
├── compose.compare.yml          ← los 4 stacks juntos
├── docs/                        ← análisis + postmortem
├── shared/                      ← assets compartidos
├── 🐘 php/                      ← `OPERATIVO` — runbook checks + share-knowledge
├── 🐍 python/                   ← `OPERATIVO` — try/except + dict owners
├── 🟢 node/                     ← `OPERATIVO` — optional chaining como runbook
├── ☕ java/                     ← `OPERATIVO` — Optional<T> + record types
└── 🔵 dotnet/                   ← 🔧 estructura lista
```
