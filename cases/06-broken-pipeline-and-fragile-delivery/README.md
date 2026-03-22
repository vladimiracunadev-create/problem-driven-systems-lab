# 🚚 Caso 06 — Pipeline roto y entrega frágil

[![Estado](https://img.shields.io/badge/Estado-Base%20documental%20lista-blue)](docs/)
[![Categoría](https://img.shields.io/badge/Categoría-Entrega-green)](../../README.md)

---

## 🔍 Qué problema representa

El software funciona correctamente en desarrollo, pero **falla al desplegar, al promover cambios entre entornos o al intentar revertir un incidente** de forma segura. Cada despliegue es un evento de riesgo.

> Un delivery frágil aumenta el riesgo operativo, detiene equipos y hace que cada cambio sea más caro que el anterior.

---

## ⚠️ Síntomas típicos

| Síntoma | Dónde se observa |
|---------|-----------------|
| Cambios menores rompen despliegues sin causa clara | Logs de CI/CD / postmortems |
| Diferencias entre ambientes dev, staging y producción | Issues que solo ocurren en producción |
| Rollback poco claro o dependiente de intervención manual | Playbooks de incidentes |
| Dependencia de una sola persona para publicar | Conversaciones de equipo / bus factor |

---

## 🧩 Causas frecuentes

- **Pipelines poco versionados** — acoplados al servidor o a configuración local
- **Variables y secretos sin control** — diferencias silenciosas entre entornos
- **Pruebas insuficientes antes del deploy** — la validación llega demasiado tarde
- **Drift de configuración** — los entornos divergen con el tiempo sin que nadie lo note

---

## 🔬 Estrategia de diagnóstico

1. Revisar los pasos manuales del flujo de despliegue actual
2. Mapear dependencias y configuraciones por ambiente
3. Analizar postmortems de fallas previas de despliegue
4. Verificar reproducibilidad local, CI y producción (¿son iguales?)

---

## 💡 Opciones de solución

| Opción | Impacto |
|--------|---------|
| **Estandarizar pipelines como código** | Elimina ambigüedad y dependencia de personas |
| **Automatizar validaciones previas al deploy** | Detecta problemas antes de que lleguen a producción |
| **Estrategia de rollback documentada** | Reduce tiempo de recuperación en incidentes |
| **Feature flags** | Desacopla despliegue de activación de funcionalidades |
| **Separar configuración, secretos y código** | Elimina el drift de configuración entre entornos |

---

## ⚖️ Trade-offs

| Decisión | Ventaja | Costo |
|----------|---------|-------|
| Más controles en pipeline | Menos riesgo de falla | Ciclo inicial más largo |
| Feature flags | Despliegue sin riesgo de activación | Requieren gobierno y limpieza |
| IaC estricta | Entornos idénticos y reproducibles | Disciplina alta para mantener |

---

## 💼 Valor de negocio

> Permite publicar con menos riesgo, reducir incidentes de despliegue
> y mejorar la velocidad real de entrega del equipo.

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
make case-up CASE=06-broken-pipeline-and-fragile-delivery STACK=php
make compare-up CASE=06-broken-pipeline-and-fragile-delivery
```

---

## 📁 Estructura

```text
06-broken-pipeline-and-fragile-delivery/
├── 📄 README.md
├── 🐳 compose.compare.yml
├── 📚 docs/
├── 🔗 shared/
├── 🐘 php/  🟢 node/  🐍 python/  ☕ java/  🔵 dotnet/
```
