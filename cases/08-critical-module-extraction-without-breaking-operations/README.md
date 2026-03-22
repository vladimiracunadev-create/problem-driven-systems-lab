# 🔧 Caso 08 — Extracción de módulo crítico sin romper operación

[![Estado](https://img.shields.io/badge/Estado-Base%20documental%20lista-blue)](docs/)
[![Categoría](https://img.shields.io/badge/Categoría-Arquitectura-blueviolet)](../../README.md)

---

## 🔍 Qué problema representa

Se necesita **desacoplar una parte clave del sistema**, pero esa parte participa en flujos altamente sensibles con alta visibilidad para el negocio. No admite quiebres. No admite ventanas de mantenimiento largas.

> Es la versión más delicada de la modernización: extraer sin cortar, mover sin romper.

---

## ⚠️ Síntomas típicos

| Síntoma | Dónde se observa |
|---------|-----------------|
| Módulo con demasiadas responsabilidades | Revisiones de código / métricas de complejidad |
| Cambios que requieren coordinación de todo el equipo | Planning / bloqueos de releases |
| Imposibilidad de versionar o desplegar ese módulo de forma independiente | Monorepo sin separación real |
| Acoplamiento que impide escalar o reemplazar una parte | Diseño de la arquitectura |

---

## 🧩 Causas frecuentes

- **Sin contratos explícitos** entre el módulo y sus consumidores
- **Lógica de negocio mezclada con infraestructura** — difícil separar qué hace qué
- **Estado compartido sin fronteras** — múltiples consumidores escribiendo al mismo recurso
- **Sin tests de contrato** — no hay validación de que el módulo cumple lo que promete

---

## 🔬 Estrategia de diagnóstico

1. Identificar las interfaces públicas reales del módulo (lo que los demás consumen)
2. Mapear los flujos de negocio que lo usan y su criticidad
3. Medir cobertura de testing del módulo y de los flujos dependientes
4. Definir el contrato esperado antes de empezar a mover nada

---

## 💡 Opciones de solución

| Patrón | Descripción |
|--------|------------|
| **Contrato explícito primero** | Definir la interfaz antes de mover la implementación |
| **Feature toggle por consumidor** | Activar el módulo extraído progresivamente por componente |
| **Proxy de compatibilidad** | Mantener la interfaz original delegando al nuevo módulo |
| **Event sourcing / mensajería** | Desacoplar con eventos para reducir dependencia síncrona |

---

## ⚖️ Trade-offs

| Decisión | Ventaja | Costo |
|----------|---------|-------|
| Proxy de compatibilidad | Transición sin cambios en consumidores | Doble lógica hasta completar la extracción |
| Mensajería asíncrona | Desacople real | Complejidad operacional nueva |
| Big bang extraction | Termina rápido | Riesgo muy alto de incidente |

---

## 💼 Valor de negocio

> Reduce riesgo operacional y habilita la evolución controlada
> de piezas críticas del negocio sin detener la operación.

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
make case-up CASE=08-critical-module-extraction-without-breaking-operations STACK=php
make compare-up CASE=08-critical-module-extraction-without-breaking-operations
```

---

## 📁 Estructura

```text
08-critical-module-extraction-without-breaking-operations/
├── 📄 README.md
├── 🐳 compose.compare.yml
├── 📚 docs/
├── 🔗 shared/
├── 🐘 php/  🟢 node/  🐍 python/  ☕ java/  🔵 dotnet/
```
