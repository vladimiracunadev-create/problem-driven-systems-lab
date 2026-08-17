// Caso 13 — Cache stampede (thundering herd) — stack Go 1.23.
//
// Naive: la clave expira y los N llamadores concurrentes recalculan el origen.
// `origin_computations == concurrency`.
// Single-flight: `origin_computations == 1` sin importar cuantos lleguen.
//
// Primitiva Go distintiva:
//
//	Go tiene la respuesta canonica a este problema en `golang.org/x/sync/singleflight`
//	— pero eso es un modulo externo, y este lab compila sin red. Resulta que no
//	hace falta: el patron entero cabe en ~25 lineas de stdlib, y escribirlo a
//	mano es mas didactico que importarlo.
//
//	La pieza es `sync.WaitGroup` usada al reves de como se usa normalmente. En
//	vez de "el coordinador espera a los trabajadores", aca el LIDER hace Add(1)
//	antes de empezar y Done() al terminar, y todos los seguidores hacen Wait().
//	Un WaitGroup es un contador con espera; eso es exactamente un single-flight
//	con una sola operacion pendiente.
//
//	El mapa de vuelos en curso va bajo `sync.Mutex` normal — no `sync.Map`. La
//	regla practica: `sync.Map` gana cuando las claves se escriben una vez y se
//	leen muchas; aca cada entrada se crea y se borra en cada expiracion, que es
//	justo el patron en el que `sync.Map` va peor que un map con mutex.
//
// El origen es CPU real (digest iterativo), no `time.Sleep`. Un sleep no modela
// lo que duele: que el origen HACE el trabajo N veces.
package main

import (
	"encoding/json"
	"log"
	"math/rand"
	"net/http"
	"os"
	"sort"
	"strconv"
	"sync"
	"sync/atomic"
	"time"
)

const (
	caseName     = "13 - Cache stampede y thundering herd"
	ttlBaseMs    = 4000
	jitterPct    = 25
	softFraction = 0.6
)

var stack = envOr("APP_STACK", "Go 1.23")

// ---------- cache ----------

type entry struct {
	value      string
	computedAt time.Time
	softMs     int64
	hardMs     int64
}

var (
	cacheMu sync.Mutex
	cache   = map[string]entry{}

	rngMu sync.Mutex
	rng   = rand.New(rand.NewSource(130513))
)

func ttlWithJitter() (hardMs, softMs int64) {
	spread := int64(ttlBaseMs) * jitterPct / 100
	rngMu.Lock()
	jitter := rng.Int63n(spread*2+1) - spread
	rngMu.Unlock()
	hardMs = ttlBaseMs + jitter
	return hardMs, int64(float64(hardMs) * softFraction)
}

func cacheStore(key, value string) {
	hard, soft := ttlWithJitter()
	cacheMu.Lock()
	cache[key] = entry{value: value, computedAt: time.Now(), softMs: soft, hardMs: hard}
	cacheMu.Unlock()
}

// cacheState devuelve fresh | stale | miss.
func cacheState(key string) (string, string) {
	cacheMu.Lock()
	defer cacheMu.Unlock()
	e, ok := cache[key]
	if !ok {
		return "", "miss"
	}
	age := time.Since(e.computedAt).Milliseconds()
	switch {
	case age <= e.softMs:
		return e.value, "fresh"
	case age <= e.hardMs:
		return e.value, "stale"
	default:
		return "", "miss"
	}
}

// ---------- origen: trabajo real ----------

var (
	originActive int64
	originPeak   int64
)

func digestWork(key string, rounds int) string {
	h := uint32(0)
	salt := uint32(len(key))
	if salt == 0 {
		salt = 1
	}
	iterations := rounds * 2000
	for i := 0; i < iterations; i++ {
		h = h*31 + (uint32(i) ^ salt)
	}
	return strconv.FormatUint(uint64(h), 16)
}

func computeOrigin(key string, rounds int) string {
	active := atomic.AddInt64(&originActive, 1)
	for {
		peak := atomic.LoadInt64(&originPeak)
		if active <= peak || atomic.CompareAndSwapInt64(&originPeak, peak, active) {
			break
		}
	}
	defer atomic.AddInt64(&originActive, -1)

	digest := digestWork(key, rounds)
	cacheStore(key, digest)
	return digest
}

// ---------- single-flight en stdlib ----------

// call representa un recalculo en curso. `wg` es el contador con espera: el
// lider lo deja en 1 mientras trabaja y los seguidores hacen Wait().
type call struct {
	wg  sync.WaitGroup
	did bool
}

var (
	flightMu sync.Mutex
	flights  = map[string]*call{}
)

// do es singleflight.Group.Do escrito a mano.
// Devuelve (huboRecalculoReal, fuiElLider).
func do(key string, fn func() bool) (bool, bool) {
	flightMu.Lock()
	if c, ok := flights[key]; ok {
		// Ya hay un recalculo en vuelo: soltar el lock ANTES de esperar. Con el
		// lock tomado, el lider no podria borrar su entrada al terminar.
		flightMu.Unlock()
		c.wg.Wait()
		return c.did, false
	}
	c := new(call)
	c.wg.Add(1)
	flights[key] = c
	flightMu.Unlock()

	c.did = fn()
	c.wg.Done()

	flightMu.Lock()
	delete(flights, key)
	flightMu.Unlock()
	return c.did, true
}

