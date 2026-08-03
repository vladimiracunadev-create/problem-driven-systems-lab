// Caso 11 — Reportes pesados que bloquean operacion — stack Go 1.23.
//
// Legacy: el reporte corre sin acotar en la goroutine del request. Con varios
// reportes concurrentes se ocupan todos los procesadores logicos y /order-write
// se degrada.
// Isolated: el reporte pasa por un limitador de concurrencia; como maximo N
// reportes corren a la vez y siempre queda CPU para la operacion.
//
// El contraste que este stack aporta, y por que este caso NO se traduce literal:
//
//   Java y .NET aislan con **pools de threads separados**: un ThreadPoolExecutor
//   de 4 para trafico y otro de 2 para reporting. Go no tiene pools de threads
//   que dimensionar — el runtime multiplexa goroutines sobre GOMAXPROCS hilos
//   del SO, y crear una goroutine cuesta ~2 KB. "Agotar el pool" no es un modo
//   de falla que exista aca.
//
//   Lo que SI existe es saturar el scheduler: una goroutine CPU-bound monopoliza
//   su procesador logico, y si hay tantas como GOMAXPROCS, las goroutines que
//   sirven trafico esperan. El sintoma final es el mismo (la operacion se
//   degrada); la causa raiz y el instrumento son distintos.
//
//   Por eso el aislamiento aca no es un pool: es un **semaforo de concurrencia**
//   (`chan struct{}` con capacidad N) que acota cuantos reportes corren a la vez,
//   dejando GOMAXPROCS-N procesadores libres para el trafico. Y el trabajo
//   pesado llama a `runtime.Gosched()` periodicamente para ceder el procesador
//   — el equivalente honesto del `Thread.yield()` de Java.
package main

import (
	"encoding/json"
	"log"
	"net/http"
	"os"
	"runtime"
	"strconv"
	"sync/atomic"
	"time"
)

const (
	caseName          = "11 - Reportes pesados que bloquean operacion"
	reportingSlots    = 2
	degradedThreshold = 100 // ms
)

var stack = envOr("APP_STACK", "Go 1.23")

var (
	legacyReports       int64
	isolatedReports     int64
	orderWrites         int64
	orderWritesDegraded int64

	// inFlight cuenta requests en vuelo. Go no expone "threads activos" porque
	// no hay pool: esta es la medida equivalente y honesta.
	inFlight int64
	// reportingWaiting cuenta reportes esperando slot — el analogo de la cola.
	reportingWaiting int64
)

// reportingLimiter: semaforo de concurrencia. Sustituye al reportingPool de
// Java, pero acota trabajo simultaneo, no threads reservados.
var reportingLimiter = make(chan struct{}, reportingSlots)

// ---------- arranque ----------

func main() {
	mux := http.NewServeMux()
	mux.HandleFunc("/", route)

	port := envOr("PORT", "8080")
	log.Printf("[case11-go] listening on %s (GOMAXPROCS=%d)", port, runtime.GOMAXPROCS(0))
	if err := http.ListenAndServe(":"+port, mux); err != nil {
		log.Fatalf("listen: %v", err)
	}
}

// ---------- routing ----------

func route(w http.ResponseWriter, r *http.Request) {
	atomic.AddInt64(&inFlight, 1)
	defer atomic.AddInt64(&inFlight, -1)

	path := r.URL.Path
	rows := bounded(r.URL.Query().Get("rows"), 200000, 1000, 5000000)

	status := http.StatusOK
	var payload any

	switch path {
	case "/", "/index":
		payload = map[string]any{
			"case":  caseName,
			"stack": stack,
			"routes": []string{
				"/health", "/report-legacy?rows=200000", "/report-isolated?rows=200000",
				"/order-write", "/activity", "/diagnostics/summary", "/reset-lab",
			},
		}
	case "/health":
		payload = map[string]string{"status": "ok", "stack": stack, "case": caseName}
	case "/report-legacy":
		payload = reportLegacy(rows)
		atomic.AddInt64(&legacyReports, 1)
	case "/report-isolated":
		payload = reportIsolated(rows)
		atomic.AddInt64(&isolatedReports, 1)
	case "/order-write":
		payload = orderWrite()
		atomic.AddInt64(&orderWrites, 1)
	case "/activity":
		payload = activity()
	case "/diagnostics/summary":
		payload = diagnostics()
	case "/reset-lab":
		atomic.StoreInt64(&legacyReports, 0)
		atomic.StoreInt64(&isolatedReports, 0)
		atomic.StoreInt64(&orderWrites, 0)
		atomic.StoreInt64(&orderWritesDegraded, 0)
		payload = map[string]string{"status": "reset"}
	default:
		status = http.StatusNotFound
		payload = map[string]string{"error": "not_found", "path": path}
	}

	sendJSON(w, status, payload)
}

// ---------- trabajo pesado ----------

