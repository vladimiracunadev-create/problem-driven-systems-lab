# 🐍 Caso 18 — Python 3.12

<!-- nav-stack -->
[⬅️ Caso 18](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐍 Perfil de Python](../../../docs/languages/python.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Python del caso 18. Instancias frías con el tráfico ya encima contra un pool tibio, midiendo la disponibilidad **durante** el escalado.

## Python no tiene JIT, y eso es la mitad de su historia

Ni compilación en capas, ni OSR, ni desoptimización. CPython compila a bytecode una vez y lo interpreta siempre igual. Es —junto a PHP— la única familia del laboratorio donde `p99_first_100_ms` y `p99_after_1000_ms` salen prácticamente iguales.

Eso es a la vez su virtud y su techo: **no hay calentamiento porque no hay nada que calentar**. La petición número 1 es tan rápida como la 100.000, y ninguna de las dos va a mejorar.

## Lo que sí cuesta en Python es el arranque

```python
import django          # compila a .pyc, ejecuta el módulo, resuelve el árbol
```

Cada `import` compila a bytecode (una vez, cacheado en `__pycache__`), **ejecuta el módulo completo** y resuelve sus dependencias transitivas. Un proyecto con 200 módulos tarda segundos antes de la primera línea de código propio.

Y a diferencia de .NET —que tiene `PublishReadyToRun`— o de Java —que tiene AppCDS y GraalVM—, **Python no tiene artefacto compilado al que escapar**. La única palanca es de diseño: menos imports en el camino del arranque, e imports diferidos donde se pueda.

```python
def handler():
    import pandas as pd   # se paga cuando hace falta, no al arrancar
```

## El número que sale, y por qué no es 1,0 exacto

`warmup_speedup_x` mide ≈1,8x en la variante fría. **No es JIT**: es contención. Las primeras 100 peticiones corren mientras los hilos de inicialización están construyendo tablas, y bajo el GIL eso se nota. En la variante templada, con el pool ya listo, el número cae por debajo de 1: no hay curva.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `threading.Thread` | Cada instancia arranca en su hilo; el bloqueo por I/O lo suelta el GIL. |
| `threading.Barrier` | La largada común de los clientes. |
| Imports diferidos | La única palanca real de Python contra su tiempo de arranque. |
| `__pycache__` | La caché de bytecode. El opcache de Python, pero por archivo y en disco. |

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
docker compose -f compose.python.yml up -d --build
curl "http://127.0.0.1:8200/18/boot-cold?requests=2400&instances=3"
curl "http://127.0.0.1:8200/18/boot-warmed?requests=2400&instances=3"
curl "http://127.0.0.1:8200/18/ready"
```

## Lo que ningún stack cambia

La inicialización cuesta lo que cuesta. **El trabajo no desaparece: se adelanta.**

Lo que decide si la aplicación devuelve 503 no es cuánto tarda en arrancar, sino `health_vs_ready_gap_ms` — cuánto tiempo el sistema afirma estar disponible sin estarlo.
