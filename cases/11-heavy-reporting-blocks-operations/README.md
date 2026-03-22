# 📊 Caso 11 — Reportes pesados que bloquean la operación

[![Estado](https://img.shields.io/badge/Estado-Base%20documental%20lista-blue)](docs/)
[![Categoría](https://img.shields.io/badge/Categoría-Operaciones-teal)](../../README.md)

---

## 🔍 Qué problema representa

Las consultas y procesos de reporting **compiten directamente con la operación transaccional** y degradan el sistema completo durante la generación de informes. El día de cierre mensual, el sistema va a paso de tortuga.

> La analítica y la operación tienen naturalezas distintas. Mezclarlas sin estrategia convierte cada reporte en un incidente.

---

## ⚠️ Síntomas típicos

| Síntoma | Dónde se observa |
|---------|-----------------|
| El sistema se pone lento durante reportes de cierre | Latencia elevada en horarios específicos del mes |
| Queries de análisis que forzan full table scans en producción | Logs de slow queries / explain analyze |
| Base de datos compartida entre OLTP y OLAP sin separación | Arquitectura del sistema |
| Usuarios que experimentan timeouts en operaciones simples durante reportes | Alertas / quejas de usuarios |

---

## 🧩 Causas frecuentes

- **Base OLTP usada directamente para OLAP** — un diseño que no separa responsabilidades
- **Queries sin límites** — sin paginación ni time-bounds en reportes
- **Sin índices para lectura analítica** — los índices transaccionales no sirven para aggregations
- **Reportes síncronos** — el usuario espera bloqueado mientras la query del reporte corre

---

## 🔬 Estrategia de diagnóstico

1. Identificar las queries más lentas durante períodos de reporte
2. Medir el impacto de esas queries en los tiempos de la operación transaccional
3. Revisar si existe separación entre bases OLTP y OLAP
4. Analizar si los reportes tienen límites de tiempo y volumen

---

## 💡 Opciones de solución

| Opción | Cuándo aplica |
|--------|--------------|
| **Réplica de lectura dedicada** | Para separar tráfico analítico del transaccional |
| **Procesamiento asíncrono de reportes** | El usuario recibe el reporte luego, no espera bloqueado |
| **Pre-aggregación** | Calcular resúmenes con anticipación (batch nocturno) |
| **Data warehouse separado** | Para cargas analíticas complejas o de alto volumen |
| **Throttling de reportes** | Limitar recursos disponibles para queries analíticas |

---

## ⚖️ Trade-offs

| Decisión | Ventaja | Costo |
|----------|---------|-------|
| Réplica de lectura | Separación sin cambiar arquitectura core | Replicación lag / datos no 100% real-time |
| Reportes asíncronos | Protege la operación completamente | Cambio en la experiencia del usuario |
| Pre-aggregación | Muy rápido para el usuario | Datos no en tiempo real, lógica de cálculo extra |

---

## 💼 Valor de negocio

> Protege la operación diaria y permite crecer en analítica
> sin romper el sistema transaccional.

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
make case-up CASE=11-heavy-reporting-blocks-operations STACK=php
make compare-up CASE=11-heavy-reporting-blocks-operations
```

---

## 📁 Estructura

```text
11-heavy-reporting-blocks-operations/
├── 📄 README.md
├── 🐳 compose.compare.yml
├── 📚 docs/
├── 🔗 shared/
├── 🐘 php/  🟢 node/  🐍 python/  ☕ java/  🔵 dotnet/
```
