# 🔍 Diagnóstico

## La pregunta que ordena todo

**¿Cuánto tiempo pasa entre que `/health` responde 200 y que `/ready` responde 200?**

Ese número es `health_vs_ready_gap_ms`, y es la ventana exacta durante la cual un balanceador mal configurado manda tráfico a una instancia que no puede servirlo.

## Cómo medirlo

```bash
# el hueco, con instancias arrancando mientras entra el tráfico
curl "http://localhost:8400/18/boot-cold?requests=2400&instances=3"

# el mismo tráfico contra un pool que ya estaba listo
curl "http://localhost:8400/18/boot-warmed?requests=2400&instances=3"

# el estado por instancia: viva, lista, cuánto tardó, cuánto sirvió
curl http://localhost:8400/18/ready
```

## Qué mirar en la respuesta

| Campo | Qué dice |
|---|---|
| `health_vs_ready_gap_ms` | La ventana de peligro. Si el balanceador mira liveness, esto es tiempo de caída. |
| `rejected_cold_start` | Peticiones que se cayeron con el proceso vivo. |
| `availability_pct` | Disponibilidad medida **durante** el escalado, no después. |
| `p99_first_100_ms` | Lo que cuesta atender cuando la instancia recién llegó. |
| `p99_after_1000_ms` | Lo que cuesta atender cuando el runtime ya se acomodó. |
| `warmup_speedup_x` | El cociente de los dos: **la curva de calentamiento, medida**. |

## Lo que revela `warmup_speedup_x`

Es el número que separa a las dos familias de runtime, y sale del mismo código en los siete stacks:

| Stack | Medido | Qué significa |
|---|---|---|
| ☕ Java | **~52x** | El bytecode arranca interpretado y C2 recompila mucho después. |
| 🔵 .NET | **~2,3x** | Tier 0 sin optimizar, Tier 1 optimizado, OSR para los lazos. |
| 🐍 Python | ~1,8x | No hay JIT: el exceso es contención con los hilos que están inicializando. |
| 🟢 Node | ~1,1x | V8 llega a TurboFan muy rápido en un lazo así de simple. |
| 🐘 PHP | ~1,1x | El JIT existe desde 8.0 pero viene apagado. |
| 🐹 Go | ~1,0x | Binario AOT: la petición 1 corre el mismo código que la 100.000. |
| 🦀 Rust | **~1,0x** | Igual, y sin runtime que inicializar. |

> ⚠️ **Honestidad sobre el número.** En la variante fría, `p99_first_100_ms` mezcla dos efectos reales: el calentamiento del runtime **y** la contención con las instancias que están inicializando en paralelo. Los dos ocurren de verdad durante un arranque en frío de producción, así que la mezcla es representativa — pero es una mezcla. El 52x de Java no se explica por contención: es la JVM.

## Lo que NO hay que concluir

Que un `warmup_speedup_x` alto hace malo a un lenguaje. Java gana el caso 17 y el caso 15; acá pierde. La conclusión útil es más chica y más accionable: **cuánto tarda un runtime en dar su mejor rendimiento decide qué tan agresivo puede ser el autoescalado encima de él**.

<!-- nav-case-doc -->
---

**Caso 18 · Arranque en frío y retraso del autoescalado** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · **🔍 Diagnóstico** · [🧠 Causas raíz](root-causes.md) · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
