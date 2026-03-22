# 🗺️ Mapa de problemas

> Los 12 casos del laboratorio con su descripción técnica, síntomas y valor de negocio.

---

## 📊 Resumen visual

| # | Categoría | Problema central | Valor principal |
|---|-----------|-----------------|-----------------|
| ⚡ 01 | Rendimiento | API de reportes lenta bajo carga concurrente real | Reducir latencia y evitar sobredimensionar infra |
| 🔄 02 | Rendimiento | Demasiadas queries por request, ORM mal usado | Base sana = menos incidentes y menos costos |
| 🔭 03 | Observabilidad | Errores sin trazabilidad, causa raíz imposible de hallar | Menor MTTR y decisiones operacionales más precisas |
| ⛓️ 04 | Resiliencia | Integración lenta dispara reintentos y cascadas de fallas | Evitar caídas en cascada ante terceros inestables |
| 🧠 05 | Rendimiento | Memoria y descriptores que crecen hasta degradar el sistema | Eliminar reinicios silenciosos e infra innecesaria |
| 🚚 06 | Entrega | Software que funciona en dev pero falla al desplegar | Publicar con menos riesgo y revertir incidentes |
| 🏗️ 07 | Arquitectura | Sistema legacy crítico cuya evolución es muy lenta y cara | Renovar plataformas vivas sin reescritura total |
| 🔧 08 | Arquitectura | Módulo clave que participa en flujos sensibles y no admite quiebres | Desacople controlado de piezas críticas del negocio |
| 🌐 09 | Resiliencia | API externa con latencia, errores intermitentes y reglas cambiantes | Mitigar dependencia y estabilizar tu producto |
| 💰 10 | Arquitectura | Solución técnica más cara y compleja de lo que el problema necesita | Reducir costos y acelerar entrega con foco en el negocio |
| 📊 11 | Operaciones | Consultas de reporting que compiten con operación transaccional | Proteger la operación diaria durante analítica pesada |
| 🧑‍💼 12 | Operaciones | Conocimiento crítico concentrado en una sola persona o módulo | Reducir riesgo organizacional y mejorar continuidad |

---

## 🔍 Detalle por caso

---

### ⚡ 01 — API lenta bajo carga

**Problema:** La aplicación responde bien con pocos usuarios, pero degrada su latencia y estabilidad al aumentar la concurrencia. El diseño cae en filtros no sargables, patrón N+1 y procesamiento transaccional que compite con reportes pesados.

**Síntomas frecuentes:**
- p95/p99 de latencia que se dispara bajo carga
- Timeouts intermitentes al aumentar usuarios concurrentes
- CPU y conexiones de DB saturadas

**Valor:** Mejorar latencia reduce abandono, mejora estabilidad operacional y evita sobredimensionar infraestructura.

---

### 🔄 02 — N+1 queries y cuellos de botella en base de datos

**Problema:** La aplicación ejecuta demasiadas consultas por solicitud o usa el ORM de forma ineficiente, generando saturación de base de datos silenciosa que empeora con el tiempo.

**Síntomas frecuentes:**
- Gran cantidad de queries por request en el profiler
- CPU alta en la base con poca carga aparente de usuarios
- Respuestas lentas al consultar listas con relaciones

**Valor:** Una base sana evita incidentes recurrentes, mejora rendimiento transversal y reduce costos de hardware y licenciamiento.

---

### 🔭 03 — Observabilidad deficiente y logs inútiles

**Problema:** Existen errores e incidentes, pero no hay trazabilidad suficiente para identificar la causa raíz de forma rápida y confiable. Los logs registran sin contexto real.

**Síntomas frecuentes:**
- Incidentes sin causa raíz identificable en tiempo razonable
- Logs que dicen "error" pero sin correlación ni contexto
- Alertas que disparan sin datos accionables

**Valor:** Buena observabilidad reduce MTTR, mejora calidad de decisión y fortalece continuidad operacional.

---

### ⛓️ 04 — Cadena de timeouts y tormentas de reintentos

**Problema:** Una integración lenta o inestable dispara reintentos agresivos, bloqueos de hilo y cascadas de fallas que van afectando servicios en cadena.

**Síntomas frecuentes:**
- Un fallo puntual en un servicio externo tumba el sistema propio
- Reintentos que amplifican la carga en lugar de mitigarla
- Tiempos de respuesta que se vuelven impredecibles

