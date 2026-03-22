# ⛓️ Caso 04 — Cadena de timeouts y tormentas de reintentos

[![Estado](https://img.shields.io/badge/Estado-Base%20documental%20lista-blue)](docs/)
[![Categoría](https://img.shields.io/badge/Categoría-Resiliencia-orange)](../../README.md)

---

## 🔍 Qué problema representa

Una integración lenta o inestable dispara **reintentos agresivos, bloqueos de hilo y cascadas de fallas** que van afectando a servicios en cadena. Un problema puntual en un componente termina tumbando todo el sistema.

> El sistema propio se convierte en víctima del comportamiento de un tercero o de un componente más lento.

---

## ⚠️ Síntomas típicos

| Síntoma | Dónde se observa |
|---------|-----------------|
| Un fallo externo puntual tumba el sistema propio | Alertas de disponibilidad de alto nivel |
| Reintentos que amplifican la carga en lugar de mitigarla | Logs de requests salientes con repetición |
| Tiempos de respuesta que se vuelven impredecibles | APM / p95/p99 de endpoints |
| Cascada de errores entre servicios internos | Trazas distribuidas |

---

## 🧩 Causas frecuentes

- **Timeout sin límite** — las conexiones esperan sin deadline definido
- **Reintentos sin backoff** — se golpea el servicio caído repetidamente
- **Sin circuit breaker** — no hay mecanismo para abrir el circuito ante fallos continuos
- **Dependencia síncrona no protegida** — el hilo espera hasta que el externo responda

---

## 🔬 Estrategia de diagnóstico

1. Mapear todas las dependencias externas del sistema
2. Revisar si existen timeouts configurados en cada integración
3. Analizar patrones de retry y si tienen backoff exponencial
4. Verificar si hay circuit breakers implementados
5. Revisar cómo se propagan los errores del externo al usuario final

---

## 💡 Opciones de solución

| Patrón | Cuándo aplicar |
|--------|---------------|
| **Timeout con deadline explícito** | Siempre — nunca esperar indefinidamente |
| **Retry con backoff exponencial + jitter** | Cuando el error puede ser transitorio |
| **Circuit breaker** | Cuando los fallos son continuos y la degradación se propaga |
| **Bulkhead / aislamiento de recursos** | Para proteger el sistema propio de una dependencia inestable |
| **Fallback** | Cuando existe una respuesta alternativa aceptable (caché, degradado) |

---

## ⚖️ Trade-offs

| Decisión | Ventaja | Costo |
|----------|---------|-------|
| Circuit breaker | Aísla las fallas del externo | Lógica más compleja de configurar y mantener |
| Fallback | Mejor experiencia degradada | Puede enmascarar problemas reales |
| Retry con backoff | Maneja errores transitorios | Aumenta latencia en el caso de fallo real |

---

## 💼 Valor de negocio

> Evita caídas en cascada y mejora la resiliencia del sistema
> frente a terceros o componentes internos inestables.

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
make case-up CASE=04-timeout-chain-and-retry-storms STACK=php
make compare-up CASE=04-timeout-chain-and-retry-storms
```

---

## 📁 Estructura

```text
04-timeout-chain-and-retry-storms/
├── 📄 README.md
├── 🐳 compose.compare.yml
├── 📚 docs/
├── 🔗 shared/
├── 🐘 php/  🟢 node/  🐍 python/  ☕ java/  🔵 dotnet/
```
