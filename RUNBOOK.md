# 🛠️ RUNBOOK

> Estado: activo
> Uso recomendado: operacion diaria, demos, diagnostico inicial y respuesta a fallas locales

## 🚪 Entradas soportadas

| Escenario | Comando recomendado |
| --- | --- |
| Laboratorio PHP completo | `docker compose -f compose.root.yml up -d --build` |
| Portal liviano | `docker compose -f compose.portal.yml up -d --build` |
| Caso 01 PHP | `docker compose -f cases/01-api-latency-under-load/php/compose.yml up -d --build` |
| Caso 02 PHP | `docker compose -f cases/02-n-plus-one-and-db-bottlenecks/php/compose.yml up -d --build` |
| Caso 03 PHP | `docker compose -f cases/03-poor-observability-and-useless-logs/php/compose.yml up -d --build` |
| Caso 03 Node.js | `docker compose -f cases/03-poor-observability-and-useless-logs/node/compose.yml up -d --build` |
| Caso 03 Python | `docker compose -f cases/03-poor-observability-and-useless-logs/python/compose.yml up -d --build` |

## ▶️ Arranque recomendado

1. Levanta `compose.root.yml` si quieres ver hoy todo el laboratorio PHP desde una sola entrada.
2. Levanta un caso operativo especifico segun el problema que quieres evaluar.
3. Verifica `docker compose ps`.
4. Valida la URL esperada del servicio.

## 🔎 Diagnostico rapido

| Componente | URL o chequeo | Senal esperada |
| --- | --- | --- |
| Portal | `http://localhost:8080` | Landing local disponible |
| Casos PHP 07-12 | `http://localhost:817` a `http://localhost:8112` | Endpoints vivos y visibles desde el portal |
| Caso 01 API | `http://localhost:811/health` | Respuesta saludable |
| Caso 01 Grafana | `http://localhost:3001` | Login accesible |
| Caso 01 Prometheus | `http://localhost:9091` | Targets visibles |
| Caso 02 API | `http://localhost:812/health` | Respuesta saludable |
| Caso 03 PHP | `http://localhost:813/health` | Respuesta saludable |
| Caso 03 Node.js | `http://localhost:823/health` | Respuesta saludable |
| Caso 03 Python | `http://localhost:833/health` | Respuesta saludable |

## 🧰 Comandos utiles de operacion

```bash
docker compose -f compose.root.yml ps
docker compose -f compose.root.yml logs --tail=100
docker compose -f compose.portal.yml ps
docker compose -f compose.portal.yml logs --tail=100

docker compose -f cases/01-api-latency-under-load/php/compose.yml ps
docker compose -f cases/01-api-latency-under-load/php/compose.yml logs --tail=100

docker compose -f cases/02-n-plus-one-and-db-bottlenecks/php/compose.yml ps
docker compose -f cases/02-n-plus-one-and-db-bottlenecks/php/compose.yml logs --tail=100

docker compose -f cases/03-poor-observability-and-useless-logs/php/compose.yml ps
docker compose -f cases/03-poor-observability-and-useless-logs/php/compose.yml logs --tail=100

docker compose -f cases/03-poor-observability-and-useless-logs/node/compose.yml ps
docker compose -f cases/03-poor-observability-and-useless-logs/node/compose.yml logs --tail=100

docker compose -f cases/03-poor-observability-and-useless-logs/python/compose.yml ps
docker compose -f cases/03-poor-observability-and-useless-logs/python/compose.yml logs --tail=100
```

## 🚨 Respuesta a incidencias comunes

| Problema | Respuesta operativa |
| --- | --- |
| Puerto ocupado | Libera el puerto o cambia el mapeo antes de levantar el caso |
| Contenedor `db` tarda en quedar sano | Espera el healthcheck o revisa `logs` antes de reiniciar |
| La API responde lento en caso 01 | Confirma si el worker y la carga estan activos; el caso esta pensado para mostrar contencion real |
| `make` falla en Windows | Usa `docker compose` directo o ejecuta el Makefile desde Git Bash o WSL |
| Telemetria o datos quedan "sucios" despues de muchas pruebas | Baja el caso y vuelve a levantarlo; si necesitas reinicio completo, recrea el stack y sus volumenes conscientemente |

## 🧯 Apagado ordenado

```bash
docker compose -f compose.root.yml down
docker compose -f compose.portal.yml down
docker compose -f cases/01-api-latency-under-load/php/compose.yml down
docker compose -f cases/02-n-plus-one-and-db-bottlenecks/php/compose.yml down
docker compose -f cases/03-poor-observability-and-useless-logs/php/compose.yml down
docker compose -f cases/03-poor-observability-and-useless-logs/node/compose.yml down
docker compose -f cases/03-poor-observability-and-useless-logs/python/compose.yml down
```

## 🧭 Cuando usar este runbook

- Antes de una demo o revision tecnica.
- Cuando un caso operativo no levanta como esperas.
- Cuando necesitas validar si el problema esta en Docker, en el caso o en el host.

## 📚 Documentos relacionados

- [INSTALL.md](INSTALL.md)
- [SUPPORT.md](SUPPORT.md)
- [docs/docker-strategy.md](docs/docker-strategy.md)
- [docs/usage-and-scope.md](docs/usage-and-scope.md)
