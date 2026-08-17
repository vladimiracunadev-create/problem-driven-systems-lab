// Caso 14 — Agotamiento del pool de conexiones — stack Go 1.23.
//
// Leaky: sin deadline de adquisicion y con la devolucion solo en el camino
// feliz. Cada error se lleva una conexion que nunca vuelve al pool.
// Managed: `select` con `time.After` para el deadline y `defer` para la
// devolucion garantizada.
//
// Primitiva Go distintiva:
//
//	Un canal bufferizado **es** el pool. `<-pool` adquiere, `pool <- conn`
//	devuelve, y la capacidad del canal es el tamaño maximo. No hace falta
//	semaforo aparte ni contador: el canal ya lleva las conexiones Y limita
//	cuantas hay en vuelo, con una sola estructura.
//
//	El deadline se agrega envolviendo la recepcion en un `select`:
//
//	    select {
//	    case conn := <-pool: ...
//	    case <-time.After(timeout): // no habia
//	    }
//
//	Es la MISMA primitiva que el caso 04 usa para cancelacion, el 08 para el
//	bus de eventos y el 09 para la cuota. Cuatro problemas distintos, un solo
//	concepto que aprender.
//
//	La devolucion se garantiza con `defer`. Y aca esta el limite honesto de Go:
//	`defer` es una linea que hay que acordarse de escribir. Un `return` temprano
//	antes del `defer` fuga la conexion y **compila igual**. Rust cierra esa
//	puerta con `Drop`; Go la deja abierta y la hace facil de grepear.
//
// El "query" es un `time.Sleep` a proposito, al reves que en el caso 13. Una
// conexion se retiene mientras se espera a la red, no mientras se quema CPU.
package main

import (
	"encoding/json"
	"errors"
	"log"
	"math"
	"net/http"
	"os"
	"sort"
	"strconv"
	"sync"
	"sync/atomic"
	"time"
)

const (
	caseName          = "14 - Agotamiento del pool de conexiones"
	acquireTimeoutMs  = 200
	leakyWatchdogMs   = 2000
)

var stack = envOr("APP_STACK", "Go 1.23")

var errNoConn = errors.New("pool acquire timeout")

// ---------- pool ----------

type conn struct {
	id   int
	uses int64
}

// pool: el canal bufferizado ES el pool.
type pool struct {
	size        int
	free        chan *conn
	acquired    int64
	released    int64
	waiting     int64
	waitingPeak int64
}

func newPool(size int) *pool {
	p := &pool{size: size, free: make(chan *conn, size)}
	for i := 1; i <= size; i++ {
		p.free <- &conn{id: i}
	}
	return p
}

// acquire con deadline: select entre la conexion y el reloj.
func (p *pool) acquire(timeout time.Duration) (*conn, error) {
	w := atomic.AddInt64(&p.waiting, 1)
	for {
		peak := atomic.LoadInt64(&p.waitingPeak)
		if w <= peak || atomic.CompareAndSwapInt64(&p.waitingPeak, peak, w) {
			break
		}
	}
	defer atomic.AddInt64(&p.waiting, -1)

	timer := time.NewTimer(timeout)
	defer timer.Stop()

	select {
	case c := <-p.free:
		atomic.AddInt64(&c.uses, 1)
		atomic.AddInt64(&p.acquired, 1)
		return c, nil
	case <-timer.C:
		return nil, errNoConn
	}
}

func (p *pool) release(c *conn) {
	if c == nil {
		return
	}
	atomic.AddInt64(&p.released, 1)
	select {
	case p.free <- c:
	default: // el canal nunca deberia estar lleno; si lo esta, se descarta
	}
}

func (p *pool) leaked() int64 {
	return atomic.LoadInt64(&p.acquired) - atomic.LoadInt64(&p.released)
}

func (p *pool) available() int { return len(p.free) }

var (
	poolMu  sync.RWMutex
	current = newPool(4)
)

