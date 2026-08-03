# Caso 11 — Go 1.23

Stack Go operativo del caso 11. Reporte pesado sin acotar vs reporte con concurrencia limitada, midiendo si la operacion conserva aire.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `chan struct{}` con capacidad N | **Limitador de concurrencia**: como maximo N reportes corren a la vez. |
| `runtime.Gosched()` | Cede el procesador logico durante trabajo CPU-bound. Equivalente del `Thread.yield()` de Java. |
| `runtime.GOMAXPROCS(0)` · `runtime.NumGoroutine()` | Observabilidad del scheduler sin agente externo. |
| `sync/atomic` sobre `inFlight` | Requests en vuelo — la medida honesta cuando no hay pool que consultar. |

## Contraste

**Legacy** — corre sin acotar en la goroutine del request:
```go
checksum := crunch(rows, true)   // nada limita cuantos de estos corren a la vez
```

**Isolated** — adquiere un slot antes de trabajar:
```go
reportingLimiter <- struct{}{}          // bloquea si ya hay N corriendo
defer func() { <-reportingLimiter }()   // libera el slot pase lo que pase
checksum := crunch(rows, true)
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/report-legacy?rows=200000` | `ran_on_pool: request-goroutine (sin acotar)` |
| `/report-isolated?rows=200000` | `ran_on_pool: reporting-limiter (max 2 concurrentes)` |
| `/order-write` | `degraded: true` si la latencia supera 100 ms |
| `/activity` | `gomaxprocs`, goroutines vivas, slots usados, writes degradados |
| `/diagnostics/summary` | reportes por variante + snapshot de actividad |
| `/reset-lab` | reinicia contadores |

## Hub

```
docker compose -f compose.go.yml up -d --build
# disparar varios reportes en paralelo y medir la operacion mientras tanto
for i in $(seq 1 8); do curl -s "http://127.0.0.1:8600/11/report-legacy?rows=3000000" > /dev/null & done
curl http://127.0.0.1:8600/11/order-write
```

## Por que este caso NO se traduce literal desde Java

Java y .NET aislan con **pools de threads separados**: un `ThreadPoolExecutor` de 4 para trafico y otro de 2 para reporting. Ese modelo no existe en Go.

El runtime multiplexa goroutines sobre `GOMAXPROCS` hilos del SO, y crear una goroutine cuesta ~2 KB. **"Agotar el pool" no es un modo de falla que exista aca** — podes tener cien mil goroutines vivas sin drama.

Lo que si existe es **saturar el scheduler**: una goroutine CPU-bound monopoliza su procesador logico, y si hay tantas como `GOMAXPROCS`, las goroutines que sirven trafico esperan. El sintoma final es el mismo que en Java —la operacion se degrada— pero la causa raiz y el instrumento son distintos.

Por eso el aislamiento aca no es un pool sino un **semaforo de concurrencia**: acota cuantos reportes corren a la vez y deja `GOMAXPROCS - N` procesadores libres para el trafico. Traducir literalmente el `ExecutorService` habria producido codigo que compila y no enseña nada, porque estaria resolviendo un problema que Go no tiene.
