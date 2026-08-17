// Caso 15 — Backpressure en colas de mensajes — stack Go 1.23.
//
// Unbounded: una slice que crece sin techo. El productor nunca se entera de que
// el consumidor no da abasto.
// Bounded: un canal bufferizado de capacidad N y una politica explicita.
//
// Primitiva Go distintiva:
//
//	El canal bufferizado y `select` con `default`. Las tres politicas del caso
//	NO son tres estructuras: son tres formas de escribir el mismo envio.
//
//	    q <- msg                          // bloquea: backpressure al productor
//	    select { case q <- msg:           // no bloquea:
//	             default: /* llena */ }   //   el llamador decide
//	    select { case q <- msg:
//	             case <-time.After(d): }  // espera acotada y despues decide
//
//	Es la misma primitiva del caso 04 (cancelacion), del 08 (bus de eventos),
//	del 09 (cuota) y del 14 (pool). Cinco problemas distintos, un concepto.
//
//	Y hay algo que Go hace mejor que casi todos aca: **la capacidad es parte de
//	la construccion del canal**, no un parametro opcional de una llamada. No
//	existe `make(chan T)` "con buffer infinito": o es sin buffer, o el numero
//	esta escrito. La cola sin limite de este caso hay que construirla a mano con
//	una slice, justamente porque el lenguaje no la ofrece.
//
// La leccion del caso es que ninguna politica es gratis: bloquear frena al
// productor, descartar pierde datos, y la DLQ muda el problema a otra cola que
// alguien tiene que mirar (eso es el caso 20).
package main

import (
	"encoding/json"
	"log"
	"net/http"
	"os"
	"strconv"
	"sync"
	"time"
)

const (
	caseName = "15 - Backpressure en colas de mensajes"
	msgBytes = 2048
)

var stack = envOr("APP_STACK", "Go 1.23")

var policies = []string{"block", "drop_oldest", "dead_letter"}

type msg struct {
	seq        int
	enqueuedAt time.Time
}

type dlqEntry struct {
	Seq    int    `json:"seq"`
	Reason string `json:"reason"`
	At     string `json:"at"`
}

var (
	stateMu   sync.Mutex
	dlq       []dlqEntry
	lastState = map[string]any{}
)

type slot struct {
	Runs              int64 `json:"runs"`
	Produced          int64 `json:"produced"`
	Consumed          int64 `json:"consumed"`
	Dropped           int64 `json:"dropped"`
	DeadLettered      int64 `json:"dead_lettered"`
	MaxQueueDepth     int64 `json:"max_queue_depth"`
	MaxOldestAgeMs    float64
	ProducerBlockedMs float64
}

var (
	metricsMu sync.Mutex
	metrics   = freshMetrics()
)

func freshMetrics() map[string]*slot {
	return map[string]*slot{"unbounded": {}, "bounded": {}}
}

// ---------- variante unbounded ----------

// La cola sin limite hay que construirla a mano: Go no ofrece un canal con
// buffer infinito. Esa ausencia es deliberada del lenguaje, y es el punto.
type unboundedQueue struct {
	mu    sync.Mutex
	items []msg
	peak  int
}

func (u *unboundedQueue) push(m msg) {
	u.mu.Lock()
	u.items = append(u.items, m)
	if len(u.items) > u.peak {
		u.peak = len(u.items)
	}
	u.mu.Unlock()
}

func (u *unboundedQueue) pop() (msg, bool) {
	u.mu.Lock()
	defer u.mu.Unlock()
	if len(u.items) == 0 {
		return msg{}, false
	}
	m := u.items[0]
	u.items = u.items[1:]
	return m, true
}

func (u *unboundedQueue) depth() int {
	u.mu.Lock()
	defer u.mu.Unlock()
	return len(u.items)
}

