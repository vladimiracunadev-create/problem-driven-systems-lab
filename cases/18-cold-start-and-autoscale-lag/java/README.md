# ☕ Caso 18 — Java 21

<!-- nav-stack -->
[⬅️ Caso 18](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [☕ Perfil de Java](../../../docs/languages/java.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Java del caso 18. Instancias frías con el tráfico ya encima contra un pool tibio, midiendo la disponibilidad **durante** el escalado.

## Acá el stack es el problema, y el número lo dice

`warmup_speedup_x` mide **≈52x**. Es el valor más alto de los siete, por un orden de magnitud, y sale del mismo lazo entero que corre en los otros seis.

```
interpretado  →  C1 (~200 llamados)  →  C2 (~10.000 llamados, con perfil)
```

El mismo método, **sin tocar una línea**, corre cincuenta veces más rápido a la petición diez mil que a la primera. Eso convierte a Java en el caso canónico del arranque en frío: la instancia que el autoescalador acaba de sumar no solo tarda en estar lista, sino que además **atiende lento las primeras miles de peticiones**.

Y con tráfico encima, esa lentitud mantiene la CPU alta, lo que vuelve a disparar al autoescalador, lo que produce más instancias frías. **El sistema se realimenta**, que es el hallazgo del [postmortem](../docs/postmortem.md) de este caso.

## La caja de herramientas más profunda del laboratorio

| Herramienta | Qué hace | Costo |
|---|---|---|
| **AppCDS** | Comparte el classloading entre arranques | Un paso en el build |
| `-XX:TieredStopAtLevel=1` | Llega rápido a C1 y se queda ahí | Techo de rendimiento más bajo |
| **GraalVM `native-image`** | Compila AOT y elimina la curva entera | Toolchain aparte, sin reflexión dinámica |
| `-Xshare:on` | Reutiliza el archivo de clases compartidas | Configuración de JVM |

Ninguna viene puesta por defecto. Esa es la queja legítima, y es lo que separa a Java de .NET en este caso: **.NET no tiene mejores herramientas, tiene las suyas activables con una línea del `.csproj`**.

## El trade-off que se pierde al ir a AOT

GraalVM borra el calentamiento — y también borra el JIT que, después de miles de peticiones, produce código **mejor** que el AOT porque tiene el perfil real de ejecución. Para un servicio de larga vida y tráfico constante, la JVM caliente le gana a su propia versión nativa. La decisión depende de cuánto vive un proceso, no de cuál es «mejor».

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| Compilación en capas (C1/C2) | La curva que este caso mide. |
| `Thread` + `CountDownLatch` | Arranque en paralelo y largada común de clientes. |
| `AtomicBoolean` volátil | El flag de readiness, visible entre hilos sin lock. |
| AppCDS / `native-image` | Las dos salidas, ninguna por defecto. |

## Rutas

| Ruta | Qué muestra |
|---|---|
| `/health` | **liveness**: responde 200 apenas el proceso arranca |
| `/ready` | **readiness**: responde 200 recién cuando la instancia puede servir |
| `/boot-cold?requests=2400&instances=3` | `rejected_cold_start` > 0 con el proceso vivo todo el tiempo |
| `/boot-warmed?requests=2400&instances=3` | `rejected_cold_start` = 0 y 100% de disponibilidad |
| `/warmup?instances=3&prime=1500` | construye el pool tibio antes de que llegue el tráfico |
| `/diagnostics/summary` | acumulado por variante, más la nota de fidelidad |
| `/reset-lab` | vacía la flota, el pool tibio y las métricas |

**Parámetros:** `requests` (100–20k), `instances` (1–32), `clients` (1–64), `io_ms` (parte de I/O del arranque), `pace_ms` (ritmo de llegada), `work_iters` (trabajo por petición), `prime` (peticiones de calentamiento del pool).

## Qué se mide y qué se modela

- **Se mide, no se simula:** la curva de calentamiento. El trabajo por petición es un lazo entero puro, idéntico en los siete stacks, sin un solo `sleep`. `p99_first_100_ms` contra `p99_after_1000_ms` es lo que ese runtime hace de verdad con el mismo código repetido.
- **Se modela:** la parte de I/O de la inicialización —abrir el pool, resolver DNS, negociar TLS— es un `sleep` de `io_ms`. Esperar a la red no quema CPU, y fijarla es lo que vuelve comparables a los siete stacks.
- **Es real:** la parte de CPU de la inicialización construye una tabla de configuración. Ese costo sí depende del runtime.

> ⚠️ En la variante fría, `p99_first_100_ms` mezcla dos efectos reales: el calentamiento del runtime **y** la contención con las instancias que están inicializando en paralelo. Los dos ocurren de verdad durante un arranque en frío de producción.

## Hub

```bash
docker compose -f compose.java.yml up -d --build
curl "http://127.0.0.1:8400/18/boot-cold?requests=2400&instances=3"
curl "http://127.0.0.1:8400/18/boot-warmed?requests=2400&instances=3"
curl "http://127.0.0.1:8400/18/ready"
```

## Lo que ningún stack cambia

La inicialización cuesta lo que cuesta. **El trabajo no desaparece: se adelanta.**

Lo que decide si la aplicación devuelve 503 no es cuánto tarda en arrancar, sino `health_vs_ready_gap_ms` — cuánto tiempo el sistema afirma estar disponible sin estarlo.
