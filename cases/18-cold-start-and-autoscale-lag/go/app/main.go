// Caso 18 — Arranque en frio y retraso del autoescalado — stack Go 1.23.
//
// Frio: el autoescalador levanta instancias cuando el trafico ya subio. El
// proceso queda vivo al instante y /health responde 200 — pero la instancia no
// sirve nada hasta terminar de inicializar. El balanceador que mira liveness en
// vez de readiness manda trafico a ese hueco. Ahi nacen los 503.
//
// Templado: pool tibio ya inicializado y ya ejercitado, y balanceador que
// enruta por /ready.
//
// Que es real y que esta modelado:
//
//	La curva de calentamiento se MIDE, no se simula. El trabajo por peticion es
//	un lazo entero puro, identico en los siete stacks, sin sleep. En Go la curva
//	sale PLANA — y esa es justamente la respuesta del stack.
//
//	La parte de I/O de la inicializacion (abrir el pool, DNS, TLS) es un sleep de
//	io_ms: esperar a la red no quema CPU, y fijarlo hace comparables a los siete.
//	La parte de CPU —construir la tabla— es trabajo real.
//
// Primitiva Go distintiva:
//
//	Go compila ahead-of-time a un binario estatico. No hay VM que levantar, no
//	hay JIT que calentar, no hay classloader, no hay opcache. El proceso arranca
//	en el orden de milisegundos y la peticion numero 1 corre con el MISMO codigo
//	maquina que la numero 100.000. `warmup_speedup_x` sale ~1.0 y eso no es que
//	el experimento falle: es el resultado.
//
//	Lo que Go si tiene, y es la parte honesta del caso, es `sync.Once`: la
//	inicializacion perezosa hecha explicita y segura para concurrencia. El
//	primer llamador la ejecuta, el resto espera, y nunca corre dos veces. Es la
//	forma idiomatica de decir "esto cuesta, y cuesta una sola vez" — y tambien
//	la trampa, porque una `sync.Once` en el camino de la peticion convierte la
//	primera peticion de cada proceso en la mas lenta de todas.
package main

import (
	"encoding/json"
	"fmt"
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
	workIters      = 250000  // calibrado para ~0.3 ms por peticion
	initTableRows  = 2000000 // parte de CPU de la inicializacion: trabajo real
)

var (
	appStack = envOr("APP_STACK", "Go 1.23")
	caseName = "18 - Arranque en frio y retraso del autoescalado"
	start    = time.Now()
)

func envOr(k, d string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return d
}

func nowMs() float64 { return float64(time.Since(start).Nanoseconds()) / 1e6 }

// work es el trabajo por peticion: lazo entero puro, sin sleep, sin I/O.
// Identico en los siete stacks. Lo que cambia es lo que el runtime hace con el
// mismo codigo repetido mil veces — que es lo que este caso mide.
func work(iters int) uint32 {
	h := uint32(2166136261)
	for i := 0; i < iters; i++ {
		h = (h ^ uint32(i)) * 16777619
	}
	return h
}

// Instance es una instancia del servicio. Vive apenas arranca; esta lista mucho
// despues. El hueco entre las dos cosas es todo el caso.
type Instance struct {
	ID      string
	live    atomic.Bool
	ready   atomic.Bool
	liveAt  float64
	readyAt atomic.Value // float64
	served  atomic.Int64
	once    sync.Once
	table   []uint32
}

func newInstance(id string) *Instance {
	in := &Instance{ID: id, liveAt: nowMs()}
	in.live.Store(true) // el proceso arranco: /health responde 200 YA
	return in
}

// boot inicializa la instancia. La `sync.Once` es la primitiva del stack: la
// inicializacion perezosa hecha explicita, que corre una sola vez por mas
// goroutines que la pidan a la vez.
func (in *Instance) boot(ioMs int) {
	in.once.Do(func() {
		// Parte de CPU: construir la tabla de configuracion. Trabajo de verdad.
		table := make([]uint32, 256)
		h := uint32(2166136261)
		for i := 0; i < initTableRows; i++ {
			h = (h ^ uint32(i)) * 16777619
			table[h&0xFF] = h
		}
		// Parte de I/O: abrir el pool, resolver DNS, negociar TLS.
		time.Sleep(time.Duration(ioMs) * time.Millisecond)
		in.table = table
		in.readyAt.Store(nowMs())
		in.ready.Store(true)
	})
}

