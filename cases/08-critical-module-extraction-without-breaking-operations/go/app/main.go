// Caso 08 — Extraccion critica de modulo (cutover) — stack Go 1.23.
//
// Big-bang: el cambio de contrato rompe consumers sensibles (checkout, partners).
// Compatible: un proxy traduce el contrato viejo ↔ nuevo en vuelo, y un bus de
// eventos publica el avance del cutover.
//
// El contraste que este stack aporta:
//
//   Java modela el bus con `CopyOnWriteArrayList<Consumer<Event>>` y .NET con
//   un `event` del CLR. Go no tiene ninguna de las dos cosas — y no las
//   necesita, porque tiene canales.
//
//   Aca el bus es un `chan event` con una goroutine suscriptora leyendolo. Eso
//   cambia una propiedad real, no solo la sintaxis: la publicacion es
//   **asincrona y desacoplada**. En Java, `emit()` corre los subscribers en el
//   thread del request — un subscriber lento penaliza al consumer que disparo
//   el evento. Aca `emit()` empuja al canal y vuelve; el subscriber consume a su
//   ritmo en su propia goroutine.
//
//   El canal es bufferizado y el envio usa `select` con `default`: si el buffer
//   esta lleno, el evento se descarta en vez de bloquear el request. Es una
//   decision explicita —preferir perder telemetria antes que frenar trafico— y
//   es exactamente el tipo de backpressure que el caso 15 del roadmap estudia.
package main

import (
	"encoding/json"
	"log"
	"net/http"
	"os"
	"strconv"
	"sync"
	"sync/atomic"
	"time"
)

const (
	caseName  = "08 - Extraccion critica de modulo"
	maxEvents = 50
)

var stack = envOr("APP_STACK", "Go 1.23")

var (
	bigbangCalls        int64
	bigbangBroken       int64
	compatibleCalls     int64
	proxyHits           int64
	contractTestsPassed int64
)

// ---------- contratos ----------

// Contrato viejo: el consumer manda {sku, cost_usd}.
type priceRequestOld struct {
	SKU     string
	CostUSD float64
}

// Contrato nuevo: el modulo extraido espera {sku, price, currency}.
type priceRequestNew struct {
	SKU      string
	Price    float64
	Currency string
}

// compatProxy: el ACL de contrato. Una funcion, no una clase adapter.
func compatProxy(old priceRequestOld) priceRequestNew {
	return priceRequestNew{SKU: old.SKU, Price: old.CostUSD * 1.0, Currency: "USD"}
}

// ---------- bus de eventos por canal ----------

type busEvent struct {
	At    string `json:"at"`
	Event string `json:"event"`
}

var (
	cutoverBus = make(chan busEvent, 256)

	eventsMu     sync.Mutex
	recentEvents []busEvent

	progressMu      sync.Mutex
	cutoverProgress = map[string]bool{
		"checkout":   false,
		"partners":   false,
		"backoffice": false,
	}
)

// emit publica sin bloquear. Si el buffer esta lleno se descarta el evento:
// preferimos perder telemetria antes que frenar el request.
func emit(name string) {
	select {
	case cutoverBus <- busEvent{At: time.Now().UTC().Format(time.RFC3339Nano), Event: name}:
	default:
	}
}

// startBusConsumer arranca la goroutine suscriptora. Consume a su ritmo, en su
// propio hilo de ejecucion — no en el del request que disparo el evento.
func startBusConsumer() {
	go func() {
		for evt := range cutoverBus {
			eventsMu.Lock()
			recentEvents = append([]busEvent{evt}, recentEvents...)
			if len(recentEvents) > maxEvents {
				recentEvents = recentEvents[:maxEvents]
			}
			eventsMu.Unlock()
		}
	}()
}

// ---------- arranque ----------

func main() {
	startBusConsumer()

	mux := http.NewServeMux()
	mux.HandleFunc("/", route)

	port := envOr("PORT", "8080")
	log.Printf("[case08-go] listening on %s", port)
	if err := http.ListenAndServe(":"+port, mux); err != nil {
		log.Fatalf("listen: %v", err)
	}
}

// ---------- routing ----------

