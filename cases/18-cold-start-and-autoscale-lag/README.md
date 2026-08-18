# ❄️ Caso 18 — Arranque en frío y retraso del autoescalado

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-success)](php/README.md)
[![Stacks](https://img.shields.io/badge/Stacks-7%20operativos%20%C2%B7%20PHP%20%C2%B7%20Python%20%C2%B7%20Node%20%C2%B7%20Java%20%C2%B7%20.NET%20%C2%B7%20Go%20%C2%B7%20Rust-blue)](../../docs/languages/README.md)
[![Categoría](https://img.shields.io/badge/Categoría-Resiliencia-green)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda viven en el link de arriba y en `comparison.md`.

---

## 🔍 Qué problema representa

El tráfico sube, el autoescalador suma instancias, y **la tasa de error sube con cada instancia agregada**.

Un proceso está **vivo** en el milisegundo cero: el puerto está abierto y `/health` responde 200. Pero todavía no leyó la configuración, no abrió el pool, no resolvió DNS y —en los runtimes con máquina virtual— no compiló una sola línea de su propio código a nativo. Si el balanceador enruta por *liveness*, le manda tráfico a ese hueco.

Y ese tráfico no falla con un error interesante: falla con 503 desde una instancia que **ninguna alerta considera caída**.

---

## ⚠️ Síntomas típicos

- **503 justo después de escalar**, y solo por unos segundos: cuando alguien mira, ya pasó
- El healthcheck en verde durante todo el incidente — **el proceso nunca murió**
- Latencia p99 que **empeora al agregar instancias**, en vez de mejorar
- El autoescalador rebota: suma instancias, la latencia sube, suma más
- Errores concentrados en instancias con menos de un minuto de vida
- Un incidente que **dura menos que el intervalo de scrape** y deja el dashboard plano

---

## 🧩 Causas frecuentes

- **El balanceador enruta por liveness**, no por readiness
- **El autoescalado reacciona a una métrica que ya se disparó** (CPU al 70%)
- **Inicialización perezosa en el camino de la petición**: `sync.Once` disparada por tráfico
- **Compilación en capas sin calentar**: el código sigue lento después de estar «listo»
- **Arranque medido sin límite de CPU**, que es donde menos duele

---

## 🔬 Estrategia de diagnóstico

- Medir `health_vs_ready_gap_ms`: **cuánto tiempo el sistema afirma estar disponible sin estarlo**
- Mirar disponibilidad **durante** el escalado, no después
- Comparar p99 por **edad de instancia**: si las jóvenes son peores, es esto
- Medir `p99_first_100_ms` contra `p99_after_1000_ms` para ver la curva del runtime
- Verificar que `/health` no responda antes que la aplicación

---

## 💡 Opciones de solución

1. **Separar readiness de liveness** — el mínimo indispensable, y lo que elimina los 503
2. **Pool tibio** — escalar por una métrica adelantada, o mantener capacidad de sobra
3. **Reducir el arranque** — `PublishReadyToRun`, AppCDS, `opcache.preload`, snapshots
4. **Calentar antes de anunciarse lista** — ejercitar los caminos calientes durante el arranque
5. **Dimensionar para el pico** — válido por decisión, no por omisión

---

## 🗺️ Diagrama — 2.400 peticiones, 3 instancias, `io_ms=150`

```text
  Arranque en frío (el balanceador mira /health):

    instancia ──[ arrancando ═════ 150 ms ]──▶ lista
    /health   ──▶ 200 200 200 200 200 200 200 200   ← "el proceso vive"
    tráfico   ──▶ ✗ ✗ ✗ ✗ ✗ ✗ ✗ ✗ ✓ ✓ ✓ ✓ ✓ ✓ ✓

      rechazadas ≈ 900 · hueco health→ready = 150 ms · disponibilidad ~60%


  Pool tibio (el balanceador mira /ready):

    instancia ──[ arrancó ANTES del tráfico ]──▶ lista desde el inicio
    tráfico   ──▶ ✓ ✓ ✓ ✓ ✓ ✓ ✓ ✓ ✓ ✓ ✓ ✓ ✓ ✓ ✓

      rechazadas = 0 · hueco health→ready = 0 ms · disponibilidad 100%

  La inicialización cuesta lo mismo en las dos. El trabajo no desaparece: se adelanta.
```

---

## 🌡️ Y además: la curva de calentamiento, medida

El trabajo por petición es **el mismo lazo entero puro en los siete stacks**, sin un solo `sleep`. `warmup_speedup_x` es `p99_first_100_ms / p99_after_1000_ms`: qué hace ese runtime con el mismo código repetido mil veces.

```text
  ☕ Java   ████████████████████████████████████████████████  51,9x
  🔵 .NET   ██                                                 2,3x
  🐍 Python █▌                                                 1,8x  ← contención, no JIT
  🟢 Node   █                                                  1,1x
  🐘 PHP    █                                                  1,1x
  🐹 Go     █                                                  1,0x
  🦀 Rust   █                                                  1,00x
```

---

## 🏗️ Implementación actual

Los siete stacks exponen el mismo contrato: `/boot-cold` y `/boot-warmed` con clientes concurrentes midiendo `availability_pct` **durante** el escalado.

### ✅ PHP 8.3

El **único stack que arranca en frío en cada petición, por diseño**. `opcache` es lo que evita que eso sea catastrófico — y es el único AOT del lab cuya caché comparten los procesos, no los hilos. Ver [`php/README.md`](php/README.md). Modo aislado: `8118`.

### 🐍 Python 3.12

Sin JIT: la curva es plana porque no hay nada que calentar. Y **el único stack sin ninguna salida compilada** — la única palanca contra su arranque es rediseñar los imports. Ver [`python/README.md`](python/README.md). Modo aislado: `8318`. Hub: `http://localhost:8200/18/`.

### 🟢 Node.js 22

V8 optimiza en capas de verdad, pero el cold start de Node no está ahí: está en el **grafo de `require`**, que este caso no alcanza. El número es honesto e incompleto, y por eso se dice. Ver [`node/README.md`](node/README.md). Modo aislado: `8218`. Hub: `http://localhost:8300/18/`.

### ☕ Java 21

**51,9x medidos.** El arranque en frío canónico, y el único donde la lentitud posterior a estar «listo» realimenta al autoescalador. La caja de herramientas más profunda, y ninguna activada por defecto. Ver [`java/README.md`](java/README.md). Modo aislado: `8418`. Hub: `http://localhost:8400/18/`.

### 🔵 .NET 8

El mismo problema que Java con la respuesta **en la caja**: `PublishReadyToRun`, `TieredPGO`, `PublishAot`. Tres líneas del `.csproj`. Ver [`dotnet/README.md`](dotnet/README.md). Modo aislado: `8518`. Hub: `http://localhost:8500/18/`.

### 🐹 Go 1.23

No gana por rápido: gana por **no tener nada que calentar**. Y `sync.Once` es a la vez la primitiva correcta y la trampa, si se dispara con tráfico en vez de con el arranque. Ver [`go/README.md`](go/README.md). Modo aislado: `8618`. Hub: `http://localhost:8600/18/`.

### 🦀 Rust 1.83

La curva más plana, y `OnceLock` haciendo que el estado «todavía no lista» sea **inalcanzable por tipos**, no solo improbable. El reverso exacto del [caso 17](../17-zero-downtime-schema-migration/README.md). Ver [`rust/README.md`](rust/README.md). Modo aislado: `8718`. Hub: `http://localhost:8700/18/`.

---

## ⚖️ Trade-offs

- **El pool tibio cuesta dinero todo el tiempo**: capacidad ociosa es capacidad pagada
- **Calentar alarga el arranque**: mejora la primera petición real y empeora el tiempo hasta estar disponible
- **AOT elimina la curva y se lleva el pico**: la JVM caliente le gana a su propia versión nativa
- **Readiness estricto reduce capacidad justo cuando falta**
- **Menos dependencias arranca más rápido y se escribe más lento**

---

## 💼 Valor de negocio

El arranque en frío falla **exactamente cuando el sistema está bajo presión**: nadie escala en un valle de tráfico. Cada 503 de esta clase ocurre en el lanzamiento, la campaña o el pico de la mañana — y sin nada rojo en el dashboard.

El indicador honesto no es cuánto tarda en arrancar, sino **`health_vs_ready_gap_ms`**: cuánto tiempo el sistema afirma estar disponible sin estarlo. Un servicio que tarda 30 segundos y lo anuncia bien no pierde una petición; uno que tarda 2 y miente durante esos 2, sí.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8.3 | `OPERATIVO` (`opcache` + `pm.start_servers`; cold start en cada petición) |
| 🐍 Python 3.12 | `OPERATIVO` (sin JIT; imports diferidos como única palanca) |
| 🟢 Node.js 22 | `OPERATIVO` (V8 en capas; el costo real está en `require`) |
| ☕ Java 21 | `OPERATIVO` (compilación en capas — **51,9x medidos**) |
| 🔵 .NET 8 | `OPERATIVO` (`PublishReadyToRun` / `TieredPGO` / `PublishAot`) |
| 🐹 Go 1.23 | `OPERATIVO` (binario AOT + `sync.Once`) |
| 🦀 Rust 1.83 | `OPERATIVO` (AOT sin runtime + `OnceLock` con garantía de tipo) |

---

## 🚀 Cómo levantar

**Modo hub (recomendado):**
```bash
docker compose -f compose.java.yml up -d --build && curl http://localhost:8400/18/health   # Java
docker compose -f compose.go.yml   up -d --build && curl http://localhost:8600/18/health   # Go
docker compose -f compose.root.yml up -d --build && curl http://localhost:8100/18/health   # PHP
```

**Ver la caída y su corrección (ejemplo Java):**
```bash
# instancias frías con el tráfico ya encima: cientos de 503 y el proceso vivo
curl "http://localhost:8400/18/boot-cold?requests=2400&instances=3"

# pool tibio y enrutado por readiness: 0 rechazos, 100% de disponibilidad
curl "http://localhost:8400/18/boot-warmed?requests=2400&instances=3"

# el estado por instancia: viva, lista, cuánto tardó, cuánto sirvió
curl http://localhost:8400/18/ready

# construir el pool tibio antes de que llegue el tráfico
curl "http://localhost:8400/18/warmup?instances=3&prime=1500"
```

**Los siete stacks a la vez:**
```bash
docker compose -f cases/18-cold-start-and-autoscale-lag/compose.compare.yml up -d --build
```

---

## 📚 Lectura recomendada

| Documento | Qué cubre |
| --- | --- |
| [`comparison.md`](comparison.md) | La curva de calentamiento medida en los 7 runtimes y el ranking de fit |
| [`docs/postmortem.md`](docs/postmortem.md) | 34 minutos de errores que subían con cada instancia agregada |
| [`docs/context.md`](docs/context.md) | Por qué hacen falta los dos remedios, no uno |
| [`docs/symptoms.md`](docs/symptoms.md) | Por qué el dashboard queda plano mientras los usuarios ven 503 |
| [`docs/diagnosis.md`](docs/diagnosis.md) | Qué revela `warmup_speedup_x`, y qué no hay que concluir de él |
| [`docs/root-causes.md`](docs/root-causes.md) | Las cinco causas, incluida la realimentación del autoescalador |
| [`docs/solution-options.md`](docs/solution-options.md) | Readiness, pool tibio, AOT y calentamiento explícito |
| [`docs/trade-offs.md`](docs/trade-offs.md) | Por qué AOT se lleva el pico junto con la curva |
| [`docs/business-value.md`](docs/business-value.md) | El indicador honesto: el hueco, no el arranque |

---

## 📁 Estructura del caso

```
18-cold-start-and-autoscale-lag/
├── README.md                    ← este archivo
├── comparison.md                ← comparativa multi-stack
├── compose.compare.yml          ← los 7 stacks juntos
├── docs/                        ← análisis + postmortem
├── shared/                      ← assets compartidos
├── 🐘 php/                      ← `OPERATIVO` — opcache, cold start en cada request
├── 🐍 python/                   ← `OPERATIVO` — sin JIT, sin salida compilada
├── 🟢 node/                     ← `OPERATIVO` — V8 en capas, costo en require
├── ☕ java/                     ← `OPERATIVO` — 51,9x medidos, el caso canónico
├── 🔵 dotnet/                   ← `OPERATIVO` — ReadyToRun / TieredPGO / AOT
├── 🐹 go/                       ← `OPERATIVO` — binario AOT + sync.Once
└── 🦀 rust/                     ← `OPERATIVO` — AOT + OnceLock con garantía de tipo
```
