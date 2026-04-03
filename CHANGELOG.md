# 📝 CHANGELOG

Todos los cambios notables de este laboratorio se registran aqui con foco en madurez tecnica y documental.

## 2026-04-03 - Catalogo compartido, CI minima y caso 03 multi-stack

### Added

- `ARCHITECTURE.md` como vista ejecutiva de la arquitectura actual.
- `shared/catalog/cases.json` como fuente de verdad del catalogo.
- `scripts/generate_case_catalog.php` para generar `docs/case-catalog.md`.
- `.github/workflows/ci.yml` con validacion estructural, chequeo del catalogo generado y smoke boot de compose.

### Changed

- `portal/app/index.php` ahora consume metadatos compartidos y presenta una landing mas profesional con iconos y estados.
- `compose.root.yml` monta el catalogo compartido para eliminar duplicacion manual del portal.
- `scripts/validate-structure.sh`, `.gitignore`, `Makefile`, `shared/README.md` y `templates/problem-metadata.json` endurecidos para crecimiento mas limpio.
- Caso `03` profundizado en Node.js y Python con `legacy` vs `observable`, logs estructurados, trazas, metricas y endpoints de diagnostico.

## 2026-04-02 - Profesionalizacion documental

### Added

- `RECRUITER.md` como ruta ejecutiva para evaluacion rapida.
- `INSTALL.md`, `RUNBOOK.md`, `SUPPORT.md`, `SECURITY.md` y `CONTRIBUTING.md` en la raiz.
- `docs/BEGINNERS_GUIDE.md` para primeros pasos.

### Changed

- `README.md` reestructurado con rutas por audiencia, taxonomia honesta y contexto de ecosistema.
- `ROADMAP.md`, `docs/recruiter-guide.md`, `docs/usage-and-scope.md`, `docs/positioning-and-objective.md`, `docs/case-catalog.md` y `docs/docker-strategy.md` alineados con el nuevo estandar editorial.

## 2026-04-02 - Casos 02 y 03 operativos en PHP

### Added

- Caso `02` implementado con PostgreSQL real y comparacion N+1 legacy vs lectura optimizada.
- Caso `03` implementado con comparacion entre logs pobres y telemetria util.

### Changed

- Estrategia Docker consolidada como via oficial para casos implementados.
- Limpieza de artefactos versionados y endurecimiento de validacion estructural.
- Caso `01` ajustado para manejar metricas temporales fuera del arbol del repositorio.
