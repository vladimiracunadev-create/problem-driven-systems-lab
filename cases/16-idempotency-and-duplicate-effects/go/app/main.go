// Caso 16 — Idempotencia y efectos duplicados — stack Go 1.23.
//
// Unsafe: N reintentos del mismo pago aplican N cargos.
// Idempotent: `Idempotency-Key` persistida + outbox pattern.
//
// Primitiva Go distintiva:
//
//	`sync.Map.LoadOrStore(key, value)`.
//
//	Devuelve `(valorExistente, cargado)`. Si `cargado` es false, la clave se
//	acaba de reservar y sos el primero; si es true, alguien llego antes y te
//	llevas su valor. Una sola operacion resuelve la carrera Y te dice de que
//	lado quedaste — igual que `putIfAbsent` de Java, `TryAdd` de .NET y
//	`entry()` de Rust.
//
//	Lo distintivo de Go es el CUANDO. `sync.Map` esta documentado para dos casos
//	de uso, y este es exactamente el segundo: claves que se escriben una vez y
//	se leen muchas. Es lo contrario del caso 13, donde un `map` bajo mutex era
//	la eleccion correcta porque cada entrada se creaba y se borraba en cada
//	expiracion.
//
//	El mismo lab, dos casos, dos respuestas opuestas — y la regla que las separa
//	es el patron de escritura, no la preferencia.
//
// La segunda mitad es el **outbox pattern**: el cargo va a la base y el email a
// una cola, sin transaccion que los abarque. El outbox escribe el efecto en la
// misma escritura que el cargo y deja que un worker lo entregue.
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
	caseName        = "16 - Idempotencia y efectos duplicados"
	dedupeWindowMs  = int64(24 * 60 * 60 * 1000)
	maxRows         = 200
)

var stack = envOr("APP_STACK", "Go 1.23")

type entry struct {
	mu       sync.Mutex
	response string
	storedAt int64
}

type outboxRow struct {
	Key         string `json:"key"`
	Kind        string `json:"kind"`
	AmountCents int64  `json:"amount_cents"`
	At          string `json:"at"`
	Status      string `json:"status"`
	Via         string `json:"via"`
}

var (
	ledgerMu sync.Mutex
	ledger   = map[string]int64{}

	// sync.Map: claves que se escriben una vez y se leen muchas. Es el caso de
	// uso documentado, y el opuesto al del caso 13.
	idempotency sync.Map

	boxMu     sync.Mutex
	outbox    []outboxRow
	delivered []outboxRow
)

type slot struct {
	Runs                int64 `json:"runs"`
	Attempts            int64 `json:"attempts"`
	ChargesApplied      int64 `json:"charges_applied"`
	DuplicatesPrevented int64 `json:"duplicates_prevented"`
	DuplicatesApplied   int64 `json:"duplicates_applied"`
	IdempotencyHits     int64 `json:"idempotency_hits"`
	SideEffects         int64 `json:"side_effects_emitted"`
	Overcharged         int64 `json:"overcharged_cents"`
}

var (
	metricsMu sync.Mutex
	metrics   = freshMetrics()
)

func freshMetrics() map[string]*slot {
	return map[string]*slot{"unsafe": {}, "idempotent": {}}
}

func nowMs() int64 { return time.Now().UnixMilli() }

func applyCharge(account string, amount int64) int64 {
	ledgerMu.Lock()
	defer ledgerMu.Unlock()
	ledger[account] += amount
	return ledger[account]
}

// emitDirect publica el efecto FUERA de la transaccion del cargo.
func emitDirect(key string, amount int64) {
	boxMu.Lock()
	delivered = append(delivered, outboxRow{key, "payment_receipt_email", amount,
		time.Now().UTC().Format(time.RFC3339), "delivered", "direct"})
	if len(delivered) > maxRows {
		delivered = delivered[len(delivered)-maxRows:]
	}
	boxMu.Unlock()
}

