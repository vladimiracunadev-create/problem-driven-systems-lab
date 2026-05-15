# 🚀 INSTALL

> Estado: activo
> Ruta oficial: Docker Compose
> Uso recomendado: levantar el laboratorio completo por lenguaje o un caso puntual de forma limpia y reproducible

## 📋 Requisitos

| Requisito | Comentario |
| --- | --- |
| Docker Desktop o Docker Engine + Compose | Requisito principal para casos operativos |
| Git | Para clonar el repositorio |
| Terminal Bash-compatible | Opcional; solo si quieres usar el `Makefile` tal cual |

Notas practicas:

- En Windows, la ruta mas estable es `docker compose` directo.
- El `Makefile` actual usa `/bin/bash`, por lo que funciona mejor en WSL, Git Bash o Linux/macOS.
- Caso `01` PHP consume mas recursos porque incluye PostgreSQL, Prometheus y Grafana.

## 📦 Clonacion

```bash
git clone https://github.com/vladimiracunadev-create/problem-driven-systems-lab.git
cd problem-driven-systems-lab
```

## 🧭 Convención: un compose por lenguaje

Cada lenguaje tiene su propio archivo compose en la raíz. Un solo comando levanta los 12 casos de ese lenguaje. Los stacks son independientes y pueden correr en paralelo.

| Archivo | Lenguaje | Puertos | Estado |
| --- | --- | --- | --- |
| [`compose.root.yml`](compose.root.yml) | PHP 8.3 | `8080` portal · `8100` PHP hub · `9091` Prometheus · `3001` Grafana | `OPERATIVO` |
| [`compose.python.yml`](compose.python.yml) | Python 3.12 | `8200` Python hub | `OPERATIVO` |
| [`compose.nodejs.yml`](compose.nodejs.yml) | Node.js 20 | `8300` Node hub | `OPERATIVO` |
| [`compose.java.yml`](compose.java.yml) | Java 21 | `8400` Java hub | `PARCIAL` (casos 01-06) |
| `compose.dotnet.yml` | .NET 8 | `8500` .NET hub | `PLANIFICADO` |

## 🐘 Laboratorio PHP completo

```bash
docker compose -f compose.root.yml up -d --build
```

URLs esperadas:

- Portal: `http://localhost:8080`
- PHP hub (todos los casos): `http://localhost:8100/01/` … `http://localhost:8100/12/`
- Prometheus: `http://localhost:9091` · Grafana: `http://localhost:3001`

Para apagar:

```bash
docker compose -f compose.root.yml down
```

## 🐍 Laboratorio Python completo

```bash
docker compose -f compose.python.yml up -d --build
```

URLs esperadas:

- Python hub: `http://localhost:8200/`
- Casos Python: `http://localhost:8200/01/health` ... `http://localhost:8200/12/health`

Para apagar:

```bash
docker compose -f compose.python.yml down
```

## 🟢 Laboratorio Node.js completo

```bash
docker compose -f compose.nodejs.yml up -d --build
```

URLs esperadas:

- Node.js hub: `http://localhost:8300/`
- Casos Node.js: `http://localhost:8300/01/health` ... `http://localhost:8300/12/health`

Para apagar:

```bash
docker compose -f compose.nodejs.yml down
```

## ☕ Laboratorio Java (casos 01-06)

```bash
docker compose -f compose.java.yml up -d --build
```

URLs esperadas:

- Java hub: `http://localhost:8400/`
- Casos Java: `http://localhost:8400/01/health` ... `http://localhost:8400/06/health`

Para apagar:

```bash
docker compose -f compose.java.yml down
```

Casos 07-12 Java no estan operativos todavia — ese rango devolvera 404 desde el hub.

## 🪶 Portal liviano solamente

```bash
docker compose -f compose.portal.yml up -d --build
```

URL esperada:

- Portal: `http://localhost:8080`

Para apagar:

```bash
docker compose -f compose.portal.yml down
```

## ✅ Ejecucion aislada de un solo caso

Cada caso mantiene su propio `compose.yml` interno. Util para desarrollo o revision individual sin levantar el stack completo.

### Caso 01

```bash
# PHP (con PostgreSQL + Prometheus + Grafana)
docker compose -f cases/01-api-latency-under-load/php/compose.yml up -d --build

# Python (contenedor unico, sin dependencias externas)
docker compose -f cases/01-api-latency-under-load/python/compose.yml up -d --build
```

URLs esperadas:

- PHP via hub: `http://localhost:8100/01/` — Prometheus: `http://localhost:9091` — Grafana: `http://localhost:3001`
- Python via hub: `http://localhost:8200/01/`

### Caso 02

```bash
docker compose -f cases/02-n-plus-one-and-db-bottlenecks/php/compose.yml up -d --build
docker compose -f cases/02-n-plus-one-and-db-bottlenecks/python/compose.yml up -d --build
```

URLs esperadas:

- PHP via hub: `http://localhost:8100/02/`
- Python via hub: `http://localhost:8200/02/`

### Caso 03 (disponible en tres lenguajes)

```bash
docker compose -f cases/03-poor-observability-and-useless-logs/php/compose.yml up -d --build
docker compose -f cases/03-poor-observability-and-useless-logs/node/compose.yml up -d --build
docker compose -f cases/03-poor-observability-and-useless-logs/python/compose.yml up -d --build
```

URLs esperadas:

- PHP via hub: `http://localhost:8100/03/`
- Node.js (aislado): `http://localhost:823`
- Python via hub: `http://localhost:8200/03/`

### Casos 01, 02, 04, 05 en Node.js (aislados)

Cada uno se levanta independiente y deja senales Node-especificas (`event_loop_lag_ms`, `process.memoryUsage()`, `AbortController`):

```bash
docker compose -f cases/01-api-latency-under-load/node/compose.yml up -d --build       # http://localhost:821
docker compose -f cases/02-n-plus-one-and-db-bottlenecks/node/compose.yml up -d --build # http://localhost:822
docker compose -f cases/04-timeout-chain-and-retry-storms/node/compose.yml up -d --build # http://localhost:824
docker compose -f cases/05-memory-pressure-and-resource-leaks/node/compose.yml up -d --build # http://localhost:825
```

## 🛠️ Atajos con Makefile

Si tu shell soporta `/bin/bash`, puedes usar:

```bash
make portal-up
make case-up CASE=01-api-latency-under-load STACK=php
make case-up CASE=02-n-plus-one-and-db-bottlenecks STACK=php
make case-up CASE=03-poor-observability-and-useless-logs STACK=php
```

## 🔎 Verificacion basica

Recomendado despues de levantar cualquier stack:

```bash
# PHP
docker compose -f compose.root.yml ps

# Python
docker compose -f compose.python.yml ps

# Caso aislado
docker compose -f cases/01-api-latency-under-load/php/compose.yml ps
docker compose -f cases/01-api-latency-under-load/php/compose.yml logs --tail=50
```

## 🧯 Apagado ordenado

```bash
docker compose -f compose.root.yml down
docker compose -f compose.python.yml down
docker compose -f compose.portal.yml down
```

## ⚖️ Alcance honesto de la instalacion

- La ruta oficialmente soportada es Docker para los casos implementados.
- PHP, Python y Node.js levantan los 12 casos cada uno con un solo compose en la raiz.
- Java levanta los casos 01-06 con `compose.java.yml` (puerto `8400`); casos 07-12 Java pendientes.
- .NET sigue como scaffold; cuando se implemente seguira el mismo patron con `compose.dotnet.yml` (puerto `8500`).
- Levantar un caso aislado sigue siendo la mejor ruta cuando quieres diagnostico fino o menos consumo de recursos.
