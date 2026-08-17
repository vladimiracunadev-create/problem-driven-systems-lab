# 🚰 Caso 14 — Agotamiento del pool de conexiones

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-success)](php/README.md)
[![Stacks](https://img.shields.io/badge/Stacks-7%20operativos%20%C2%B7%20PHP%20%C2%B7%20Python%20%C2%B7%20Node%20%C2%B7%20Java%20%C2%B7%20.NET%20%C2%B7%20Go%20%C2%B7%20Rust-blue)](../../docs/languages/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Rendimiento-blue)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda viven en el link de arriba y en `comparison.md`.

---

## 🔍 Qué problema representa

«Could not get connection from pool.» El error aparece con tráfico **moderado**, no en un pico. La base está sana, el CPU bajo, y aun así los requests se acumulan esperando una conexión que nunca llega.

Lo que engaña es que el pool **no está ocupado: está vacío**. Son dos estados distintos que la misma métrica muestra igual. Un pool ocupado se libera solo cuando terminan las queries en vuelo. Un pool vacío por fuga no se libera nunca, porque las conexiones que faltan no las tiene nadie — se perdieron en un camino de excepción donde no había `finally`.

La segunda mitad del problema es que **esperar no tiene límite**. Sin timeout de adquisición, el que llega tarde no falla: se queda. El sistema deja de responder sin que ningún proceso muera y sin que ninguna alerta de error dispare, porque técnicamente no falló nada.

---

## ⚠️ Síntomas típicos

- «Could not get connection from pool» con **tráfico moderado**, no en un pico
- La base sana: CPU bajo, pocas conexiones activas, sin queries lentas
- El error aparece **más seguido con los días** y desaparece al reiniciar
- Requests que no fallan ni responden: se cuelgan hasta el timeout del cliente
- **El p99 desaparece del gráfico** en vez de dispararse
- Conexiones disponibles que bajan en escalones y nunca vuelven a subir

---

## 🧩 Causas frecuentes

- **Devolución solo en el camino feliz**: el `release()` después del trabajo, no en un `finally`
- **Sin timeout de adquisición**: convierte «no hay capacidad» en «este request no responde nunca»
- **Pool dimensionado por intuición** en vez de por la ley de Little
- **Queries más lentas de lo previsto**: el pool no cambió, cambió el tiempo de servicio
- **Transacciones abiertas** que vuelven al pool con estado sucio

---

## 🔬 Estrategia de diagnóstico

- Contar **adquisiciones contra devoluciones**: `acquired - released`. Si crece, hay fuga
- Distinguir **pool ocupado de pool vacío**: `available == 0` con `leaked > 0` es fuga, no saturación
- Buscar **cada camino de salida** entre el `acquire` y el `release`
- Verificar que la adquisición tenga **deadline**
- Dimensionar con **la ley de Little**: `throughput × tiempo_de_servicio + buffer`

---

## 💡 Opciones de solución

- **Devolución garantizada por el lenguaje**: `try-with-resources`, `using`, `defer`, `finally`, `Drop`, context manager
- **Timeout de adquisición** con 503 + `Retry-After` en vez de espera infinita
- **Dimensionado por ley de Little** en vez de por intuición
- **Métrica `acquired - released`** alertada antes de que el pool se agote

Las dos primeras no son alternativas: una evita la fuga, la otra limita el daño de la saturación legítima.

---

## 🗺️ Diagrama — qué pasa con un pool de 4 y 24 requests

```text
  Leaky (sin finally, sin deadline):

    pool: [1][2][3][4]
      ↓ 7 de 24 queries lanzan excepción
    pool: [ ][ ][ ][ ]        ← 4 conexiones perdidas, nadie las tiene
      ↓
    requests 13..24 ──▶ esperan una conexión que ya no existe
                        (hilo bloqueado / Promise sin resolver)

      leaked = 4 · hung = 12 · pool_available_after = 0/4 · wall = 2000 ms


  Managed (finally + deadline de 200 ms):

    pool: [1][2][3][4]
      ↓ las mismas 7 excepciones
    pool: [1][2][3][4]        ← todas vuelven: el finally corre igual
      ↓
    requests 13..24 ──▶ toman una conexión libre y siguen

      leaked = 0 · hung = 0 · pool_available_after = 4/4 · wall = 155 ms
```

---

## 🏗️ Implementación actual

Los siete stacks exponen el mismo contrato: `/pool-leaky` y `/pool-managed` sobre la misma carga, con `leaked` como métrica central.

### ✅ PHP 8.3

`finally` garantiza la devolución en todos los caminos, incluido el `continue` del `catch`. Documenta además la versión PHP real del problema: `max_children` de FPM por conexiones `PDO::ATTR_PERSISTENT` contra el `max_connections` del motor. Ver [`php/README.md`](php/README.md). Modo aislado: `8114`.

### 🐍 Python 3.12

`queue.Queue(maxsize=N)` **es** el pool, y `@contextmanager` aporta la disciplina de devolverlo. Es de los pocos casos donde el GIL no molesta: `sleep` lo libera. Ver [`python/README.md`](python/README.md). Modo aislado: `8314`. Hub: `http://localhost:8200/14/`.

### 🟢 Node.js 22

El modo de falla más silencioso del set: el que espera no es un hilo sino una Promise que nadie va a resolver. `AbortSignal.timeout()` es lo único que le da un final observable. Ver [`node/README.md`](node/README.md). Modo aislado: `8214`. Hub: `http://localhost:8300/14/`.

### ☕ Java 21

`ArrayBlockingQueue` —la base de HikariCP— más `Lease implements AutoCloseable`. El compilador genera el `finally` de try-with-resources para todos los caminos de salida. Ver [`java/README.md`](java/README.md). Modo aislado: `8414`. Hub: `http://localhost:8400/14/`.

### 🔵 .NET 8

`SemaphoreSlim.WaitAsync(timeout)` devuelve `false` en vez de lanzar, separando «no había conexión» de «la conexión falló». Y `using var` hace que el código correcto sea más corto que el incorrecto. Ver [`dotnet/README.md`](dotnet/README.md). Modo aislado: `8514`. Hub: `http://localhost:8500/14/`.

### 🐹 Go 1.23

El canal bufferizado **es** el pool: contenedor y límite en una estructura. El `select` con temporizador es la misma primitiva de los casos 04, 08 y 09. El límite honesto: `defer` hay que acordarse de escribirlo. Ver [`go/README.md`](go/README.md). Modo aislado: `8614`. Hub: `http://localhost:8600/14/`.

### 🦀 Rust 1.83

`impl Drop` devuelve la conexión sin que haya línea que recordar — ni siquiera durante un `panic`. La variante con fuga tuvo que escribirse con `std::mem::forget`, la única forma de perder un recurso en Rust seguro. Ver [`rust/README.md`](rust/README.md). Modo aislado: `8714`. Hub: `http://localhost:8700/14/`.

---

## ⚖️ Trade-offs

- **Un pool más grande no es gratis**: cada conexión ociosa cuenta contra el `max_connections` de la base, multiplicada por réplicas
- **El timeout convierte esperas en errores**: el número de 5xx *sube* al aplicarlo, y hay que explicarlo antes
- **Fallar rápido traslada la decisión al cliente**: sin backoff del otro lado, alimenta una tormenta de reintentos ([caso 04](../04-timeout-chain-and-retry-storms/README.md))
- **`finally` devuelve la conexión, no la limpia**: una transacción sin cerrar vuelve al pool igual

---

## 💼 Valor de negocio

Convierte una indisponibilidad que nadie sabe explicar —el servicio no responde, la base está bien— en **dos contadores** que cualquiera puede leer: cuántas conexiones se pidieron y cuántas volvieron. Elimina el reinicio preventivo como parte del runbook, y reemplaza el dimensionado por intuición con una fórmula sobre throughput medido.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8.3 | `OPERATIVO` (`finally` + nota sobre persistentes de FPM) |
| 🐍 Python 3.12 | `OPERATIVO` (`queue.Queue` como pool + `@contextmanager`) |
| 🟢 Node.js 22 | `OPERATIVO` (`AbortSignal.timeout` + `finally`) |
| ☕ Java 21 | `OPERATIVO` (`ArrayBlockingQueue` + try-with-resources) |
| 🔵 .NET 8 | `OPERATIVO` (`SemaphoreSlim.WaitAsync` + `using var`) |
| 🐹 Go 1.23 | `OPERATIVO` (canal bufferizado como pool + `select` + `defer`) |
| 🦀 Rust 1.83 | `OPERATIVO` (`impl Drop`; la fuga exige `mem::forget`) |

---

## 🚀 Cómo levantar

**Modo hub (recomendado):**
```bash
docker compose -f compose.rust.yml   up -d --build && curl http://localhost:8700/14/health   # Rust
docker compose -f compose.java.yml   up -d --build && curl http://localhost:8400/14/health   # Java
docker compose -f compose.go.yml     up -d --build && curl http://localhost:8600/14/health   # Go
```

**Ver la fuga y su corrección (ejemplo Java):**
```bash
# pool de 4, 24 requests, 25% de fallo: 4 conexiones perdidas, 12 requests colgadas
curl "http://localhost:8400/14/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25"

# el pool quedó en 0 de 4 y no se recupera
curl http://localhost:8400/14/pool/state

# misma carga con try-with-resources y deadline: leaked=0, pool 4/4, 13× más rápido
curl "http://localhost:8400/14/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25"

# el tamaño de pool que la ley de Little recomienda para ese throughput
curl http://localhost:8400/14/diagnostics/summary
```

**Los siete stacks a la vez:**
```bash
docker compose -f cases/14-connection-pool-exhaustion/compose.compare.yml up -d --build
```

---

## 📚 Lectura recomendada

| Documento | Qué cubre |
| --- | --- |
| [`comparison.md`](comparison.md) | Comparativa multi-stack con el código de cada garantía de devolución y el ranking de fit |
| [`docs/postmortem.md`](docs/postmortem.md) | Seis semanas reiniciando pods porque el reinicio funcionaba |
| [`docs/context.md`](docs/context.md) | Por qué un pool vacío y uno ocupado se ven igual |
| [`docs/symptoms.md`](docs/symptoms.md) | Cómo se ve una fuga en los gráficos |
| [`docs/root-causes.md`](docs/root-causes.md) | Las cinco causas, y por qué hacen falta dos a la vez |
| [`docs/solution-options.md`](docs/solution-options.md) | Devolución garantizada, deadline, ley de Little |
| [`docs/trade-offs.md`](docs/trade-offs.md) | Qué cuesta agrandar el pool y qué cuesta fallar rápido |
| [`docs/business-value.md`](docs/business-value.md) | Los dos contadores que reemplazan al reinicio preventivo |

---

## 📁 Estructura del caso

```
14-connection-pool-exhaustion/
├── README.md                    ← este archivo
├── comparison.md                ← comparativa multi-stack
├── compose.compare.yml          ← los 7 stacks juntos
├── docs/                        ← análisis + postmortem
├── shared/                      ← assets compartidos
├── 🐘 php/                      ← `OPERATIVO` — finally + nota sobre persistentes FPM
├── 🐍 python/                   ← `OPERATIVO` — queue.Queue + @contextmanager
├── 🟢 node/                     ← `OPERATIVO` — AbortSignal.timeout + finally
├── ☕ java/                     ← `OPERATIVO` — ArrayBlockingQueue + try-with-resources
├── 🔵 dotnet/                   ← `OPERATIVO` — SemaphoreSlim.WaitAsync + using var
├── 🐹 go/                       ← `OPERATIVO` — canal como pool + select + defer
└── 🦀 rust/                     ← `OPERATIVO` — impl Drop; fugar exige mem::forget
```
