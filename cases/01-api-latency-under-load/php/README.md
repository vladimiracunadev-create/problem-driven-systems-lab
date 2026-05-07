# ⚡ Caso 01 - PHP 8 + PostgreSQL + worker concurrente

> Implementacion operativa real del caso 01 para estudiar latencia bajo carga con evidencia observable.

## 🎯 Que resuelve

Modela una API de reportes con dos variantes:

- `report-legacy`: consulta defectuosa sobre tabla transaccional con demasiada carga sobre DB.
- `report-optimized`: lectura sobre tabla resumen refrescada por un worker concurrente.

El escenario no se queda en un `sleep`. Mantiene un proceso de fondo que recalcula el resumen y deja visible la competencia real por recursos.

## 💻 Interfaz Visual Nativa

Al abrir la ruta raíz en tu navegador (`Accept: text/html`), este caso inyecta automáticamente un **Dashboard visual interactivo** renderizado en Vanilla JS/CSS. Esto permite observar las métricas y efectos simulados en tiempo real sin perder la capacidad de responder a consultas JSON de CLI o Postman.

## 💼 Por que importa

Este caso sirve para mostrar como se pasa de una API que "parece funcionar" a una implementacion que deja evidencia medible de por que degrada y como se corrige sin adivinar infraestructura.

## 🔬 Análisis Técnico de la Implementación (PHP)

A nivel de código puro, este caso desnuda el estrangulamiento de los workers manejadores de peticiones por latencia física entre FPM y Base de Datos (I/O Bounded Operations).

*   **Implementación Falla (`legacy`):** La arquitectura procedural utiliza `PDOStatement->fetch()` englobada en estructuras repetitivas (`foreach ($rows as &$row)`). El algoritmo hace una consulta primaria y después entra a un bucle donde ejecuta de nuevo un par de lecturas dependientes por iteración instanciando `PDO->prepare()` de manera secuencial 1+2N veces. PHP levanta la conexión bloqueante por Socket (`timedQuery()`) en cada iteración del bucle interrumpiendo el Event Loop síncrono. Esta detención de código anidada provoca que para 20 registros, el Worker quede inyectando pausas (*usleeps* físicos esperando la DB) a lo largo de 41 preparaciones PDO. La RAM de PHP no sufre (ya que descarta `$row` constantemente), pero el despachador de hilos principal agota su ciclo de vida y los tiempos de latencia crecen exponencialmente limitando drásticamente el Request Per Second (RPS).
*   **Sanitización Algoritmica (`optimized`):** El código se sana extirpando la base de datos fuera de los ciclos iterativos iterados. Utiliza sub-arreglos y `array_column()` para cosechar todos los IDs y armar un constructo dinámico `IN (...)` con `str_repeat('?,', count($ids) - 1) . '?'`. Así, empaquetamos el algoritmo en solo 3 accesos PDO asincrónicos netos cruzando las barreras al socket una única vez por tabla. Luego PHP consume su fuerza principal nativa emparejando la data en memoria usando Hash Maps asociativos construidos con `array_column($customers, null, 'id')`, accediendo en `O(1)` directo al cruzar registros, desinflando la latencia real por un factor de 10x sin exigirle más hardware a PostgreSQL ni a Docker.

## 🧱 Servicios

- `app` -> API PHP 8 con rutas legacy y optimized.
- `db` -> PostgreSQL 16 con datos semilla.
- `worker` -> refresco periodico de tabla resumen y heartbeat operacional.
- `postgres-exporter` -> metricas del motor PostgreSQL.
- `prometheus` -> scraping de la app y la base.
- `grafana` -> dashboard inicial del caso.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

## Como consumir (dos opciones)

**Hub PHP (recomendado, 8100 en `compose.root.yml`):** este caso queda servido en `http://localhost:8100/01/...` junto a los otros 11 casos.

**Modo aislado (811 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## 🔎 Endpoints

```bash
curl http://localhost:8100/01/
curl http://localhost:8100/01/health
curl "http://localhost:8100/01/report-legacy?days=30&limit=20"
curl "http://localhost:8100/01/report-optimized?days=30&limit=20"
curl http://localhost:8100/01/batch/status
curl http://localhost:8100/01/job-runs?limit=10
curl http://localhost:8100/01/diagnostics/summary
curl http://localhost:8100/01/metrics
curl http://localhost:8100/01/metrics-prometheus
```

## 📈 Observabilidad

- Prometheus: `http://localhost:9091`
- Grafana: `http://localhost:3001` (`admin` / `admin`)
- PostgreSQL exporter: `http://localhost:9187/metrics`

## 🧪 Benchmark sugerido

### Manual

```bash
curl http://localhost:8100/01/reset-metrics
make case-load CASE=01-api-latency-under-load TARGET_URL="http://php-app:8080/report-legacy?days=30&limit=20" REQUESTS=60 CONCURRENCY=8
curl http://localhost:8100/01/metrics | jq
curl http://localhost:8100/01/diagnostics/summary | jq

curl http://localhost:8100/01/reset-metrics
make case-load CASE=01-api-latency-under-load TARGET_URL="http://php-app:8080/report-optimized?days=30&limit=20" REQUESTS=60 CONCURRENCY=8
curl http://localhost:8100/01/metrics | jq
curl http://localhost:8100/01/diagnostics/summary | jq
```

### Automatizado

```bash
bash ../shared/benchmark/run-benchmark.sh
```

## 🧭 Que observar

- diferencia de latencia entre legacy y optimized;
- diferencia de `avg_db_queries` y `avg_db_time_ms`;
- duracion de los refresh del worker;
- efecto del proceso concurrente sobre las lecturas;
- si la mejora es real o solo aparente.

## ⚖️ Nota de honestidad

No pretende benchmarkear PHP contra otros runtimes. Su objetivo es mostrar diagnostico y remediacion real de un problema de latencia bajo carga, con observabilidad suficiente para sostener la conclusion.