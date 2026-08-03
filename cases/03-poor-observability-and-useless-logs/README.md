> [!IMPORTANT]
> **📖 [Ver Análisis Técnico Senior de esta solución (PHP)](php/README.md)**
>
> Este documento es un resumen ejecutivo. La evidencia de ingeniería, los algoritmos y la remediación profunda se encuentran en el link de arriba.

---

## 🔍 Qué problema representa

Existen errores e incidentes, pero **no hay trazabilidad suficiente para identificar la causa raíz** de forma rápida y confiable. Los logs registran sin contexto real, las alertas son ruidosas y los dashboards no ayudan a priorizar.

> Sin observabilidad, incluso los equipos fuertes pierden horas en diagnósticos reactivos y decisiones incompletas.

---

## ⚠️ Síntomas típicos

| Síntoma | Dónde se observa |
|---------|-----------------|
| Logs sin context-id ni estructura consistente | Salida de logs de la aplicación |
| Métricas inexistentes o dashboards irrelevantes | Herramientas de monitoreo |
| Alertas ruidosas que disparan sin ser accionables | PagerDuty / Slack / alerting |
| Imposibilidad de correlacionar front, backend, queues y DB | Postmortems de incidentes |

---

## 🧩 Causas frecuentes

- **Logging agregado tarde y sin estándar** — cada módulo loguea diferente
- **Métricas no alineadas a objetivos de negocio** — se mide CPU pero no conversión
- **Falta de trazas distribuidas** — no hay correlation ID entre servicios
- **Entornos distintos sin convención común** — producción no se puede reproducir

---

## 🔬 Estrategia de diagnóstico

1. Auditar logs, métricas, trazas y alertas disponibles
2. Revisar cobertura de flujos críticos y errores relevantes
3. Definir qué preguntas operativas **no** se pueden responder hoy
4. Mapear vacíos entre síntoma visible, evidencia disponible y causa real

---

## 💡 Opciones de solución

| Opción | Impacto |
|--------|---------|
| Estandarizar logs estructurados (JSON + campos base) | Inmediato, base de todo lo demás |
| Agregar correlation IDs y trazas | Indispensable para sistemas distribuidos |
| Métricas de negocio y de plataforma | Permite alertas basadas en impacto real |
| Reducir alertas a señales accionables | Reduce fatiga y mejora tiempo de respuesta |

---

## 🏗️ Implementación actual

### ✅ PHP 8, Node.js y Python

Los stacks PHP, Node.js y Python ya implementan este caso con dos modos del mismo flujo operacional:

- `checkout-legacy` -> logs pobres, genéricos y difíciles de correlacionar
- `checkout-observable` -> logs estructurados, `request_id`, `trace_id`, métricas y trazas locales
- `/logs/legacy`, `/logs/observable`, `/traces` y `/diagnostics/summary` -> permiten comparar qué tan diagnosticable es el incidente

### Java 21 (implementacion operativa)

Stack Java operativo con `ThreadLocal<RequestContext>` para propagar `correlation_id` durante todo el handler sin pasarlo por parametros (equivalente a `ScopedValue` de JDK 21 sin requerir preview flags), `UUID.randomUUID()` por request, y JSON estructurado construido con `StringBuilder` (sin Log4j/SLF4J — single-file sin deps). `/logs` devuelve los ultimos 200 logs estructurados al estilo Loki compacto. Limpieza con `CTX.remove()` en `finally` evita leak del contexto al proximo handler en el mismo thread. Ver [`java/README.md`](java/README.md). Hub: `http://localhost:8400/03/`. Aislado: puerto `843`.

### .NET 8 (implementacion operativa)

Stack .NET operativo con `AsyncLocal<RequestContext>` para propagar `correlation_id` a traves de `await` sobre el `ThreadPool` (equivalente moderno del `ThreadLocal` Java en codigo async), `Guid.NewGuid()` por request, y JSON estructurado con `System.Text.Json` (sin Serilog/NLog — sin dependencias externas). `/logs` devuelve los ultimos 200 logs estructurados. Ver [`dotnet/README.md`](dotnet/README.md). Hub: `http://localhost:8500/03/`. Aislado: puerto `853`.

### Go 1.23 (implementacion operativa)

Stack Go operativo con `context.Context` como contexto **explicito**: el correlation ID viaja como parametro, no en almacenamiento ambiente, y la clave es un tipo privado del paquete. `log/slog` da el JSON estructurado desde la stdlib — unico stack del lab donde el logger no es una libreria externa. Ver [`go/README.md`](go/README.md). Hub: `http://localhost:8600/03/`.

### Rust 1.83 (implementacion operativa)

Stack Rust operativo con `&RequestCtx` prestado por referencia: el borrow checker impide que esa referencia sobreviva al handler, asi que un contexto no puede filtrarse al request siguiente. Es la unica garantia de compilador del lab para este problema. Contrapartida honesta: `std` no trae logger estructurado y el JSON se arma con `format!`. Ver [`rust/README.md`](rust/README.md). Hub: `http://localhost:8700/03/`.

---

## ⚖️ Trade-offs

| Decisión | Ventaja | Costo |
|----------|---------|-------|
| Más telemetría | Más visibilidad | Más costo y ruido si no se filtra bien |
| Instrumentación extensa | Diagnóstico más rápido | Requiere disciplina continua |
| Observabilidad centralizada | Correlación entre servicios | Dependencia de infra adicional |

---

## 💼 Valor de negocio

> Buena observabilidad reduce MTTR, mejora la calidad de decisión en incidentes
> y fortalece la continuidad operacional del equipo.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
|-------|--------|
| 🐘 PHP 8 | ✅ Implementado (Docker + telemetría útil) |
| 🟢 Node.js | ✅ Implementado (legacy vs observable) |
| 🐍 Python | ✅ Implementado (legacy vs observable) |
| ☕ Java 21 | `OPERATIVO` (`ThreadLocal<RequestContext>` con correlation_id + log estructurado JSON) |
| 🔵 .NET 8 | `OPERATIVO` (`AsyncLocal<RequestContext>` + `System.Text.Json` estructurado) |

---

## 🚀 Cómo levantar

```bash
make case-up CASE=03-poor-observability-and-useless-logs STACK=php
make compare-up CASE=03-poor-observability-and-useless-logs
```

---

## 📚 Lectura recomendada

| Archivo | Contenido |
|---------|-----------|
| `docs/context.md` | Escenario del sistema ficticio |
| `docs/symptoms.md` | Síntomas observables |
| `docs/diagnosis.md` | Cómo auditar la observabilidad actual |
| `docs/root-causes.md` | Por qué la observabilidad es deficiente |
| `docs/solution-options.md` | Opciones y herramientas |
| `docs/trade-offs.md` | Costos de cada decisión |
| `docs/business-value.md` | Impacto en el equipo y la operación |

---

## 📁 Estructura

```text
03-poor-observability-and-useless-logs/
├── 📄 README.md
├── 🐳 compose.compare.yml
├── 📚 docs/
├── 🔗 shared/
├── 🐘 php/  🟢 node/  🐍 python/  ☕ java/  🔵 dotnet/  🐹 go/  🦀 rust/
```
