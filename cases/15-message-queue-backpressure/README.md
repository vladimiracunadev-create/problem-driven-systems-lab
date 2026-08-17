# 🌊 Caso 15 — Backpressure en colas de mensajes

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-success)](php/README.md)
[![Stacks](https://img.shields.io/badge/Stacks-7%20operativos%20%C2%B7%20PHP%20%C2%B7%20Python%20%C2%B7%20Node%20%C2%B7%20Java%20%C2%B7%20.NET%20%C2%B7%20Go%20%C2%B7%20Rust-blue)](../../docs/languages/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Resiliencia-orange)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda viven en el link de arriba y en `comparison.md`.

---

## 🔍 Qué problema representa

El productor va más rápido que el consumidor. La cola interna absorbe la diferencia sin quejarse, la memoria del proceso crece, y una madrugada el OOM killer lo mata. O peor: la cola tenía un límite silencioso y los mensajes se perdieron sin que nadie se enterara.

Lo que hace difícil el caso es que **la cola sin límite se ve bien en todas las métricas que la gente mira**: throughput alto, cero errores, cero descartes, productor que nunca espera. Las dos que lo delatan casi nunca están en el dashboard — la **profundidad** de la cola y la **edad del mensaje más viejo**.

Un sistema que procesa 1.000 mensajes por segundo con una cola de 400.000 está entregando resultados de hace siete minutos, y su gráfico de throughput no lo dice.

---

## ⚠️ Síntomas típicos

- Memoria que crece de forma **monótona** y OOM killer cada tantas horas
- Throughput alto y sin errores, pero resultados **minutos atrasados**
- La latencia del consumidor se ve sana: mide lo que tarda en procesar **uno**, no lo que ese uno esperó
- Mensajes que se pierden sin error, sin log y sin métrica
- Reiniciar «arregla» la memoria y **pierde todo lo que había en la cola**

---

## 🧩 Causas frecuentes

- **Cola sin capacidad por defecto**: en varios stacks «sin límite» es lo que sale si no se escribe nada
- **Sin señal de backpressure** hacia el productor, que sigue produciendo al mismo ritmo
- **Descarte silencioso**: cola acotada sin contador de rechazos
- **Confundir latencia del consumidor con latencia del mensaje**
- **DLQ sin dueño**: se elige porque «no frena ni pierde» y nadie define quién la mira

---

## 🔬 Estrategia de diagnóstico

- Graficar **profundidad**, no throughput
- Medir la **edad del mensaje más viejo**, no la latencia del consumidor
- Buscar `Queue()`, `CreateUnbounded()`, `ConcurrentLinkedQueue`, `mpsc::channel()` en el código
- Verificar que exista `messages_dropped_total`, **aunque valga cero**
- Preguntar **dónde está el freno**: si no está en la cola, está en el kernel, el broker o el balanceador

---

## 💡 Opciones de solución

| Política | Qué paga | Cuándo |
|---|---|---|
| **`block`** | latencia: la lentitud viaja aguas arriba | pagos, órdenes, auditoría |
| **`drop_oldest`** | datos: se pierden mensajes | telemetría, métricas, GPS |
| **`dead_letter`** | deuda operativa: alguien tiene que mirarla | importante pero no urgente, **con dueño definido** |

**No existe una cuarta opción gratis.** La cola sin límite parece serlo porque el costo llega después y de golpe.

---

## 🗺️ Diagrama — 120 mensajes, consumidor 3× más lento

```text
  Sin límite:

    productor ──▶ [ ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓ 120 ] ──▶ consumidor
                    la cola absorbe TODO                  (3x más lento)

      queue_depth_peak = 120 · oldest_msg_age = 280 ms · dropped = 0
      memoria: crece hasta el OOM · el throughput se ve perfecto


  Acotada a 32, política `block`:

    productor ──⏸──▶ [ ▓▓▓▓▓▓ 32 ] ──▶ consumidor
       se frena         acotada

      queue_depth_peak = 32 · oldest_msg_age = 80 ms · dropped = 0
      producer_blocked_ms = 218  ← el costo, ahora visible


  Acotada a 32, política `drop_oldest` / `dead_letter`:

    productor ──▶ [ ▓▓▓▓▓▓ 32 ] ──▶ consumidor
        │           acotada
        └──▶ 88 descartados  ó  88 a la DLQ  ← el costo, ahora contado
```

---

## 🏗️ Implementación actual

Los siete stacks exponen el mismo contrato: `/produce-unbounded` y `/produce-bounded` con las tres políticas, y `queue_depth_peak` más `oldest_msg_age_ms_peak` como métricas centrales.

### ✅ PHP 8.3

No tiene cola en proceso: el backpressure vive en el transporte (`listen.backlog` de FPM, `pm.max_children`, la DLQ del broker). Es el stack que mejor enseña que **el freno es una propiedad del sistema, no de la cola**. Ver [`php/README.md`](php/README.md). Modo aislado: `8115`.

### 🐍 Python 3.12

La política se elige en la firma de `put()`: bloquear, levantar `Full`, o esperar acotado. La API más explícita del set — y `Queue()` sin `maxsize` se escribe con menos caracteres que la acotada. Ver [`python/README.md`](python/README.md). Modo aislado: `8315`. Hub: `http://localhost:8200/15/`.

### 🟢 Node.js 22

Único stack donde el backpressure es parte del protocolo del runtime: `write()` devuelve `false` y `'drain'` avisa cuándo seguir. Y único donde **ignorarlo compila y pasa los tests**. Ver [`node/README.md`](node/README.md). Modo aislado: `8215`. Hub: `http://localhost:8300/15/`.

### ☕ Java 21

`BlockingQueue` codifica las tres políticas en `put`/`offer`/`offer(timeout)`, igual que las `RejectedExecutionHandler`. El contraste: `ConcurrentLinkedQueue` comparte interfaz y **no tiene capacidad**. Ver [`java/README.md`](java/README.md). Modo aislado: `8415`. Hub: `http://localhost:8400/15/`.

### 🔵 .NET 8

Único stack donde la política es un **enum del constructor**, decidido una vez para todo el sistema. Y el canal avisa cuando descarta, así que la pérdida silenciosa es difícil de escribir. Ver [`dotnet/README.md`](dotnet/README.md). Modo aislado: `8515`. Hub: `http://localhost:8500/15/`.

### 🐹 Go 1.23

**No existe el canal con buffer infinito**: la capacidad es parte del `make`. La versión con el bug hay que construirla a mano, con más código que la correcta. Ver [`go/README.md`](go/README.md). Modo aislado: `8615`. Hub: `http://localhost:8600/15/`.

### 🦀 Rust 1.83

El límite está en el **tipo**: `Sender<T>` sin capacidad contra `SyncSender<T>` acotado. Y `TrySendError::Full(T)` devuelve el mensaje rechazado adentro — justo lo que una DLQ necesita. Ver [`rust/README.md`](rust/README.md). Modo aislado: `8715`. Hub: `http://localhost:8700/15/`.

---

## ⚖️ Trade-offs

- **Bloquear propaga la lentitud, y puede ser correcto**: pero si el productor reintenta con timeout, se convierte en una tormenta ([caso 04](../04-timeout-chain-and-retry-storms/README.md))
- **Descartar necesita contarse o no existe**: el descarte silencioso es peor que la cola sin límite
- **La DLQ no resuelve: muda** — y si nadie la mira, es el [caso 20](../20-forgotten-dead-letter-queue/README.md)
- **Escalar consumidores mueve el cuello de botella** al recurso compartido: la base o el pool ([caso 14](../14-connection-pool-exhaustion/README.md))

---

## 💼 Valor de negocio

Convierte un reinicio inexplicable cada tantas horas —y una pérdida de datos que nadie sabía que existía— en una **decisión explícita y documentada** sobre qué se sacrifica cuando el sistema no da abasto. El indicador honesto no es el throughput, que se ve perfecto hasta el segundo antes del OOM, sino la profundidad de la cola y la edad de su mensaje más viejo.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8.3 | `OPERATIVO` (el freno vive en FPM y el broker, no en el lenguaje) |
| 🐍 Python 3.12 | `OPERATIVO` (`queue.Queue(maxsize=N)` + política en la firma de `put()`) |
| 🟢 Node.js 22 | `OPERATIVO` (`stream.Writable` con `highWaterMark` y `'drain'`) |
| ☕ Java 21 | `OPERATIVO` (`ArrayBlockingQueue` + `put`/`offer`/`offer(timeout)`) |
| 🔵 .NET 8 | `OPERATIVO` (`Channel.CreateBounded` + `BoundedChannelFullMode`) |
| 🐹 Go 1.23 | `OPERATIVO` (canal bufferizado + `select` con `default`) |
| 🦀 Rust 1.83 | `OPERATIVO` (`mpsc::sync_channel` + `TrySendError::Full(T)`) |

---

## 🚀 Cómo levantar

**Modo hub (recomendado):**
```bash
docker compose -f compose.go.yml     up -d --build && curl http://localhost:8600/15/health   # Go
docker compose -f compose.rust.yml   up -d --build && curl http://localhost:8700/15/health   # Rust
docker compose -f compose.dotnet.yml up -d --build && curl http://localhost:8500/15/health   # .NET
```

**Ver el problema y las tres políticas (ejemplo Go):**
```bash
# sin límite: la cola absorbe los 120 y el más viejo espera ~300 ms
curl "http://localhost:8600/15/produce-unbounded?messages=120&consume_ms=2"

# acotada, el productor se frena: profundidad 32, nada se pierde
curl "http://localhost:8600/15/produce-bounded?messages=120&capacity=32&policy=block&consume_ms=2"

# acotada, se descarta: el productor no se frena, pero se pierden 88
curl "http://localhost:8600/15/produce-bounded?messages=120&capacity=32&policy=drop_oldest"

# acotada, a la DLQ: ni se frena ni se pierde — pero alguien tiene que mirarla
curl "http://localhost:8600/15/produce-bounded?messages=120&capacity=32&policy=dead_letter"
curl "http://localhost:8600/15/dlq?limit=5"
```

**Los siete stacks a la vez:**
```bash
docker compose -f cases/15-message-queue-backpressure/compose.compare.yml up -d --build
```

---

## 📚 Lectura recomendada

| Documento | Qué cubre |
| --- | --- |
| [`comparison.md`](comparison.md) | Comparativa multi-stack con el código de cada primitiva y el ranking de fit |
| [`docs/postmortem.md`](docs/postmortem.md) | Cuatro meses subiendo el límite de memoria porque funcionaba |
| [`docs/context.md`](docs/context.md) | Por qué el throughput se ve perfecto hasta el OOM |
| [`docs/symptoms.md`](docs/symptoms.md) | Cómo se ve una cola sin freno en los gráficos |
| [`docs/root-causes.md`](docs/root-causes.md) | Las cinco causas, incluida la DLQ sin dueño |
| [`docs/solution-options.md`](docs/solution-options.md) | Las tres políticas y qué paga cada una |
| [`docs/trade-offs.md`](docs/trade-offs.md) | Por qué escalar consumidores solo mueve el cuello de botella |
| [`docs/business-value.md`](docs/business-value.md) | La decisión explícita que reemplaza a la pérdida silenciosa |

---

## 📁 Estructura del caso

```
15-message-queue-backpressure/
├── README.md                    ← este archivo
├── comparison.md                ← comparativa multi-stack
├── compose.compare.yml          ← los 7 stacks juntos
├── docs/                        ← análisis + postmortem
├── shared/                      ← assets compartidos
├── 🐘 php/                      ← `OPERATIVO` — el freno vive en FPM y el broker
├── 🐍 python/                   ← `OPERATIVO` — queue.Queue(maxsize) + firma de put()
├── 🟢 node/                     ← `OPERATIVO` — Writable con highWaterMark y 'drain'
├── ☕ java/                     ← `OPERATIVO` — ArrayBlockingQueue + put/offer
├── 🔵 dotnet/                   ← `OPERATIVO` — Channel.CreateBounded + FullMode
├── 🐹 go/                       ← `OPERATIVO` — canal bufferizado + select default
└── 🦀 rust/                     ← `OPERATIVO` — sync_channel + TrySendError::Full
```
