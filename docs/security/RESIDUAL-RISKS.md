# Riesgos residuales

Fecha: **2026-10-25**.

| Riesgo | Estado | Accion requerida |
| --- | --- | --- |
| Python 3.13.18 no disponible aun como imagen oficial exacta | Abierto. 3.13.17 actualiza pip/certifi, pero no contiene todas las correcciones del 25-10 | Cambiar los 21 pins a `python:3.13.18-alpine3.24` cuando Docker Official Images la publique; hasta entonces no usar `ssl`, `tarfile` ni XML con entrada no confiable |
| PHP CVE-2026-115560 sin release correctiva | Mitigado, no corregido | Mantener `session.upload_progress.enabled=0`; subir al siguiente 8.4.x que PHP identifique como correctivo |
| Java CVE-2026-91403 | Mitigado en trust store, no cerrado por version | Sustituir la mitigacion por un CSPU de proveedor cuyo aviso nombre explicitamente la correccion; no inferirla desde `25.0.5` |
| `.NET 10.0` y Rust 1.99 siguen con tags de linea, no de parche | Fuera del cambio semanal aplicado | Hacer un ciclo separado de pin exacto y aviso de proveedor; no se mezclo con una correccion sin evidencia |
| Paquetes del sistema operativo dentro de las imagenes | No enumerados por el SBOM de bases | Generar SBOM profundo y escanear las imagenes en CI cuando se autorice consumo de scanner; no se afirma cero CVE residual |
| Dependencias descargadas por Java y Go durante build | Versiones existentes conservadas para no alterar escenarios | Auditar `sqlite-jdbc`, `go.mod/go.sum` y sus licencias en un cambio separado |
| Servicios didacticos locales sin auth/TLS/rate limiting | Intencional y ya documentado | Mantener solo en localhost o delante de controles externos; no exponer directamente a Internet |

Los fallos pedagogicos se conservaron. Ninguna referencia historica del `CHANGELOG.md` o de los documentos comparativos se reescribio como si las mediciones antiguas hubieran usado los runtimes nuevos.
