# 🛠️ RUNBOOK

> Estado: activo
> Uso recomendado: operacion diaria, demos, diagnostico inicial y respuesta a fallas locales

## 🚪 Entradas soportadas

### Stacks completos (un comando por lenguaje)

| Escenario | Comando | Puertos |
| --- | --- | --- |
| PHP — portal + hub + DB + observabilidad | `docker compose -f compose.root.yml up -d --build` | `8080` portal · `8100` hub · `9091` Prometheus · `3001` Grafana |
| Python — dispatcher unificado | `docker compose -f compose.python.yml up -d --build` | `8200` hub |
| Portal liviano | `docker compose -f compose.portal.yml up -d --build` | `8080` |

Ambos stacks pueden correr en paralelo sin colisión de puertos.

### Casos aislados (desarrollo o revisión individual)

| Escenario | Comando recomendado |
| --- | --- |
| Caso 01 PHP | `docker compose -f cases/01-api-latency-under-load/php/compose.yml up -d --build` |
| Caso 01 Python | `docker compose -f cases/01-api-latency-under-load/python/compose.yml up -d --build` |
| Caso 02 PHP | `docker compose -f cases/02-n-plus-one-and-db-bottlenecks/php/compose.yml up -d --build` |
| Caso 02 Python | `docker compose -f cases/02-n-plus-one-and-db-bottlenecks/python/compose.yml up -d --build` |
| Caso 03 PHP | `docker compose -f cases/03-poor-observability-and-useless-logs/php/compose.yml up -d --build` |
| Caso 03 Node.js | `docker compose -f cases/03-poor-observability-and-useless-logs/node/compose.yml up -d --build` |
| Caso 03 Python | `docker compose -f cases/03-poor-observability-and-useless-logs/python/compose.yml up -d --build` |
| Caso 01 Node.js | `docker compose -f cases/01-api-latency-under-load/node/compose.yml up -d --build` |
| Caso 02 Node.js | `docker compose -f cases/02-n-plus-one-and-db-bottlenecks/node/compose.yml up -d --build` |
| Caso 04 Node.js | `docker compose -f cases/04-timeout-chain-and-retry-storms/node/compose.yml up -d --build` |
| Caso 05 Node.js | `docker compose -f cases/05-memory-pressure-and-resource-leaks/node/compose.yml up -d --build` |

## ▶️ Arranque recomendado

1. Levanta `compose.root.yml` si quieres ver hoy todo el laboratorio PHP desde una sola entrada.
2. Levanta un caso operativo especifico segun el problema que quieres evaluar.
3. Verifica `docker compose ps`.
4. Valida la URL esperada del servicio.

## 🔎 Diagnostico rapido

### PHP (compose.root.yml)

| Componente | URL | Senal esperada |
| --- | --- | --- |
| Portal | `http://localhost:8080` | Landing local disponible |
| PHP hub — índice | `http://localhost:8100/` | Lista de casos JSON |
| Caso 01 PHP | `http://localhost:8100/01/health` | Respuesta saludable |
| Caso 02 PHP | `http://localhost:8100/02/health` | Respuesta saludable |
| Casos 03–12 PHP | `http://localhost:8100/03/health` … `http://localhost:8100/12/health` | Respuesta saludable |
| Prometheus | `http://localhost:9091` | Targets visibles |
| Grafana | `http://localhost:3001` | Login accesible |

### Python (compose.python.yml)

| Componente | URL | Senal esperada |
| --- | --- | --- |
| Python hub — índice | `http://localhost:8200/` | Lista de casos JSON |
| Caso 01 Python | `http://localhost:8200/01/health` | Respuesta saludable |
| Caso 02 Python | `http://localhost:8200/02/health` | Respuesta saludable |
| Casos 03–12 Python | `http://localhost:8200/03/health` … `http://localhost:8200/12/health` | Respuesta saludable |

### Otros (Node.js casos aislados)

| Componente | URL | Senal esperada |
| --- | --- | --- |
| Caso 01 Node.js | `http://localhost:821/health` | Respuesta saludable |
| Caso 02 Node.js | `http://localhost:822/health` | Respuesta saludable |
| Caso 03 Node.js | `http://localhost:823/health` | Respuesta saludable |
| Caso 04 Node.js | `http://localhost:824/health` | Respuesta saludable |
| Caso 05 Node.js | `http://localhost:825/health` | Respuesta saludable |

## 🧰 Comandos utiles de operacion

```bash
# Ver estado de todo el stack PHP
docker compose -f compose.root.yml ps
docker compose -f compose.root.yml logs --tail=100

# Ver estado de todo el stack Python
docker compose -f compose.python.yml ps
docker compose -f compose.python.yml logs --tail=100

# Ver logs de un caso Python especifico
docker compose -f compose.python.yml logs -f case03-python

# Portal
docker compose -f compose.portal.yml ps
docker compose -f compose.portal.yml logs --tail=100

# Casos aislados (ejemplos)
docker compose -f cases/01-api-latency-under-load/php/compose.yml ps
docker compose -f cases/01-api-latency-under-load/php/compose.yml logs --tail=100

docker compose -f cases/03-poor-observability-and-useless-logs/node/compose.yml ps
docker compose -f cases/03-poor-observability-and-useless-logs/node/compose.yml logs --tail=100
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
# Bajar stack completo PHP
docker compose -f compose.root.yml down

# Bajar stack completo Python
docker compose -f compose.python.yml down

# Bajar portal
docker compose -f compose.portal.yml down

# Bajar casos aislados (si los levantaste individualmente)
docker compose -f cases/01-api-latency-under-load/php/compose.yml down
docker compose -f cases/03-poor-observability-and-useless-logs/node/compose.yml down
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