func runUnbounded(messages, consumeMs int) map[string]any {
	q := &unboundedQueue{}
	var consumed int64
	var maxOldestMs float64
	var consumerMu sync.Mutex
	done := make(chan struct{})

	go func() {
		defer close(done)
		idle := 0
		for {
			m, ok := q.pop()
			if !ok {
				idle++
				if idle > 200 {
					return
				}
				time.Sleep(time.Millisecond)
				continue
			}
			idle = 0
			// Se mide ANTES de procesar: la edad del mensaje mas viejo es la
			// latencia real del consumidor final, y sin limite crece sin techo.
			age := float64(time.Since(m.enqueuedAt).Microseconds()) / 1000.0
			consumerMu.Lock()
			if age > maxOldestMs {
				maxOldestMs = age
			}
			consumerMu.Unlock()
			time.Sleep(time.Duration(consumeMs) * time.Millisecond)
			consumed++
		}
	}()

	t0 := time.Now()
	for seq := 0; seq < messages; seq++ {
		// Nunca bloquea: el productor no tiene forma de enterarse.
		q.push(msg{seq: seq, enqueuedAt: time.Now()})
	}
	depthAtEnd := q.depth()
	<-done
	wallMs := msSince(t0)

	consumerMu.Lock()
	oldest := maxOldestMs
	consumerMu.Unlock()

	return map[string]any{
		"variant":                          "unbounded",
		"policy":                           nil,
		"capacity":                         nil,
		"produced":                         messages,
		"consumed":                         consumed,
		"dropped":                          0,
		"dead_lettered":                    0,
		"queue_depth_peak":                 q.peak,
		"queue_depth_at_end_of_production": depthAtEnd,
		"queue_bytes_peak":                 q.peak * msgBytes,
		"oldest_msg_age_ms_peak":           round2(oldest),
		"producer_blocked_ms":              0.0,
		"backpressure_signals":             0,
		"wall_ms":                          round2(wallMs),
		"throughput_msg_s":                 throughput(messages, wallMs),
		"note": "Slice sin limite construida a mano — Go no ofrece un canal con buffer infinito. El productor nunca " +
			"espera y la cola crece hasta donde de la memoria.",
	}
}

// ---------- variante bounded ----------

func runBounded(messages, capacity, consumeMs int, policy string) map[string]any {
	q := make(chan msg, capacity)
	var consumed int64
	var maxOldestMs float64
	var consumerMu sync.Mutex
	done := make(chan struct{})

	go func() {
		defer close(done)
		for m := range q {
			age := float64(time.Since(m.enqueuedAt).Microseconds()) / 1000.0
			consumerMu.Lock()
			if age > maxOldestMs {
				maxOldestMs = age
			}
			consumerMu.Unlock()
			time.Sleep(time.Duration(consumeMs) * time.Millisecond)
			consumed++
		}
	}()

	t0 := time.Now()
	var produced, dropped, dead, signals int64
	var blockedMs float64
	peak := 0

	for seq := 0; seq < messages; seq++ {
		m := msg{seq: seq, enqueuedAt: time.Now()}
		switch policy {
		case "block":
			// El envio bloqueante ES la señal de backpressure. El productor se
			// frena solo, sin protocolo extra.
			if len(q) == cap(q) {
				signals++
			}
			b0 := time.Now()
			q <- m
			waited := msSince(b0)
			if waited > 0.5 {
				blockedMs += waited
			}
			produced++
		case "drop_oldest":
			select {
			case q <- m:
				produced++
			default:
				// Canal lleno: se saca el mas viejo para que entre el nuevo.
				signals++
				select {
				case <-q:
					dropped++
					select {
					case q <- m:
						produced++
					default:
						dropped++
					}
				default:
					dropped++
				}
			}
		default: // dead_letter
			select {
			case q <- m:
				produced++
			default:
				signals++
				stateMu.Lock()
				dlq = append(dlq, dlqEntry{Seq: m.seq, Reason: "queue_full", At: time.Now().UTC().Format(time.RFC3339)})
				if len(dlq) > 200 {
					dlq = dlq[len(dlq)-200:]
				}
				stateMu.Unlock()
				dead++
			}
		}
		if len(q) > peak {
			peak = len(q)
		}
	}

	depthAtEnd := len(q)
	close(q)
	<-done
	wallMs := msSince(t0)

	consumerMu.Lock()
	oldest := maxOldestMs
	consumerMu.Unlock()

	notes := map[string]string{
		"block": "Envio bloqueante `q <- m`: la capacidad del canal ES el freno. Nada se pierde, pero el productor " +
			"se frena y esa lentitud viaja aguas arriba.",
		"drop_oldest": "`select` con `default`: el productor nunca se frena, pero se pierden datos en silencio. " +
			"Aceptable para telemetria, inaceptable para pagos.",
		"dead_letter": "`select` con `default` + DLQ: no se frena ni se pierde, pero el problema se muda a otra cola " +
			"que alguien tiene que mirar. Si nadie la mira, es el caso 20.",
	}

	return map[string]any{
		"variant":                          "bounded",
		"policy":                           policy,
		"capacity":                         capacity,
		"produced":                         produced,
		"consumed":                         consumed,
		"dropped":                          dropped,
		"dead_lettered":                    dead,
		"queue_depth_peak":                 peak,
		"queue_depth_at_end_of_production": depthAtEnd,
		"queue_bytes_peak":                 peak * msgBytes,
		"oldest_msg_age_ms_peak":           round2(oldest),
		"producer_blocked_ms":              round2(blockedMs),
		"backpressure_signals":             signals,
		"wall_ms":                          round2(wallMs),
		"throughput_msg_s":                 throughput(int(produced), wallMs),
		"note":                             notes[policy],
	}
}

