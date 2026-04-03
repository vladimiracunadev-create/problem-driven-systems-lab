# Estrategia Docker

> Como y cuando usar Docker en este laboratorio.

## Resumen ejecutivo

Docker no es opcional en los casos implementados. Es la ruta oficial para:

- documentar entornos de forma reproducible;
- aislar dependencias por escenario;
- ejecutar demos serias sin configuracion manual extensa;
- comparar stacks sin contaminar el resto del laboratorio.

## Regla de diseno

El laboratorio no se levanta como un unico sistema enorme. Se trabaja por capas:

| Patron | Uso |
| --- | --- |
| `compose.root.yml` | Portal del laboratorio |
| `cases/<caso>/<stack>/compose.yml` | Un escenario concreto y aislado |
| `cases/<caso>/compose.compare.yml` | Comparacion entre stacks del mismo caso |

## Por que este enfoque es mejor aqui

| Beneficio | Impacto |
| --- | --- |
| Menor consumo | Solo levantas lo que necesitas |
| Menos ruido | No mezclas logs ni servicios de otros casos |
| Mejor diagnostico | Cada problema se observa con menos interferencia |
| Portafolio mas claro | Puedes mostrar un caso concreto sin cargar todo el mundo |

## Lo que se evita conscientemente

- un `docker compose up` gigante para todos los casos;
- dependencias cruzadas entre escenarios que deberian ser aislados;
- infraestructura innecesaria solo para "verse enterprise".

## Regla practica actual

- Los casos `01`, `02` y `03` deben poder levantarse con Docker de forma limpia.
- Cada `compose.yml` debe incluir solo la infraestructura que el problema realmente necesita.
- La presencia de `compose.compare.yml` no implica que todos los stacks tengan la misma profundidad funcional.

## Ejemplos concretos

- Caso `01`: necesita `app + db + worker + observabilidad` porque el problema es contencion real bajo carga.
- Caso `02`: necesita `app + db` porque el N+1 debe verse sobre relaciones reales.
- Caso `03`: usa solo `app` porque el foco esta en logs, trazas y diagnostico, no en una DB externa.

## Nota sobre el Makefile

El `Makefile` es util como atajo, pero no reemplaza la estrategia oficial. Si trabajas en Windows puro, prefiere `docker compose` directo.
