# 🔁 Caso 16 — Idempotencia y efectos duplicados

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-success)](php/README.md)
[![Stacks](https://img.shields.io/badge/Stacks-7%20operativos%20%C2%B7%20PHP%20%C2%B7%20Python%20%C2%B7%20Node%20%C2%B7%20Java%20%C2%B7%20.NET%20%C2%B7%20Go%20%C2%B7%20Rust-blue)](../../docs/languages/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Resiliencia-orange)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda viven en el link de arriba y en `comparison.md`.

---

## 🔍 Qué problema representa

Un cliente reintenta una petición porque el primer intento dio timeout, hubo un corte de red, o alguien apretó el botón dos veces. El resultado es un cobro duplicado, un email enviado dos veces, un mensaje publicado dos veces.

Lo que hace este caso difícil de ver es que **el primer intento sí llegó**. Lo que se perdió fue la respuesta.

El cliente no puede distinguir «no llegó al servidor» de «llegó y no me enteré», así que reintenta — y hace bien, porque la alternativa es perder operaciones legítimas. El problema está del otro lado: **el servidor tampoco puede distinguirlo**, salvo que el cliente le dé una `Idempotency-Key`.

Y la operación que la hace funcionar tiene un requisito que parece menor y no lo es: reservar la clave tiene que ser **una sola operación indivisible**.

---

## ⚠️ Síntomas típicos

- Clientes que reportan **cobros duplicados**, y el log muestra que pidieron dos veces
- Emails de confirmación enviados dos o tres veces por el mismo evento
- El duplicado aparece **más seguido cuando el sistema está lento**: más timeouts, más reintentos
- Un botón «pagar» apretado dos veces produce dos pagos, y se atribuye al usuario
- **Después de escalar de uno a dos pods** empiezan a aparecer duplicados que antes no había

---

## 🧩 Causas frecuentes

- **Operaciones no idempotentes con retries automáticos** del cliente, el proxy o el balanceador
- **Check-then-act** en vez de una operación atómica: `if (!existe) { crear }` tiene una ventana
- **Tabla de idempotencia en memoria del proceso**: correcta con una réplica, incorrecta con dos
- **Efecto lateral fuera de la transacción**: el cargo en la base y el email en la cola, sin nada que los ate
- **Sin ventana de deduplicación**: una clave que vive para siempre es una tabla que crece para siempre

---

## 🔬 Estrategia de diagnóstico

- Contar **cargos por operación**, no operaciones: `charges_applied` sobre una misma clave
- Buscar la **ventana check-then-act** y reemplazarla por la primitiva atómica del runtime
- Preguntar **dónde vive la tabla**: si vive en el heap, el bug aparece al escalar
- Verificar que el reintento reciba **la misma respuesta**, no un `409`
- Separar el efecto local del que **cruza el boundary**

---

## 💡 Opciones de solución

- **`Idempotency-Key` + reserva atómica**: `putIfAbsent`, `TryAdd`, `LoadOrStore`, `entry()`, `INSERT ... ON CONFLICT`
- **Respuesta cacheada por clave**: el reintento recibe lo mismo que el original
- **Ventana de deduplicación** (24 h) con limpieza
- **Outbox pattern** para el efecto que cruza el boundary

---

## 🗺️ Diagrama — cinco reintentos de un pago de $25

```text
  Sin clave de idempotencia:

    intento 1 ──▶ cobra $25 ──▶ email          (la respuesta se pierde)
    intento 2 ──▶ cobra $25 ──▶ email          ← el cliente reintentó
    intento 3 ──▶ cobra $25 ──▶ email
    intento 4 ──▶ cobra $25 ──▶ email
    intento 5 ──▶ cobra $25 ──▶ email

      charges_applied = 5 · overcharged = $100 · emails = 5


  Con Idempotency-Key + outbox:

    intento 1 ──▶ [ reserva atómica: GANA ] ──▶ cobra $25 ──┐
                                                            ├─ misma escritura
                                                     outbox ─┘
    intento 2..5 ─▶ [ reserva atómica: PIERDE ] ──▶ devuelve la respuesta guardada
                                                            │
                                          worker ──────────▶ email ×1

      charges_applied = 1 · duplicates_prevented = 4 · emails = 1
```

---

## 🏗️ Implementación actual

Los siete stacks exponen el mismo contrato: `/charge-unsafe` y `/charge-idempotent` sobre los mismos N reintentos, con `charges_applied` y `overcharged_cents` como métricas centrales.

### ✅ PHP 8.3

Sin heap compartido, la clave vive en el almacenamiento y la atomicidad la aporta el motor (`INSERT ... ON CONFLICT DO NOTHING`, modelado con `flock`). **Es la única de las siete versiones que sigue siendo correcta con veinte réplicas.** Ver [`php/README.md`](php/README.md). Modo aislado: `8116`.

### 🐍 Python 3.12

`dict.setdefault` bajo `Lock`: una operación en vez de dos. El `Lock` está igual porque la atomicidad del GIL es un detalle de CPython, no un contrato del lenguaje. Ver [`python/README.md`](python/README.md). Modo aislado: `8316`. Hub: `http://localhost:8200/16/`.

### 🟢 Node.js 22

La primitiva distintiva es **una ausencia**: `has()` + `set()` es atómico porque no hay otro hilo. Y por eso el código correcto para un proceso es incorrecto para dos, sin cambiar una línea. Ver [`node/README.md`](node/README.md). Modo aislado: `8216`. Hub: `http://localhost:8300/16/`.

### ☕ Java 21

`ConcurrentHashMap.putIfAbsent` resuelve la carrera **y** dice quién ganó en una sola llamada. Es la formulación más directa del patrón. Ver [`java/README.md`](java/README.md). Modo aislado: `8416`. Hub: `http://localhost:8400/16/`.

### 🔵 .NET 8

`TryAdd` sí es atómico — a diferencia de `GetOrAdd` con fábrica, que en el caso 13 hubo que envolver en `Lazy<T>`. Dos APIs en la misma clase con garantías distintas. Ver [`dotnet/README.md`](dotnet/README.md). Modo aislado: `8516`. Hub: `http://localhost:8500/16/`.

### 🐹 Go 1.23

`sync.Map.LoadOrStore` con el contrato comma-ok de siempre. Y es el caso donde `sync.Map` **sí** corresponde: claves escritas una vez y leídas muchas — el opuesto exacto del caso 13. Ver [`go/README.md`](go/README.md). Modo aislado: `8616`. Hub: `http://localhost:8600/16/`.

### 🦀 Rust 1.83

La entry API con `match` exhaustivo: **ignorar el resultado no compila**. Y el `Entry` presta el mapa, así que la ventana check-then-act es inexpresable. Ver [`rust/README.md`](rust/README.md). Modo aislado: `8716`. Hub: `http://localhost:8700/16/`.

---

## ⚖️ Trade-offs

- **La tabla de idempotencia es estado nuevo que hay que operar**: ventana, limpieza y dimensionamiento
- **Guardar la respuesta cuesta más que guardar la clave**, y es lo que evita que el reintento reciba un `409`
- **El outbox agrega latencia al efecto**, a cambio de que no pueda perderse
- **La entrega del outbox es at-least-once**: duplicar un email es visible y corregible; perderlo, no
- **En memoria alcanza hasta que hay dos réplicas** — el trade-off más importante, y el que menos se prueba

---

## 💼 Valor de negocio

Convierte cobros duplicados —que se devuelven de a uno, con costo de soporte y de reputación— en un número que se puede poner en un panel: cuántos duplicados se evitaron. `overcharged_cents` es plata real, en la unidad en que el negocio discute.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8.3 | `OPERATIVO` (reserva en almacenamiento compartido; la única que escala) |
| 🐍 Python 3.12 | `OPERATIVO` (`dict.setdefault` bajo `Lock`) |
| 🟢 Node.js 22 | `OPERATIVO` (`Map` atómico por el modelo de un solo hilo) |
| ☕ Java 21 | `OPERATIVO` (`ConcurrentHashMap.putIfAbsent`) |
| 🔵 .NET 8 | `OPERATIVO` (`ConcurrentDictionary.TryAdd`) |
| 🐹 Go 1.23 | `OPERATIVO` (`sync.Map.LoadOrStore`) |
| 🦀 Rust 1.83 | `OPERATIVO` (`HashMap::entry` con `match` exhaustivo) |

---

## 🚀 Cómo levantar

**Modo hub (recomendado):**
```bash
docker compose -f compose.rust.yml up -d --build && curl http://localhost:8700/16/health   # Rust
docker compose -f compose.java.yml up -d --build && curl http://localhost:8400/16/health   # Java
docker compose -f compose.root.yml up -d --build && curl http://localhost:8100/16/health   # PHP
```

**Ver el cobro duplicado y su corrección (ejemplo Java):**
```bash
# 5 reintentos del mismo pago sin clave: 5 cargos, $100 de más
curl "http://localhost:8400/16/charge-unsafe?key=order-4711&attempts=5&amount=2500"

curl http://localhost:8400/16/reset-lab

# los mismos 5 reintentos con clave: 1 cargo, 4 duplicados evitados
curl "http://localhost:8400/16/charge-idempotent?key=order-4711&attempts=5&amount=2500"

# el efecto salió una sola vez, y por el outbox
curl http://localhost:8400/16/outbox
```

**Los siete stacks a la vez:**
```bash
docker compose -f cases/16-idempotency-and-duplicate-effects/compose.compare.yml up -d --build
```

---

## 📚 Lectura recomendada

| Documento | Qué cubre |
| --- | --- |
| [`comparison.md`](comparison.md) | La misma operación con cinco nombres, y por qué solo una versión escala |
| [`docs/postmortem.md`](docs/postmortem.md) | 1.847 cobros duplicados en once minutos |
| [`docs/context.md`](docs/context.md) | Por qué el cliente hizo lo correcto al reintentar |
| [`docs/symptoms.md`](docs/symptoms.md) | Cómo se ve un duplicado antes de que llegue soporte |
| [`docs/root-causes.md`](docs/root-causes.md) | La ventana check-then-act y las otras cuatro causas |
| [`docs/solution-options.md`](docs/solution-options.md) | Clave, respuesta cacheada, ventana y outbox |
| [`docs/trade-offs.md`](docs/trade-offs.md) | Por qué at-least-once es una decisión y no una limitación |
| [`docs/business-value.md`](docs/business-value.md) | `overcharged_cents`: el número que se lleva a la reunión |

---

## 📁 Estructura del caso

```
16-idempotency-and-duplicate-effects/
├── README.md                    ← este archivo
├── comparison.md                ← comparativa multi-stack
├── compose.compare.yml          ← los 7 stacks juntos
├── docs/                        ← análisis + postmortem
├── shared/                      ← assets compartidos
├── 🐘 php/                      ← `OPERATIVO` — reserva en almacenamiento; la única que escala
├── 🐍 python/                   ← `OPERATIVO` — dict.setdefault bajo Lock
├── 🟢 node/                     ← `OPERATIVO` — Map atómico por el modelo de un hilo
├── ☕ java/                     ← `OPERATIVO` — ConcurrentHashMap.putIfAbsent
├── 🔵 dotnet/                   ← `OPERATIVO` — ConcurrentDictionary.TryAdd
├── 🐹 go/                       ← `OPERATIVO` — sync.Map.LoadOrStore
└── 🦀 rust/                     ← `OPERATIVO` — HashMap::entry con match exhaustivo
```
