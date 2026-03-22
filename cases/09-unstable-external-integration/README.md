# 🌐 Caso 09 — Integración externa inestable

[![Estado](https://img.shields.io/badge/Estado-Base%20documental%20lista-blue)](docs/)
[![Categoría](https://img.shields.io/badge/Categoría-Resiliencia-orange)](../../README.md)

---

## 🔍 Qué problema representa

Una API, servicio o proveedor externo introduce **latencia variable, errores intermitentes o reglas cambiantes** que afectan directamente la estabilidad del sistema propio. El problema no está en tu código — pero los síntomas sí los sufre tu producto.

> La estabilidad de tu sistema no debería depender del comportamiento de un tercero que no controlas.

---

## ⚠️ Síntomas típicos

| Síntoma | Dónde se observa |
|---------|-----------------|
| Errores esporádicos sin patrón reproducible local | Logs de requests salientes |
| Latencia que varía ampliamente entre requests | APM / trazas de integración |
| Cambios en la API externa que rompen sin previo aviso | Alertas de errores de serialización |
| El sistema propio sube errores cuando el externo tiene mantenimiento | Correlación entre downtime externo y alertas propias |

---

## 🧩 Causas frecuentes

- **Sin timeout definido** — la request espera indefinidamente
- **Sin mecanismo de retry inteligente** — reintenta inmediatamente sin backoff
- **Sin circuit breaker** — sigue llamando al servicio caído
- **Sin contrato de versión** — la API externa cambia sin que el sistema se entere
- **Sin mock del externo en tests** — el test pasa en CI pero falla en producción

---

## 🔬 Estrategia de diagnóstico

1. Mapear todas las integraciones externas y su criticidad
2. Revisar qué pasa en el sistema propio cuando el externo falla totalmente
3. Medir el patrón de latencia del servicio externo a lo largo del tiempo
4. Verificar si existe un contrato o SLA del proveedor

---

## 💡 Opciones de solución

| Patrón | Descripción |
|--------|------------|
| **Timeout estricto** | Nunca esperar indefinidamente a un servicio externo |
| **Retry con backoff + jitter** | Manejar errores transitorios sin amplificar la carga |
| **Circuit breaker** | Abrir el circuito cuando el externo falla continuamente |
| **Caché de respuestas externas** | Servir la última respuesta conocida si el externo no responde |
| **Mock en tests y staging** | Aislar la integración para validar tu lógica sin el externo real |

---

## ⚖️ Trade-offs

| Decisión | Ventaja | Costo |
|----------|---------|-------|
| Circuit breaker | Aísla el fallo externo | Necesita configuración y períodos de prueba |
| Caché de respuestas | Continuidad parcial | Puede retornar datos desactualizados |
| Mock en integración | Tests confiables | Puede no reflejar el comportamiento real del externo |

---

## 💼 Valor de negocio

> Mitiga la dependencia de terceros y evita que un proveedor
> defina la estabilidad de tu producto.

---

## 🛠️ Stacks disponibles

| Stack | Estado |
|-------|--------|
| 🐘 PHP 8 | 🔧 Estructura lista |
| 🟢 Node.js | 🔧 Estructura lista |
| 🐍 Python | 🔧 Estructura lista |
| ☕ Java | 🔧 Estructura lista |
| 🔵 .NET 8 | 🔧 Estructura lista |

---

## 🚀 Cómo levantar

```bash
make case-up CASE=09-unstable-external-integration STACK=php
make compare-up CASE=09-unstable-external-integration
```

---

## 📁 Estructura

```text
09-unstable-external-integration/
├── 📄 README.md
├── 🐳 compose.compare.yml
├── 📚 docs/
├── 🔗 shared/
├── 🐘 php/  🟢 node/  🐍 python/  ☕ java/  🔵 dotnet/
```