// enqueueOutbox escribe el efecto junto al cargo. No lo entrega.
func enqueueOutbox(key string, amount int64) {
	boxMu.Lock()
	outbox = append(outbox, outboxRow{key, "payment_receipt_email", amount,
		time.Now().UTC().Format(time.RFC3339), "pending", "outbox"})
	if len(outbox) > maxRows {
		outbox = outbox[len(outbox)-maxRows:]
	}
	boxMu.Unlock()
}

// drainOutbox mueve lo pendiente al destino real. Idempotente por diseño.
func drainOutbox() int {
	boxMu.Lock()
	defer boxMu.Unlock()
	moved := 0
	for i := range outbox {
		if outbox[i].Status == "pending" {
			outbox[i].Status = "delivered"
			delivered = append(delivered, outbox[i])
			moved++
		}
	}
	if len(delivered) > maxRows {
		delivered = delivered[len(delivered)-maxRows:]
	}
	return moved
}

type outcome struct {
	applied  bool
	hit      bool
	lookupMs float64
}

// ---------- variante unsafe ----------

func attemptUnsafe(key, account string, amount int64, start <-chan struct{}) outcome {
	<-start
	applyCharge(account, amount)
	emitDirect(key, amount)
	return outcome{applied: true}
}

// ---------- variante idempotent: LoadOrStore ----------

func attemptIdempotent(key, account string, amount int64, start <-chan struct{}) outcome {
	<-start
	t0 := time.Now()

	if v, ok := idempotency.Load(key); ok {
		e := v.(*entry)
		if nowMs()-e.storedAt > dedupeWindowMs {
			// Fuera de la ventana: la clave caduco y esto es una operacion nueva.
			idempotency.Delete(key)
		}
	}

	mine := &entry{storedAt: nowMs()}
	mine.mu.Lock()

	// Una sola operacion: reserva si nadie la tiene y devuelve quien gano.
	actual, loaded := idempotency.LoadOrStore(key, mine)
	if !loaded {
		// El cargo y el efecto pendiente se escriben JUNTOS.
		balance := applyCharge(account, amount)
		enqueueOutbox(key, amount)
		mine.response = `{"status":"charged","key":"` + escape(key) + `","account":"` + escape(account) +
			`","amount_cents":` + strconv.FormatInt(amount, 10) +
			`,"balance_cents":` + strconv.FormatInt(balance, 10) + `}`
		mine.mu.Unlock()
		return outcome{applied: true, lookupMs: msSince(t0)}
	}
	mine.mu.Unlock()

	// Reintento: se espera a que el lider deje la respuesta y se devuelve tal
	// cual. Un reintento no debe recibir un error ni un cuerpo distinto.
	winner := actual.(*entry)
	winner.mu.Lock()
	winner.mu.Unlock()
	return outcome{hit: true, lookupMs: msSince(t0)}
}

func msSince(t0 time.Time) float64 {
	return float64(time.Since(t0).Microseconds()) / 1000.0
}

// ---------- orquestacion ----------

