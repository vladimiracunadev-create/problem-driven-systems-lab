# 🌩️ Caso 13 — Cache stampede y thundering herd

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-success)](php/README.md)
[![Stacks](https://img.shields.io/badge/Stacks-7%20operativos%20%C2%B7%20PHP%20%C2%B7%20Python%20%C2%B7%20Node%20%C2%B7%20Java%20%C2%B7%20.NET%20%C2%B7%20Go%20%C2%B7%20Rust-blue)](../../docs/languages/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Rendimiento-blue)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda viven en el link de arriba y en `comparison.md`.

---

## 🔍 Qué problema representa

Una clave de cache caliente expira y, en ese instante, **todos** los requests que la estaban usando encuentran el hueco a la vez. Ninguno sabe que los otros existen, así que todos van al origen a recalcular exactamente el mismo valor.

Es el fallo que más se parece a una denegación de servicio hecha por uno mismo. El sistema funciona perfecto durante horas y cae en el segundo exacto en que la cache deja de proteger a la base — normalmente de madrugada, cuando un TTL fijo puesto en un deploy hace seis meses vence para mil claves al mismo tiempo.

Lo que lo vuelve difícil de diagnosticar: **la cache estaba haciendo su trabajo**. El hit rate del dashboard dice 99%. Lo que el dashboard no muestra es cuántos recálculos simultáneos recibe el origen en el 1% restante.

---

## ⚠️ Síntomas típicos

- La base cae **90 segundos exactos** a las 03:00, todas las noches, sin que suba el tráfico
- Hit rate de cache al 99% y aun así picos de miles de consultas idénticas contra el origen
- p99 que se dispara en **escalón**, no en rampa: de 20 ms a 4 segundos en un solo intervalo
- Reiniciar el servicio de cache **provoca** la caída en vez de arreglarla
- Todas las claves de un mismo tipo expiran en el mismo minuto

---

## 🧩 Causas frecuentes

- **Ausencia de single-flight**: nada coordina a los N llamadores que ven el mismo hueco
- **TTL fijo sin jitter**: las claves cargadas juntas expiran juntas
- **Un solo estado de validez**: sin soft TTL, alguien tiene que esperar al origen sí o sí
- **Lock sin double check**: el intento de arreglo más común, y no cambia nada medible

---

## 🔬 Estrategia de diagnóstico

- Contar **recálculos**, no requests: la métrica es `origin_computations`
- Mirar la **distribución de expiraciones**: un histograma de `expires_at` debería verse plano, no como un pico
- Buscar la **ventana check-then-act** entre leer la cache y escribirla
- Verificar que el lock tenga **relectura adentro**

---

## 💡 Opciones de solución

- **Single-flight**: un solo recálculo, el resto se cuelga del mismo resultado
- **TTL con jitter** (`base ± 25%`): desincroniza expiraciones masivas
- **Soft TTL + refresh por uno solo**: nadie espera al origen
- **Lock distribuido** cuando hay varias réplicas: es lo único que lleva el número a 1 de verdad

---

## 🗺️ Diagrama — qué recibe el origen en cada variante

```text
  Naive (16 llamadores, clave recién expirada):

    caller 1 ──┐
    caller 2 ──┤
    caller 3 ──┼──▶ [ cache: MISS ] ──▶ origen ×16   ← 16 recálculos idénticos
       ...     │                          │
    caller 16 ─┘                          ▼
                                    pool agotado

      origin_computations = 16 · stampede_depth = 16 · coalesced_waiters = 0


  Single-flight (mismos 16 llamadores):

    caller 1 ──▶ [ MISS ] ──▶ registra vuelo ──▶ double check ──▶ origen ×1
                                   │
    caller 2..16 ──▶ [ MISS ] ──▶ encuentran el vuelo ──▶ esperan ──┘
                                                                    │
                                                                    ▼
                                                          mismo valor para los 16

      origin_computations = 1 · stampede_depth = 1 · coalesced_waiters = 15
```

---

## 🏗️ Implementación actual

Los siete stacks exponen el mismo contrato: `/cache-naive` y `/cache-singleflight` sobre la misma ráfaga, con `origin_computations` como métrica central.

### ✅ PHP 8.3

Sin heap compartido entre requests, el single-flight vive en el almacenamiento: `flock()` exclusivo más **double-checked locking**. Es el stack que no puede esconder el paso que los otros seis sí pueden omitir. Ver [`php/README.md`](php/README.md). Modo aislado: puerto `8113`.

### 🐍 Python 3.12

Dict de vuelos en curso protegido por `Lock`, con un `threading.Event` por clave. El líder lo publica antes de calcular; los seguidores hacen `wait()`. Incluye una barrera de dos fases para que el GIL no produzca un falso verde. Ver [`python/README.md`](python/README.md). Modo aislado: `8313`. Hub: `http://localhost:8200/13/`.

### 🟢 Node.js 22

`Map<key, Promise>`: la Promise **es** el single-flight. Tres líneas, y el orden entre el `Map.set` y el primer `await` es toda la garantía. Ver [`node/README.md`](node/README.md). Modo aislado: `8213`. Hub: `http://localhost:8300/13/`.

### ☕ Java 21

`ConcurrentHashMap.computeIfAbsent` es atómico por clave: no hay ventana check-then-act que ordenar a mano. El trabajo caro corre en otro executor para no dejar el bin tomado. Ver [`java/README.md`](java/README.md). Modo aislado: `8413`. Hub: `http://localhost:8400/13/`.

### 🔵 .NET 8

`GetOrAdd` **no** garantiza fábrica única — la garantía la aporta `Lazy<Task<T>>` con `ExecutionAndPublication`. Es el contraste directo con Java. Ver [`dotnet/README.md`](dotnet/README.md). Modo aislado: `8513`. Hub: `http://localhost:8500/13/`.

### 🐹 Go 1.23

`singleflight` escrito a mano en 25 líneas de stdlib: `sync.WaitGroup` usado al revés — el líder hace `Add(1)`, los seguidores `Wait()`. Ver [`go/README.md`](go/README.md). Modo aislado: `8613`. Hub: `http://localhost:8600/13/`.

### 🦀 Rust 1.83

Sin `Future` ejecutable en la `std`, el patrón se construye con `Mutex` + `Condvar` + `wait_while`. El `Arc<Flight>` obligatorio impide el use-after-remove que en los otros seis es responsabilidad del programador. Ver [`rust/README.md`](rust/README.md). Modo aislado: `8713`. Hub: `http://localhost:8700/13/`.

---

## ⚖️ Trade-offs

- **Single-flight serializa**: si el origen es lento y la clave muy caliente, los que esperan forman cola. El soft TTL es lo que evita que se note.
- **Servir stale es una decisión de producto**, no de ingeniería: la ventana se define con el dueño del dato.
- **Coordinar dentro del proceso no alcanza con N réplicas**: 20 recálculos en vez de 2000 es mejor, pero no es 1.
- **El lock agrega un punto de falla**: mal liberado, bloquea la clave hasta que expire su propio TTL.

---

## 💼 Valor de negocio

Convierte una caída nocturna recurrente e inexplicable en un número que se puede mostrar en una reunión. El indicador honesto no es el hit rate —que puede marcar 99,9% mientras el sistema se cae— sino **cuántos recálculos simultáneos recibe el origen cuando la cache deja de proteger**. Para infraestructura: menos capacidad reservada «por las dudas». Para el negocio: una franja horaria que deja de ser frágil.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8.3 | `OPERATIVO` (`flock` + double-checked locking) |
| 🐍 Python 3.12 | `OPERATIVO` (dict de vuelos + `threading.Event`) |
| 🟢 Node.js 22 | `OPERATIVO` (`Map<key, Promise>`) |
| ☕ Java 21 | `OPERATIVO` (`computeIfAbsent` atómico + `CompletableFuture`) |
| 🔵 .NET 8 | `OPERATIVO` (`Lazy<Task<T>>` con `ExecutionAndPublication`) |
| 🐹 Go 1.23 | `OPERATIVO` (`singleflight` a mano con `sync.WaitGroup`) |
| 🦀 Rust 1.83 | `OPERATIVO` (`Arc<Flight>` con `Mutex` + `Condvar`) |

---

## 🚀 Cómo levantar

**Modo hub (recomendado):**
```bash
docker compose -f compose.root.yml    up -d --build && curl http://localhost:8100/13/health   # PHP
docker compose -f compose.python.yml  up -d --build && curl http://localhost:8200/13/health   # Python
docker compose -f compose.nodejs.yml  up -d --build && curl http://localhost:8300/13/health   # Node
docker compose -f compose.go.yml      up -d --build && curl http://localhost:8600/13/health   # Go
```

**Ver la estampida y su corrección (ejemplo Go):**
```bash
# 16 llamadores sobre una clave fría: el origen recibe los 16
curl "http://localhost:8600/13/cache-naive?key=k&concurrency=16&cost=40"

curl http://localhost:8600/13/reset-lab

# misma ráfaga con single-flight: origin_computations = 1, coalesced_waiters = 15
curl "http://localhost:8600/13/cache-singleflight?key=k&concurrency=16&cost=40"

# el estado de la cache, con soft TTL, hard TTL y el jitter aplicado
curl http://localhost:8600/13/cache/state
```

**Los siete stacks a la vez:**
```bash
docker compose -f cases/13-cache-stampede-and-thundering-herd/compose.compare.yml up -d --build
```

---

## 📚 Lectura recomendada

| Documento | Qué cubre |
| --- | --- |
| [`comparison.md`](comparison.md) | Comparativa multi-stack con el código real de cada primitiva y el ranking de fit |
| [`docs/postmortem.md`](docs/postmortem.md) | La caída de 94 segundos a las 03:00 que motiva el caso |
| [`docs/context.md`](docs/context.md) | Por qué un hit rate del 99% no protege de esto |
| [`docs/symptoms.md`](docs/symptoms.md) | Cómo se ve una estampida en los gráficos |
| [`docs/root-causes.md`](docs/root-causes.md) | Las cinco causas, y por qué hacen falta tres a la vez |
| [`docs/solution-options.md`](docs/solution-options.md) | Single-flight, jitter, soft TTL, precalentado, lock distribuido |
| [`docs/trade-offs.md`](docs/trade-offs.md) | Qué cuesta serializar y qué cuesta servir stale |
| [`docs/business-value.md`](docs/business-value.md) | El número que se lleva a una reunión |

---

## 📁 Estructura del caso

```
13-cache-stampede-and-thundering-herd/
├── README.md                    ← este archivo
├── comparison.md                ← comparativa multi-stack
├── compose.compare.yml          ← los 7 stacks juntos
├── docs/                        ← análisis + postmortem
├── shared/                      ← assets compartidos
├── 🐘 php/                      ← `OPERATIVO` — flock + double-checked locking
├── 🐍 python/                   ← `OPERATIVO` — dict de vuelos + threading.Event
├── 🟢 node/                     ← `OPERATIVO` — Map<key, Promise>
├── ☕ java/                     ← `OPERATIVO` — computeIfAbsent atómico
├── 🔵 dotnet/                   ← `OPERATIVO` — Lazy<Task<T>> ExecutionAndPublication
├── 🐹 go/                       ← `OPERATIVO` — singleflight a mano con WaitGroup
└── 🦀 rust/                     ← `OPERATIVO` — Arc<Flight> con Mutex + Condvar
```