func activePool() *pool {
	poolMu.RLock()
	defer poolMu.RUnlock()
	return current
}

// ---------- metricas ----------

type slot struct {
	Runs          int64
	Completed     int64
	FailedQuery   int64
	FailedTimeout int64
	Hung          int64
	MaxLeaked     int64
	waitSamples   []float64
}

var (
	metricsMu sync.Mutex
	metrics   = freshMetrics()
)

func freshMetrics() map[string]*slot {
	return map[string]*slot{"leaky": {}, "managed": {}}
}

// ---------- trabajo ----------

// fails reparte los fallos de forma determinista.
//
// `idx % 100 < failRate` parece equivalente y no lo es: con 24 requests y
// failRate=25 fallarian las 24, porque todos los indices son menores que 25.
func fails(idx, failRate int) bool {
	return (idx*37)%100 < failRate
}

// runQuery: el trabajo que retiene la conexion. Una espera, no CPU.
func runQuery(c *conn, queryMs int, shouldFail bool) error {
	time.Sleep(time.Duration(queryMs) * time.Millisecond)
	if shouldFail {
		return errors.New("query fallo en la conexion " + strconv.Itoa(c.id))
	}
	return nil
}

type outcome struct {
	kind   string
	waitMs float64
}

// ---------- variante leaky ----------

func workerLeaky(idx, queryMs, failRate int, p *pool) outcome {
	t0 := time.Now()
	c, err := p.acquire(leakyWatchdogMs * time.Millisecond)
	waitMs := msSince(t0)
	if err != nil {
		return outcome{"hung", waitMs}
	}

	// El bug: falta el `defer p.release(c)`. Con el return de abajo, la
	// conexion se pierde. Compila, pasa los tests del camino feliz, y el pool
	// se achica en silencio en produccion.
	if err := runQuery(c, queryMs, fails(idx, failRate)); err != nil {
		return outcome{"failed_query", waitMs}
	}
	p.release(c)
	return outcome{"completed", waitMs}
}

// ---------- variante managed ----------

func workerManaged(idx, queryMs, failRate int, p *pool) outcome {
	t0 := time.Now()
	c, err := p.acquire(acquireTimeoutMs * time.Millisecond)
	waitMs := msSince(t0)
	if err != nil {
		// Falla rapido y de forma contable, en vez de dejar la goroutine
		// esperando algo que ya no va a llegar.
		return outcome{"failed_timeout", waitMs}
	}
	// La linea que separa las dos variantes. Corre en todos los caminos de
	// salida de esta funcion, incluido el return del error de abajo.
	defer p.release(c)

	if err := runQuery(c, queryMs, fails(idx, failRate)); err != nil {
		return outcome{"failed_query", waitMs}
	}
	return outcome{"completed", waitMs}
}

func msSince(t0 time.Time) float64 {
	return float64(time.Since(t0).Microseconds()) / 1000.0
}

// ---------- orquestacion ----------

