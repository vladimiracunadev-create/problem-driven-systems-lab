// Caso 07 — Modernizacion incremental de monolito (strangler) — stack Go 1.23.
//
// Legacy: el cambio toca el shared_schema acoplado, blast radius alto.
// Strangler: una tabla de routing por consumer decide si la operacion va al
// modulo nuevo o cae al monolito con ACL.
//
// El contraste que este stack aporta:
//
//   La tabla de routing es `map[string]handlerFunc`, donde handlerFunc es un
//   tipo funcion de primera clase. Java necesita `Function<Request,Response>` y
//   .NET `Func<Request,Response>`; en Go la firma ES el tipo, sin envoltorio
//   generico:
//
//       type handlerFunc func(request) response
//
//   Eso importa en un strangler porque el ACL —la capa que traduce el contrato
//   viejo al nuevo— es literalmente una funcion que envuelve a otra. En Go se
//   escribe sin ceremonia y el compilador verifica el contrato en el punto de
//   registro, no cuando llega el primer request.
//
//   La tabla se protege con RWMutex, no con sync.Map: las lecturas son
//   masivamente mas frecuentes que los registros, y RWMutex deja que todos los
//   lectores entren en paralelo mientras no haya una migracion registrandose.
package main

import (
	"encoding/json"
	"log"
	"net/http"
	"os"
	"sync"
	"sync/atomic"
)

const caseName = "07 - Modernizacion incremental de monolito"

var stack = envOr("APP_STACK", "Go 1.23")

var (
	legacyCalls       int64
	stranglerCalls    int64
	routedToNewModule int64
)

// ---------- tipos ----------

type request struct {
	Consumer string
	Op       string
	Payload  map[string]string
}

type response struct {
	Result           string
	RoutedTo         string
	BlastRadiusScore int
	RiskScore        int
}

// handlerFunc: la firma es el tipo. Sin Function<,> ni Func<,>.
type handlerFunc func(request) response

// ---------- tabla de routing ----------

// RWMutex y no sync.Map: se lee en cada request y se escribe solo al registrar
// una migracion. Los lectores no se estorban entre si.
var (
	routingMu    sync.RWMutex
	routingTable = map[string]handlerFunc{}

	migrationProgress = map[string]int{
		"billing":   100,
		"orders":    0,
		"inventory": 0,
		"reporting": 0,
	}
)

func init() {
	// Routing inicial: billing ya migrado al modulo nuevo; el resto sigue en el
	// monolito. Registrar una migracion nueva es esta unica linea.
	routingTable["billing:change"] = func(req request) response {
		return response{Result: "ok-new-module", RoutedTo: "new-billing-svc", BlastRadiusScore: 1, RiskScore: 1}
	}
}

func lookupHandler(key string) (handlerFunc, bool) {
	routingMu.RLock()
	defer routingMu.RUnlock()
	h, ok := routingTable[key]
	return h, ok
}

func routingTableSize() int {
	routingMu.RLock()
	defer routingMu.RUnlock()
	return len(routingTable)
}

// ---------- arranque ----------

func main() {
	mux := http.NewServeMux()
	mux.HandleFunc("/", route)

	port := envOr("PORT", "8080")
	log.Printf("[case07-go] listening on %s", port)
	if err := http.ListenAndServe(":"+port, mux); err != nil {
		log.Fatalf("listen: %v", err)
	}
}

// ---------- routing HTTP ----------

func route(w http.ResponseWriter, r *http.Request) {
	path := r.URL.Path
	q := r.URL.Query()
	consumer := queryOr(q.Get("consumer"), "billing")
	op := queryOr(q.Get("op"), "change")

	status := http.StatusOK
	var payload any

	switch path {
	case "/", "/index":
		payload = map[string]any{
			"case":  caseName,
			"stack": stack,
			"routes": []string{
				"/health", "/change-legacy?consumer=billing&op=change",
				"/change-strangler?consumer=billing&op=change",
				"/flows", "/diagnostics/summary", "/reset-lab",
			},
		}
	case "/health":
		payload = map[string]string{"status": "ok", "stack": stack, "case": caseName}
	case "/change-legacy":
		payload = changeLegacy(consumer, op)
		atomic.AddInt64(&legacyCalls, 1)
	case "/change-strangler":
		payload = changeStrangler(consumer, op)
		atomic.AddInt64(&stranglerCalls, 1)
	case "/flows":
		payload = flows()
	case "/diagnostics/summary":
		payload = diagnostics()
	case "/reset-lab":
		atomic.StoreInt64(&legacyCalls, 0)
		atomic.StoreInt64(&stranglerCalls, 0)
		atomic.StoreInt64(&routedToNewModule, 0)
		payload = map[string]string{"status": "reset"}
	default:
		status = http.StatusNotFound
		payload = map[string]string{"error": "not_found", "path": path}
	}

	sendJSON(w, status, payload)
}

// ---------- endpoints ----------

// changeLegacy: todos los consumers pegan al mismo monolito. Un cambio en el
// shared_schema propaga a los 4 modulos.
func changeLegacy(consumer, op string) map[string]any {
	return map[string]any{
		"variant": "legacy", "consumer": consumer, "op": op,
		"routed_to":          "shared-monolith",
		"blast_radius_score": 4,
		"risk_score":         8,
		"note":               "cambio en shared_schema afecta los 4 modulos del monolito.",
	}
}

// changeStrangler: consulta la tabla de routing. Si hay handler nuevo, el
// monolito queda intocado; si no, cae al legacy pero acotado por ACL.
func changeStrangler(consumer, op string) map[string]any {
	key := consumer + ":" + op
	if handler, ok := lookupHandler(key); ok {
		r := handler(request{Consumer: consumer, Op: op, Payload: map[string]string{}})
		atomic.AddInt64(&routedToNewModule, 1)
		return map[string]any{
			"variant": "strangler", "consumer": consumer, "op": op,
			"routed_to":          r.RoutedTo,
			"blast_radius_score": r.BlastRadiusScore,
			"risk_score":         r.RiskScore,
			"note":               "routing table apunta a nuevo modulo; monolito intocado.",
		}
	}
	return map[string]any{
		"variant": "strangler", "consumer": consumer, "op": op,
		"routed_to":          "legacy-monolith",
		"blast_radius_score": 2,
		"risk_score":         4,
		"note":               "consumer aun no migrado; routing cae al legacy pero con ACL.",
	}
}

func flows() map[string]any {
	progress := make(map[string]int, len(migrationProgress))
	for k, v := range migrationProgress {
		progress[k] = v
	}
	return map[string]any{
		"migration_progress":  progress,
		"routing_table_size":  routingTableSize(),
	}
}

func diagnostics() map[string]any {
	return map[string]any{
		"stack": stack,
		"case":  caseName,
		"legacy": map[string]any{
			"calls":             atomic.LoadInt64(&legacyCalls),
			"avg_blast_radius":  4,
			"avg_risk":          8,
		},
		"strangler": map[string]any{
			"calls":                 atomic.LoadInt64(&stranglerCalls),
			"routed_to_new_module":  atomic.LoadInt64(&routedToNewModule),
			"routing_table_size":    routingTableSize(),
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
