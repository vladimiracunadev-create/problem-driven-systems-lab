# 🧠 Caso 05 — Presión de memoria y fugas de recursos

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-success)](php/README.md)
[![Stacks](https://img.shields.io/badge/Stacks-PHP%20%C2%B7%20Python%20%C2%B7%20Node%20%C2%B7%20Java-blue)](#-stacks-disponibles)
[![Categoría](https://img.shields.io/badge/Categoría-Rendimiento-red)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda viven en el link de arriba y en `comparison.md`.

---

## 🔍 Qué problema representa

El sistema consume memoria, descriptores o conexiones de forma **progresiva** hasta degradar o caerse. La señal típica: en dev funciona perfecto; en producción, tras unas horas, los reinicios automáticos empiezan a aparecer "sin razón aparente".

**Las fugas no fallan al instante** — acumulan estado, presionan al GC (o al asignador), generan jitter de latencia, y eventualmente disparan OOM kills o reinicios "preventivos". Son incidentes caros porque son **difíciles de reproducir** y casi imposibles de detectar sin telemetría continua.

---

## ⚠️ Síntomas típicos

- Uso de memoria creciente que **nunca vuelve a línea base** tras requests
- OOM kills, reinicios o garbage collection excesiva
- Conexiones, sockets o file descriptors que no se liberan
- Procesos estables localmente pero **inestables bajo carga real prolongada** (soak)
- Latencia que se degrada con el tiempo, no con la carga puntual

---

## 🧩 Causas frecuentes

- Colecciones o caches **sin límites** (un `Map`/`Array`/`List` que crece para siempre)
- Listeners, closures o referencias retenidas (cierra los datos por captura léxica)
- Streams, conexiones o archivos **sin cierre correcto** en path de error
- Buffers grandes o serialización costosa que se mantiene viva más de lo necesario

---

## 🔬 Estrategia de diagnóstico

- Tomar snapshots de memoria o heap **antes y después** de operaciones repetidas
- Medir crecimiento por operación: si crece linealmente con N requests, hay leak real
- Auditar recursos abiertos y lifecycle de objetos (¿quién retiene esa referencia?)
- Comparar comportamiento en pruebas **prolongadas (soak)** — no en una sola corrida

---

## 💡 Opciones de solución

- **Liberar recursos explícitamente** cuando corresponda (`close`, `dispose`, `unset`, `try-with-resources`)
- **Acotar cachés y buffers** con políticas de eviction (LRU, TTL, cap fijo)
- **Revisar ciclos de vida** de objetos persistentes — singletons, listeners globales, módulos cargados
- **Soak testing en CI**: correr durante horas para detectar lo que un test corto no ve

---

## 🏗️ Implementación actual

### ✅ PHP 8 (memory_get_usage + retención explícita)

`batch-legacy` acumula estado entre requests en un módulo estático; `batch-optimized` aplica cache acotada con eviction. `memory_get_usage()` y métricas locales dejan visible el `retained_kb` y el `pressure_level` en el tiempo. Ver [`php/README.md`](php/README.md). Modo aislado: puerto `815`.

### Python 3.12 (`tracemalloc` + `gc.collect()`)

Usa `tracemalloc.start()` para diff snapshots, `sys.getsizeof()` para medir objetos individuales, `gc.collect()` para liberar ciclos. Cache acotada con `collections.OrderedDict` + `popitem(last=False)` como LRU. Ver [`python/README.md`](python/README.md). Modo aislado: puerto `835`. Hub: `http://localhost:8200/05/`.

### Node.js 20 (heap V8 + RSS + external)

`process.memoryUsage()` expone los 4 números clave: `heapUsed`, `heapTotal`, `rss`, `external`. La fuga real es un array de módulo que retiene buffers cross-request. La versión optimizada usa un `Map` acotado con eviction explícita. Ver [`node/README.md`](node/README.md). Modo aislado: puerto `825`. Hub: `http://localhost:8300/05/`.

### Java 21 (`LinkedHashMap` LRU + `Runtime` metrics)

**`LinkedHashMap` con `removeEldestEntry`** es LRU built-in del JDK — una línea agrega política de eviction. `Runtime.getRuntime().totalMemory()/freeMemory()/maxMemory()` mide el heap del JVM directo, sin agente. El leak legacy es una `static List<byte[]>` retenida por una raíz GC: el GC corre, no encuentra nada que liberar, el heap crece monótonamente hasta `-Xmx`. Ver [`java/README.md`](java/README.md). Modo aislado: puerto `845`. Hub: `http://localhost:8400/05/`.

### .NET (espacio de crecimiento)

Estructura dockerizada lista; sin paridad funcional todavía.

---

## ⚖️ Trade-offs

- Más liberación manual puede aumentar la complejidad y el riesgo de double-free (en lenguajes con GC, peor: liberar muy agresivo invalida caches útiles).
- Pools y cachés mal configurados también degradan rendimiento (un cap muy bajo = misses costosos).
- Reducir buffers puede subir uso de CPU o I/O — el ahorro de RAM no es gratis.
- "Leak en lenguaje con GC" es **trampa semántica**: el GC funciona, pero las referencias siguen alcanzables desde una raíz. Hay que entender el modelo, no culpar al runtime.

---

## 💼 Valor de negocio

Disminuye **incidentes silenciosos**, reinicios inesperados y consumo innecesario de infraestructura. Permite **dimensionar correctamente** (saber cuánta RAM realmente necesita un servicio, no inflar "por si acaso"). Reduce páginas operacionales por OOM y mejora la confianza en deploys largos sin restart programado.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8 | `OPERATIVO` (`memory_get_usage` + retención explícita en módulo) |
| 🐍 Python 3.12 | `OPERATIVO` (`tracemalloc` + `gc.collect()` + cache acotado) |
| 🟢 Node.js 20 | `OPERATIVO` (`process.memoryUsage()` con heap V8 + RSS + external) |
| ☕ Java 21 | `OPERATIVO` (`LinkedHashMap.removeEldestEntry` LRU built-in + `Runtime` metrics) |
| 🔵 .NET 8 | 🔧 Estructura lista |

---

## 🚀 Cómo levantar

**Modo hub (recomendado):**
```bash
docker compose -f compose.root.yml    up -d --build && curl http://localhost:8100/05/health   # PHP
docker compose -f compose.python.yml  up -d --build && curl http://localhost:8200/05/health   # Python
docker compose -f compose.nodejs.yml  up -d --build && curl http://localhost:8300/05/health   # Node
docker compose -f compose.java.yml    up -d --build && curl http://localhost:8400/05/health   # Java
```

**Modo aislado (recomendado para este caso — medir memoria sin contaminación):**
```bash
docker compose -f cases/05-memory-pressure-and-resource-leaks/php/compose.yml    up -d --build  # :815
docker compose -f cases/05-memory-pressure-and-resource-leaks/python/compose.yml up -d --build  # :835
docker compose -f cases/05-memory-pressure-and-resource-leaks/node/compose.yml   up -d --build  # :825
docker compose -f cases/05-memory-pressure-and-resource-leaks/java/compose.yml   up -d --build  # :845
```

**Generar presión y observar la diferencia (ejemplo Java):**
```bash
# Legacy: 50 requests con 128 KB cada uno → heap crece monotonicamente
for i in {1..50}; do curl -s "http://localhost:8400/05/batch-legacy?size_kb=128" > /dev/null; done
curl http://localhost:8400/05/state   # heap_used_mb sube, retained_count alto

# Optimized: 5000 requests con cap=1000 → heap estable, eviction visible
for i in {1..5000}; do curl -s "http://localhost:8400/05/batch-optimized?size_kb=64" > /dev/null; done
curl http://localhost:8400/05/state   # heap estable, evictions_total > 4000
```

---

## 📚 Lectura recomendada

| Documento | Qué cubre |
| --- | --- |
| [`comparison.md`](comparison.md) | Comparativa multi-stack (PHP · Python · Node.js · Java) con snippets y diferencias del modelo de memoria por runtime |
| [`docs/context.md`](docs/context.md) | Por qué las fugas se ven tarde en producción |
| [`docs/symptoms.md`](docs/symptoms.md) | Cómo se ve un leak antes del OOM kill |
| [`docs/diagnosis.md`](docs/diagnosis.md) | Estrategia de snapshots y diff |
| [`docs/root-causes.md`](docs/root-causes.md) | Las 4 causas más frecuentes |
| [`docs/solution-options.md`](docs/solution-options.md) | Opciones ordenadas por riesgo |
| [`docs/trade-offs.md`](docs/trade-offs.md) | Lo que cuesta cada estrategia de mitigación |
| [`docs/business-value.md`](docs/business-value.md) | Impacto operacional y de costos cloud |

---

## 📁 Estructura del caso

```
05-memory-pressure-and-resource-leaks/
├── README.md                    ← este archivo
├── comparison.md                ← comparativa multi-stack PHP · Python · Node · Java
├── compose.compare.yml          ← levanta los 4 stacks juntos
├── docs/                        ← análisis problem-driven (8 documentos)
├── shared/                      ← assets compartidos
├── 🐘 php/                      ← `OPERATIVO` — memory_get_usage + retención
├── 🐍 python/                   ← `OPERATIVO` — tracemalloc + cache acotada
├── 🟢 node/                     ← `OPERATIVO` — process.memoryUsage heap V8
├── ☕ java/                     ← `OPERATIVO` — LinkedHashMap LRU + Runtime
└── 🔵 dotnet/                   ← 🔧 estructura lista
```