func runLoad(variant string, requests, poolSize, queryMs, failRate int) map[string]any {
	p := newPool(poolSize)
	poolMu.Lock()
	current = p
	poolMu.Unlock()

	results := make([]outcome, requests)
	var wg sync.WaitGroup
	wg.Add(requests)
	t0 := time.Now()
	for i := 0; i < requests; i++ {
		go func(idx int) {
			defer wg.Done()
			if variant == "leaky" {
				results[idx] = workerLeaky(idx, queryMs, failRate, p)
			} else {
				results[idx] = workerManaged(idx, queryMs, failRate, p)
			}
		}(i)
	}
	wg.Wait()
	wallMs := msSince(t0)

	counts := map[string]int64{"completed": 0, "failed_query": 0, "failed_timeout": 0, "hung": 0}
	waits := make([]float64, 0, requests)
	for _, r := range results {
		counts[r.kind]++
		waits = append(waits, r.waitMs)
	}
	sort.Float64s(waits)

	metricsMu.Lock()
	s := metrics[variant]
	s.Runs++
	s.Completed += counts["completed"]
	s.FailedQuery += counts["failed_query"]
	s.FailedTimeout += counts["failed_timeout"]
	s.Hung += counts["hung"]
	if l := p.leaked(); l > s.MaxLeaked {
		s.MaxLeaked = l
	}
	s.waitSamples = append(s.waitSamples, waits...)
	if len(s.waitSamples) > 500 {
		s.waitSamples = s.waitSamples[len(s.waitSamples)-500:]
	}
	metricsMu.Unlock()

	note := "Sin deadline y con release solo en el camino feliz: falta el defer, cada error se lleva una conexion y el pool se achica en silencio."
	if variant != "leaky" {
		note = "select con time.After para el deadline + defer para la devolucion: los fallos siguen ocurriendo, pero fallan rapido y devuelven la conexion."
	}

	maxWait := 0.0
	if len(waits) > 0 {
		maxWait = waits[len(waits)-1]
	}
	var acquireTimeout any
	if variant == "managed" {
		acquireTimeout = acquireTimeoutMs
	}

	return map[string]any{
		"variant":              variant,
		"requests":             requests,
		"pool_size":            poolSize,
		"query_ms":             queryMs,
		"fail_rate_pct":        failRate,
		"acquire_timeout_ms":   acquireTimeout,
		"completed":            counts["completed"],
		"failed_query":         counts["failed_query"],
		"failed_timeout":       counts["failed_timeout"],
		"hung":                 counts["hung"],
		"acquired":             atomic.LoadInt64(&p.acquired),
		"released":             atomic.LoadInt64(&p.released),
		"leaked":               p.leaked(),
		"pool_available_after": p.available(),
		"pool_waiting_peak":    atomic.LoadInt64(&p.waitingPeak),
		"pool_wait_ms_p99":     percentile(waits, 99),
		"pool_wait_ms_max":     round2(maxWait),
		"wall_ms":              round2(wallMs),
		"littles_law":          littlesLaw(requests, queryMs, wallMs),
		"note":                 note,
	}
}

func littlesLaw(requests, queryMs int, wallMs float64) map[string]any {
	if wallMs <= 0 {
		return map[string]any{"avg_throughput_rps": 0, "avg_query_ms": queryMs, "recommended_pool_size": 1}
	}
	rps := float64(requests) / (wallMs / 1000.0)
	recommended := int(math.Ceil(rps*(float64(queryMs)/1000.0))) + 2
	if recommended < 1 {
		recommended = 1
	}
	return map[string]any{
		"avg_throughput_rps":    round2(rps),
		"avg_query_ms":          queryMs,
		"recommended_pool_size": recommended,
		"formula":               "ceil(throughput_rps * query_s) + 2 de buffer",
	}
}

func percentile(sorted []float64, pct int) float64 {
	if len(sorted) == 0 {
		return 0
	}
	idx := (pct*len(sorted)+99)/100 - 1
	if idx < 0 {
		idx = 0
	}
	if idx >= len(sorted) {
		idx = len(sorted) - 1
	}
	return round2(sorted[idx])
}

func round2(v float64) float64 { return float64(int64(v*100+0.5)) / 100 }

// ---------- rutas ----------

func poolState() map[string]any {
	p := activePool()
	return map[string]any{
		"initialized":        true,
		"pool_size":          p.size,
		"available":          p.available(),
		"acquired_total":     atomic.LoadInt64(&p.acquired),
		"released_total":     atomic.LoadInt64(&p.released),
		"leaked":             p.leaked(),
		"waiting_now":        atomic.LoadInt64(&p.waiting),
		"waiting_peak":       atomic.LoadInt64(&p.waitingPeak),
		"acquire_timeout_ms": acquireTimeoutMs,
		"leaky_watchdog_ms":  leakyWatchdogMs,
	}
}