// ---------- registro ----------

func record(variant string, r map[string]any) {
	metricsMu.Lock()
	s := metrics[variant]
	s.Runs++
	s.Produced += toI64(r["produced"])
	s.Consumed += toI64(r["consumed"])
	s.Dropped += toI64(r["dropped"])
	s.DeadLettered += toI64(r["dead_lettered"])
	if d := toI64(r["queue_depth_peak"]); d > s.MaxQueueDepth {
		s.MaxQueueDepth = d
	}
	if a := toF64(r["oldest_msg_age_ms_peak"]); a > s.MaxOldestAgeMs {
		s.MaxOldestAgeMs = a
	}
	s.ProducerBlockedMs += toF64(r["producer_blocked_ms"])
	metricsMu.Unlock()

	stateMu.Lock()
	lastState = map[string]any{
		"last_variant":           variant,
		"last_policy":            r["policy"],
		"capacity":               r["capacity"],
		"queue_depth_peak":       r["queue_depth_peak"],
		"queue_bytes_peak":       r["queue_bytes_peak"],
		"oldest_msg_age_ms_peak": r["oldest_msg_age_ms_peak"],
	}
	stateMu.Unlock()
}

func queueState() map[string]any {
	stateMu.Lock()
	out := map[string]any{}
	for k, v := range lastState {
		out[k] = v
	}
	out["dlq_depth"] = len(dlq)
	stateMu.Unlock()
	out["msg_bytes"] = msgBytes
	out["policies"] = policies
	out["note"] = "queue_depth_peak x msg_bytes es lo que la cola llego a ocupar. La version sin limite no tiene techo."
	return out
}

func dlqView(limit int) map[string]any {
	stateMu.Lock()
	defer stateMu.Unlock()
	out := make([]dlqEntry, 0, limit)
	for i := len(dlq) - 1; i >= 0 && len(out) < limit; i-- {
		out = append(out, dlq[i])
	}
	return map[string]any{
		"dlq_depth": len(dlq),
		"limit":     limit,
		"messages":  out,
		"note":      "La DLQ no resuelve el backpressure: lo muda. El caso 20 trata que pasa cuando nadie la mira.",
	}
}

