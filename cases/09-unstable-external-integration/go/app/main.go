// Caso 09 — Integracion externa inestable — stack Go 1.23.
//
// Legacy: cada request pega al provider sin cache, sin budget, sin breaker.
// Hardened: budget de cuota + snapshot cache + breaker + mapping defensivo.
//
// El contraste que este stack aporta:
//
//   Java usa `Semaphore(5)` para el budget de cuota. Go no tiene semaforo en la
//   stdlib — y no le hace falta, porque **un canal bufferizado ES un semaforo**:
//
//       budget := make(chan struct{}, 5)   // 5 permisos
//       select {
//       case <-budget:  // adquirir: consume un permiso
//       default:        // sin permisos → no bloquear, degradar a cache
//       }
//
//   `chan struct{}` no ocupa memoria por elemento (struct{} tiene tamaño cero):
//   el canal es puro conteo. Y el `select` con `default` da el `tryAcquire()`
//   no bloqueante sin ninguna API extra — la misma primitiva que ya se usa para
//   timeouts y para fan-in sirve aca sin aprender otra abstraccion.
//
//   Ese es el argumento de fondo de la concurrencia en Go: una primitiva
//   (canal + select) cubre semaforo, cola, timeout, cancelacion y pipeline. En
//   Java cada uno de esos es una clase distinta del paquete concurrent.
package main

import (
	"encoding/json"
	"log"
	"net/http"
	"os"
	"sync"
	"sync/atomic"
	"time"
)

const (
	caseName         = "09 - Integracion externa inestable"
	budgetPerWindow  = 5
)

var stack = envOr("APP_STACK", "Go 1.23")

var (
	legacyCalls          int64
	legacyFailures       int64
	hardenedCalls        int64
	hardenedFromCache    int64
	hardenedBudgetDenied int64
)

// providerBudget: canal bufferizado usado como semaforo de cuota.
var providerBudget = make(chan struct{}, budgetPerWindow)

// breaker con proteccion atomica simple (solo transiciones de string).
var breakerState atomic.Value // string

// snapshotCache: leida cuando el provider no esta disponible.
var (
	cacheMu       sync.RWMutex
	snapshotCache = map[string]map[string]any{
		"widget-A": {"name": "Widget A", "price": 42.0, "snapshot_at": "2026-05-01T00:00:00Z"},
		"widget-B": {"name": "Widget B", "price": 13.5, "snapshot_at": "2026-05-01T00:00:00Z"},
	}
)

func fillBudget() {
	// Vaciar y volver a llenar hasta el maximo.
	for {
		select {
		case <-providerBudget:
		default:
			for i := 0; i < budgetPerWindow; i++ {
				providerBudget <- struct{}{}
			}
			return
		}
	}
}

// tryAcquireBudget: el tryAcquire() no bloqueante, con select+default.
func tryAcquireBudget() bool {
	select {
	case <-providerBudget:
		return true
	default:
		return false
	}
}

func budgetRemaining() int { return len(providerBudget) }

func breaker() string {
	if v, ok := breakerState.Load().(string); ok {
		return v
	}
	return "closed"
}

// ---------- arranque ----------

func main() {
	breakerState.Store("closed")
	fillBudget()

	mux := http.NewServeMux()
	mux.HandleFunc("/", route)

	port := envOr("PORT", "8080")
	log.Printf("[case09-go] listening on %s", port)
	if err := http.ListenAndServe(":"+port, mux); err != nil {
		log.Fatalf("listen: %v", err)
	}
}

// ---------- routing ----------

func route(w http.ResponseWriter, r *http.Request) {
	path := r.URL.Path
	q := r.URL.Query()
	sku := queryOr(q.Get("sku"), "widget-A")
	scenario := queryOr(q.Get("scenario"), "ok")

	status := http.StatusOK
	var payload any

	switch path {
	case "/", "/index":
		payload = map[string]any{
			"case":  caseName,
			"stack": stack,
			"routes": []string{
				"/health", "/catalog-legacy?sku=widget-A&scenario=drift",
				"/catalog-hardened?sku=widget-A&scenario=drift",
				"/sync-events", "/diagnostics/summary", "/reset-lab",
			},
		}
	case "/health":
		payload = map[string]string{"status": "ok", "stack": stack, "case": caseName}
	case "/catalog-legacy":
		payload = catalogLegacy(sku, scenario)
		atomic.AddInt64(&legacyCalls, 1)
	case "/catalog-hardened":
		payload = catalogHardened(sku, scenario)
		atomic.AddInt64(&hardenedCalls, 1)
	case "/sync-events":
		payload = state()
	case "/diagnostics/summary":
		payload = diagnostics()
	case "/reset-lab":
		atomic.StoreInt64(&legacyCalls, 0)
		atomic.StoreInt64(&legacyFailures, 0)
		atomic.StoreInt64(&hardenedCalls, 0)
		atomic.StoreInt64(&hardenedFromCache, 0)
		atomic.StoreInt64(&hardenedBudgetDenied, 0)
		fillBudget()
		breakerState.Store("closed")
		payload = map[string]string{"status": "reset"}
	default:
		status = http.StatusNotFound
		payload = map[string]string{"error": "not_found", "path": path}
	}

	sendJSON(w, status, payload)
}