func diagnostics() map[string]any {
	metricsMu.Lock()
	variants := make(map[string]any, 2)
	for name, s := range metrics {
		sorted := append([]float64{}, s.waitSamples...)
		sort.Float64s(sorted)
		avg := 0.0
		if len(sorted) > 0 {
			sum := 0.0
			for _, v := range sorted {
				sum += v
			}
			avg = round2(sum / float64(len(sorted)))
		}
		variants[name] = map[string]any{
			"runs":           s.Runs,
			"completed":      s.Completed,
			"failed_query":   s.FailedQuery,
			"failed_timeout": s.FailedTimeout,
			"hung":           s.Hung,
			"max_leaked":     s.MaxLeaked,
			"avg_wait_ms":    avg,
			"p99_wait_ms":    percentile(sorted, 99),
		}
	}
	metricsMu.Unlock()

	return map[string]any{
		"stack":    stack,
		"case":     caseName,
		"variants": variants,
		"pool":     poolState(),
		"interpretation": map[string]string{
			"leaky":   "leaked > 0 y hung > 0: las conexiones perdidas en el camino de error no vuelven, y lo que llega despues espera a algo que ya no existe.",
			"managed": "leaked = 0 siempre. Los fallos de query se siguen contando, pero la conexion vuelve al pool y el que no alcanza recibe un timeout rapido.",
			"go_note": "El canal bufferizado ES el pool: lleva las conexiones y limita cuantas hay en vuelo con una sola estructura. El limite honesto es que `defer` hay que acordarse de escribirlo.",
		},
	}
}

func route(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	requests := clamp(atoiOr(q.Get("requests"), 24), 1, 200)
	poolSize := clamp(atoiOr(q.Get("pool"), 4), 1, 64)
	queryMs := clamp(atoiOr(q.Get("query_ms"), 25), 1, 500)
	failRate := clamp(atoiOr(q.Get("fail_rate"), 25), 0, 100)

	status := http.StatusOK
	var payload any

	switch r.URL.Path {
	case "/", "/index":
		payload = map[string]any{
			"case":  caseName,
			"stack": stack,
			"go_specific": "El canal bufferizado ES el pool; select con time.After da el deadline y defer garantiza la devolucion.",
			"routes": []string{
				"/health",
				"/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25",
				"/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25",
				"/pool/state", "/diagnostics/summary", "/reset-lab",
			},
		}
	case "/health":
		payload = map[string]string{"status": "ok", "stack": stack, "case": caseName}
	case "/pool-leaky":
		payload = runLoad("leaky", requests, poolSize, queryMs, failRate)
	case "/pool-managed":
		payload = runLoad("managed", requests, poolSize, queryMs, failRate)
	case "/pool/state":
		payload = poolState()
	case "/diagnostics/summary":
		payload = diagnostics()
	case "/reset-lab":
		poolMu.Lock()
		current = newPool(poolSize)
		poolMu.Unlock()
		metricsMu.Lock()
		metrics = freshMetrics()
		metricsMu.Unlock()
		payload = map[string]string{"status": "reset", "message": "Pool reconstruido y metricas reiniciadas."}
	default:
		status = http.StatusNotFound
		payload = map[string]string{"error": "Ruta no encontrada", "path": r.URL.Path}
	}

	sendJSON(w, status, payload)
}

func main() {
	mux := http.NewServeMux()
	mux.HandleFunc("/", route)
	port := envOr("PORT", "8080")
	log.Printf("[case14-go] listening on %s", port)
	if err := http.ListenAndServe(":"+port, mux); err != nil {
		log.Fatalf("listen: %v", err)
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

func atoiOr(v string, fallback int) int {
	n, err := strconv.Atoi(v)
	if err != nil {
		return fallback
	}
	return n
}

func clamp(v, lo, hi int) int {
	if v < lo {
		return lo
	}
	if v > hi {
		return hi
	}
	return v
}
