# Reporte de remediacion de runtimes

Fecha: **2026-10-25**.

## Resultado

- PHP: 23 Dockerfiles migrados desde etiquetas 8.3 flotantes a 8.4.27 exacta; 22 CLI/`cli-server` y un Apache `mod_php`.
- CVE-2026-115560: mitigacion oficial aplicada en las 23 configuraciones PHP.
- Node.js: 21 Dockerfiles fijados en 22.23.4.
- Go: 21 stages de build fijados en 1.27.3.
- Python: 21 Dockerfiles dejaron de usar `3.12-alpine` flotante y fijan 3.13.17; la release 3.13.18 del mismo dia aun no tiene imagen oficial comprobada, por lo que queda abierta y no se presenta como remediada.
- Java: 21 Dockerfiles fijados en Corretto 25.0.5 y con la eliminacion oficial de los cuatro roots eMudhra; una segunda comprobacion de `keytool` hace fallar el build si algun alias sigue presente.
- Composer: no existe manifiesto ni lockfile.
- CI: PHP de validacion subido a 8.4 y agregadas comprobaciones estaticas de imagenes exactas y mitigaciones.
- Catalogo, perfiles de lenguaje y diagramas actuales sincronizados. README y licencia se preservaron.

## Evidencia obtenida

La verificacion se limito a seguridad de runtimes, no a recertificar el comportamiento historicamente operativo del producto.

| Comando / comprobacion | Resultado |
| --- | --- |
| `php -n tests/php-security-audit.php` | 430 comprobaciones estaticas aprobadas antes del cierre documental |
| `php -n tests/runtime-base-images.php` | 20 casos + dispatcher con bases uniformes; portal Apache exacto y mitigaciones presentes |
| `php -n scripts/generate_case_catalog.php --check` | Catalogo sincronizado |
| `python scripts/generate_diagrams.py` | Cinco SVG regenerados desde el catalogo |
| Build `php-dispatcher/Dockerfile` | Exitoso con base `php:8.4.27-cli-alpine3.24`, digest observado `sha256:d8784155...` |
| Build `portal/Dockerfile` | Exitoso con base `php:8.4.27-apache-trixie`, digest observado `sha256:c6527b06...` |
| Verificacion dentro de ambas imagenes PHP | `PHP_VERSION=8.4.27`; `session.upload_progress.enabled`, `file_uploads` y `expose_php` desactivados |
| `docker manifest inspect python:3.13.17-alpine3.24` | Etiqueta existente |
| `docker manifest inspect python:3.12.16-alpine3.24` | Etiqueta inexistente al momento de la auditoria |
| Busqueda de APIs Python afectadas | Casos sin imports de `ssl`, `tarfile` o XML; `urllib` del dispatcher solo conecta por HTTP loopback |

No se declara prueba funcional completa de los casos 01/02 porque requieren PostgreSQL y el usuario acoto expresamente la tarea a correcciones CVE de software. El CI existente conserva sus smoke tests e integracion de hubs.

## Archivos de control

- `tests/php-security-audit.php`: version PHP, mitigacion y ausencia de primitivas PHP peligrosas no utilizadas.
- `tests/runtime-base-images.php`: pins exactos en 20 casos + dispatcher por stack y mitigacion Java.
- `scripts/check-language-versions.sh`: evita que un caso vuelva a una etiqueta divergente o flotante.
- `docs/security/runtime-sbom.spdx.json`: SBOM de componentes runtime fijados. Es deliberadamente un SBOM de bases, no un inventario de cada paquete del sistema operativo.
