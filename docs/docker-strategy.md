# 🐳 Estrategia Docker

> Cómo y cuándo usar cada archivo `compose` del laboratorio.

---

## 🎯 Resumen ejecutivo

El laboratorio **no se levanta todo junto**. Se levanta únicamente lo necesario en cada momento:

```
┌─ ¿Quieres explorar el laboratorio? ─────────────────────────┐
│  → compose.root.yml   (portal principal)                     │
└──────────────────────────────────────────────────────────────┘

┌─ ¿Quieres trabajar un caso específico? ─────────────────────┐
│  → cases/<caso>/<stack>/compose.yml                          │
└──────────────────────────────────────────────────────────────┘

┌─ ¿Quieres comparar stacks del mismo caso? ──────────────────┐
│  → cases/<caso>/compose.compare.yml                          │
└──────────────────────────────────────────────────────────────┘
```

---

## 📋 Cuándo usar cada patrón

| Patrón | Cuándo usarlo | Comando |
|--------|--------------|---------|
| `compose.root.yml` | Para navegar la landing local del laboratorio | `make portal-up` |
| `cases/<caso>/<stack>/compose.yml` | Para trabajar un escenario concreto | `make case-up CASE=... STACK=...` |
| `cases/<caso>/compose.compare.yml` | Para comparar implementaciones del mismo caso | `make compare-up CASE=...` |

---

## ✅ Qué se logra con este diseño

| Beneficio | Descripción |
|-----------|-------------|
| 🪶 **Menor consumo de recursos** | Solo levantas lo que necesitas |
| 🔍 **Menos ruido** | No hay servicios de otros casos corriendo en paralelo |
| 🛠️ **Mejor mantenibilidad** | Cada caso es independiente y no rompe a los demás |
| 📊 **Demos más claras** | Puedes mostrar un caso sin distracciones |
| 🔒 **Aislamiento por escenario** | Los entornos no se interfieren entre sí |

---

## 🚫 Qué se evita conscientemente

- ❌ Un único `docker compose up` que levante el laboratorio completo
- ❌ Acoplamiento entre casos o dependencias cruzadas
- ❌ Un `compose.yml` raíz que crezca indefinidamente
- ❌ Dificultad de depuración por mezcla de entornos

---

## 💡 Filosofía

> **Docker aquí no es adorno.**
>
> Es una forma de:
> - 📝 documentar entornos de forma reproducible,
> - 🔒 aislar dependencias por escenario,
> - ▶️ facilitar la ejecución sin configuración manual,
> - ⚖️ mantener comparabilidad entre stacks.

## 🧭 Regla práctica actual

- Los casos implementados deben poder levantarse de forma limpia con Docker.
- No todos los casos necesitan la misma infraestructura.
- Cada `compose.yml` debe incluir solo lo necesario para reproducir el problema con honestidad.

Ejemplos actuales:
- Caso 02 PHP usa `app + db` porque el problema depende de relaciones y round-trips reales.
- Caso 03 PHP usa un contenedor liviano de `app` porque el foco está en logs, trazas y capacidad de diagnóstico, no en una base de datos externa.