func runAttempts(variant, key, account string, amount int64, attempts int) map[string]any {
	// Largada comun: los reintentos de un cliente con timeout llegan casi
	// juntos, no en fila.
	start := make(chan struct{})
	results := make([]outcome, attempts)
	var wg sync.WaitGroup
	wg.Add(attempts)
	for i := 0; i < attempts; i++ {
		go func(idx int) {
			defer wg.Done()
			if variant == "unsafe" {
				results[idx] = attemptUnsafe(key, account, amount, start)
			} else {
				results[idx] = attemptIdempotent(key, account, amount, start)
			}
		}(i)
	}
	t0 := time.Now()
	close(start)
	wg.Wait()
	wallMs := msSince(t0)

	var applied, hits int64
	var lookups []float64
	for _, r := range results {
		if r.applied {
			applied++
		}
		if r.hit {
			hits++
		}
		if r.lookupMs > 0 {
			lookups = append(lookups, r.lookupMs)
		}
	}

	deliveredNow := 0
	if variant == "idempotent" {
		deliveredNow = drainOutbox()
	}

	ledgerMu.Lock()
	balance := ledger[account]
	ledgerMu.Unlock()

	boxMu.Lock()
	pending := 0
	for _, r := range outbox {
		if r.Status == "pending" {
			pending++
		}
	}
	deliveredTotal := len(delivered)
	boxMu.Unlock()

	overcharged := int64(0)
	if applied > 1 {
		overcharged = (applied - 1) * amount
	}
	effects := int64(deliveredNow)
	if variant == "unsafe" {
		effects = int64(attempts)
	}

	metricsMu.Lock()
	s := metrics[variant]
	s.Runs++
	s.Attempts += int64(attempts)
	s.ChargesApplied += applied
	s.DuplicatesPrevented += hits
	if applied > 1 {
		s.DuplicatesApplied += applied - 1
	}
	s.IdempotencyHits += hits
	s.SideEffects += effects
	s.Overcharged += overcharged
	metricsMu.Unlock()

	avgLookup := 0.0
	if len(lookups) > 0 {
		sum := 0.0
		for _, v := range lookups {
			sum += v
		}
		avgLookup = round3(sum / float64(len(lookups)))
	}

	note := "Sin clave de idempotencia: cada reintento aplica su propio cargo y publica su propio efecto. El cliente reintento por un timeout, no porque quisiera pagar de nuevo."
	transport := "directo, fuera de la transaccion"
	if variant != "unsafe" {
		note = "LoadOrStore resuelve la carrera en una sola operacion + outbox en la misma escritura que el cargo: un cobro, un efecto, y los reintentos reciben la respuesta guardada."
		transport = "outbox, en la misma escritura que el cargo"
	}

	dupApplied := int64(0)
	if applied > 1 {
		dupApplied = applied - 1
	}

	return map[string]any{
		"variant":                variant,
		"key":                    key,
		"account":                account,
		"attempts":               attempts,
		"amount_cents":           amount,
		"charges_applied":        applied,
		"duplicates_prevented":   hits,
		"duplicates_applied":     dupApplied,
		"idempotency_hits":       hits,
		"balance_cents":          balance,
		"overcharged_cents":      overcharged,
		"side_effects_emitted":   effects,
		"side_effect_transport":  transport,
		"outbox_pending":         pending,
		"outbox_delivered":       deliveredTotal,
		"lookup_overhead_ms":     avgLookup,
		"dedupe_window_ms":       dedupeWindowMs,
		"wall_ms":                round2(wallMs),
		"note":                   note,
	}
}

// ---------- rutas ----------

func idempotencyState() map[string]any {
	keys := map[string]any{}
	count := 0
	now := nowMs()
	idempotency.Range(func(k, v any) bool {
		e := v.(*entry)
		age := now - e.storedAt
		keys[k.(string)] = map[string]any{
			"age_ms":       age,
			"expired":      age > dedupeWindowMs,
			"has_response": e.response != "",
		}
		count++
		return true
	})
	ledgerMu.Lock()
	led := map[string]int64{}
	for k, v := range ledger {
		led[k] = v
	}
	ledgerMu.Unlock()
	return map[string]any{
		"keys":             keys,
		"key_count":        count,
		"ledger_cents":     led,
		"dedupe_window_ms": dedupeWindowMs,
		"note":             "La tabla de idempotencia necesita ventana y limpieza: una clave que vive para siempre es una tabla que crece para siempre.",
	}
}

func lastRows(list []outboxRow, limit int) []outboxRow {
	out := make([]outboxRow, 0, limit)
	for i := len(list) - 1; i >= 0 && len(out) < limit; i-- {
		out = append(out, list[i])
	}
	return out
}

func outboxView(limit int) map[string]any {
	boxMu.Lock()
	defer boxMu.Unlock()
	pending := 0
	for _, r := range outbox {
		if r.Status == "pending" {
			pending++
		}
	}
	return map[string]any{
		"outbox_pending":  pending,
		"outbox_total":    len(outbox),
		"delivered_total": len(delivered),
		"limit":           limit,
		"outbox":          lastRows(outbox, limit),
		"delivered":       lastRows(delivered, limit),
		"note":            "El outbox se escribe en la misma transaccion que el cargo. El worker que lo drena puede reintentar sin miedo: entregar dos veces el mismo row es visible y corregible, perder el efecto no.",
	}
}