// ---------- llamadores ----------

type outcome struct {
	waitMs   float64
	computed bool
	stale    bool
	waited   bool
}

func callerNaive(key string, rounds int, start <-chan struct{}, readGate *sync.WaitGroup) outcome {
	<-start
	t0 := time.Now()
	_, state := cacheState(key)
	// Segunda fase: los N ya leyeron la cache antes de que ninguno escriba.
	readGate.Done()
	readGate.Wait()
	if state == "fresh" {
		return outcome{waitMs: msSince(t0)}
	}
	computeOrigin(key, rounds)
	return outcome{waitMs: msSince(t0), computed: true}
}

func callerSingleflight(key string, rounds int, start <-chan struct{}, readGate *sync.WaitGroup) outcome {
	<-start
	t0 := time.Now()
	_, state := cacheState(key)
	readGate.Done()
	readGate.Wait()
	if state == "fresh" {
		return outcome{waitMs: msSince(t0)}
	}

	if state == "stale" {
		// Soft TTL vencida pero dentro de la hard: el valor viejo sigue siendo
		// servible. Si ya hay alguien refrescando, se devuelve el viejo sin
		// esperar; si no, este llamador se convierte en el que refresca.
		flightMu.Lock()
		_, refreshing := flights[key]
		flightMu.Unlock()
		if refreshing {
			return outcome{waitMs: msSince(t0), stale: true}
		}
	}

	didCompute, leader := do(key, func() bool {
		// Double check dentro del vuelo. Sin esto el patron funciona pero no
		// alcanza: el lider de la primera generacion termina, borra su entrada
		// del mapa, y las goroutines que todavia no habian llegado al `do` se
		// vuelven lideres de una segunda generacion. Con `cost` chico eso da 3
		// o 4 recalculos en vez de 1 — falta este `if`, no el patron.
		if _, st := cacheState(key); st == "fresh" {
			return false
		}
		computeOrigin(key, rounds)
		return true
	})
	if leader && didCompute {
		return outcome{waitMs: msSince(t0), computed: true}
	}
	return outcome{waitMs: msSince(t0), waited: true}
}

func msSince(t0 time.Time) float64 {
	return float64(time.Since(t0).Microseconds()) / 1000.0
}

// ---------- metricas ----------

type slot struct {
	Runs               int64     `json:"runs"`
	OriginComputations int64     `json:"origin_computations"`
	CacheHits          int64     `json:"cache_hits"`
	CoalescedWaiters   int64     `json:"coalesced_waiters"`
	ServedStale        int64     `json:"served_stale"`
	MaxStampedeDepth   int64     `json:"max_stampede_depth"`
	wallSamples        []float64 `json:"-"`
}

var (
	metricsMu sync.Mutex
	metrics   = freshMetrics()
)

func freshMetrics() map[string]*slot {
	return map[string]*slot{"naive": {}, "singleflight": {}}
}

// ---------- rafaga ----------

