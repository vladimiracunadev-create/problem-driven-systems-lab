# 🚀 INSTALL

> Estado: activo
> Ruta oficial: Docker Compose
> Uso recomendado: levantar el laboratorio PHP completo o un caso puntual de forma limpia y reproducible

## 📋 Requisitos

| Requisito | Comentario |
| --- | --- |
| Docker Desktop o Docker Engine + Compose | Requisito principal para casos operativos |
| Git | Para clonar el repositorio |
| Terminal Bash-compatible | Opcional; solo si quieres usar el `Makefile` tal cual |

Notas practicas:

- En Windows, la ruta mas estable es `docker compose` directo.
- El `Makefile` actual usa `/bin/bash`, por lo que funciona mejor en WSL, Git Bash o Linux/macOS.
- Caso `01` consume mas recursos porque incluye PostgreSQL, Prometheus y Grafana.

## 📦 Clonacion

```bash
git clone https://github.com/vladimiracunadev-create/problem-driven-systems-lab.git
cd problem-driven-systems-lab
```

## 🧭 Laboratorio PHP completo

```bash
docker compose -f compose.root.yml up -d --build
```

URL esperada:

- Portal: `http://localhost:8080`
- Casos PHP: `http://localhost:811` a `http://localhost:819` y `http://localhost:8110` a `http://localhost:8112`

Para apagar:

```bash
docker compose -f compose.root.yml down
```

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

## ✅ Casos operativos actuales por separado

### Caso 01

```bash
docker compose -f cases/01-api-latency-under-load/php/compose.yml up -d --build
```

URLs esperadas:

- API: `http://localhost:811`
- Prometheus: `http://localhost:9091`
- Grafana: `http://localhost:3001`

### Caso 02

```bash
docker compose -f cases/02-n-plus-one-and-db-bottlenecks/php/compose.yml up -d --build
```

URL esperada:

- API: `http://localhost:812`

### Caso 03

```bash
docker compose -f cases/03-poor-observability-and-useless-logs/php/compose.yml up -d --build
```

URL esperada:

- API: `http://localhost:813`

Variantes operativas adicionales del caso 03:

```bash
docker compose -f cases/03-poor-observability-and-useless-logs/node/compose.yml up -d --build
docker compose -f cases/03-poor-observability-and-useless-logs/python/compose.yml up -d --build
```

URLs esperadas:

- Node.js: `http://localhost:823`
- Python: `http://localhost:833`

## 🛠️ Atajos con Makefile

Si tu shell soporta `/bin/bash`, puedes usar:

```bash
make portal-up
make case-up CASE=01-api-latency-under-load STACK=php
make case-up CASE=02-n-plus-one-and-db-bottlenecks STACK=php
make case-up CASE=03-poor-observability-and-useless-logs STACK=php
```

## 🔁 Comparacion multi-stack

Cada caso tiene `compose.compare.yml`, pero eso no significa que todos los stacks esten al mismo nivel de madurez. Usalo como marco de comparacion, no como garantia de paridad funcional completa.

```bash
docker compose -f cases/01-api-latency-under-load/compose.compare.yml up -d --build
```

## 🔎 Verificacion basica

Recomendado despues de levantar un caso:

```bash
docker compose -f cases/01-api-latency-under-load/php/compose.yml ps
docker compose -f cases/01-api-latency-under-load/php/compose.yml logs --tail=50
```

## 🧯 Apagado ordenado

```bash
docker compose -f cases/01-api-latency-under-load/php/compose.yml down
docker compose -f cases/02-n-plus-one-and-db-bottlenecks/php/compose.yml down
docker compose -f cases/03-poor-observability-and-useless-logs/php/compose.yml down
```

## ⚖️ Alcance honesto de la instalacion

- La ruta oficialmente soportada hoy es Docker para los casos implementados.
- Los casos `01` al `12` en PHP ya pueden levantarse juntos con `compose.root.yml`.
- La instalacion completa actual es PHP-first; Node.js quedara para una etapa posterior y coexistira como otra familia de runtime.
- Seguir levantando un caso aislado sigue siendo la mejor ruta cuando quieres diagnostico fino o menos consumo.