func (in *Instance) gapMs() float64 {
	if v, ok := in.readyAt.Load().(float64); ok {
		return round2(v - in.liveAt)
	}
	return round2(nowMs() - in.liveAt)
}

type slot struct {
	Runs              int     `json:"runs"`
	Served            int     `json:"served"`
	RejectedColdStart int     `json:"rejected_cold_start"`
	ColdStarts        int     `json:"cold_starts"`
	MaxReadyAtMs      float64 `json:"max_ready_at_ms"`
}

var (
	mu       sync.Mutex
	fleet    []*Instance
	warmPool []*Instance
	metrics  = map[string]*slot{"cold": {}, "warmed": {}}
)

func round2(v float64) float64 { return math.Round(v*100) / 100 }
func round3(v float64) float64 { return math.Round(v*1000) / 1000 }

func percentile(values []float64, pct float64) float64 {
	if len(values) == 0 {
		return 0
	}
	sv := append([]float64(nil), values...)
	sort.Float64s(sv)
	idx := int(math.Ceil(pct/100*float64(len(sv)))) - 1
	if idx < 0 {
		idx = 0
	}
	if idx >= len(sv) {
		idx = len(sv) - 1
	}
	return round3(sv[idx])
}

// ---------------------------------------------------------------------------
// El pool tibio: instancias ya inicializadas Y ya ejercitadas
// ---------------------------------------------------------------------------

func buildWarmPool(instances, ioMs, prime, iters int) map[string]any {
	t0 := nowMs()
	pool := make([]*Instance, instances)
	var wg sync.WaitGroup
	for i := range pool {
		pool[i] = newInstance(fmt.Sprintf("warm-%d", i))
		wg.Add(1)
		go func(in *Instance) { defer wg.Done(); in.boot(ioMs) }(pool[i])
	}
	wg.Wait()
	initMs := nowMs() - t0

	// Ejercitar: en los stacks con JIT esta mitad aplana la curva. En Go no
	// cambia nada, porque no hay curva — el binario ya venia compilado.
	for i := 0; i < prime; i++ {
		work(iters)
	}
	for _, in := range pool {
		in.served.Add(int64(prime / max(1, instances)))
	}

	mu.Lock()
	warmPool = pool
	mu.Unlock()

	return map[string]any{
		"warm_pool_size":      len(pool),
		"init_ms":             round2(initMs),
		"prime_requests":      prime,
		"warmup_duration_ms":  round2(nowMs() - t0),
	}
}

// ---------------------------------------------------------------------------
// El balanceador: la diferencia entre mirar /health y mirar /ready
// ---------------------------------------------------------------------------

func pick(pool []*Instance, byReadiness bool, counter int) *Instance {
	n := len(pool)
	for k := 0; k < n; k++ {
		in := pool[(counter+k)%n]
		if byReadiness {
			if in.ready.Load() {
				return in
			}
		} else if in.live.Load() {
			return in
		}
	}
	return nil
}

