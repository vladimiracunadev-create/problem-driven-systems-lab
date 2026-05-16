# 🌐 Caso 09 — Integración externa inestable

[![Estado](https://img.shields.io/badge/Estado-Multi--stack%20operativo-success)](php/README.md)
[![Stacks](https://img.shields.io/badge/Stacks-PHP%20%C2%B7%20Python%20%C2%B7%20Node%20%C2%B7%20Java-blue)](#-stacks-disponibles)
[![Categoría](https://img.shields.io/badge/Categoría-Resiliencia-orange)](../../README.md)

> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda viven en el link de arriba y en `comparison.md`.

---

## 🔍 Qué problema representa

Una API, servicio o proveedor externo introduce **latencia, errores intermitentes o reglas cambiantes** que afectan al sistema propio. El control interno termina donde empieza el tercero — y ahí es exactamente donde la resiliencia debe comenzar a aplicarse.

**No controlamos al tercero.** No controlamos su uptime, ni su versionado, ni sus rate limits, ni su ventana de mantenimiento. Lo que sí controlamos es **cómo respondemos a su falla**: con cache, con budget, con breaker, con schema mapping defensivo, con fallback degradado. El lab demuestra esa diferencia.

---

## ⚠️ Síntomas típicos

- Errores **intermitentes difíciles de reproducir** (anda, no anda, anda otra vez)
- Respuestas **lentas o con formatos cambiantes** (drift de schema sin aviso)
- **Dependencia funcional alta** del proveedor (si cae, caemos)
- Necesidad de **reprocesar manualmente** cuando algo se pierde en el aire

---

## 🧩 Causas frecuentes

- Contratos **débiles o mal versionados** del proveedor
- **Rate limits no considerados** en el diseño del cliente
- Manejo insuficiente de errores y reintentos
- Falta de **almacenamiento intermedio** o idempotencia

---

## 🔬 Estrategia de diagnóstico

- Clasificar **tipos de fallas y frecuencia** (timeout vs 5xx vs schema drift vs rate limit)
- Medir **dependencia por flujo de negocio** (cuáles dejan al negocio sin operar)
- Revisar contratos, timeout y políticas del proveedor (SLA real vs documentado)
- Diseñar **pruebas de resiliencia** controladas (chaos engineering puntual)

---

## 💡 Opciones de solución

- **Adaptadores internos** para desacoplar contrato externo (proxy de schema)
- **Manejo idempotente** y colas cuando aplique
- **Circuit breaker, caching y fallback** combinados
- **Versionado defensivo** y validación de payloads (no asumir, verificar)

---

## 🗺️ Diagrama — Adapter endurecido: budget → cache → breaker → schema mapping

```text
                       request: /catalog-hardened?sku=widget-A
                                          │
                                          ▼
                            ┌─────────────────────────┐
                            │ providerBudget.tryAcquire()│   ← Semaphore (max N/window)
                            └────────────┬────────────┘
                       permits == 0       │      permit obtenido
                  ┌─────────┘             │              └─────────────────────────┐
                  ▼                                                                 ▼
        ╔════════════════════════╗                              ┌────────────────────────────────┐
        ║ served_from: cache     ║                              │ breaker.get() == "open"?       │
        ║ reason: budget_exhausted║                              └──────┬──────────────────┬──────┘
        ╚════════════════════════╝                                     │ si               │ no
                  ▲                                                     ▼                  ▼
                  │                                       ╔════════════════════╗   call provider real
                  │                                       ║ served_from: cache ║   (simulado en lab)
                  │                                       ║ breaker:open       ║          │
                  │                                       ╚════════════════════╝          ▼
                  │                                                                ┌─────────────────┐
                  │   ┌────────────────────────────────────────────────────────────┤  provider falla?│
                  │   │                                                            └────┬────────────┘
                  │   │                                                                 │
                  │   │                                                       no        │ si
                  │   │                                                ┌────────────────┴────────────┐
                  │   │                                                ▼                              ▼
                  │   │                                       ┌─────────────────┐         ┌──────────────────────┐
                  │   │                                       │ snapshotCache   │         │ breaker.set("open")  │
                  │   │                                       │   .put(fresh)   │         │ AtomicReference CAS  │
                  │   │                                       │ breaker.set     │         └──────────┬───────────┘
                  │   │                                       │   ("closed")    │                    │
                  │   │                                       └────────┬────────┘                    │
                  │   │                                                │                              │
                  │   │                                                ▼                              ▼
                  │   │                                       ╔══════════════════╗      ╔════════════════════╗
                  │   │                                       ║ served_from:     ║      ║ served_from: cache ║
                  │   │                                       ║   provider       ║      ║ snapshot (stale)   ║
                  │   │                                       ╚══════════════════╝      ╚═══════╤════════════╝
                  │   │                                                                          │
                  └───┴──────────────────────────────────────────────────────────────────────────┘

  Legacy: cualquier fallo del provider = falla visible al cliente.
  Hardened: budget protege cuota; cache protege disponibilidad; breaker protege al provider.
```

---

## 🏗️ Implementación actual

### ✅ PHP 8

`catalog-legacy` pega directo al proveedor sin protecciones; `catalog-hardened` usa adapter + cache + breaker + budget. `integration/state`, `sync-events` y `diagnostics/summary` exponen budget restante, schema mappings y eventos de cuarentena. Ver [`php/README.md`](php/README.md). Modo aislado: puerto `819`.

### Python 3.12

Misma lógica con stdlib + `dict` como cache + `threading.Lock` para budget. Ver [`python/README.md`](python/README.md). Modo aislado: puerto `839`. Hub: `http://localhost:8200/09/`.

### Node.js 20

`AbortSignal.timeout(ms)` (Node 18+) marca deadline del llamado externo + circuit breaker en memoria con tres estados (`closed`/`open`/`half_open`) y reapertura automática tras cooldown. Ver [`node/README.md`](node/README.md). Modo aislado: puerto `829`. Hub: `http://localhost:8300/09/`.

### Java 21

`Semaphore` como budget de cuota (`tryAcquire()` no bloquea — si no hay permits, sirve snapshot). `ConcurrentHashMap` como snapshot cache thread-safe. `AtomicReference<String>` como breaker con CAS implícito. Ver [`java/README.md`](java/README.md). Modo aislado: puerto `849`. Hub: `http://localhost:8400/09/`.

### .NET (espacio de crecimiento)

Estructura dockerizada lista; sin paridad funcional todavía.

---

## ⚖️ Trade-offs

- Más **desacoplamiento implica más componentes** que mantener
- **Persistencia intermedia** requiere limpieza y soporte (TTLs, vacuums)
- **Fallback puede afectar frescura de datos** (snapshot viejo vs error visible)

---

## 💼 Valor de negocio

**Mitiga dependencia de terceros** y evita que un proveedor defina la estabilidad de tu producto. Ejemplos reales: el catálogo sigue navegable aunque el provider esté caído; el checkout sigue cotizando con snapshot mientras la API externa se recupera; el partner externo no tira al sistema entero cuando aplica un rate limit nuevo.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
| --- | --- |
| 🐘 PHP 8 | `OPERATIVO` (adapter + cache + breaker persistente) |
| 🐍 Python 3.12 | `OPERATIVO` (stdlib + threading.Lock + dict cache) |
| 🟢 Node.js 20 | `OPERATIVO` (`AbortSignal.timeout` + CB en memoria) |
| ☕ Java 21 | `OPERATIVO` (`Semaphore` budget + snapshot cache + `AtomicReference` breaker) |
| 🔵 .NET 8 | 🔧 Estructura lista |

---

## 🚀 Cómo levantar

**Modo hub (recomendado):**
```bash
docker compose -f compose.root.yml    up -d --build && curl http://localhost:8100/09/health   # PHP
docker compose -f compose.python.yml  up -d --build && curl http://localhost:8200/09/health   # Python
docker compose -f compose.nodejs.yml  up -d --build && curl http://localhost:8300/09/health   # Node
docker compose -f compose.java.yml    up -d --build && curl http://localhost:8400/09/health   # Java
```

**Agotar budget y observar fallback a snapshot cache (ejemplo Java, budget=5):**
```bash
# 6 calls consecutivos: los primeros 5 al provider, el 6to es budget_exhausted → cache
for i in 1 2 3 4 5 6 7; do
  curl -s "http://localhost:8400/09/catalog-hardened?sku=widget-A" | head -c 120; echo
done

# Estado: breaker, budget restante, tamaño del cache
curl http://localhost:8400/09/sync-events
```

---

## 📚 Lectura recomendada

| Documento | Qué cubre |
| --- | --- |
| [`comparison.md`](comparison.md) | Comparativa multi-stack con snippets de adapter por lenguaje |
| [`docs/postmortem.md`](docs/postmortem.md) | Postmortem del incidente que motivó el caso |
| [`docs/context.md`](docs/context.md) | Por qué la resiliencia empieza donde termina nuestro control |
| [`docs/symptoms.md`](docs/symptoms.md) | Cómo se ve un proveedor inestable desde el log de prod |
| [`docs/root-causes.md`](docs/root-causes.md) | Las 4 causas de fragilidad externa más frecuentes |
| [`docs/solution-options.md`](docs/solution-options.md) | Adapter, cache, breaker, budget, schema versioning |
| [`docs/trade-offs.md`](docs/trade-offs.md) | Lo que cuesta sostener componentes extras |
| [`docs/business-value.md`](docs/business-value.md) | Continuidad de operación frente a terceros |

---

## 📁 Estructura del caso

```
09-unstable-external-integration/
├── README.md                    ← este archivo
├── comparison.md                ← comparativa multi-stack
├── compose.compare.yml          ← los 4 stacks juntos
├── docs/                        ← análisis + postmortem
├── shared/                      ← assets compartidos
├── 🐘 php/                      ← `OPERATIVO` — adapter + breaker persistente
├── 🐍 python/                   ← `OPERATIVO` — stdlib + Lock + dict cache
├── 🟢 node/                     ← `OPERATIVO` — AbortSignal.timeout + CB
├── ☕ java/                     ← `OPERATIVO` — Semaphore + cache + AtomicReference
└── 🔵 dotnet/                   ← 🔧 estructura lista
```
