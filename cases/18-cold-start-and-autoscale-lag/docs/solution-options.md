# 🛠️ Opciones de solución

## 1. Separar readiness de liveness — el mínimo indispensable

```yaml
livenessProbe:   { httpGet: { path: /health } }   # ¿hay que reiniciarlo?
readinessProbe:  { httpGet: { path: /ready  } }   # ¿le mando tráfico?
```

Es la corrección más barata del caso y la que elimina los 503. No acelera nada: solo impide que el tráfico llegue antes de tiempo.

> ⚠️ Un `livenessProbe` que apunte a `/ready` es peor que no tenerlo: mata contenedores que solo estaban arrancando.

## 2. Pool tibio: escalar antes del pico

Mantener capacidad de sobra (`minReplicas` por encima de lo estrictamente necesario), escalar por una métrica adelantada —profundidad de cola, RPS de entrada— en vez de CPU, o precalentar por agenda si el tráfico es predecible.

Cuesta dinero. Es un intercambio explícito de costo por latencia de escalado, y conviene decidirlo con el número a la vista.

## 3. Reducir el arranque en sí

| Stack | Herramienta | Dónde vive |
|---|---|---|
| ☕ Java | AppCDS, `-XX:TieredStopAtLevel=1`, GraalVM `native-image` | Flags de JVM / toolchain aparte |
| 🔵 .NET | `PublishReadyToRun`, `TieredPGO`, `PublishAot` | Tres líneas del `.csproj` |
| 🟢 Node | `--build-snapshot`, SEA, menos dependencias | Fuera del camino por defecto |
| 🐍 Python | Menos `import` en el arranque, imports diferidos | Diseño del código |
| 🐘 PHP | `opcache`, `opcache.preload`, `pm.start_servers` | Configuración, no código |
| 🐹 Go | Nada que hacer: el binario ya está compilado | — |
| 🦀 Rust | Nada que hacer | — |

## 4. Calentar explícitamente antes de anunciarse lista

Ejercitar los caminos calientes durante el arranque —antes de que `/ready` diga que sí— y recién entonces anunciarse. Alarga el arranque a cambio de que la primera petición real llegue a un runtime ya acomodado.

Es la mitad que el pool tibio de este laboratorio hace en `/warmup`, y la que separa «la instancia está lista» de «**el runtime** está listo».

## 5. Aceptar el costo y dimensionar para el pico

Perfectamente válido cuando el tráfico es estable y el ahorro del autoescalado es marginal. La trampa es llegar acá por omisión en vez de por decisión.

<!-- nav-case-doc -->
---

**Caso 18 · Arranque en frío y retraso del autoescalado** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · [🧠 Causas raíz](root-causes.md) · **🛠️ Opciones de solución** · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