func runScenario(variant string, requests, instances, clients, ioMs, paceMs, iters, prime int) map[string]any {
	var warmInfo map[string]any
	var byReadiness bool
	var coldStarts int
	var bootWG sync.WaitGroup
	var local []*Instance

	if variant == "cold" {
		// El autoescalador reacciona tarde: las instancias arrancan CON el
		// trafico encima, no antes.
		local = make([]*Instance, instances)
		for i := range local {
			local[i] = newInstance(fmt.Sprintf("cold-%d", i))
			bootWG.Add(1)
			go func(in *Instance) { defer bootWG.Done(); in.boot(ioMs) }(local[i])
		}
		byReadiness = false // el balanceador ingenuo mira /health
		coldStarts = instances
	} else {
		mu.Lock()
		havePool := len(warmPool) >= instances
		mu.Unlock()
		if !havePool {
			warmInfo = buildWarmPool(instances, ioMs, prime, iters)
		}
		mu.Lock()
		local = append([]*Instance(nil), warmPool[:instances]...)
		mu.Unlock()
		byReadiness = true // el balanceador correcto mira /ready
		coldStarts = 0
	}

	mu.Lock()
	fleet = local
	mu.Unlock()

	var latMu sync.Mutex
	ordered := make([]float64, 0, requests)
	var served, rejected atomic.Int64
	var gate sync.WaitGroup
	gate.Add(clients)

	var wg sync.WaitGroup
	t0 := nowMs()
	for c := 0; c < clients; c++ {
		wg.Add(1)
		go func(idx int) {
			defer wg.Done()
			gate.Done()
			gate.Wait() // largada comun
			mine := requests/clients + boolToInt(idx < requests%clients)
			for k := 0; k < mine; k++ {
				in := pick(local, byReadiness, idx+k)
				st := nowMs()
				if in == nil || !in.ready.Load() {
					// El proceso esta vivo, el healthcheck da verde, y la
					// peticion se cae igual. Nada dispara una alerta.
					rejected.Add(1)
				} else {
					work(iters)
					in.served.Add(1)
					latMu.Lock()
					ordered = append(ordered, nowMs()-st)
					latMu.Unlock()
					served.Add(1)
				}
				if paceMs > 0 {
					time.Sleep(time.Duration(paceMs) * time.Millisecond)
				}
			}
		}(c)
	}
	wg.Wait()
	bootWG.Wait()
	wall := nowMs() - t0

	first100 := ordered
	if len(first100) > 100 {
		first100 = first100[:100]
	}
	var after1000 []float64
	if len(ordered) > 1000 {
		after1000 = ordered[1000:]
	} else if len(ordered) > 100 {
		after1000 = ordered[len(ordered)-100:]
	} else {
		after1000 = ordered
	}
	p99First := percentile(first100, 99)
	p99After := percentile(after1000, 99)

	readyAt := 0.0
	for _, in := range local {
		if g := in.gapMs(); g > readyAt {
			readyAt = g
		}
	}

	mu.Lock()
	s := metrics[variant]
	s.Runs++
	s.Served += int(served.Load())
	s.RejectedColdStart += int(rejected.Load())
	s.ColdStarts += coldStarts
	if readyAt > s.MaxReadyAtMs {
		s.MaxReadyAtMs = readyAt
	}
	warmSize := len(warmPool)
	mu.Unlock()

	firstResp := 0.0
	if len(ordered) > 0 {
		firstResp = round3(ordered[0])
	}
	speedup := 1.0
	if p99After > 0 {
		speedup = round2(p99First / p99After)
	}
	gap := 0.0
	if coldStarts > 0 {
		gap = round2(readyAt)
	}
	lb := "readiness (/ready)"
	if !byReadiness {
		lb = "liveness (/health)"
	}
	note := "El pool ya estaba inicializado y ya ejercitado, y el balanceador enruta por readiness. Ninguna " +
		"peticion cae en una instancia a medio levantar: 0 rechazos y la latencia parte donde la otra variante " +
		"recien termina."
	if variant == "cold" {
		note = "El proceso esta vivo desde el milisegundo cero y /health lo confirma, pero la instancia no sirve " +
			"nada hasta terminar de inicializar. El balanceador que enruta por liveness manda trafico a ese hueco: " +
			"los 503 salen de una instancia que ninguna alerta considera caida."
	}

	out := map[string]any{
		"variant":                variant,
		"instances":              instances,
		"requests":               requests,
		"clients":                clients,
		"lb_routes_by":           lb,
		"cold_start_count":       coldStarts,
		"warm_pool_size":         warmSize,
		"ready_at_ms":            round2(readyAt),
		"health_vs_ready_gap_ms": gap,
		"first_response_ms":      firstResp,
		"p99_first_100_ms":       p99First,
		"p99_after_1000_ms":      p99After,
		"warmup_speedup_x":       speedup,
		"p50_ms":                 percentile(ordered, 50),
		"served":                 served.Load(),
		"rejected_cold_start":    rejected.Load(),
		"availability_pct":       round2(float64(served.Load()) / math.Max(1, float64(served.Load()+rejected.Load())) * 100),
		"work_iters":             iters,
		"io_ms":                  ioMs,
		"pace_ms":                paceMs,
		"wall_ms":                round2(wall),
		"note":                   note,
		"go_note": "Go compila AOT a un binario estatico: la peticion 1 corre el mismo codigo maquina que la " +
			"100.000. warmup_speedup_x ~1.0 no es que el experimento falle, es el resultado. Lo que si cuesta es " +
			"la sync.Once del arranque, y por eso esta explicita en el codigo.",
	}
	if warmInfo != nil {
		out["warm_pool_built_now"] = warmInfo
	}
	return out
}

