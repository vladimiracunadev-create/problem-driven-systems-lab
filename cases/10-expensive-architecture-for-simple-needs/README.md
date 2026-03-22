# 💰 Caso 10 — Arquitectura cara para un problema simple

[![Estado](https://img.shields.io/badge/Estado-Base%20documental%20lista-blue)](docs/)
[![Categoría](https://img.shields.io/badge/Categoría-Arquitectura-blueviolet)](../../README.md)

---

## 🔍 Qué problema representa

La solución técnica consume **más servicios, más complejidad y más costo** del que el problema de negocio realmente necesita. Se sobreingenia antes de validar. El equipo mantiene una arquitectura que nadie puede operar completamente.

> La complejidad que no aporta valor no es sofisticación — es deuda operacional.

---

## ⚠️ Síntomas típicos

| Síntoma | Dónde se observa |
|---------|-----------------|
| Factura de infraestructura desproporcionada al tráfico real | Costos de cloud mensual |
| Tiempo de desarrollo elevado para features simples | Velocidad del equipo / sprint velocity |
| Nadie en el equipo conoce todos los servicios corriendo | Onboarding / documentación operacional |
| Los incidentes son difíciles de aislar entre tantos componentes | Postmortems y diagnóstico |

---

## 🧩 Causas frecuentes

- **YAGNI ignorado** — se construye "para cuando crezcamos" sin evidencia de ese crecimiento
- **Micro-servicios prematuros** — separación sin dominio claro ni necesidad real
- **Adopción de tendencias sin contexto** — Kubernetes para un sistema con 10 usuarios
- **Sin métricas de uso real** — se diseña en el vacío sin datos de comportamiento

---

## 🔬 Estrategia de diagnóstico

1. Mapear todos los servicios activos y su costo mensual
2. Medir uso real de cada componente (tráfico, queries, latencia)
3. Comparar la complejidad operativa contra el valor entregado
4. Identificar qué podría resolverse con menos sin sacrificar el negocio

---

## 💡 Opciones de solución

| Opción | Cuándo aplica |
|--------|--------------|
| **Consolidar servicios** | Cuando hay micro-servicios sin razón de dominio real |
| **Simplificar la infra** | Cuando el costo operativo supera el beneficio de la separación |
| **Madurar progresivamente** | Empezar simple y escalar solo cuando hay evidencia de necesidad |
| **Medir antes de escalar** | Basar decisiones de arquitectura en datos reales del sistema |

---

## ⚖️ Trade-offs

| Decisión | Ventaja | Costo |
|----------|---------|-------|
| Monolito modulado simple | Operación y desarrollo más directos | Puede necesitar reescritura si crece mucho |
| Consolidar servicios | Menos costo y menos complejidad | Requiere coordinación y posible downtime |
| Mantener la arquitectura compleja | Sin regresiones durante transición | Deuda operacional continua |

---

## 💼 Valor de negocio

> Mejora la adaptabilidad del sistema, reduce costos en infraestructura
> y acelera la entrega manteniendo el foco en el problema de negocio real.

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
make case-up CASE=10-expensive-architecture-for-simple-needs STACK=php
make compare-up CASE=10-expensive-architecture-for-simple-needs
```

---

## 📁 Estructura

```text
10-expensive-architecture-for-simple-needs/
├── 📄 README.md
├── 🐳 compose.compare.yml
├── 📚 docs/
├── 🔗 shared/
├── 🐘 php/  🟢 node/  🐍 python/  ☕ java/  🔵 dotnet/
```
