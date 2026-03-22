# 📋 ADR 0002 — Portal raíz separado de los casos

| Campo | Valor |
|-------|-------|
| **Estado** | ✅ Aceptada |
| **Fecha** | 2025 |

---

## 🔍 Contexto

Se necesitaba un punto de entrada central al laboratorio que permita navegar el proyecto, entender su propósito y aterrizar visualmente, **sin necesidad de levantar todos los casos**.

Incluir el portal dentro de un `docker compose` global hubiera acoplado el punto de entrada con todos los entornos del laboratorio.

---

## ✅ Decisión

Crear un **portal raíz liviano en PHP 8** (`portal/`) con su propio `compose.root.yml`, separado completamente de los casos.

El portal:
- Muestra la landing local del laboratorio
- Explica la arquitectura general
- Enlaza a los casos
- No ejecuta ni depende de ningún caso

---

## ⚖️ Consecuencias

| Consecuencia | Detalle |
|-------------|---------|
| ✅ Navegación local simple | Se levanta con un solo comando sin levantar nada más |
| ✅ Menor consumo de recursos | No hay servicios de casos corriendo sin necesidad |
| ✅ Menos acoplamiento | El portal no depende de que los casos estén disponibles |
| ⚠️ Un servicio adicional que mantener | El portal PHP necesita actualizarse si cambia la arquitectura general |