// ---------- endpoints ----------

func isDrift(scenario string) bool {
	return scenario == "drift" || scenario == "rate_limit" || scenario == "maintenance"
}

// catalogLegacy: cada request golpea al provider. Sin cache, un drift de
// esquema o un rate limit se traduce directo en fallo al usuario.
func catalogLegacy(sku, scenario string) map[string]any {
	if isDrift(scenario) {
		atomic.AddInt64(&legacyFailures, 1)
		return map[string]any{
			"variant": "legacy", "sku": sku, "status": "failed", "scenario": scenario,
			"note": "provider devuelve drift de esquema / rate limit / maintenance; sin cache, falla.",
		}
	}
	return map[string]any{
		"variant": "legacy", "sku": sku, "status": "ok",
		"data": map[string]any{"name": sku + " Live", "price": 42.0},
		"note": "hit directo al provider, sin budget ni cache.",
	}
}

// catalogHardened: primero el budget (canal como semaforo), despues el provider;
// si algo falla, snapshot cacheado en vez de error al usuario.
func catalogHardened(sku, scenario string) map[string]any {
	if !tryAcquireBudget() {
		atomic.AddInt64(&hardenedBudgetDenied, 1)
		return fromSnapshot(sku, "budget_exhausted", "budget de cuota agotado; sirviendo snapshot cacheado.")
	}
	// El permiso NO se devuelve: cuenta como uso de la ventana. Reset via /reset-lab.

	if isDrift(scenario) {
		breakerState.Store("open")
		return fromSnapshot(sku, "provider_failing", "provider con drift/rate_limit/maintenance; snapshot cacheado.")
	}

	fresh := map[string]any{
		"name":        sku + " Live",
		"price":       42.0,
		"snapshot_at": time.Now().UTC().Format(time.RFC3339Nano),
	}
	cacheMu.Lock()
	snapshotCache[sku] = fresh
	cacheMu.Unlock()
	breakerState.Store("closed")

	return map[string]any{
		"variant": "hardened", "sku": sku, "status": "ok",
		"data": fresh, "served_from": "provider",
		"budget_remaining": budgetRemaining(),
		"breaker":          breaker(),
	}
}

func fromSnapshot(sku, reason, note string) map[string]any {
	atomic.AddInt64(&hardenedFromCache, 1)
	cacheMu.RLock()
	cached := snapshotCache[sku]
	cacheMu.RUnlock()
	return map[string]any{
		"variant": "hardened", "sku": sku, "status": "served_from_cache",
		"reason": reason, "data": cached, "served_from": "snapshot_cache",
		"budget_remaining": budgetRemaining(),
		"breaker":          breaker(),
		"note":             note,
	}
}

func state() map[string]any {
	cacheMu.RLock()
	size := len(snapshotCache)
	cacheMu.RUnlock()
	return map[string]any{
		"breaker":             breaker(),
		"budget_remaining":    budgetRemaining(),
		"budget_max":          budgetPerWindow,
		"snapshot_cache_size": size,
	}
}

func diagnostics() map[string]any {
	return map[string]any{
		"stack": stack,
		"case":  caseName,
		"legacy": map[string]any{
			"calls":    atomic.LoadInt64(&legacyCalls),
			"failures": atomic.LoadInt64(&legacyFailures),
		},
		"hardened": map[string]any{
			"calls":             atomic.LoadInt64(&hardenedCalls),
			"served_from_cache": atomic.LoadInt64(&hardenedFromCache),
			"budget_denied":     atomic.LoadInt64(&hardenedBudgetDenied),
		},
		"state": state(),
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