func boolToInt(b bool) int {
	if b {
		return 1
	}
	return 0
}

func readyState() map[string]any {
	mu.Lock()
	local := append([]*Instance(nil), fleet...)
	warmSize := len(warmPool)
	mu.Unlock()

	items := make([]map[string]any, 0, len(local))
	allReady := len(local) > 0
	for _, in := range local {
		r := in.ready.Load()
		if !r {
			allReady = false
		}
		items = append(items, map[string]any{
			"id":               in.ID,
			"live":             in.live.Load(),
			"ready":            r,
			"ready_at_ms":      in.gapMs(),
			"requests_served":  in.served.Load(),
		})
	}
	return map[string]any{
		"ready":          allReady,
		"instances":      items,
		"warm_pool_size": warmSize,
		"note": "`/health` responde 200 apenas el proceso arranca. `/ready` responde 200 recien cuando la " +
			"instancia puede servir. Si el balanceador mira la primera en vez de la segunda, el hueco entre las " +
			"dos es tiempo de caida que nadie registra como caida.",
	}
}

func diagnostics() map[string]any {
	mu.Lock()
	snapshot := map[string]slot{"cold": *metrics["cold"], "warmed": *metrics["warmed"]}
	mu.Unlock()
	return map[string]any{
		"stack":    appStack,
		"case":     caseName,
		"variants": snapshot,
		"fleet":    readyState(),
		"fidelity": map[string]any{
			"medido": "La curva de calentamiento. El trabajo por peticion es un lazo entero puro sin sleep, " +
				"identico en los 7 stacks; p99_first_100_ms vs p99_after_1000_ms es lo que ese runtime hace de verdad.",
			"modelado": "La parte de I/O de la inicializacion (abrir pool, DNS, TLS) es un sleep de io_ms: esperar " +
				"a la red no quema CPU, y fijarlo es lo que hace comparables a los 7 stacks.",
			"real": "La parte de CPU de la inicializacion recorre 2.000.000 de iteraciones. Eso si es trabajo.",
		},
		"interpretation": map[string]any{
			"cold": "rejected_cold_start > 0 con el proceso vivo todo el tiempo. health_vs_ready_gap_ms es la " +
				"ventana exacta en la que el balanceador mando trafico a una instancia que no podia servirlo.",
			"warmed": "rejected_cold_start = 0. El pool ya estaba, y el balanceador enruta por readiness.",
			"go_note": "warmup_speedup_x ~1.0 es la firma de un binario AOT. Go no gana este caso por ser rapido: " +
				"lo gana por no tener nada que calentar.",
		},
	}
}

func clampInt(v, lo, hi int) int {
	if v < lo {
		return lo
	}
	if v > hi {
		return hi
	}
	return v
}

func queryInt(r *http.Request, key string, def int) int {
	raw := r.URL.Query().Get(key)
	if raw == "" {
		return def
	}
	n, err := strconv.Atoi(raw)
	if err != nil {
		return def
	}
	return n
}

