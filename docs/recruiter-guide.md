# 👔 Guia extendida para reclutadores y revisores no tecnicos

> Documento complementario a [RECRUITER.md](../RECRUITER.md).

## ⏱️ Lectura recomendada en 5 minutos

| Paso | Documento o ruta | Que senal deja |
| --- | --- | --- |
| 1 | Portal local (`http://localhost:8080`) | Entender rapido que hace el producto, que audiencias cubre y que stacks estan operativos |
| 2 | [README.md](../README.md) | Ver proposito, taxonomia de madurez y entrada rapida |
| 3 | [docs/case-catalog.md](case-catalog.md) | Diferenciar con honestidad entre casos operativos y scaffolds |
| 4 | [caso 01](../cases/01-api-latency-under-load/README.md), [caso 04](../cases/04-timeout-chain-and-retry-storms/README.md), [caso 05](../cases/05-memory-pressure-and-resource-leaks/README.md) o [caso 06](../cases/06-broken-pipeline-and-fragile-delivery/README.md) | Confirmar que existe implementacion real y no solo descripcion |
| 5 | [RUNBOOK.md](../RUNBOOK.md) | Ver que la operacion tambien fue pensada |

## 🔎 Senales que vale la pena observar

| Senal | Como se evidencia |
| --- | --- |
| Organizacion | Estructura clara, repetible y guiada por problema, no por moda de stack |
| Criterio tecnico | Cada caso parte desde sintomas y termina en una solucion con evidencia esperable |
| Producto entendible | El portal separa rutas por audiencia y explica por que importa cada caso |
| Honestidad | El repo declara explicitamente cuales stacks y casos son operativos hoy |
| Operacion | Docker, health checks y probes del portal permiten corroborar que el entorno esta vivo |

## 🚫 Que no deberia esperarse

- doce casos al mismo nivel de implementacion hoy;
- paridad funcional completa entre los cinco lenguajes;
- una promesa de benchmark absoluto entre runtimes.

## ✅ Que si puede concluirse con fundamento

- hay criterio para modelar problemas reales y explicarlos bien;
- existe capacidad de documentar, operar y presentar soluciones tecnicas con claridad;
- Docker se usa para reproducibilidad, no solo como adorno;
- la narrativa del repo es coherente con un perfil orientado a modernizacion, performance, observabilidad y continuidad operacional.
