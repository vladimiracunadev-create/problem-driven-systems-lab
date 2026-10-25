# Inventario real de runtimes PHP

Fecha: **2026-10-25**.

## Superficie efectiva

| Componente | Cantidad | Imagen efectiva | SAPI / modo | Exposicion |
| --- | ---: | --- | --- | --- |
| `cases/01` a `cases/20`, `php/Dockerfile` | 20 | `php:8.4.27-cli-alpine3.24` | `cli-server` mediante `php -S` | HTTP, directamente por los compose individuales o detras del dispatcher |
| `php-dispatcher/Dockerfile` | 1 | `php:8.4.27-cli-alpine3.24` | `cli-server`; inicia y enruta los 20 procesos de caso | HTTP `:8100`, rutas `/01/...` a `/20/...` |
| `docker/php/Dockerfile` | 1 | `php:8.4.27-cli-alpine3.24` | CLI para workers y servidor integrado segun comando | Worker del caso 01 y builds compartidos |
| `portal/Dockerfile` | 1 | `php:8.4.27-apache-trixie` | Apache 2 con `mod_php` | HTTP `:80` dentro del contenedor, publicado normalmente en `:8080` |

No hay imagen PHP-FPM ni `php-cgi`. La palabra FPM aparece en documentacion pedagogica, no como proceso ejecutado. El SAPI CLI puro se usa en workers; las peticiones servidas por `php -S` reportan `cli-server`.

## Casos inventariados

1. `01-api-latency-under-load`
2. `02-n-plus-one-and-db-bottlenecks`
3. `03-poor-observability-and-useless-logs`
4. `04-timeout-chain-and-retry-storms`
5. `05-memory-pressure-and-resource-leaks`
6. `06-broken-pipeline-and-fragile-delivery`
7. `07-incremental-monolith-modernization`
8. `08-critical-module-extraction-without-breaking-operations`
9. `09-unstable-external-integration`
10. `10-expensive-architecture-for-simple-needs`
11. `11-heavy-reporting-blocks-operations`
12. `12-single-point-of-knowledge-and-operational-risk`
13. `13-cache-stampede-and-thundering-herd`
14. `14-connection-pool-exhaustion`
15. `15-message-queue-backpressure`
16. `16-idempotency-and-duplicate-effects`
17. `17-zero-downtime-schema-migration`
18. `18-cold-start-and-autoscale-lag`
19. `19-search-index-drift-and-broken-cdc`
20. `20-forgotten-dead-letter-queue`

Se contaron 61 archivos PHP dentro de `cases/` y 68 en el repositorio excluyendo `.git`. No existe `composer.json` ni `composer.lock`: no hay dependencias Composer que actualizar o auditar.

## Configuracion de seguridad comun

Las 23 imagenes escriben `zz-security.ini` con:

```ini
session.upload_progress.enabled=0
file_uploads=0
allow_url_include=0
expose_php=0
display_errors=0
log_errors=1
```

La primera directiva es la mitigacion vigente para CVE-2026-115560. `file_uploads=0` es compatible con el inventario: no existen `$_FILES` ni `move_uploaded_file()` en el codigo PHP.

## Dependencias y backports

- PHP: imagen oficial upstream exacta, no paquete de distribucion con backport.
- Extension adicional: `pdo_pgsql`, compilada contra el PHP de la imagen para los casos 01/02.
- Servicios externos: PostgreSQL y observabilidad pertenecen a los casos didacticos; no cambian el SAPI PHP.
- Los fallos pedagogicos, incluido el comportamiento intencional del caso 08, se conservaron.