func route(w http.ResponseWriter, r *http.Request) {
	path := r.URL.Path
	q := r.URL.Query()
	consumer := queryOr(q.Get("consumer"), "checkout")
	sku := queryOr(q.Get("sku"), "ABC")
	costUSD := parseFloatOr(q.Get("cost_usd"), 100.0)

	status := http.StatusOK
	var payload any

	switch path {
	case "/", "/index":
		payload = map[string]any{
			"case":  caseName,
			"stack": stack,
			"routes": []string{
				"/health",
				"/pricing-bigbang?consumer=checkout&sku=ABC&cost_usd=100",
				"/pricing-compatible?consumer=checkout&sku=ABC&cost_usd=100",
				"/flows", "/diagnostics/summary", "/reset-lab",
			},
		}
	case "/health":
		payload = map[string]string{"status": "ok", "stack": stack, "case": caseName}
	case "/pricing-bigbang":
		payload = pricingBigbang(consumer, sku)
		atomic.AddInt64(&bigbangCalls, 1)
	case "/pricing-compatible":
		payload = pricingCompatible(consumer, sku, costUSD)
		atomic.AddInt64(&compatibleCalls, 1)
	case "/flows":
		payload = flows()
	case "/diagnostics/summary":
		payload = diagnostics()
	case "/reset-lab":
		atomic.StoreInt64(&bigbangCalls, 0)
		atomic.StoreInt64(&bigbangBroken, 0)
		atomic.StoreInt64(&compatibleCalls, 0)
		atomic.StoreInt64(&proxyHits, 0)
		atomic.StoreInt64(&contractTestsPassed, 0)
		eventsMu.Lock()
		recentEvents = nil
		eventsMu.Unlock()
		progressMu.Lock()
		for k := range cutoverProgress {
			cutoverProgress[k] = false
		}
		progressMu.Unlock()
		payload = map[string]string{"status": "reset"}
	default:
		status = http.StatusNotFound
		payload = map[string]string{"error": "not_found", "path": path}
	}

	sendJSON(w, status, payload)
}

// ---------- endpoints ----------

// pricingBigbang: el modulo nuevo solo entiende {price, currency}; el consumer
// manda {sku, cost_usd}. Sin capa de compatibilidad, es contract_violation.
func pricingBigbang(consumer, sku string) map[string]any {
	atomic.AddInt64(&bigbangBroken, 1)
	emit("bigbang_broke:" + consumer)
	return map[string]any{
		"variant": "bigbang", "consumer": consumer, "sku": sku,
		"status": "contract_violation",
		"reason": "new module expects {price, currency}; consumer sent {sku, cost_usd}",
		"note":   "cutover sin compat layer rompe consumers sensibles.",
	}
}

// pricingCompatible: el proxy traduce old→new; el consumer no se entera y el
// cutover avanza de a un consumer por vez.
func pricingCompatible(consumer, sku string, costUSD float64) map[string]any {
	translated := compatProxy(priceRequestOld{SKU: sku, CostUSD: costUSD})
	atomic.AddInt64(&proxyHits, 1)
	atomic.AddInt64(&contractTestsPassed, 1)

	progressMu.Lock()
	done, tracked := cutoverProgress[consumer]
	if tracked && !done {
		cutoverProgress[consumer] = true
		done = true
		progressMu.Unlock()
		emit("cutover_done:" + consumer)
	} else {
		progressMu.Unlock()
	}

	return map[string]any{
		"variant": "compatible", "consumer": consumer,
		"sku":                      translated.SKU,
		"price":                    translated.Price,
		"currency":                 translated.Currency,
		"compatibility_proxy_hit":  true,
		"cutover_done":             done,
		"note":                     "proxy traduce {cost_usd}→{price,currency}; consumer no rompe.",
	}
}

func flows() map[string]any {
	progressMu.Lock()
	progress := make(map[string]bool, len(cutoverProgress))
	for k, v := range cutoverProgress {
		progress[k] = v
	}
	progressMu.Unlock()

	eventsMu.Lock()
	evts := append([]busEvent{}, recentEvents...)
	eventsMu.Unlock()

	return map[string]any{"cutover_progress": progress, "recent_events": evts}
}

func diagnostics() map[string]any {
	return map[string]any{
		"stack": stack,
		"case":  caseName,
		"bigbang": map[string]any{
			"calls":           atomic.LoadInt64(&bigbangCalls),
			"broken_consumers": atomic.LoadInt64(&bigbangBroken),
			"behavior":        "cambio de contrato sin compat layer",
		},
		"compatible": map[string]any{
			"calls":                 atomic.LoadInt64(&compatibleCalls),
			"proxy_hits":            atomic.LoadInt64(&proxyHits),
			"contract_tests_passed": atomic.LoadInt64(&contractTestsPassed),
			"behavior":              "proxy de compatibilidad + bus de eventos por canal",
		},
		"flows": flows(),
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

func parseFloatOr(raw string, fallback float64) float64 {
	v, err := strconv.ParseFloat(raw, 64)
	if err != nil {
		return fallback
	}
	return v
}
