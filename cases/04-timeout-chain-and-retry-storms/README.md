# ⏱️ Caso 04 — Cadena de timeouts y tormentas de reintentos

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-success)](php/README.md)
[![Stacks](https://img.shields.io/badge/Stacks-PHP%20%C2%B7%20Python%20%C2%B7%20Node%20%C2%B7%20Java-blue)](#-stacks-disponibles)
[![Categoría](https://img.shields.io/badge/Categoría-Resiliencia-orange)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda viven en el link de arriba y en `comparison.md`.

---

## 🔍 Qué problema representa

Una integración lenta o inestable dispara **reintentos sin control**, satura threads o workers, y propaga la falla aguas arriba. Lo que empieza como una dependencia con 200 ms extra de latencia termina derribando al servicio que la llama — y a los que dependen de él.

**Es un problema clásico de sistemas distribuidos:** una falla parcial se transforma en caída global cuando los timeouts no están alineados, no hay backoff, y no existe un mecanismo para "dejar de tocar a quien ya está caído".

---

## ⚠️ Síntomas típicos

- Picos de latencia en cascada entre servicios
- Reintentos simultáneos que multiplican la carga sobre el proveedor caído
- Colas creciendo, workers saturados, request queue depth disparado
- Tiempo de respuesta impredecible — bimodal: rápido o catastrófico

---

## 🧩 Causas frecuentes

- Timeouts mal alineados entre capas (cliente espera 30s, gateway 10s, backend 5s)
- Retry sin backoff ni circuit breaker — cada intento mete más presión
- Dependencias externas frágiles tratadas como confiables
- Falta de aislamiento (un pool de threads compartido para llamadas críticas y secundarias)

---

## 🔬 Estrategia de diagnóstico

- Trazar el flujo extremo a extremo midiendo tiempo por hop
- Comparar timeouts configurados en cada capa — alinear contratos
- Medir ratio de reintentos y causa raíz por dependencia (timeout vs 5xx vs cierre de conexión)
- Simular fallas parciales y latencias inducidas en pre-prod antes que produccion lo descubra

---

## 💡 Opciones de solución

- **Alinear timeouts por contrato**: el deadline más corto manda; las capas superiores no esperan más.
- **Backoff + jitter + circuit breaker**: dejar de golpear a quien ya está caído.
- **Aislar dependencias** con colas o fallback cacheado.
- **Budgets de error y degradación controlada**: cuando se rompe el budget, devolver respuesta degradada en vez de fallar entero.

---

## 🏗️ Implementación actual

### ✅ PHP 8 (timeout + retry + circuit breaker + fallback)

`quote-legacy` amplifica el incidente con más intentos y más espera. `quote-resilient` aplica timeout corto, backoff, circuit breaker en estado persistente y fallback cacheado. `dependency/state`, `incidents` y `diagnostics/summary` dejan visible el costo operacional de cada postura. Ver [`php/README.md`](php/README.md). Modo aislado: puerto `814`.

### Python 3.12

Misma lógica que PHP con `stdlib`: `time.sleep` para latencias, `threading.Lock` para el breaker, `time.time` para cooldown. Sin requirements externos. Ver [`python/README.md`](python/README.md). Modo aislado: puerto `834`. Hub Python: `http://localhost:8200/04/`.

### Node.js 20

`AbortController` y `AbortSignal` como timeout **cooperativo**: el handler decide si abortar al recibir la señal. Circuit breaker en memoria con tres estados (`closed`/`open`/`half_open`) y reapertura automática tras cooldown. Ver [`node/README.md`](node/README.md). Modo aislado: puerto `824`. Hub Node: `http://localhost:8300/04/`.

### Java 21

`CompletableFuture.orTimeout(Duration)` como deadline a nivel future (cancela cooperativamente al pasar el plazo). `AtomicReference<BreakerState>` con CAS para transiciones `closed → open → half_open` sin lock global. `record BreakerState(state, failCount, openedAt)` inmutable evita race conditions de "leí state pero failCount era stale". Ver [`java/README.md`](java/README.md). Modo aislado: puerto `844`. Hub Java: `http://localhost:8400/04/`.

### .NET (espacio de crecimiento)

Estructura dockerizada lista; sin paridad funcional todavía.

---

## ⚖️ Trade-offs

- Más protección implica más estados y mayor complejidad operativa.
- Fallbacks pueden degradar precisión funcional (cotización cacheada vs cotización fresca).
- No todos los flujos toleran consistencia eventual — pagos críticos requieren consistencia fuerte aunque eso signifique fallar.

---

## 💼 Valor de negocio

Evita caídas en cascada y mejora resiliencia frente a terceros o componentes inestables. **Reduce el blast radius** de cualquier falla externa: una API caída deja de tirar al checkout entero. También ayuda a **diseñar SLOs realistas** — si depender de X significa heredar su disponibilidad, el lab lo deja visible.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8 | `OPERATIVO` (timeout corto + backoff + circuit breaker + fallback) |
| 🐍 Python 3.12 | `OPERATIVO` (`threading.Lock`, `time.time()` cooldown, stdlib pura) |
| 🟢 Node.js 20 | `OPERATIVO` (`AbortController`/`AbortSignal` cooperativo + CB en memoria) |
| ☕ Java 21 | `OPERATIVO` (`CompletableFuture.orTimeout` + `AtomicReference<BreakerState>` CAS) |
| 🔵 .NET 8 | 🔧 Estructura lista |

---

## 🚀 Cómo levantar

**Modo hub (recomendado — un puerto por lenguaje):**
```bash
# PHP — incluye DB del caso 01 y observabilidad
docker compose -f compose.root.yml up -d --build
curl http://localhost:8100/04/health

# Python
docker compose -f compose.python.yml up -d --build
curl http://localhost:8200/04/health

# Node.js
docker compose -f compose.nodejs.yml up -d --build
curl http://localhost:8300/04/health

# Java (casos 01-06)
docker compose -f compose.java.yml up -d --build
curl http://localhost:8400/04/health
```

**Modo aislado (un caso, un puerto):**
```bash
docker compose -f cases/04-timeout-chain-and-retry-storms/php/compose.yml    up -d --build  # :814
docker compose -f cases/04-timeout-chain-and-retry-storms/python/compose.yml up -d --build  # :834
docker compose -f cases/04-timeout-chain-and-retry-storms/node/compose.yml   up -d --build  # :824
docker compose -f cases/04-timeout-chain-and-retry-storms/java/compose.yml   up -d --build  # :844
```

**Reproducir un retry storm y la apertura del breaker (ejemplo Java):**
```bash
# 3 fallos consecutivos abren el breaker durante 5s
for i in 1 2 3; do curl -s "http://localhost:8400/04/quote-resilient?fail=on" | head -c 80; echo; done
curl http://localhost:8400/04/dependency/state    # {"state":"open", ...}
# proximo call retorna fallback inmediato sin tocar al provider
curl "http://localhost:8400/04/quote-resilient?fail=on"
```

---

## 📚 Lectura recomendada

| Documento | Qué cubre |
| --- | --- |
| [`comparison.md`](comparison.md) | Comparativa multi-stack (PHP · Python · Node.js · Java) con snippets de código y diferencias de runtime |
| [`docs/context.md`](docs/context.md) | Por qué este patrón es tan común en sistemas vivos |
| [`docs/symptoms.md`](docs/symptoms.md) | Cómo se ve un retry storm desde afuera |
| [`docs/diagnosis.md`](docs/diagnosis.md) | Estrategia ordenada para llegar a causa raíz |
| [`docs/root-causes.md`](docs/root-causes.md) | Las 4 causas más frecuentes que detonan este caso |
| [`docs/solution-options.md`](docs/solution-options.md) | Las opciones de mitigación, ordenadas por costo |
| [`docs/trade-offs.md`](docs/trade-offs.md) | Lo que se gana y lo que se pierde con cada decisión |
| [`docs/business-value.md`](docs/business-value.md) | Impacto operacional y de negocio |

---

## 📁 Estructura del caso

```
04-timeout-chain-and-retry-storms/
├── README.md                    ← este archivo
├── comparison.md                ← comparativa multi-stack PHP · Python · Node · Java
├── compose.compare.yml          ← levanta los 4 stacks juntos para comparar
├── docs/                        ← análisis problem-driven (8 documentos)
├── shared/                      ← assets compartidos del caso
├── 🐘 php/                      ← `OPERATIVO` — circuit breaker persistente
├── 🐍 python/                   ← `OPERATIVO` — stdlib + threading.Lock
├── 🟢 node/                     ← `OPERATIVO` — AbortController + CB en memoria
├── ☕ java/                     ← `OPERATIVO` — CompletableFuture.orTimeout + CAS
└── 🔵 dotnet/                   ← 🔧 estructura lista
```