func runBurst(variant, key string, concurrency, rounds int) map[string]any {
	atomic.StoreInt64(&originPeak, 0)

	start := make(chan struct{})
	var readGate sync.WaitGroup
	readGate.Add(concurrency)

	results := make([]outcome, concurrency)
	var wg sync.WaitGroup
	wg.Add(concurrency)
	for i := 0; i < concurrency; i++ {
		go func(idx int) {
			defer wg.Done()
			if variant == "naive" {
				results[idx] = callerNaive(key, rounds, start, &readGate)
			} else {
				results[idx] = callerSingleflight(key, rounds, start, &readGate)
			}
		}(i)
	}

	t0 := time.Now()
	close(start) // largada comun
	wg.Wait()
	wallMs := msSince(t0)

	var computations, stale, waiters int64
	waits := make([]float64, 0, concurrency)
	for _, r := range results {
		if r.computed {
			computations++
		}
		if r.stale {
			stale++
		}
		if r.waited {
			waiters++
		}
		waits = append(waits, r.waitMs)
	}
	hits := int64(concurrency) - computations - stale - waiters
	sort.Float64s(waits)
	depth := atomic.LoadInt64(&originPeak)

	metricsMu.Lock()
	s := metrics[variant]
	s.Runs++
	s.OriginComputations += computations
	s.CacheHits += hits
	s.CoalescedWaiters += waiters
	s.ServedStale += stale
	if depth > s.MaxStampedeDepth {
		s.MaxStampedeDepth = depth
	}
	s.wallSamples = append(s.wallSamples, wallMs)
	if len(s.wallSamples) > 200 {
		s.wallSamples = s.wallSamples[len(s.wallSamples)-200:]
	}
	metricsMu.Unlock()

	value, _ := cacheState(key)
	note := "Sin coordinacion: cada llamador que vio el miss recalcula. El origen recibe la rafaga entera."
	if variant != "naive" {
		note = "singleflight a mano: WaitGroup como contador con espera, mapa de vuelos bajo mutex."
	}

	maxWait := 0.0
	if len(waits) > 0 {
		maxWait = waits[len(waits)-1]
	}

	return map[string]any{
		"variant":             variant,
		"key":                 key,
		"concurrency":         concurrency,
		"cost_rounds":         rounds,
		"origin_computations": computations,
		"cache_hits":          hits,
		"coalesced_waiters":   waiters,
		"served_stale":        stale,
		"stampede_depth":      depth,
		"wall_ms":             round2(wallMs),
		"p99_wait_ms":         percentile(waits, 99),
		"max_wait_ms":         round2(maxWait),
		"value_digest":        value,
		"ttl_base_ms":         ttlBaseMs,
		"jitter_pct":          jitterPct,
		"note":                note,
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

func round2(v float64) float64 {
	return float64(int64(v*100+0.5)) / 100
}

// ---------- rutas ----------

func cacheStateJSON() map[string]any {
	cacheMu.Lock()
	entries := make(map[string]any, len(cache))
	for k, e := range cache {
		age := time.Since(e.computedAt).Milliseconds()
		entries[k] = map[string]any{
			"age_ms":       age,
			"soft_ttl_ms":  e.softMs,
			"hard_ttl_ms":  e.hardMs,
			"soft_expired": age > e.softMs,
			"hard_expired": age > e.hardMs,
			"value_digest": e.value,
		}
	}
	cacheMu.Unlock()

	flightMu.Lock()
	keys := make([]string, 0, len(flights))
	for k := range flights {
		keys = append(keys, k)
	}
	flightMu.Unlock()
	sort.Strings(keys)

	return map[string]any{
		"entries":       entries,
		"ttl_base_ms":   ttlBaseMs,
		"jitter_pct":    jitterPct,
		"soft_fraction": softFraction,
		"inflight_keys": keys,
	}
}

func diagnostics() map[string]any {
	metricsMu.Lock()
	defer metricsMu.Unlock()
	variants := make(map[string]any, 2)
	total := int64(0)
	for name, s := range metrics {
		sorted := append([]float64{}, s.wallSamples...)
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
			"runs":                s.Runs,
			"origin_computations": s.OriginComputations,
			"cache_hits":          s.CacheHits,
			"coalesced_waiters":   s.CoalescedWaiters,
			"served_stale":        s.ServedStale,
			"max_stampede_depth":  s.MaxStampedeDepth,
			"avg_wall_ms":         avg,
			"p99_wall_ms":         percentile(sorted, 99),
		}
		total += s.OriginComputations
	}
	return map[string]any{
		"stack":                     stack,
		"case":                      caseName,
		"variants":                  variants,
		"origin_total_computations": total,
		"interpretation": map[string]string{
			"naive":        "origin_computations crece linealmente con la concurrencia: el origen ve la rafaga completa.",
			"singleflight": "origin_computations se mantiene en 1 por expiracion, sin importar cuantos llamadores lleguen.",
			"go_note":      "singleflight no necesita libreria: WaitGroup + map bajo mutex cubren el patron en ~25 lineas.",
		},
	}
}

func route(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	key := queryOr(q.Get("key"), "report-alpha")
	if len(key) > 60 {
		key = key[:60]
	}
	concurrency := clamp(atoiOr(q.Get("concurrency"), 16), 1, 128)
	rounds := clamp(atoiOr(q.Get("cost"), 40), 1, 400)

	status := http.StatusOK
	var payload any

	switch r.URL.Path {
	case "/", "/index":
		payload = map[string]any{
			"case":  caseName,
			"stack": stack,
			"go_specific": "singleflight escrito a mano con sync.WaitGroup y un map bajo mutex; sin dependencias externas.",
			"routes": []string{
				"/health",
				"/cache-naive?key=report-alpha&concurrency=16&cost=40",
				"/cache-singleflight?key=report-alpha&concurrency=16&cost=40",
				"/cache/state", "/diagnostics/summary", "/reset-lab",
			},
		}
	case "/health":
		payload = map[string]string{"status": "ok", "stack": stack, "case": caseName}
	case "/cache-naive":
		payload = runBurst("naive", key, concurrency, rounds)
	case "/cache-singleflight":
		payload = runBurst("singleflight", key, concurrency, rounds)
	case "/cache/state":
		payload = cacheStateJSON()
	case "/diagnostics/summary":
		payload = diagnostics()
	case "/reset-lab":
		cacheMu.Lock()
		cache = map[string]entry{}
		cacheMu.Unlock()
		flightMu.Lock()
		flights = map[string]*call{}
		flightMu.Unlock()
		metricsMu.Lock()
		metrics = freshMetrics()
		metricsMu.Unlock()
		atomic.StoreInt64(&originPeak, 0)
		payload = map[string]string{"status": "reset", "message": "Cache y metricas reiniciadas."}
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
	log.Printf("[case13-go] listening on %s", port)
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

func queryOr(v, fallback string) string {
	if v == "" {
		return fallback
	}
	return v
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