**Valor:** Evita caídas en cascada y mejora resiliencia frente a terceros o componentes inestables.

---

### 🧠 05 — Presión de memoria y fugas de recursos

**Problema:** El sistema consume memoria, descriptores de archivo o conexiones de forma progresiva hasta degradar o caerse, generalmente sin un error explícito hasta demasiado tarde.

**Síntomas frecuentes:**
- Reinicios periódicos del proceso sin error visible claro
- Uso de memoria que crece con el tiempo sin liberarse
- Degradación gradual de respuesta a lo largo del día

**Valor:** Disminuye incidentes silenciosos, reinicios inesperados y consumo innecesario de infraestructura.

---

### 🚚 06 — Pipeline roto y entrega frágil

**Problema:** El software funciona correctamente en desarrollo, pero falla al desplegar, al promover cambios entre entornos o al intentar revertir incidentes con seguridad.

**Síntomas frecuentes:**
- Deploys manuales, frágiles o dependientes de una persona clave
- Imposibilidad de hacer rollback seguro y rápido
- Diferencias entre entornos que generan bugs en producción

**Valor:** Permite publicar con menos riesgo, reducir incidentes de despliegue y mejorar la velocidad real de entrega.

---

### 🏗️ 07 — Modernización incremental de monolito

**Problema:** El sistema legacy sigue siendo crítico, pero su evolución se vuelve lenta, riesgosa y costosa. Cambiar una parte rompe otras no relacionadas.

**Síntomas frecuentes:**
- Miedo a desplegar debido al scope de cambios
- Imposibilidad de trabajar en paralelo sin conflictos constantes
- Acoplamiento alto que impide reutilización o separación

**Valor:** Permite renovar plataformas reales sin detener la operación ni asumir una reescritura total de alto riesgo.

---

### 🔧 08 — Extracción de módulo crítico sin romper operación

**Problema:** Se necesita desacoplar una parte clave del sistema, pero esa parte participa en flujos sensibles con alta visibilidad y no admite interrupciones.

**Síntomas frecuentes:**
- Módulos con demasiadas responsabilidades y acoplamiento alto
- Cambios que requieren coordinación de todo el equipo
- Imposibilidad de versionar o desplegar ese módulo de forma independiente

**Valor:** Reduce riesgo operacional y habilita evolución controlada de piezas críticas del negocio.

---

### 🌐 09 — Integración externa inestable

**Problema:** Una API, servicio o proveedor externo introduce latencia variable, errores intermitentes o reglas cambiantes que afectan directamente la estabilidad del sistema propio.

**Síntomas frecuentes:**
- Errores dependientes de un tercero que se escapan del control propio
- Latencia que varía sin patrón predecible
- Cambios en la API externa que rompen el sistema sin previo aviso

**Valor:** Mitiga la dependencia de terceros y evita que un proveedor defina la estabilidad de tu producto.

---

### 💰 10 — Arquitectura cara para un problema simple

**Problema:** La solución técnica consume más servicios, complejidad y costo del que el problema de negocio realmente necesita. Se sobreingenia antes de validar.

**Síntomas frecuentes:**
- Infraestructura costosa para un volumen de tráfico bajo
- Complejidad operativa que supera el valor entregado
- Tiempo de desarrollo elevado para funcionalidades simples

**Valor:** Mejora adaptabilidad, reduce costos y acelera la entrega manteniendo el foco en el problema real.

---

### 📊 11 — Reportes pesados que bloquean la operación

**Problema:** Consultas y procesos de reporting compiten con la operación transaccional y degradan el sistema completo durante la generación de informes.

**Síntomas frecuentes:**
- El sistema se pone lento durante reportes de fin de mes
- Queries de análisis que fuerzan full table scans en producción
- Base de datos compartida entre OLTP y OLAP sin separación

**Valor:** Protege la operación diaria y permite crecer en analítica sin romper el sistema transaccional.

---

### 🧑‍💼 12 — Punto único de conocimiento y riesgo operacional

**Problema:** Una persona, módulo o procedimiento concentra tanto conocimiento que el sistema se vuelve frágil ante ausencias, rotación o vacaciones.

**Síntomas frecuentes:**
- Incidentes que solo puede resolver una persona
- Documentación de operación inexistente o desactualizada
- Flujos críticos que nadie fuera de una persona entiende

**Valor:** Reduce riesgo organizacional, mejora continuidad y hace al producto más sostenible a largo plazo.
