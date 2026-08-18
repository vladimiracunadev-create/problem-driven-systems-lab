# 🚨 Postmortem — Caso 16: 1.847 cobros duplicados en once minutos

**Severidad:** SEV-1 (impacto financiero directo sobre clientes)
**Estado:** Resuelto · Acciones implementadas en el lab
**Documento:** retrospectiva basada en el patrón de incidente que motiva este caso

> Este postmortem es **una reconstrucción narrativa del incidente** que justifica la existencia del caso `16`. No documenta un incidente real de producción — documenta el **patrón operacional** que el lab reproduce y resuelve, en formato de postmortem real para evaluación ejecutiva.

---

## 📝 Resumen

El pasarela de pagos empezó a responder lento por un problema en el proveedor: latencias de 8 a 20 segundos donde normalmente había 400 ms. El timeout del cliente móvil estaba en 10 segundos, con dos reintentos automáticos.

El servidor recibía las tres peticiones. Las tres llegaban. Las tres cobraban. Lo que se perdía era la respuesta de la primera.

En once minutos se aplicaron 1.847 cobros duplicados sobre 923 operaciones legítimas, por 4,2 millones de pesos. Cada cliente afectado recibió además dos o tres emails de confirmación.

**Blast radius:** 923 clientes cobrados de más; 4,2 M en devoluciones; 1.847 emails duplicados.

---

## 🕒 Timeline

| Hora | Evento |
|---|---|
| 14:02 | El proveedor de pagos empieza a degradarse. Latencia de 400 ms a 8 s. |
| 14:04 | Los primeros clientes móviles alcanzan su timeout de 10 s y reintentan. |
| 14:04 | El servidor procesa el reintento como una operación nueva. Primer duplicado. |
| 14:07 | La latencia del proveedor llega a 20 s. Ahora **todos** los clientes reintentan. |
| 14:09 | Soporte recibe el primer reporte de doble cobro. Se abre incidente. |
| 14:11 | Se identifica que el problema es del lado propio, no del proveedor. |
| 14:13 | Se desactiva el reintento automático del cliente por feature flag. Los duplicados se detienen. |
| 14:40 | El proveedor se recupera. |
| +3 días | Se termina de devolver el dinero. El proceso fue manual. |

---

## 🎯 Causa raíz

```java
// El endpoint de cobro
public Response charge(ChargeRequest req) {
    var result = gateway.charge(req.amount());   // sin clave, sin dedupe
    ledger.apply(req.account(), req.amount());
    email.sendReceipt(req.account());            // efecto fuera de la transacción
    return Response.ok(result);
}
```

Tres cosas, y ninguna sola habría bastado:

1. **Sin `Idempotency-Key`.** El servidor no tenía forma de saber que la segunda petición era la misma que la primera.
2. **Reintento automático del cliente.** Correcto por sí solo — sin él se perderían operaciones legítimas cuando la red falla. Peligroso combinado con lo anterior.
3. **Efecto lateral fuera de la transacción.** El email salía en el mismo handler que el cargo, así que cada duplicado también duplicó la notificación.

El detalle que cuesta aceptar: **el cliente hizo lo correcto**. No puede distinguir «no llegó» de «llegó y no me enteré», y ante la duda reintentar es la decisión sensata. Quien tenía que distinguirlo era el servidor.

---

## ✅ Lo que funcionó

- El feature flag para desactivar el reintento del cliente cortó el sangrado en 2 minutos.
- El ledger permitió reconstruir exactamente qué cobros eran duplicados, porque cada uno quedó registrado.

## ❌ Lo que no funcionó

- No había ninguna métrica de cobros por operación. El duplicado se detectó por un reporte de soporte, no por un panel.
- La devolución fue manual: tres días de trabajo para 923 clientes.
- El primer intento de arreglo —agregar `if (!yaExiste(key))` antes del cobro— **no eliminó el problema**. Con reintentos concurrentes, la ventana entre el chequeo y la escritura seguía dejando pasar duplicados. Hizo falta cambiarlo por una operación atómica.

---

## 🔧 Acciones

| Acción | Estado |
|---|---|
| `Idempotency-Key` obligatoria en operaciones con efecto | ✅ Implementado (`/charge-idempotent` en los 7 stacks) |
| Reserva **atómica** de la clave, no check-then-act | ✅ Implementado (`putIfAbsent`, `TryAdd`, `LoadOrStore`, `entry()`, `ON CONFLICT`) |
| Respuesta cacheada: el reintento recibe lo mismo que el original | ✅ Implementado |
| Outbox pattern para el efecto que cruza el boundary | ✅ Implementado (`/outbox`) |
| Ventana de deduplicación de 24 h con limpieza | ✅ Implementado |
| Backoff exponencial en el reintento del cliente | ⛔ Fuera del alcance — se cubre en el [caso 04](../../04-timeout-chain-and-retry-storms/README.md) |
| Tabla de idempotencia compartida entre réplicas | ⚠️ Documentado como límite: seis de las siete versiones del lab resuelven la carrera dentro de su proceso |

---

## 📚 Lección

> El cliente hizo lo correcto al reintentar. Quien tenía que distinguir el reintento del pedido nuevo era el servidor.

Y la que costó el segundo intento de arreglo: **agregar un `if` no es lo mismo que hacer la operación atómica**. `if (!existe) { crear }` son dos operaciones con una ventana en el medio, y bajo concurrencia esa ventana es exactamente el bug que se quería cerrar.

<!-- nav-case-doc -->
---

**Caso 16 · Idempotencia y efectos duplicados** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · **🚨 Postmortem**
<!-- /nav-case-doc -->