// crunch es el trabajo CPU-bound. Gosched() cede el procesador periodicamente:
// sin eso, una goroutine CPU-bound puede retener su P y demorar al resto.
func crunch(rows int, yield bool) int64 {
	var checksum int64
	for i := 0; i < rows; i++ {
		checksum += (int64(i) * 13) % 7
		if yield && (i&0xFFFF) == 0 {
			runtime.Gosched()
		}
	}
	return checksum
}

// reportLegacy: corre sin acotar en la goroutine del request. N reportes
// concurrentes ocupan N procesadores logicos y le quitan CPU al trafico.
func reportLegacy(rows int) map[string]any {
	start := time.Now()
	checksum := crunch(rows, true)
	return map[string]any{
		"variant": "legacy", "rows": rows, "checksum": checksum,
		"elapsed_ms":        time.Since(start).Milliseconds(),
		"ran_on_pool":       "request-goroutine (sin acotar)",
		"main_pool_active":  atomic.LoadInt64(&inFlight),
		"main_pool_queue":   atomic.LoadInt64(&reportingWaiting),
		"gomaxprocs":        runtime.GOMAXPROCS(0),
		"note":              "corre sin limite de concurrencia; mas reportes = menos CPU para /order-write.",
	}
}

// reportIsolated: adquiere un slot del limitador. Como maximo `reportingSlots`
// reportes corren a la vez, el resto espera — y GOMAXPROCS-N procesadores
// quedan disponibles para servir trafico.
func reportIsolated(rows int) map[string]any {
	start := time.Now()

	atomic.AddInt64(&reportingWaiting, 1)
	reportingLimiter <- struct{}{} // adquirir slot (bloquea si no hay)
	atomic.AddInt64(&reportingWaiting, -1)
	defer func() { <-reportingLimiter }() // liberar slot

	checksum := crunch(rows, true)
	return map[string]any{
		"variant": "isolated", "rows": rows, "checksum": checksum,
		"elapsed_ms":        time.Since(start).Milliseconds(),
		"ran_on_pool":       "reporting-limiter (max " + strconv.Itoa(reportingSlots) + " concurrentes)",
		"main_pool_active":  atomic.LoadInt64(&inFlight),
		"main_pool_queue":   atomic.LoadInt64(&reportingWaiting),
		"gomaxprocs":        runtime.GOMAXPROCS(0),
		"note":              "acotado por semaforo de concurrencia; /order-write conserva CPU disponible.",
	}
}

// orderWrite: escritura corta. Si tarda de mas, la operacion esta degradada.
func orderWrite() map[string]any {
	activeBefore := atomic.LoadInt64(&inFlight)
	start := time.Now()
	time.Sleep(20 * time.Millisecond)
	elapsedMs := time.Since(start).Milliseconds()

	degraded := elapsedMs > degradedThreshold
	if degraded {
		atomic.AddInt64(&orderWritesDegraded, 1)
	}
	note := "operacion responde con latencia normal"
	if degraded {
		note = "la latencia subio por saturacion de CPU del proceso"
	}
	return map[string]any{
		"variant": "order-write", "elapsed_ms": elapsedMs, "degraded": degraded,
		"main_pool_active_at_entry": activeBefore,
		"note":                      note,
	}
}

func activity() map[string]any {
	return map[string]any{
		"main_pool_active":      atomic.LoadInt64(&inFlight),
		"main_pool_queue":       atomic.LoadInt64(&reportingWaiting),
		"main_pool_size":        runtime.NumGoroutine(),
		"main_pool_max":         runtime.GOMAXPROCS(0),
		"reporting_slots":       reportingSlots,
		"reporting_slots_used":  len(reportingLimiter),
		"order_writes":          atomic.LoadInt64(&orderWrites),
		"order_writes_degraded": atomic.LoadInt64(&orderWritesDegraded),
	}
}

func diagnostics() map[string]any {
	return map[string]any{
		"stack": stack,
		"case":  caseName,
		"legacy": map[string]any{
			"reports":  atomic.LoadInt64(&legacyReports),
			"behavior": "reporte sin acotar en la goroutine del request; /order-write pierde CPU",
		},
		"isolated": map[string]any{
			"reports":  atomic.LoadInt64(&isolatedReports),
			"behavior": "semaforo de concurrencia acota reportes simultaneos; /order-write intacto",
		},
		"activity": activity(),
	}
}

// ---------- helpers ----------

func sendJSON(w http.ResponseWriter, status int, payload any) {
	body, err := json.Marshal(payload)
	if err != nil {
		http.Error(w, `{"error":"marshal"}`, http.StatusInternalServerError)
		return
	}
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_, _ = w.Write(body)
}

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func bounded(raw string, fallback, min, max int) int {
	n, err := strconv.Atoi(raw)
	if err != nil {
		n = fallback
	}
	if n < min {
		return min
	}
	if n > max {
		return max
	}
	return n
}
