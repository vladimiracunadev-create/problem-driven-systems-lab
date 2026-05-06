# 🛠️ Mapa de stacks

> Por qué hay múltiples lenguajes en el laboratorio y cómo se usan.

---

## 🎯 Objetivo de la multi-stack

El objetivo no es demostrar que todos los lenguajes son iguales.
El objetivo es mostrar cómo **el mismo problema se manifiesta y se resuelve de forma diferente** según el ecosistema.

---

## 📦 Stacks base incluidos

| Stack | Ícono | Versión | Fortaleza en el contexto del lab |
|-------|-------|---------|----------------------------------|
| PHP | 🐘 | 8.x | Ideal para casos de APIs web, ORMs y patrones MVC |
| Node.js | 🟢 | 20 LTS | Single-thread + event loop; primitivas estandar (`AbortController`, `AbortSignal.timeout`, `Proxy`, `EventEmitter`, `monitorEventLoopDelay`, `process.memoryUsage`) que mapean a problemas de cancelacion, contratos, eventos y observabilidad sin libreria externa |
| Python | 🐍 | 3.x | Data, análisis, scripting y rapidez de prototipado |
| Java | ☕ | JVM | Tipado fuerte, ecosistema empresarial, Spring |
| .NET | 🔵 | 8.x | Ecosistema Microsoft, rendimiento y tipado fuerte |

---

## 🔍 Qué se estudia al comparar stacks

| Dimensión | Pregunta que responde |
|-----------|----------------------|
| 🏃 **Runtime** | ¿Cómo afecta el modelo de ejecución al problema? |
| 🧰 **Tooling** | ¿Qué herramientas existen para diagnosticar y resolver? |
| 📚 **Bibliotecas** | ¿Cómo el ecosistema facilita u obstaculiza la solución? |
| 💰 **Costos operativos** | ¿Cuánto pesa este stack en producción? |
| 🚀 **Estilo de despliegue** | ¿Cómo cambia la estrategia Docker por stack? |
| 👁️ **Observabilidad** | ¿Qué tan cómodo es instrumentar cada runtime? |
| 🤝 **Ergonomía del equipo** | ¿Es mantenible para un equipo estándar? |

---

## ⚖️ Regla de honestidad

> Este laboratorio **no afirma especialización histórica profunda** en todos los ecosistemas.
>
> Sí demuestra:
> - ✅ capacidad de análisis y comparación,
> - ✅ documentación técnica rigurosa,
> - ✅ adaptación a distintos toolings,
> - ✅ criterio para evaluar trade-offs entre lenguajes.
