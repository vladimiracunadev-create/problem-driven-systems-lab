# 📐 Alcance y uso esperado

> Que cubre esta version del laboratorio y que queda fuera conscientemente.

## ✅ Uso esperado

| Uso | Descripcion |
| --- | --- |
| Laboratorio tecnico | Explorar problemas reales y sus rutas de solucion |
| Portafolio profesional | Mostrar criterio transferible mas alla de un stack puntual |
| Base de demos serias | Levantar un caso concreto con Docker y explicar el before/after |
| Marco de crecimiento | Usar la estructura para seguir profundizando casos y stacks |

## 📦 Lo que si incluye hoy esta version

| Area | Estado actual |
| --- | --- |
| 12 casos definidos y documentados | si |
| Casos `01` al `12` operativos en PHP | si |
| Docker por caso y por stack como ruta oficial | si |
| Portal raiz para navegar el laboratorio | si |
| Familia documental profesional en la raiz | si |
| Paridad funcional completa entre todos los lenguajes | no |

## 🚫 Limites conscientes

| Fuera de alcance | Por que |
| --- | --- |
| Benchmark absoluto entre lenguajes | El objetivo es resolver problemas, no coronar runtimes |
| Entorno productivo completo | Es un laboratorio controlado y portable |
| Igual nivel de madurez en todos los stacks | Se prefirio crecer con honestidad y no con demos vacias |
| Un unico `docker compose up` para todo el universo | Haria el repo mas pesado, menos claro y peor para diagnostico |

## 🐳 Regla operativa importante

Para los casos ya implementados, Docker Compose es la ruta oficialmente soportada. El `Makefile` es una ayuda, no el contrato principal de ejecucion.

## 🧭 Como leer el alcance correctamente

- Si un caso esta marcado como `OPERATIVO`, deberia poder ejecutarse de forma limpia.
- Si un caso esta marcado como `DOCUMENTADO / SCAFFOLD`, aun no debes leerlo como solucion completa.
- Si un stack no tiene paridad funcional real, el repositorio lo declara explicitamente.
