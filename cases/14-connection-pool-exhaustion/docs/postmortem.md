# 🚨 Postmortem — Caso 14: el checkout deja de responder cada 40 horas y reiniciar lo arregla

**Severidad:** SEV-2 (degradación progresiva hasta indisponibilidad parcial)
**Estado:** Resuelto · Acciones implementadas en el lab
**Documento:** retrospectiva basada en el patrón de incidente que motiva este caso

> Este postmortem es **una reconstrucción narrativa del incidente** que justifica la existencia del caso `14`. No documenta un incidente real de producción — documenta el **patrón operacional** que el lab reproduce y resuelve, en formato de postmortem real para evaluación ejecutiva.

---

## 📝 Resumen

El servicio de checkout empezó a devolver «could not get connection from pool» de forma intermitente. El patrón era el mismo cada vez: aparecía unas 40 horas después de cada deploy, empeoraba durante 3 o 4 horas y se resolvía por completo al reiniciar los pods.

Durante seis semanas la respuesta operativa fue reiniciar. Nadie miró la base porque la base estaba sana: 12 conexiones activas de un `max_connections` de 200, CPU al 14%, sin queries lentas.

La causa era un `catch` sin `finally` en el camino de validación de cupones. Ese camino se ejecuta en el 3% de los checkouts. Cada vez que se ejecutaba, el pool perdía una conexión de forma permanente.

**Blast radius:** 6 semanas de reinicios preventivos; ~11% de los checkouts sin respuesta durante las ventanas malas.

---

## 🕒 Timeline

| Hora | Evento |
|---|---|
| T+00 h | Deploy con la validación de cupones nueva. Pool de 20 conexiones por pod. |
| T+18 h | Primeros «could not get connection». Volumen bajo, se atribuye a un pico. |
| T+34 h | El error se vuelve constante en horario comercial. El pool está en 6 de 20. |
| T+38 h | Se revisa la base de datos: sana. Se descarta el motor como causa. |
| T+40 h | Requests que no fallan ni responden. El p99 del checkout deja de reportarse porque los requests nunca terminan. |
| T+41 h | Reinicio de los pods. Todo vuelve a la normalidad de inmediato. |
| T+41 h | **Se cierra el incidente.** El reinicio se agrega al runbook como mitigación. |
| +6 semanas | Alguien grafica `acquired - released` por primera vez. La curva es una recta ascendente. |

---

## 🎯 Causa raíz

```java
Connection c = pool.getConnection();
CouponResult r = validate(c, coupon);   // lanza si el cupón está vencido
pool.release(c);                        // ← nunca se ejecuta si validate lanza
```

Tres decisiones que se necesitan mutuamente:

1. **Devolución solo en el camino feliz.** Sin `try-with-resources` ni `finally`, la excepción se lleva la conexión.
2. **Sin timeout de adquisición.** El `getConnection()` bloqueante convirtió «no hay capacidad» en «este request no responde nunca» — por eso el p99 dejó de reportarse en vez de dispararse.
3. **Sin métrica de fuga.** El dashboard mostraba conexiones activas y disponibles. Ninguna de las dos distingue un pool ocupado de un pool vacío.

Lo incómodo: **el reinicio funcionaba**. Y funcionar hizo que la mitigación entrara al runbook y el diagnóstico se detuviera ahí durante seis semanas.

---

## ✅ Lo que funcionó

- El reinicio, como mitigación inmediata, era efectivo y rápido.
- La base de datos nunca estuvo en riesgo: la fuga limitaba el daño al servicio, no al motor.

## ❌ Lo que no funcionó

- El dashboard del pool mostraba «activas» y «disponibles», que no distinguen ocupado de vacío.
- Que el p99 **dejara de reportarse** se interpretó como un problema del sistema de métricas. Era el síntoma principal: los requests no terminaban, así que no producían muestras.
- La mitigación efectiva desincentivó el diagnóstico. Un runbook que funciona puede tapar una causa raíz durante meses.

---

## 🔧 Acciones

| Acción | Estado |
|---|---|
| `try-with-resources` / `using` / `defer` / `finally` en todo acceso al pool | ✅ Implementado (`/pool-managed` en los 7 stacks) |
| Timeout de adquisición de 200 ms con 503 + `Retry-After` | ✅ Implementado |
| Métrica `acquired - released` expuesta y alertada | ✅ Implementado (`/pool/state`, `/diagnostics/summary`) |
| Dimensionado por ley de Little en vez de por intuición | ✅ Implementado (`littles_law` en cada respuesta) |
| Backoff exponencial del cliente ante 503 | ⛔ Fuera del alcance — se cubre en el [caso 04](../../04-timeout-chain-and-retry-storms/README.md) |

---

## 📚 Lección

> Un pool ocupado y un pool vacío se ven igual en el dashboard, y son problemas opuestos.

Y la que costó seis semanas: **cuando el p99 deja de reportarse, no está roto el sistema de métricas**. Están rotos los requests, que no terminan y por eso no producen muestras. Una latencia que desaparece del gráfico es peor noticia que una latencia alta.

<!-- nav-case-doc -->
---

**Caso 14 · Agotamiento del pool de conexiones** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · **🚨 Postmortem**
<!-- /nav-case-doc -->
