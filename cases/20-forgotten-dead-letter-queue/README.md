# 🪦 Caso 20 — La dead letter queue olvidada

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-success)](php/README.md)
[![Stacks](https://img.shields.io/badge/Stacks-7%20operativos%20%C2%B7%20PHP%20%C2%B7%20Python%20%C2%B7%20Node%20%C2%B7%20Java%20%C2%B7%20.NET%20%C2%B7%20Go%20%C2%B7%20Rust-blue)](../../docs/languages/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Resiliencia-green)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda viven en el link de arriba y en `comparison.md`.

---

## 🔗 Cierra el arco del caso 15

En el [caso 15](../15-message-queue-backpressure/README.md) la dead letter queue **nace**: es la política de rechazo que salva al productor de bloquearse cuando la cola se llena. Es la decisión correcta.

Acá se ve qué pasa cuando nadie vuelve a mirarla.

---

## 🔍 Qué problema representa

Un consumidor falla al procesar un mensaje. Lo manda a la DLQ y sigue con el siguiente. El pipeline no se cae, la latencia no sube, el throughput no baja. **El dashboard muestra cero errores** — porque los errores se fueron a otro lado.

Meses después alguien abre la DLQ y encuentra cuatrocientos mil mensajes.

El consumidor está haciendo exactamente lo que se le pidió: capturar el error, no morirse, seguir. **Eso es resiliencia de manual — y sin la otra mitad, es pérdida de datos con buenos modales.**

---

## ⚠️ Síntomas típicos

- **Nada.** Es el síntoma principal y el problema entero
- Throughput normal, latencia normal, **error rate en cero**
- Un cliente reporta que su pedido «nunca llegó», y en la base efectivamente no está
- Un reporte de fin de mes que no cuadra por un porcentaje pequeño y constante
- Alguien abre la DLQ por curiosidad y encuentra cuatrocientos mil mensajes

---

## 🎭 Transitorio contra venenoso

| Clase | Qué significa | Qué corresponde |
|---|---|---|
| **Transitorio** | El mismo mensaje funciona en el próximo intento | **Reintentar** con backoff |
| **Venenoso** | El mismo mensaje **nunca** va a funcionar | **A la DLQ**, ya mismo |

Timeout, 503 del downstream, deadlock: transitorios. Schema roto, campo desconocido, encoding inválido: venenosos.

**Reintentar lo venenoso es quemar CPU. Mandar lo transitorio a la DLQ es tirar trabajo que se podía salvar.** El consumidor que no distingue hace las dos cosas mal a la vez.

---

## 🧩 Causas frecuentes

- **El consumidor no clasifica el error**: un `catch (Exception)` trata igual a un timeout que a un JSON malformado
- **La DLQ no tiene profundidad publicada**: lo que no se alerta, no se mira
- **No se guarda por qué falló**: sin clase de error ni muestra, depurar obliga a reprocesar a ciegas
- **No hay salida**: sin comando de replay, el único camino es un script improvisado en un incidente
- **El `catch` genérico se traga los bugs propios**: un `KeyError` termina en la DLQ como si fuera dato malo

---

## 🔬 Estrategia de diagnóstico

- **¿Cuántos mensajes hay en la DLQ?** Si no está en un dashboard, es un agujero
- **¿Hace cuánto está el más viejo?** Si la respuesta es «semanas», ya nadie la va a drenar
- Mirar `by_error_class`: convierte un número en un diagnóstico
- Alertar sobre `dlq_oldest_msg_age_ms`, no sobre el error rate
- Probar un drenaje con `--dry-run`: cuánto se recuperaría sin cambiar una línea

---

## 💡 Opciones de solución

1. **Clasificar antes de decidir** — transitorio se reintenta, venenoso va a la DLQ ya mismo
2. **Presupuesto de reintentos, no reintentos infinitos** — con backoff, y `transient_exhausted` como clase propia
3. **La DLQ como cola observable** — profundidad, antigüedad, desglose por clase, muestras de payload
4. **Un comando de drenaje**, idealmente con `--dry-run`
5. **Alertar sobre la antigüedad**, no solo sobre la profundidad
6. **Descartar explícitamente** lo que ya no vale la pena, con un TTL escrito

---

## 🗺️ Diagrama — 3.000 mensajes, 12% transitorios, 4% venenosos

```text
  Consumidor silencioso:

    msg ──▶ procesar ──✗──▶ [ DLQ ]  ← sin clasificar, sin reintentar, sin medir
                                  │
                                  └── 416 mensajes · 13,87% · 0 alertas · 0 muestras
                                      by_error_class = { unclassified: 416 }

    dashboard: throughput normal · latencia normal · ERROR RATE = 0


  Consumidor observado:

    msg ──▶ procesar ──✗──▶ ¿qué error es?
                             ├── transitorio ──▶ reintentar ──▶ ✓  (297 recuperados)
                             └── venenoso ─────▶ [ DLQ ]  + clase + muestra
                                                     │
                                                     └── 119 mensajes · 3,97% · 1 alerta

  Drenar la DLQ del consumidor SILENCIOSO:
      recuperados = 297 de 416  →  71,39%   ← trabajo que nunca debió tirarse
      siguen fallando = 119                  ← veneno de verdad
```

---

## 🏗️ Implementación actual

Los siete stacks exponen el mismo contrato y —al ser el escenario determinista— producen **resultados idénticos hasta el último dígito**. Lo que cambia es qué tan difícil hace cada lenguaje clasificar mal.

### ✅ PHP 8.3

`catch (A | B $e)` sin duplicar el bloque, y `Throwable` haciendo explícito que capturar todo incluye los bugs propios. El drenaje como **comando de cron** es una ventaja operativa real. Ver [`php/README.md`](php/README.md). Modo aislado: `8120`.

### 🐍 Python 3.12

Clasifica en cuatro líneas y encadena causas desde la 3.0. El peligro está a una palabra: **`except Exception`** manda a la DLQ también los bugs del consumidor. Ver [`python/README.md`](python/README.md). Modo aislado: `8320`. Hub: `http://localhost:8200/20/`.

### 🟢 Node.js 22

El más débil del set justo donde el caso pega: **`instanceof` es frágil por diseño** —se rompe entre copias de paquete, workers y bibliotecas nativas— y la alternativa práctica es comparar strings. Ver [`node/README.md`](node/README.md). Modo aislado: `8220`. Hub: `http://localhost:8300/20/`.

### ☕ Java 21

La jerarquía **`sealed`** es la clasificación más expresiva del set. Lo que pierde: clasificar obliga a capturar, y capturar acorta la pila que la DLQ necesita. Ver [`java/README.md`](java/README.md). Modo aislado: `8420`. Hub: `http://localhost:8400/20/`.

### 🔵 .NET 8

Los filtros **`catch (Ex e) when (...)`** son la única primitiva del laboratorio que decide **antes de desenrollar la pila**: el registro de DLQ conserva el punto de falla original. Ver [`dotnet/README.md`](dotnet/README.md). Modo aislado: `8520`. Hub: `http://localhost:8500/20/`.

### 🐹 Go 1.23

`errors.Is`/`As` sobre cadenas `%w`: el contexto se acumula sin borrar la causa y sobrevive a los límites de paquete. Sin exhaustividad, una clase nueva cae en el camino por defecto. Ver [`go/README.md`](go/README.md). Modo aislado: `8620`. Hub: `http://localhost:8600/20/`.

### 🦀 Rust 1.83

El **`enum` con `match` exhaustivo**: una clase de error nueva no compila hasta que alguien decida qué hacer con ella. Y `panic!` como canal separado de `Result` impide estructuralmente que un bug del consumidor termine en la DLQ. Ver [`rust/README.md`](rust/README.md). Modo aislado: `8720`. Hub: `http://localhost:8700/20/`.

---

## ⚖️ Trade-offs

- **Clasificar exige conocer los errores del downstream**, y una clasificación equivocada es peor que ninguna
- **El reintento consume capacidad**: con un downstream degradado, un presupuesto generoso convierte degradación en detención
- **Guardar muestras de payload guarda datos**, y la DLQ suele tener retención más larga
- **El replay puede duplicar efectos** — es exactamente el escenario del [caso 16](../16-idempotency-and-duplicate-effects/README.md)
- **Una DLQ vacía puede ser mala señal**: puede significar que se está descartando antes

---

## 💼 Valor de negocio

Elimina la pérdida silenciosa de datos. El consumidor silencioso pierde el **13,87%** de los mensajes y no recupera ninguno; el observado manda a la DLQ el **3,97%** —solo veneno real— y recupera el resto sin que llegue a la cola.

Y la medición que cierra el caso: drenar la DLQ del silencioso recupera el **71,39%**. Ese porcentaje es trabajo que se había tirado y que se podía salvar con un reintento.

El indicador honesto no es `dlq_depth` sino **`dlq_oldest_msg_age_ms`**: mil mensajes de hace cinco minutos son un incidente en curso; los mismos mil del último trimestre son un proceso que nadie opera.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8.3 | `OPERATIVO` (`catch (A\|B)` + `Throwable` + drenaje por cron) |
| 🐍 Python 3.12 | `OPERATIVO` (jerarquía de excepciones + `raise ... from`) |
| 🟢 Node.js 22 | `OPERATIVO` (`instanceof` frágil + `error.cause` de ES2022) |
| ☕ Java 21 | `OPERATIVO` (jerarquía `sealed ... permits` + multi-catch) |
| 🔵 .NET 8 | `OPERATIVO` (filtros `when (...)`: decide sin desenrollar) |
| 🐹 Go 1.23 | `OPERATIVO` (`errors.Is` / `errors.As` sobre cadenas `%w`) |
| 🦀 Rust 1.83 | `OPERATIVO` (`enum` + `match` exhaustivo; `panic!` ≠ `Result`) |

---

## 🚀 Cómo levantar

**Modo hub (recomendado):**
```bash
docker compose -f compose.rust.yml   up -d --build && curl http://localhost:8700/20/health   # Rust
docker compose -f compose.dotnet.yml up -d --build && curl http://localhost:8500/20/health   # .NET
docker compose -f compose.root.yml   up -d --build && curl http://localhost:8100/20/health   # PHP
```

**Ver la caída y su corrección (ejemplo Rust):**
```bash
# consumidor silencioso: 416 mensajes a la DLQ, sin clasificar, sin alerta
curl "http://localhost:8700/20/consume-silent?messages=3000"

# drenar esa DLQ: 297 se recuperan sin cambiar una línea — 71,39%
curl "http://localhost:8700/20/dlq/drain?limit=500"

# consumidor observado: solo el veneno llega a la DLQ, con su clase y muestras
curl "http://localhost:8700/20/consume-observed?messages=3000"

# profundidad, antigüedad del más viejo y desglose por clase de error
curl http://localhost:8700/20/dlq/stats
```

**Los siete stacks a la vez:**
```bash
docker compose -f cases/20-forgotten-dead-letter-queue/compose.compare.yml up -d --build
```

---

## 📚 Lectura recomendada

| Documento | Qué cubre |
| --- | --- |
| [`comparison.md`](comparison.md) | Qué tan difícil hace cada lenguaje clasificar **mal** un error |
| [`docs/postmortem.md`](docs/postmortem.md) | 412.000 mensajes, el más viejo de hace catorce meses |
| [`docs/context.md`](docs/context.md) | Transitorio contra venenoso, y las cinco cosas que hacen a una DLQ |
| [`docs/symptoms.md`](docs/symptoms.md) | Por qué el síntoma principal es que no hay síntomas |
| [`docs/diagnosis.md`](docs/diagnosis.md) | Las dos preguntas, y la medición del 71,39% |
| [`docs/root-causes.md`](docs/root-causes.md) | Las cinco causas, incluida la del `catch` que se traga los bugs |
| [`docs/solution-options.md`](docs/solution-options.md) | Clasificar, presupuestar, medir, drenar |
| [`docs/trade-offs.md`](docs/trade-offs.md) | Por qué una DLQ vacía puede ser mala señal |
| [`docs/business-value.md`](docs/business-value.md) | Un mecanismo de seguridad que nadie opera |

---

## 📁 Estructura del caso

```
20-forgotten-dead-letter-queue/
├── README.md                    ← este archivo
├── comparison.md                ← comparativa multi-stack
├── compose.compare.yml          ← los 7 stacks juntos
├── docs/                        ← análisis + postmortem
├── shared/                      ← assets compartidos
├── 🐘 php/                      ← `OPERATIVO` — catch union + drenaje por cron
├── 🐍 python/                   ← `OPERATIVO` — jerarquía simple, except Exception peligroso
├── 🟢 node/                     ← `OPERATIVO` — instanceof frágil por diseño
├── ☕ java/                     ← `OPERATIVO` — jerarquía sealed, la más expresiva
├── 🔵 dotnet/                   ← `OPERATIVO` — filtros when: decide sin desenrollar
├── 🐹 go/                       ← `OPERATIVO` — errors.Is/As sobre cadenas %w
└── 🦀 rust/                     ← `OPERATIVO` — enum + match exhaustivo; panic ≠ Result
```