func diagnostics() map[string]any {
	metricsMu.Lock()
	variants := make(map[string]any, 2)
	for name, s := range metrics {
		variants[name] = map[string]any{
			"runs":                s.Runs,
			"produced":            s.Produced,
			"consumed":            s.Consumed,
			"dropped":             s.Dropped,
			"dead_lettered":       s.DeadLettered,
			"max_queue_depth":     s.MaxQueueDepth,
			"max_oldest_age_ms":   round2(s.MaxOldestAgeMs),
			"producer_blocked_ms": round2(s.ProducerBlockedMs),
		}
	}
	metricsMu.Unlock()

	stateMu.Lock()
	depth := len(dlq)
	stateMu.Unlock()

	return map[string]any{
		"stack":     stack,
		"case":      caseName,
		"variants":  variants,
		"dlq_depth": depth,
		"interpretation": map[string]string{
			"unbounded": "producer_blocked_ms = 0 y dropped = 0 se ven bien hasta que se mira queue_depth_peak y oldest_msg_age_ms_peak.",
			"bounded":   "Las tres politicas pagan algo distinto: block paga latencia del productor, drop_oldest paga datos, dead_letter paga deuda operativa.",
			"go_note":   "La capacidad es parte de la construccion del canal, no un parametro opcional: no existe un canal con buffer infinito. La cola sin limite hay que construirla a mano.",
		},
	}
}

// ---------- rutas ----------

func route(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	messages := clamp(atoiOr(q.Get("messages"), 120), 1, 2000)
	capacity := clamp(atoiOr(q.Get("capacity"), 32), 1, 1000)
	consumeMs := clamp(atoiOr(q.Get("consume_ms"), 2), 0, 100)
	limit := clamp(atoiOr(q.Get("limit"), 20), 1, 200)
	policy := q.Get("policy")
	if !contains(policies, policy) {
		policy = "block"
	}

	status := http.StatusOK
	var payload any

	switch r.URL.Path {
	case "/", "/index":
		payload = map[string]any{
			"case":  caseName,
			"stack": stack,
			"go_specific": "Canal bufferizado + select con default: la capacidad es parte de la construccion del canal, no un parametro opcional.",
			"routes": []string{
				"/health",
				"/produce-unbounded?messages=120&consume_ms=2",
				"/produce-bounded?messages=120&capacity=32&policy=block&consume_ms=2",
				"/produce-bounded?messages=120&capacity=32&policy=drop_oldest",
				"/produce-bounded?messages=120&capacity=32&policy=dead_letter",
				"/queue/state", "/dlq?limit=20", "/diagnostics/summary", "/reset-lab",
			},
			"allowed_policies": policies,
		}
	case "/health":
		payload = map[string]string{"status": "ok", "stack": stack, "case": caseName}
	case "/produce-unbounded":
		res := runUnbounded(messages, consumeMs)
		record("unbounded", res)
		payload = res
	case "/produce-bounded":
		res := runBounded(messages, capacity, consumeMs, policy)
		record("bounded", res)
		payload = res
	case "/queue/state":
		payload = queueState()
	case "/dlq":
		payload = dlqView(limit)
	case "/diagnostics/summary":
		payload = diagnostics()
	case "/reset-lab":
		stateMu.Lock()
		dlq = nil
		lastState = map[string]any{}
		stateMu.Unlock()
		metricsMu.Lock()
		metrics = freshMetrics()
		metricsMu.Unlock()
		payload = map[string]string{"status": "reset", "message": "DLQ y metricas reiniciadas."}
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
	log.Printf("[case15-go] listening on %s", port)
	if err := http.ListenAndServe(":"+port, mux); err != nil {
		log.Fatalf("listen: %v", err)
	}
}

// ---------- helpers ----------

func msSince(t0 time.Time) float64 {
	return float64(time.Since(t0).Microseconds()) / 1000.0
}

func round2(v float64) float64 { return float64(int64(v*100+0.5)) / 100 }

func throughput(n int, wallMs float64) float64 {
	if wallMs <= 0 {
		return 0
	}
	return round2(float64(n) / (wallMs / 1000.0))
}

func toI64(v any) int64 {
	switch t := v.(type) {
	case int:
		return int64(t)
	case int64:
		return t
	default:
		return 0
	}
}

func toF64(v any) float64 {
	switch t := v.(type) {
	case float64:
		return t
	case int:
		return float64(t)
	case int64:
		return float64(t)
	default:
		return 0
	}
}

func contains(list []string, v string) bool {
	for _, s := range list {
		if s == v {
			return true
		}
	}
	return false
}

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