func diagnostics() map[string]any {
	metricsMu.Lock()
	variants := map[string]any{}
	for name, s := range metrics {
		variants[name] = *s
	}
	metricsMu.Unlock()

	boxMu.Lock()
	pending := 0
	for _, r := range outbox {
		if r.Status == "pending" {
			pending++
		}
	}
	deliveredTotal := len(delivered)
	boxMu.Unlock()

	return map[string]any{
		"stack":            stack,
		"case":             caseName,
		"variants":         variants,
		"outbox_pending":   pending,
		"outbox_delivered": deliveredTotal,
		"interpretation": map[string]string{
			"unsafe":      "charges_applied = attempts: cada reintento cobro de nuevo. overcharged_cents es plata real que el negocio tiene que devolver.",
			"idempotent":  "charges_applied = 1 y duplicates_prevented = attempts - 1, sin importar cuantas veces reintente el cliente.",
			"go_note":     "sync.Map.LoadOrStore es la eleccion correcta aca porque las claves se escriben una vez y se leen muchas — el opuesto exacto del caso 13, donde cada entrada se creaba y se borraba en cada expiracion y un map bajo mutex era mejor.",
		},
	}
}

func route(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	key := queryOr(q.Get("key"), "order-4711")
	if len(key) > 60 {
		key = key[:60]
	}
	account := queryOr(q.Get("account"), "acct-1")
	if len(account) > 40 {
		account = account[:40]
	}
	attempts := clamp(atoiOr(q.Get("attempts"), 5), 1, 64)
	amount := int64(clamp(atoiOr(q.Get("amount"), 2500), 1, 10000000))
	limit := clamp(atoiOr(q.Get("limit"), 20), 1, 200)

	status := http.StatusOK
	var payload any

	switch r.URL.Path {
	case "/", "/index":
		payload = map[string]any{
			"case":  caseName,
			"stack": stack,
			"go_specific": "sync.Map.LoadOrStore: una operacion que reserva la clave y dice quien gano. Es el caso de uso documentado de sync.Map — escribir una vez, leer muchas.",
			"routes": []string{
				"/health",
				"/charge-unsafe?key=order-4711&attempts=5&amount=2500",
				"/charge-idempotent?key=order-4711&attempts=5&amount=2500",
				"/idempotency/state", "/outbox?limit=20", "/diagnostics/summary", "/reset-lab",
			},
		}
	case "/health":
		payload = map[string]string{"status": "ok", "stack": stack, "case": caseName}
	case "/charge-unsafe":
		payload = runAttempts("unsafe", key, account, amount, attempts)
	case "/charge-idempotent":
		payload = runAttempts("idempotent", key, account, amount, attempts)
	case "/idempotency/state":
		payload = idempotencyState()
	case "/outbox":
		payload = outboxView(limit)
	case "/diagnostics/summary":
		payload = diagnostics()
	case "/reset-lab":
		ledgerMu.Lock()
		ledger = map[string]int64{}
		ledgerMu.Unlock()
		idempotency.Range(func(k, _ any) bool { idempotency.Delete(k); return true })
		boxMu.Lock()
		outbox = nil
		delivered = nil
		boxMu.Unlock()
		metricsMu.Lock()
		metrics = freshMetrics()
		metricsMu.Unlock()
		payload = map[string]string{"status": "reset", "message": "Ledger, claves de idempotencia y outbox reiniciados."}
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
	log.Printf("[case16-go] listening on %s", port)
	if err := http.ListenAndServe(":"+port, mux); err != nil {
		log.Fatalf("listen: %v", err)
	}
}

// ---------- helpers ----------

func round2(v float64) float64 { return float64(int64(v*100+0.5)) / 100 }
func round3(v float64) float64 { return float64(int64(v*1000+0.5)) / 1000 }

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

func escape(v string) string {
	out := ""
	for _, r := range v {
		switch r {
		case '\\':
			out += `\\`
		case '"':
			out += `\"`
		default:
			out += string(r)
		}
	}
	return out
}
