# 🚨 Postmortem — Caso 17: 22 minutos de 503 por agregar una columna

**Severidad:** SEV-1 (indisponibilidad total del servicio principal)
**Estado:** Resuelto · Acciones implementadas en el lab
**Documento:** retrospectiva basada en el patrón de incidente que motiva este caso

> Este postmortem es **una reconstrucción narrativa del incidente** que justifica la existencia del caso `17`. No documenta un incidente real de producción — documenta el **patrón operacional** que el lab reproduce y resuelve, en formato de postmortem real para evaluación ejecutiva.

---

## 📝 Resumen

Un despliegue de rutina incluía una migración: agregar la columna `tier` a la tabla `users`, con `DEFAULT 'basic'` y `NOT NULL`.

En staging, con 1.200 usuarios, la migración tardó 180 ms. En producción, con 2,4 millones, el motor tuvo que reescribir la tabla entera para materializar el default. El `AccessExclusiveLock` se mantuvo **22 minutos**.

Durante esos 22 minutos, toda petición que tocara `users` —o sea, todas— se quedó esperando el lock hasta agotar su timeout. El servicio devolvió 503 al 100% del tráfico.

Los healthchecks siguieron en verde todo el tiempo: el proceso estaba vivo, el puerto respondía, y `/health` no tocaba la base.

**Blast radius:** 22 minutos de indisponibilidad total en horario comercial.

---

## 🕒 Timeline

| Hora | Evento |
|---|---|
| 10:14 | Despliegue automático. La migración arranca. |
| 10:14 | El motor toma `AccessExclusiveLock` sobre `users` y empieza a reescribir la tabla. |
| 10:15 | Las primeras peticiones agotan su timeout. Empiezan los 503. |
| 10:16 | El pool de conexiones se agota: todas esperan el mismo lock ([caso 14](../../14-connection-pool-exhaustion/README.md)). |
| 10:17 | Soporte reporta caída total. **Los healthchecks siguen en verde.** |
| 10:19 | Se descarta un problema de la aplicación: el proceso está sano. |
| 10:24 | Alguien mira `pg_locks` y encuentra el `AccessExclusiveLock`. |
| 10:26 | Se evalúa cancelar la migración. Se decide **no hacerlo**: un rollback a mitad de reescritura es peor. |
| 10:36 | La migración termina. El servicio vuelve solo. |
| +1 día | Se descubre que la misma migración sin `DEFAULT` habría sido instantánea. |

---

## 🎯 Causa raíz

```sql
ALTER TABLE users ADD COLUMN tier VARCHAR(20) NOT NULL DEFAULT 'basic';
```

Tres decisiones que se necesitan mutuamente:

1. **`NOT NULL` con `DEFAULT` en una sola operación.** En la versión del motor que corría, eso obliga a reescribir cada fila. Agregar la columna nullable habría sido un cambio de metadata: instantáneo.
2. **Esquema y comportamiento en el mismo despliegue.** No había forma de agregar la columna sin que el código nuevo empezara a usarla, ni de volver atrás sin otra migración.
3. **Validado contra un dataset 2.000 veces más chico.** 180 ms en staging no dice nada sobre 2,4 millones de filas.

Lo incómodo: **los healthchecks estaban bien configurados según el manual**. `/health` no debe tocar la base — eso es lo que se recomienda para no marcar el servicio como caído por un problema transitorio del motor. Y esa recomendación correcta es exactamente lo que hizo que 22 minutos de caída total no dispararan ninguna alarma automática.

---

## ✅ Lo que funcionó

- La decisión de **no cancelar** la migración a mitad de camino. Un rollback de una reescritura parcial habría dejado la tabla en un estado peor.
- El servicio se recuperó solo, sin intervención, apenas terminó el DDL.

## ❌ Lo que no funcionó

- Ninguna alerta automática detectó una caída total de 22 minutos.
- El diagnóstico tardó 10 minutos en llegar a `pg_locks`, porque todo lo que se miraba primero —CPU, memoria, estado del proceso— estaba sano.
- Staging no tenía volumen comparable, así que la migración pasó todas las revisiones.

---

## 🔧 Acciones

| Acción | Estado |
|---|---|
| Expand-contract obligatorio para columnas sobre tablas de más de 100k filas | ✅ Implementado (`/migrate-expand-contract` en los 7 stacks) |
| Backfill por lotes con pausa entre ellos | ✅ Implementado |
| Feature flag para el switch, y contract en un despliegue posterior | ✅ Implementado (`/migration/state`) |
| Métrica de disponibilidad **durante** la migración | ✅ Implementado (`availability_pct`, `readers_failed`) |
| Endpoint `/ready` que sí toca la base, separado de `/health` | ⛔ Fuera del alcance — se cubre en el [caso 18](../../18-cold-start-and-autoscale-lag/README.md) |
| Dataset de staging con volumen comparable | ⛔ Fuera del alcance del lab: es infraestructura, no código |

---

## 📚 Lección

> El trabajo total de una migración no cambia. Lo que cambia es si se cobra todo junto con la aplicación caída, o repartido en lotes que nadie nota.

Y la que costó diez minutos de diagnóstico: **un healthcheck que no toca la base es una buena práctica que, ese día, garantizó que nadie se enterara**. La respuesta no es hacer que `/health` consulte la base — es tener un `/ready` separado que sí lo haga.

<!-- nav-case-doc -->
---

**Caso 17 · Migración de esquema sin downtime** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · **🚨 Postmortem**
<!-- /nav-case-doc -->
