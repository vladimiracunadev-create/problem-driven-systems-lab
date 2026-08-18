# 🧬 Caso 17 — Migración de esquema sin downtime

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-success)](php/README.md)
[![Stacks](https://img.shields.io/badge/Stacks-7%20operativos%20%C2%B7%20PHP%20%C2%B7%20Python%20%C2%B7%20Node%20%C2%B7%20Java%20%C2%B7%20.NET%20%C2%B7%20Go%20%C2%B7%20Rust-blue)](../../docs/languages/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Entrega-green)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda viven en el link de arriba y en `comparison.md`.

---

## 🔍 Qué problema representa

Una migración sobre una tabla caliente —`ALTER TABLE users ADD COLUMN ...`— toma el lock exclusivo y no lo suelta hasta terminar. Durante veinte minutos, ningún read y ningún write entran. La aplicación devuelve 503 y el negocio pierde dinero por hora.

Lo que hace incómodo este caso es que **el trabajo total no cambia**. Rellenar dos millones de filas cuesta lo que cuesta, se haga de una vez o en mil lotes. Lo que cambia es **cómo se reparte**.

Y hay un detalle que lo vuelve difícil de detectar: **el proceso sigue vivo**. El healthcheck responde, el contenedor no se reinicia, ninguna alerta de disponibilidad de proceso dispara. Lo único que falla son las peticiones.

---

## ⚠️ Síntomas típicos

- 503 durante **toda la ventana de despliegue**, y vuelve solo cuando la migración termina
- El healthcheck en verde: **el proceso está vivo**, lo que falla son las peticiones
- Un `AccessExclusiveLock` sostenido sobre una sola tabla en `pg_locks`
- El pool de conexiones agotado, porque todas esperan el mismo lock ([caso 14](../14-connection-pool-exhaustion/README.md))
- Despliegues programados «para la madrugada» — convivir con el problema en vez de resolverlo
- Un `ALTER TABLE` que en staging tardó 200 ms tarda veinte minutos en producción

---

## 🧩 Causas frecuentes

- **DDL bloqueante** sobre una tabla caliente
- **Migración en un solo paso**: esquema, datos y código en la misma ventana de lock
- **Sin feature flag** que separe «la columna existe» de «la aplicación la usa»
- **Backfill sin pausas**: un `ALTER TABLE` largo escrito en pedazos
- **Validado contra un dataset de juguete**

---

## 🔬 Estrategia de diagnóstico

- Medir **disponibilidad durante** la migración, no después
- Mirar el **lock más largo**, no el total
- Verificar si el motor soporta **DDL online** para esa operación
- Contar filas en **producción**, no en staging
- Preguntar por el **orden del switch**: si el contract va primero, no hay vuelta atrás

---

## 💡 Opciones de solución

**Expand-contract, en este orden:**

1. **Expand** — agregar la columna nullable. Es metadata: instantáneo.
2. **Backfill** — rellenar por lotes, soltando el lock entre cada uno.
3. **Switch** — un feature flag cambia lecturas y escrituras a la columna nueva.
4. **Contract** — recién ahora, en un despliegue posterior, se borra la vieja.

**El switch va antes del contract** porque el flag es lo único reversible en un segundo.

---

## 🗺️ Diagrama — 20.000 filas, 8 lectores concurrentes

```text
  ALTER TABLE bloqueante:

    escritor ──[ LOCK EXCLUSIVO ══════════════════════ 400 ms ]──▶ listo
    lectores ──▶ ✗ ✗ ✗ ✗ ✗ ✗ ✗ ✗   (nadie entra; los que tienen
                                     timeout devuelven 503)

      readers_failed = 24 · longest_single_lock_ms = 400 · disponibilidad 98,5%


  Expand-contract (10 lotes):

    escritor ──[▪]─[▪]─[▪]─[▪]─[▪]─[▪]─[▪]─[▪]─[▪]─[▪]──▶ listo
                └─┘ └─┘ └─┘  ← las pausas, donde entran los lectores
    lectores ──▶ ✓ ✓ ✓ ✓ ✓ ✓ ✓ ✓ ✓ ✓ ✓ ✓ ✓ ✓ ✓ ✓

      readers_failed = 0 · longest_single_lock_ms = 40 · disponibilidad 100%

  lock_held_ms TOTAL: casi idéntico en las dos. El trabajo no desaparece: se reparte.
```

---

## 🏗️ Implementación actual

Los siete stacks exponen el mismo contrato: `/migrate-blocking` y `/migrate-expand-contract` con lectores concurrentes midiendo `availability_pct` **durante** la migración.

### ✅ PHP 8.3

`flock` con `LOCK_SH` / `LOCK_EX` / `LOCK_NB`: el **único read-write lock del laboratorio provisto por el sistema operativo**, y el único que coordina procesos en vez de hilos — que es lo que hace de verdad un motor. Ver [`php/README.md`](php/README.md). Modo aislado: `8117`.

### 🐍 Python 3.12

La stdlib **no tiene read-write lock**: hay que construirlo. La bandera `_writer_waiting` es la diferencia entre que el escritor entre alguna vez y que muera de hambre. Ver [`python/README.md`](python/README.md). Modo aislado: `8317`. Hub: `http://localhost:8200/17/`.

### 🟢 Node.js 22

No tiene locks porque no tiene hilos — y el caso ocurre igual, de la forma más literal: **el lock exclusivo es el event loop**. Ni siquiera el timeout del lector puede dispararse. Ver [`node/README.md`](node/README.md). Modo aislado: `8217`. Hub: `http://localhost:8300/17/`.

### ☕ Java 21

El único con **deadline y equidad de fábrica**: `tryLock(timeout, unit)` y `new ReentrantReadWriteLock(true)`. Sin el flag de justicia, el escritor puede no entrar nunca. Ver [`java/README.md`](java/README.md). Modo aislado: `8417`. Hub: `http://localhost:8400/17/`.

### 🔵 .NET 8

`TryEnterReadLock(ms)` devuelve `false` en vez de lanzar. Y `ReaderWriterLockSlim` es `IDisposable`: un lock con recursos nativos en un runtime con GC. No tiene modo justo. Ver [`dotnet/README.md`](dotnet/README.md). Modo aislado: `8517`. Hub: `http://localhost:8500/17/`.

### 🐹 Go 1.23

`sync.RWMutex` es lo más simple del set y no tiene hambruna de escritor, pero **no trae `RLock` con timeout**: hay que armarlo con goroutine y `select` — y la goroutine sobrevive al lector que se rindió. Ver [`go/README.md`](go/README.md). Modo aislado: `8617`. Hub: `http://localhost:8600/17/`.

### 🦀 Rust 1.83

La `std` no ofrece deadline de ninguna clase, así que la única opción es un **spin que consume CPU**. Es el caso donde la respuesta de Rust es peor que la de los otros seis. A cambio, los guards eliminan el unlock olvidado. Ver [`rust/README.md`](rust/README.md). Modo aislado: `8717`. Hub: `http://localhost:8700/17/`.

---

## ⚖️ Trade-offs

- **Expand-contract tarda más en total, y es correcto**: las pausas son tiempo agregado a propósito
- **Convivir con dos columnas tiene costo**: es deuda temporal, y hay que agendar el contract
- **Cuatro despliegues en vez de uno**: más coordinación a cambio de que ninguno tumbe la app
- **El lote chico no siempre es mejor**: multiplica el overhead de transacción
- **El feature flag es código que hay que borrar** — o se vuelve una rama muerta

---

## 💼 Valor de negocio

Convierte «hay que desplegar a las 3 de la mañana» en «se despliega cuando esté listo», y elimina la ventana de indisponibilidad programada que el negocio venía aceptando como inevitable. El indicador honesto no es cuánto tarda la migración sino **cuánto dura su lock más largo**.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8.3 | `OPERATIVO` (`flock` `LOCK_SH`/`LOCK_EX` — lock del SO, entre procesos) |
| 🐍 Python 3.12 | `OPERATIVO` (RWLock construido a mano sobre `Condition`) |
| 🟢 Node.js 22 | `OPERATIVO` (el event loop **es** el lock; `await` como equidad) |
| ☕ Java 21 | `OPERATIVO` (`ReentrantReadWriteLock` justo + `tryLock(timeout)`) |
| 🔵 .NET 8 | `OPERATIVO` (`ReaderWriterLockSlim` + `TryEnterReadLock(ms)`) |
| 🐹 Go 1.23 | `OPERATIVO` (`sync.RWMutex` + deadline armado con goroutine) |
| 🦀 Rust 1.83 | `OPERATIVO` (`RwLock` con spin acotado; guards sin unlock olvidado) |

---

## 🚀 Cómo levantar

**Modo hub (recomendado):**
```bash
docker compose -f compose.java.yml up -d --build && curl http://localhost:8400/17/health   # Java
docker compose -f compose.root.yml up -d --build && curl http://localhost:8100/17/health   # PHP
docker compose -f compose.go.yml   up -d --build && curl http://localhost:8600/17/health   # Go
```

**Ver la caída y su corrección (ejemplo Java):**
```bash
# ALTER TABLE bloqueante: lectores rechazados, lock de 400 ms de corrido
curl "http://localhost:8400/17/migrate-blocking?rows=20000&readers=8"

# el mismo trabajo en 10 lotes: 0 rechazados, lock más largo de 40 ms
curl "http://localhost:8400/17/migrate-expand-contract?rows=20000&readers=8&batch=2000&pause_ms=5"

# la fase, el progreso del backfill y el estado del feature flag
curl http://localhost:8400/17/migration/state

# un lote suelto, para ver el efecto de a uno
curl "http://localhost:8400/17/backfill?batch=2000"
```

**Los siete stacks a la vez:**
```bash
docker compose -f cases/17-zero-downtime-schema-migration/compose.compare.yml up -d --build
```

---

## 📚 Lectura recomendada

| Documento | Qué cubre |
| --- | --- |
| [`comparison.md`](comparison.md) | El read-write lock de cada runtime y el ranking de fit |
| [`docs/postmortem.md`](docs/postmortem.md) | 22 minutos de 503 por agregar una columna, con los healthchecks en verde |
| [`docs/context.md`](docs/context.md) | Por qué el orden de las cuatro fases no es negociable |
| [`docs/symptoms.md`](docs/symptoms.md) | Cómo se ve un DDL bloqueante desde afuera |
| [`docs/root-causes.md`](docs/root-causes.md) | Las cinco causas, incluida la de staging |
| [`docs/solution-options.md`](docs/solution-options.md) | Expand-contract, DDL online y ventana de mantenimiento |
| [`docs/trade-offs.md`](docs/trade-offs.md) | Por qué tardar más en total es lo correcto |
| [`docs/business-value.md`](docs/business-value.md) | El despliegue que deja de ser nocturno |

---

## 📁 Estructura del caso

```
17-zero-downtime-schema-migration/
├── README.md                    ← este archivo
├── comparison.md                ← comparativa multi-stack
├── compose.compare.yml          ← los 7 stacks juntos
├── docs/                        ← análisis + postmortem
├── shared/                      ← assets compartidos
├── 🐘 php/                      ← `OPERATIVO` — flock LOCK_SH/LOCK_EX del SO
├── 🐍 python/                   ← `OPERATIVO` — RWLock construido a mano
├── 🟢 node/                     ← `OPERATIVO` — el event loop es el lock
├── ☕ java/                     ← `OPERATIVO` — RWLock justo + tryLock(timeout)
├── 🔵 dotnet/                   ← `OPERATIVO` — ReaderWriterLockSlim IDisposable
├── 🐹 go/                       ← `OPERATIVO` — RWMutex + deadline con goroutine
└── 🦀 rust/                     ← `OPERATIVO` — RwLock con spin; guards sin unlock olvidado
```
