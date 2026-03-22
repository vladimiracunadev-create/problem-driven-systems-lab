# 🧠 Caso 05 — Presión de memoria y fugas de recursos

[![Estado](https://img.shields.io/badge/Estado-Base%20documental%20lista-blue)](docs/)
[![Categoría](https://img.shields.io/badge/Categoría-Rendimiento-red)](../../README.md)

---

## 🔍 Qué problema representa

El sistema consume **memoria, descriptores de archivo o conexiones de forma progresiva** hasta degradar el servicio o caerse. El problema es silencioso: no hay un error explícito hasta que ya es demasiado tarde.

> El sistema que "se pone lento solo" o "hay que reiniciarlo cada cierto tiempo" es la manifestación más frecuente.

---

## ⚠️ Síntomas típicos

| Síntoma | Dónde se observa |
|---------|-----------------|
| Reinicios periódicos del proceso sin error visible claro | Logs de sistema / Docker / systemd |
| Uso de memoria que crece con el tiempo sin liberarse | Métricas de memoria del contenedor o proceso |
| Degradación gradual de respuesta a lo largo del día | APM / latencia en función del tiempo |
| "Hay que reiniciar el servidor una vez por semana" | Conocimiento operacional del equipo |

---

## 🧩 Causas frecuentes

- **Referencias retenidas sin liberar** — objetos que nunca salen del heap
- **Conexiones a DB/cola que no se cierran** — pool que se agota gradualmente
- **Caché en memoria sin límite ni política de expiración** — crece indefinidamente
- **Listeners o handlers que se registran repetidamente** — event listeners que se acumulan
- **Buffers que no se vacían** — I/O acumulado sin flush

---

## 🔬 Estrategia de diagnóstico

1. Medir tendencia de memoria en función del tiempo (no solo el pico)
2. Perfilar el heap con herramientas específicas del runtime
3. Buscar objetos que crecen sin decrecer en reportes de GC
4. Verificar apertura/cierre de conexiones, descriptores y sockets
5. Revisar si el problema se reproduce en localmente o solo en producción

---

## 💡 Opciones de solución

| Opción | Cuándo aplica |
|--------|--------------|
| **Limitar y rotar caché en memoria** | Cuando el caché crece sin control |
| **Revisar ciclo de vida de conexiones** — usar pools correctamente | Siempre, especialmente con pools de DB |
| **Weak references o colecciones con TTL** | Para caches y registros de corta vida |
| **Profiling continuo en preproducción** | Para detectar leaks antes de producción |
| **Límites de recursos en Docker** | Para hacer visibles los problemas antes de que dañen el host |

---

## ⚖️ Trade-offs

| Decisión | Ventaja | Costo |
|----------|---------|-------|
| Reiniciar el proceso como "solución" | Recuperación rápida | Oculta el problema real y lo hace recurrente |
| Profiling en producción | Detecta problemas reales | Puede añadir overhead |
| Límites de memoria en contenedor | Falla rápido y visible | Requiere ajuste fino |

---

## 💼 Valor de negocio

> Disminuye incidentes silenciosos, reinicios inesperados
> y consumo innecesario de infraestructura.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
|-------|--------|
| 🐘 PHP 8 | 🔧 Estructura lista |
| 🟢 Node.js | 🔧 Estructura lista |
| 🐍 Python | 🔧 Estructura lista |
| ☕ Java | 🔧 Estructura lista |
| 🔵 .NET 8 | 🔧 Estructura lista |

---

## 🚀 Cómo levantar

```bash
make case-up CASE=05-memory-pressure-and-resource-leaks STACK=php
make compare-up CASE=05-memory-pressure-and-resource-leaks
```

---

## 📁 Estructura

```text
05-memory-pressure-and-resource-leaks/
├── 📄 README.md
├── 🐳 compose.compare.yml
├── 📚 docs/
├── 🔗 shared/
├── 🐘 php/  🟢 node/  🐍 python/  ☕ java/  🔵 dotnet/
```
