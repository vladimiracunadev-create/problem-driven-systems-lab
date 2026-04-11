# 📋 ADR 0002 — Portal raíz separado de los casos

| Campo | Valor |
|-------|-------|
| **Estado** | ✅ Aceptada |
| **Fecha** | 2025 |

---

## 🔍 Contexto

Se necesitaba un punto de entrada central al laboratorio que permita navegar el proyecto, entender su propósito y aterrizar visualmente.

En la primera etapa, ese objetivo se resolvió con un portal liviano separado de los casos. En la etapa actual, PHP ya cuenta con 12 casos operativos y se volvió útil tener tambien una entrada completa del laboratorio para validar el sistema entero desde `localhost`.

---

## ✅ Decisión

Mantener el **portal raíz liviano en PHP 8** (`portal/`) como pieza separada, pero distinguir ahora dos entradas:

- `compose.root.yml` para el laboratorio PHP completo, incluyendo portal y casos `01` al `12`;
- `compose.portal.yml` para el portal liviano cuando solo se quiere navegar la portada o revisar metadatos.

El portal sigue:
- mostrando la landing local del laboratorio;
- explicando la arquitectura general;
- enlazando a los casos;
- funcionando tambien en modo liviano, sin necesidad de levantar todos los casos.

---

## ⚖️ Consecuencias

| Consecuencia | Detalle |
|-------------|---------|
| ✅ Navegación local simple | Hay una entrada completa (`compose.root.yml`) y una liviana (`compose.portal.yml`) |
| ✅ Menor consumo cuando conviene | El portal puede seguir levantándose sin los casos si solo quieres revisar la portada |
| ✅ Mejor demostración de producto | El modo completo deja visible todo el laboratorio PHP desde una sola URL |
| ⚠️ Más topologías a documentar | Ahora hay que mantener clara la diferencia entre entrada completa y entrada liviana |
