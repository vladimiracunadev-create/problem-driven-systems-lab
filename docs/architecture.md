# 🏛️ Arquitectura del repositorio

> Vista estructural del laboratorio, con foco en el estado actual del sistema y no solo en la forma del arbol.

## 📐 Estructura por niveles

```text
problem-driven-systems-lab/
|- README.md
|- ARCHITECTURE.md
|- RECRUITER.md
|- INSTALL.md
|- RUNBOOK.md
|- SECURITY.md
|- SUPPORT.md
|- CONTRIBUTING.md
|- CHANGELOG.md
|- ROADMAP.md
|- compose.root.yml       ← PHP: 12 casos + portal + DB + observabilidad
|- compose.python.yml     ← Python: 12 casos, stdlib pura
|- compose.portal.yml     ← portal liviano solamente
|- docker/
|- .github/workflows/ci.yml
|- portal/
|- docs/
|- cases/
|- shared/
|  `- catalog/cases.json
`- scripts/
   `- generate_case_catalog.php
```

## 🧱 Capas principales

### 1. Capa editorial y operativa

La raiz contiene documentos para lectura ejecutiva, tecnica y operacional. Esta capa explica el producto antes de entrar a cualquier caso.

### 2. Capa de metadatos

`shared/catalog/cases.json` es la fuente de verdad del catalogo.

- el portal local lo consume;
- `scripts/generate_case_catalog.php` genera `docs/case-catalog.md`;
- la CI verifica que no exista drift documental.

### 3. Capa de portal y stacks raíz

Cada lenguaje operativo tiene su propio compose en la raíz — un comando levanta los 12 casos de ese lenguaje:

- `compose.root.yml` — PHP: portal (`8080`) + dispatcher PHP `php-lab` (`8100`, 12 casos internos en `:9001-:9012`) + PostgreSQL (casos 01–02) + worker case01 + Prometheus (`9091`) + Grafana (`3001`)
- `compose.python.yml` — Python: dispatcher único con 12 casos internos (`8200`), stdlib pura, sin dependencias externas
- `compose.nodejs.yml` — Node.js 20: dispatcher único con 12 casos internos (`8300`), stdlib pura, sin dependencias externas
- `compose.java.yml` — Java 21: dispatcher único con casos 01-06 internos (`8400`), JDK built-in (`HttpServer`, `HttpClient`), sin Maven
- `compose.portal.yml` — portal liviano solamente (`8080`)

Los cuatro stacks operativos pueden correr en paralelo sin colisión de puertos. .NET seguirá el mismo patrón con `compose.dotnet.yml` (puerto `8500`).

La capa visual sigue viviendo en `portal/`, con:

- `index.html` como portada principal para personas tecnicas y no tecnicas;
- `catalog.php` como endpoint de metadatos para la UI;
- `probe.php` como verificador server-side de health checks;
- `index.php` como redireccion de compatibilidad.

### 4. Capa de casos

Cada carpeta en `cases/` representa un problema real. La unidad central del laboratorio es el caso, no el lenguaje.

### 5. Capa de stacks

Cada caso contiene `php`, `node`, `python`, `java` y `dotnet` con Docker aislado. La madurez real de cada stack depende de su implementacion, no solo de la existencia de la carpeta.

## 🔁 Flujo de sincronizacion actual

```
shared/catalog/cases.json
  ├──▶ portal/app/catalog.php    (payload JSON para la UI)
  ├──▶ portal/app/probe.php      (health checks server-side)
  └──▶ scripts/generate_case_catalog.php
              └──▶ docs/case-catalog.md

scripts/validate-structure.sh ──▶ .github/workflows/ci.yml ◀── catalog.php
                                                            ◀── probe.php
```

## 🐳 Modelo de ejecucion

| Pieza | Rol |
| --- | --- |
| `compose.root.yml` | portal (`8080`) + `php-lab` dispatcher (`8100`, 12 casos PHP como subprocesos internos) + DB caso 01-02 + worker + Prometheus + Grafana |
| `compose.python.yml` | dispatcher Python (`8200`) con los 12 casos internos, stdlib pura, sin dependencias externas |
| `compose.nodejs.yml` | dispatcher Node.js (`8300`) con los 12 casos internos, stdlib pura |
| `compose.java.yml` | dispatcher Java (`8400`) con los casos 01-06 internos, JDK built-in (sin Maven) |
| `compose.portal.yml` | portal liviano |
| `cases/<caso>/<stack>/compose.yml` | escenario concreto y aislado (desarrollo o revision individual) |
| `cases/<caso>/compose.compare.yml` | comparacion entre stacks del mismo caso |

La familia PHP reutiliza un runtime comun en `docker/php/Dockerfile`. La familia Python usa `python:3.12-alpine` directamente en cada caso. Cada caso mantiene su propio `compose.yml` interno independientemente del compose raiz del lenguaje.

## ✅ Estado operativo real

| Caso | php | python | node | java | dotnet |
| --- | --- | --- | --- | --- | --- |
| `01` | ✅ OPERATIVO | ✅ OPERATIVO | ✅ OPERATIVO | ✅ OPERATIVO | scaffold |
| `02` | ✅ OPERATIVO | ✅ OPERATIVO | ✅ OPERATIVO | ✅ OPERATIVO | scaffold |
| `03` | ✅ OPERATIVO | ✅ OPERATIVO | ✅ OPERATIVO | ✅ OPERATIVO | scaffold |
| `04` | ✅ OPERATIVO | ✅ OPERATIVO | ✅ OPERATIVO | ✅ OPERATIVO | scaffold |
| `05` | ✅ OPERATIVO | ✅ OPERATIVO | ✅ OPERATIVO | ✅ OPERATIVO | scaffold |
| `06` | ✅ OPERATIVO | ✅ OPERATIVO | ✅ OPERATIVO | ✅ OPERATIVO | scaffold |
| `07` | ✅ OPERATIVO | ✅ OPERATIVO | ✅ OPERATIVO | scaffold | scaffold |
| `08` | ✅ OPERATIVO | ✅ OPERATIVO | ✅ OPERATIVO | scaffold | scaffold |
| `09` | ✅ OPERATIVO | ✅ OPERATIVO | ✅ OPERATIVO | scaffold | scaffold |
| `10` | ✅ OPERATIVO | ✅ OPERATIVO | ✅ OPERATIVO | scaffold | scaffold |
| `11` | ✅ OPERATIVO | ✅ OPERATIVO | ✅ OPERATIVO | scaffold | scaffold |
| `12` | ✅ OPERATIVO | ✅ OPERATIVO | ✅ OPERATIVO | scaffold | scaffold |

**OPERATIVO** = lógica real, Docker funcional, evidencia observable.
**scaffold** = estructura y documentación lista, sin implementación funcional todavía.

## 🧭 Regla principal

La arquitectura responde a esta pregunta:

> ¿Como resolver y estudiar este problema con evidencia reproducible?

No responde a:

> ¿Como ordenar lenguajes por gusto o llenar carpetas sin profundidad?
