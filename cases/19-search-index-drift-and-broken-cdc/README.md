# 🔎 Caso 19 — Deriva del índice de búsqueda y CDC roto

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-success)](php/README.md)
[![Stacks](https://img.shields.io/badge/Stacks-7%20operativos%20%C2%B7%20PHP%20%C2%B7%20Python%20%C2%B7%20Node%20%C2%B7%20Java%20%C2%B7%20.NET%20%C2%B7%20Go%20%C2%B7%20Rust-blue)](../../docs/languages/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Observabilidad-green)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda viven en el link de arriba y en `comparison.md`.

---

## 🔍 Qué problema representa

La aplicación guarda un documento en la base y después lo manda al índice de búsqueda. Dos escrituras, dos sistemas, **ninguna transacción que las ate**.

Cuando la segunda falla, el usuario no ve un error: ve una búsqueda que responde 200. Solo que lo que devuelve está mal.

Este caso no rompe nada, y esa es toda su dificultad. Un servicio caído dispara alertas. Un índice que devuelve el 98,9% de lo que debería **no dispara nada**: responde rápido, responde 200, y sus resultados se ven perfectamente razonables.

---

## ⚠️ Síntomas típicos

- **Un cliente llama** porque su producto no aparece en la búsqueda. Existe en la base
- Un resultado de búsqueda que lleva a un **404**: el documento ya no está
- Precios o títulos **viejos** en los resultados, correctos al abrir el detalle
- «La búsqueda anda rara» como reporte recurrente, sin reproducción confiable
- Un reindexado completo «lo arregla» — durante unos días
- El error rate del índice en cero: **las escrituras que fallaron ya no se cuentan**

---

## 🧩 Causas frecuentes

- **Dual-write sin transacción común** entre la base y el índice
- **El error de la segunda escritura, ignorado** — un `catch` con `log.warn` y sin alerta
- **El consumidor de CDC que avanza su offset sin confirmar** la aplicación
- **Reindexado sin borrar**: arregla `missing` y `stale`, deja `orphan` intacto
- **Ninguna reconciliación agendada**: nadie compara los dos lados a propósito

---

## 🔬 Estrategia de diagnóstico

- Preguntar **cuántos documentos hay en cada lado**. Si nadie lo sabe, la deriva ya existe
- Separar las tres caras: se ven igual y se arreglan distinto
- Medir **`drift_age_ms`**, que es la que dice si algo lo va a reparar solo
- Medir recall y precisión con consultas reales, no con el error rate del cliente
- Verificar que los documentos tengan **versión**: sin ella, `stale` es indetectable

---

## 🎭 Las tres caras de la deriva

| Cara | Qué es | Qué ve el usuario |
|---|---|---|
| `missing` | Está en la base, no en el índice | **No lo encuentra** |
| `stale` | Está en los dos, con versión vieja | **Lo encuentra mal** — precio viejo, título viejo |
| `orphan` | Está en el índice, borrado en la base | **Fantasmas** — clic en un resultado que da 404 |

Se ven igual desde afuera —«la búsqueda anda rara»— y se arreglan distinto. Un reindexado que no borra arregla las dos primeras y deja la tercera intacta.

---

## 💡 Opciones de solución

**Tres mecanismos, y hacen falta los tres:**

1. **Outbox transaccional** — el cambio se escribe en la **misma transacción** que el dato. Si el índice está caído, el cambio no se pierde: queda escrito.
2. **Checkpoint que avanza solo con la confirmación** — en orden, y después de que el índice confirmó. Un cambio que no entra queda **pendiente**, no perdido.
3. **Reconciliación periódica** — un barrido que compara los dos lados y repara. Es la red de seguridad para lo que los dos primeros no cubren.

**El outbox garantiza que ningún cambio nuevo se pierda. No arregla los que ya se perdieron.** Por eso el barrido no es opcional.

---

## 🗺️ Diagrama — 2.000 escrituras, 8% de fallo del índice

```text
  Dual-write:

    app ──▶ [ base ] ✓
        └─▶ [ índice ] ✗ ← el error se traga; el código sigue

      missing=10  stale=50  orphan=19  →  drift=79
      recall 98,95%   precision 98,02%   silent_failures=158
      ↑ no se ve como un incidente. Se ve como una búsqueda que anda.


  Outbox + checkpoint + barrido:

    app ──▶ [ base + outbox ] ✓   (una sola transacción)
                    │
            consumidor ──▶ [ índice ] ✗→↻→✓   (reintenta; el checkpoint espera)
                    │
              barrido ──▶ compara los dos lados y repara lo que quedó

      missing=0  stale=0  orphan=0  →  drift=0
      recall 100%   precision 100%   retries=157   checkpoint=2000
```

---

## 🏗️ Implementación actual

Los siete stacks exponen el mismo contrato y —al ser el escenario determinista— producen **resultados idénticos hasta el último dígito**. Cuando el resultado es el mismo, lo único que queda para comparar es cómo se escribe.

### ✅ PHP 8.3

En un runtime share-nothing el consumidor de CDC **es un comando de cron**, así que el checkpoint durable no es buena práctica: es la única opción. En contra, es el único de los siete donde nada ayuda a no ignorar el error. Ver [`php/README.md`](php/README.md). Modo aislado: `8119`.

### 🐍 Python 3.12

El diagnóstico más corto de los siete —tres líneas de álgebra de conjuntos— y el bug más corto también: `except: pass`. Ver [`python/README.md`](python/README.md). Modo aislado: `8319`. Hub: `http://localhost:8200/19/`.

### 🟢 Node.js 22

El **único stack donde el bug se produce por no escribir algo**: el `await` que falta. A favor, su modelo de un solo hilo hace atómicos base y outbox sin ningún lock. Ver [`node/README.md`](node/README.md). Modo aislado: `8219`. Hub: `http://localhost:8300/19/`.

### ☕ Java 21

`@Transactional` hace que el dual-write **parezca** atómico, y nada en el código marca dónde termina su alcance. A favor, `ConcurrentSkipListMap.tailMap` es la mejor expresión del outbox del set. Ver [`java/README.md`](java/README.md). Modo aislado: `8419`. Hub: `http://localhost:8400/19/`.

### 🔵 .NET 8

`Except` y `Join` expresan las tres caras como consultas tipadas. La trampa: **LINQ es perezoso**, y los `.ToList()` son lo que fija el resultado antes de mutar. Ver [`dotnet/README.md`](dotnet/README.md). Modo aislado: `8519`. Hub: `http://localhost:8500/19/`.

### 🐹 Go 1.23

El `_ =` es una declaración de intención auditable, y `errcheck` está en casi todos los CI. En contra: **sin tipo conjunto**, el diagnóstico se escribe a mano. Ver [`go/README.md`](go/README.md). Modo aislado: `8619`. Hub: `http://localhost:8600/19/`.

### 🦀 Rust 1.83

El **único con las dos piezas**: `#[must_use]` hace que ignorar la escritura fallida no compile sin escribirlo a propósito, y `HashSet` da el diff de tres caras sin recorrer a mano. Ver [`rust/README.md`](rust/README.md). Modo aislado: `8719`. Hub: `http://localhost:8700/19/`.

---

## ⚖️ Trade-offs

- **El outbox agrega una escritura a cada transacción**: carga real sobre una tabla caliente
- **Aplicar en orden limita el paralelismo**: particionar por documento agrega un checkpoint por partición
- **La reconciliación completa cuesta**: comparar millones de documentos compite con el tráfico real
- **Reparar en automático puede propagar un error** si el problema estaba en la fuente
- **Cero deriva no es alcanzable**: el objetivo es una deriva acotada y medida

---

## 💼 Valor de negocio

Elimina la categoría entera de «el producto existe pero no aparece». Dos puntos porcentuales de recall son, en un catálogo, productos que no se pueden comprar.

Se subestima porque **no se ve como un incidente**: el costo aparece con otro nombre —conversión que baja sin explicación, tickets sobre «un producto que no encuentro»— y ninguno de esos se rastrea hasta un índice desincronizado. El problema puede durar años.

El indicador honesto no es el error rate del índice —ya es cero, porque el error se tragó— sino **`drift_age_ms`**: hace cuánto que el cambio más viejo no llega.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8.3 | `OPERATIVO` (`array_diff_key` + checkpoint durable por obligación) |
| 🐍 Python 3.12 | `OPERATIVO` (álgebra de conjuntos: el diff más corto de los siete) |
| 🟢 Node.js 22 | `OPERATIVO` (`Map`/`Set`; el bug es el `await` que falta) |
| ☕ Java 21 | `OPERATIVO` (`ConcurrentSkipListMap.tailMap` + `removeAll`/`retainAll`) |
| 🔵 .NET 8 | `OPERATIVO` (`Except`/`Join` tipados; la pereza como trampa) |
| 🐹 Go 1.23 | `OPERATIVO` (`_ =` auditable + `errcheck`; sin tipo conjunto) |
| 🦀 Rust 1.83 | `OPERATIVO` (`#[must_use]` + `HashSet`: el único con las dos piezas) |

---

## 🚀 Cómo levantar

**Modo hub (recomendado):**
```bash
docker compose -f compose.rust.yml up -d --build && curl http://localhost:8700/19/health   # Rust
docker compose -f compose.go.yml   up -d --build && curl http://localhost:8600/19/health   # Go
docker compose -f compose.root.yml up -d --build && curl http://localhost:8100/19/health   # PHP
```

**Ver la caída y su corrección (ejemplo Rust):**
```bash
# dual-write con 8% de fallo del índice: 79 documentos derivados, recall 98,95%
curl "http://localhost:8700/19/search-drifted?writes=2000&fail_rate=8"

# outbox + checkpoint + barrido, con el MISMO 8% de fallo: deriva cero
curl "http://localhost:8700/19/search-reconciled?writes=2000&fail_rate=8"

# las tres caras y la antigüedad del cambio más viejo sin aplicar
curl http://localhost:8700/19/index/state

# un barrido suelto, para ver qué encuentra y qué repara
curl http://localhost:8700/19/reconcile
```

**Los siete stacks a la vez:**
```bash
docker compose -f cases/19-search-index-drift-and-broken-cdc/compose.compare.yml up -d --build
```

---

## 📚 Lectura recomendada

| Documento | Qué cubre |
| --- | --- |
| [`comparison.md`](comparison.md) | Qué hace cada lenguaje cuando el programador no mira |
| [`docs/postmortem.md`](docs/postmortem.md) | 31.400 documentos derivados y el más viejo de hace siete meses |
| [`docs/context.md`](docs/context.md) | Las tres caras, y por qué hacen falta los tres mecanismos |
| [`docs/symptoms.md`](docs/symptoms.md) | Por qué las métricas no muestran casi nada |
| [`docs/diagnosis.md`](docs/diagnosis.md) | La pregunta que responde el caso entero, y `drift_age_ms` |
| [`docs/root-causes.md`](docs/root-causes.md) | Las cinco causas, incluida la del reindexado que no borra |
| [`docs/solution-options.md`](docs/solution-options.md) | Outbox, checkpoint, barrido y versionado |
| [`docs/trade-offs.md`](docs/trade-offs.md) | Por qué cero deriva no es un objetivo alcanzable |
| [`docs/business-value.md`](docs/business-value.md) | El costo que aparece con otro nombre |

---

## 📁 Estructura del caso

```
19-search-index-drift-and-broken-cdc/
├── README.md                    ← este archivo
├── comparison.md                ← comparativa multi-stack
├── compose.compare.yml          ← los 7 stacks juntos
├── docs/                        ← análisis + postmortem
├── shared/                      ← assets compartidos
├── 🐘 php/                      ← `OPERATIVO` — checkpoint durable por obligación
├── 🐍 python/                   ← `OPERATIVO` — el diff más corto de los siete
├── 🟢 node/                     ← `OPERATIVO` — el bug es el await que falta
├── ☕ java/                     ← `OPERATIVO` — tailMap, y @Transactional que engaña
├── 🔵 dotnet/                   ← `OPERATIVO` — Except/Join tipados, pereza como trampa
├── 🐹 go/                       ← `OPERATIVO` — `_ =` auditable, sin tipo conjunto
└── 🦀 rust/                     ← `OPERATIVO` — #[must_use] + HashSet: las dos piezas
```