func writeJSON(w http.ResponseWriter, status int, payload map[string]any) {
	payload["timestamp_utc"] = time.Now().UTC().Format("2006-01-02T15:04:05Z")
	payload["pid"] = os.Getpid()
	body, _ := json.MarshalIndent(payload, "", "  ")
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	w.Write(body)
}

func main() {
	mux := http.NewServeMux()

	handler := func(w http.ResponseWriter, r *http.Request) {
		uri := r.URL.Path
		requests := clampInt(queryInt(r, "requests", 2400), 100, 20000)
		instances := clampInt(queryInt(r, "instances", 3), 1, 32)
		clients := clampInt(queryInt(r, "clients", 8), 1, 64)
		ioMs := clampInt(queryInt(r, "io_ms", 150), 0, 5000)
		paceMs := clampInt(queryInt(r, "pace_ms", 1), 0, 100)
		iters := clampInt(queryInt(r, "work_iters", workIters), 100, 5000000)
		prime := clampInt(queryInt(r, "prime", 1500), 0, 100000)

		status := 200
		var payload map[string]any

		switch uri {
		case "/", "/index":
			payload = map[string]any{
				"lab":   "Problem-Driven Systems Lab",
				"case":  caseName,
				"stack": appStack,
				"goal": "Mostrar que el hueco entre 'el proceso esta vivo' y 'la instancia puede servir' es tiempo " +
					"de caida real que ningun healthcheck registra como caida.",
				"go_specific": "Binario estatico AOT: no hay VM que levantar ni JIT que calentar. La curva sale " +
					"plana, y sync.Once deja explicita la unica inicializacion que si cuesta.",
				"routes": map[string]string{
					"/health":                                 "Liveness: responde 200 apenas el proceso arranca.",
					"/ready":                                  "Readiness: responde 200 recien cuando la instancia puede servir.",
					"/boot-cold?requests=2400&instances=3":     "Instancias frias con el trafico ya encima.",
					"/boot-warmed?requests=2400&instances=3":   "Pool tibio y balanceador que mira readiness.",
					"/warmup?instances=3&prime=1500":           "Construye el pool tibio antes de que llegue el trafico.",
					"/diagnostics/summary":                    "Comparativa entre variantes.",
					"/reset-lab":                              "Vacia la flota, el pool tibio y las metricas.",
				},
			}
		case "/health":
			payload = map[string]any{
				"status": "ok", "stack": appStack, "case": caseName,
				"note": "Liveness. Esto responde 200 aunque la instancia no pueda servir una sola peticion.",
			}
		case "/ready":
			payload = readyState()
		case "/boot-cold":
			payload = runScenario("cold", requests, instances, clients, ioMs, paceMs, iters, prime)
		case "/boot-warmed":
			payload = runScenario("warmed", requests, instances, clients, ioMs, paceMs, iters, prime)
		case "/warmup":
			payload = buildWarmPool(instances, ioMs, prime, iters)
			payload["status"] = "warm"
			payload["note"] = "Inicializar deja la instancia lista. Ejercitarla deja al runtime listo. Las dos " +
				"mitades hacen falta, y solo la segunda depende del lenguaje."
		case "/diagnostics/summary":
			payload = diagnostics()
		case "/reset-lab":
			mu.Lock()
			fleet = nil
			warmPool = nil
			metrics = map[string]*slot{"cold": {}, "warmed": {}}
			mu.Unlock()
			payload = map[string]any{"status": "reset", "message": "Flota, pool tibio y metricas reiniciados."}
		default:
			status = 404
			payload = map[string]any{"error": "Ruta no encontrada", "path": uri}
		}
		writeJSON(w, status, payload)
	}

	mux.HandleFunc("/", handler)

	port := envOr("PORT", "8080")
	fmt.Printf("Servidor Go escuchando en %s\n", port)
	if err := http.ListenAndServe(":"+port, mux); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}
