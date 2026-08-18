# 🧠 Causas raíz

## 1. El balanceador enruta por liveness

Es la causa directa de los 503. `/health` y `/ready` responden preguntas distintas —«¿el proceso vive?» contra «¿puedo servir?»— y Kubernetes las separa en dos sondas justamente por esto. Un `readinessProbe` ausente hace que el default sea «lista apenas arranca», que es falso en toda aplicación que abra una conexión.

## 2. El autoescalado reacciona a una métrica que ya se disparó

Escalar cuando la CPU pasa el 70% significa que la instancia nueva llega **después** del pico. Sumado al tiempo de arranque, el refuerzo aparece cuando ya no hacía falta — o peor, cuando el pico volvió a subir.

## 3. Inicialización perezosa en el camino de la petición

Un `sync.Once`, un `Lazy<T>` o un `OnceLock` que se resuelve en el primer request convierte a esa petición en la más lenta del proceso. La primitiva no es el problema —es la correcta— pero disparada por tráfico en vez de por el arranque, se paga con un usuario esperando.

## 4. Compilación en capas sin calentar

En Java y .NET el código sigue lento después de estar «listo». Sin un `warmup` explícito, las primeras miles de peticiones de cada instancia nueva pagan el interpretado. Es la causa que más cuesta ver, porque no produce errores: produce lentitud que se arregla sola.

## 5. Arranque medido en la máquina del desarrollador

Un contenedor sin límites de CPU arranca en una fracción del tiempo que tarda con `cpu: 250m`. El arranque en frío es **especialmente** sensible al límite de CPU, porque compilar y construir estructuras es exactamente lo que se hace en esos primeros segundos.

<!-- nav-case-doc -->
---

**Caso 18 · Arranque en frío y retraso del autoescalado** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · **🧠 Causas raíz** · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
