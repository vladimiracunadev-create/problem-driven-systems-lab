# 🚚 Caso 06 — Pipeline roto y entrega frágil

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-success)](php/README.md)
[![Stacks](https://img.shields.io/badge/Stacks-PHP%20%C2%B7%20Python%20%C2%B7%20Node%20%C2%B7%20Java-blue)](#-stacks-disponibles)
[![Categoría](https://img.shields.io/badge/Categoría-Entrega-purple)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda viven en el link de arriba y en `comparison.md`.

---

## 🔍 Qué problema representa

El software **funciona en desarrollo**, pero falla al desplegar, promover cambios o revertir incidentes con seguridad. El gap entre "anda en mi máquina" y "anda en prod" se vuelve incidente operativo recurrente.

**Un delivery frágil es deuda estructural cara**: aumenta riesgo operativo, detiene equipos enteros durante un deploy fallido, hace que cada cambio sea más caro que el anterior, y deja a las personas que aprueban deploys con miedo a aprobar. Eso último es lo peor — el miedo se convierte en bloqueo cultural.

---

## ⚠️ Síntomas típicos

- Cambios aparentemente menores **rompen despliegues** sin explicación inmediata
- Ambientes diferentes entre sí (staging tiene `X`, prod tiene `Y`, dev tiene `Z`)
- Rollback poco claro o **inexistente** ("¿cómo vuelvo a la versión anterior?")
- Dependencia de **una persona** para publicar cambios (bus factor = 1)
- Smoke tests que pasan **después** del impacto al cliente

---

## 🧩 Causas frecuentes

- Pipelines poco versionados o **acoplados al servidor** (config en Jenkins UI, no en git)
- Variables y secretos **sin control consistente** (cada ambiente con su propio set rotado a mano)
- Pruebas insuficientes antes del deploy — solo unit tests, sin smoke ni canary
- **Drift de configuración** entre ambientes (alguien arregló prod a mano y no lo propagó)

---

## 🔬 Estrategia de diagnóstico

- Revisar puntos manuales del flujo de deploy y mapearlos como riesgo
- Mapear dependencias por ambiente (qué consume de dónde en staging vs prod)
- Analizar **fallas previas de despliegue y rollback** — ¿se documentaron?
- Verificar reproducibilidad local → CI → producción con el mismo artefacto

---

## 💡 Opciones de solución

- **Estandarizar pipelines y artefactos**: una sola receta, un solo binario que viaja entre ambientes
- **Automatizar validaciones previas** al deploy (preflight, smoke, contract tests)
- **Definir estrategia de rollback** y feature flags — saber cómo se vuelve atrás antes de avanzar
- **Separar configuración, secretos y código** (12-factor app, secrets manager, env por ambiente)

---

## 🏗️ Implementación actual

### ✅ PHP 8

`deploy-legacy` aplica el cambio directo sin preflight y deja el ambiente en `degraded` cuando el scenario es malo. `deploy-controlled` ejecuta preflight → smoke → promote, o rollback automático al `before.version` si smoke falla. `environments`, `deployments` y `diagnostics/summary` muestran el contraste. Ver [`php/README.md`](php/README.md). Modo aislado: puerto `816`.

### Python 3.12

Misma lógica con stdlib. `dict` por ambiente, lista de deployments para historial. Sin frameworks externos. Ver [`python/README.md`](python/README.md). Modo aislado: puerto `836`. Hub: `http://localhost:8200/06/`.

### Node.js 20

`AbortController` para cancelación cooperativa entre pasos del pipeline — si el cliente desconecta o el deadline se vence, los pasos en curso reciben la señal y limpian limpio. Ver [`node/README.md`](node/README.md). Modo aislado: puerto `826`. Hub: `http://localhost:8300/06/`.

### Java 21

`record EnvState(name, version, health)` y `record Deployment(at, variant, env, version, scenario, result)` **inmutables** — cada deploy crea instancias nuevas, no muta. Eso descarta una clase entera de bugs de concurrencia: el snapshot que captura `before` en preflight se mantiene aunque otro thread haga `put()` paralelo. `ConcurrentHashMap<String, EnvState>` por ambiente; state machine como guards explícitos (preflight → smoke → promote | rollback). Ver [`java/README.md`](java/README.md). Modo aislado: puerto `846`. Hub: `http://localhost:8400/06/`.

### .NET (espacio de crecimiento)

Estructura dockerizada lista; sin paridad funcional todavía.

---

## ⚖️ Trade-offs

- Más controles previos **pueden alargar el ciclo inicial** (un preflight tarda más que un deploy directo).
- Feature flags **exigen gobierno y limpieza** — si no se quitan, terminan como código muerto con ifs anidados.
- Infraestructura como código requiere **disciplina mantenida**: drift sucede solo si alguien arregla algo a mano fuera del flujo.

---

## 💼 Valor de negocio

Permite **publicar con menos riesgo**, reducir incidentes operativos y mejorar la velocidad real de entrega. La métrica indirecta importante es **MTTR de un mal deploy**: si rollback es un comando confiable, los equipos arriesgan más en cambios chicos y avanzan más rápido. Si rollback es una odisea manual, todo el mundo espera. El lab deja visible esa diferencia.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8 | `OPERATIVO` (preflight + smoke + rollback automático) |
| 🐍 Python 3.12 | `OPERATIVO` (misma lógica con stdlib, dict por ambiente) |
| 🟢 Node.js 20 | `OPERATIVO` (`AbortController` para cancelación cooperativa entre pasos) |
| ☕ Java 21 | `OPERATIVO` (`record` types inmutables + state machine + `ConcurrentHashMap`) |
| 🔵 .NET 8 | 🔧 Estructura lista |

---

## 🚀 Cómo levantar

**Modo hub (recomendado):**
```bash
docker compose -f compose.root.yml    up -d --build && curl http://localhost:8100/06/health   # PHP
docker compose -f compose.python.yml  up -d --build && curl http://localhost:8200/06/health   # Python
docker compose -f compose.nodejs.yml  up -d --build && curl http://localhost:8300/06/health   # Node
docker compose -f compose.java.yml    up -d --build && curl http://localhost:8400/06/health   # Java
```

**Modo aislado:**
```bash
docker compose -f cases/06-broken-pipeline-and-fragile-delivery/php/compose.yml    up -d --build  # :816
docker compose -f cases/06-broken-pipeline-and-fragile-delivery/python/compose.yml up -d --build  # :836
docker compose -f cases/06-broken-pipeline-and-fragile-delivery/node/compose.yml   up -d --build  # :826
docker compose -f cases/06-broken-pipeline-and-fragile-delivery/java/compose.yml   up -d --build  # :846
```

**Reproducir un deploy roto y un rollback (ejemplo Java):**
```bash
# Legacy: deja prod en degraded
curl "http://localhost:8400/06/deploy-legacy?env=prod&version=v1.1.0&scenario=secret_drift"
curl http://localhost:8400/06/environments
#   {"envs":[{"name":"prod","version":"v1.1.0","health":"degraded"}, ...]}

# Reset + Controlled: rollback automatico, prod sigue en version previa
curl http://localhost:8400/06/reset-lab
curl "http://localhost:8400/06/deploy-controlled?env=prod&version=v1.1.0&scenario=secret_drift"
#   {"result":"rolled_back","current_version":"v1.0.0", ...}
curl http://localhost:8400/06/environments
#   {"envs":[{"name":"prod","version":"v1.0.0","health":"healthy"}, ...]}
```

---

## 📚 Lectura recomendada

| Documento | Qué cubre |
| --- | --- |
| [`comparison.md`](comparison.md) | Comparativa multi-stack (PHP · Python · Node.js · Java) con snippets y diferencias por runtime |
| [`docs/context.md`](docs/context.md) | Por qué delivery frágil es deuda estructural cara |
| [`docs/symptoms.md`](docs/symptoms.md) | Cómo se ve un pipeline roto en oncall |
| [`docs/diagnosis.md`](docs/diagnosis.md) | Mapeo de puntos manuales y drift |
| [`docs/root-causes.md`](docs/root-causes.md) | Las 4 causas más frecuentes |
| [`docs/solution-options.md`](docs/solution-options.md) | Opciones ordenadas por inversión inicial |
| [`docs/trade-offs.md`](docs/trade-offs.md) | Lo que cuesta cada estrategia |
| [`docs/business-value.md`](docs/business-value.md) | Impacto en velocidad real de entrega |

---

## 📁 Estructura del caso

```
06-broken-pipeline-and-fragile-delivery/
├── README.md                    ← este archivo
├── comparison.md                ← comparativa multi-stack PHP · Python · Node · Java
├── compose.compare.yml          ← levanta los 4 stacks juntos
├── docs/                        ← análisis problem-driven (8 documentos)
├── shared/                      ← assets compartidos
├── 🐘 php/                      ← `OPERATIVO` — preflight + smoke + rollback
├── 🐍 python/                   ← `OPERATIVO` — stdlib + dict por ambiente
├── 🟢 node/                     ← `OPERATIVO` — AbortController cooperativo
├── ☕ java/                     ← `OPERATIVO` — record + state machine
└── 🔵 dotnet/                   ← 🔧 estructura lista
```
