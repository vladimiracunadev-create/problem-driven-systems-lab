// Caso 12 — Punto unico de conocimiento y riesgo operacional — stack Go 1.23.
//
// Legacy: el incidente con owner ausente hace acceso ciego a estructuras
// anidadas y revienta.
// Distributed: ausencia explicita en la firma + fallback codificado = runbook
// que no depende de una persona. /share-knowledge sube coverage y bus factor.
//
// El contraste que este stack aporta:
//
//   Java resuelve esto con `Optional<T>` y encadenamiento `map/flatMap/orElse`.
//   Node con optional chaining `?.`. Go no tiene ninguna de las dos — y esa
//   ausencia es justamente lo interesante.
//
//   Go codifica "puede no haber valor" en el TIPO DE RETORNO, con el idioma
//   comma-ok: `script, ok := owner.Runbook[key]`. No hay forma de usar `script`
//   sin haber recibido tambien `ok`; el chequeo esta en el sitio de uso, no
//   escondido detras de un metodo encadenado. Un `Optional` mal usado
//   (`.get()` sin `isPresent()`) compila; aca la ausencia es parte de la
//   asignacion.
//
//   Lo que Go SI comparte con los otros stacks es el modo de falla real: un
//   puntero nil desreferenciado hace panic. La variante legacy lo demuestra, y
//   `recover()` en un defer es lo que impide que un incidente tumbe el proceso
//   entero — el equivalente Go del catch de ultimo recurso.
package main

import (
	"encoding/json"
	"fmt"
	"log"
	"math/rand"
	"net/http"
	"os"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

const (
	caseName     = "12 - Punto unico de conocimiento"
	maxIncidents = 30
)

var stack = envOr("APP_STACK", "Go 1.23")

var (
	legacyIncidents      int64
	legacyCrashed        int64
	distributedIncidents int64
	distributedHandled   int64

	coverage  int64 = 30
	busFactor int64 = 1
)

// ---------- tipos ----------

type owner struct {
	Name      string
	Available bool
	Runbook   map[string]string
}

type incident struct {
	At       string `json:"at"`
	Variant  string `json:"variant"`
	Scenario string `json:"scenario"`
	Result   string `json:"result"`
	MTTRMin  int64  `json:"mttr_min"`
}

var (
	stateMu   sync.Mutex
	owners    map[string]*owner
	incidents []incident

	rngMu sync.Mutex
	rng   = rand.New(rand.NewSource(120512))
)

func resetState() {
	stateMu.Lock()
	defer stateMu.Unlock()
	owners = map[string]*owner{
		"alice": {
			Name:      "alice",
			Available: true,
			Runbook: map[string]string{
				"db_failover": "step1; step2; step3",
				"cache_purge": "redis-cli flushall on prod-cache-01",
			},
		},
	}
	incidents = nil
	atomic.StoreInt64(&coverage, 30)
	atomic.StoreInt64(&busFactor, 1)
}

func record(variant, scenario, result string, mttr int64) {
	stateMu.Lock()
	defer stateMu.Unlock()
	incidents = append([]incident{{
		At:       time.Now().UTC().Format(time.RFC3339Nano),
		Variant:  variant,
		Scenario: scenario,
		Result:   result,
		MTTRMin:  mttr,
	}}, incidents...)
	if len(incidents) > maxIncidents {
		incidents = incidents[:maxIncidents]
	}
}

func randBetween(base, spread int64) int64 {
	rngMu.Lock()
	defer rngMu.Unlock()
	return base + rng.Int63n(spread)
}

// ---------- arranque ----------

func main() {
	resetState()

	mux := http.NewServeMux()
	mux.HandleFunc("/", route)

	port := envOr("PORT", "8080")
	log.Printf("[case12-go] listening on %s", port)
	if err := http.ListenAndServe(":"+port, mux); err != nil {
		log.Fatalf("listen: %v", err)
	}
}

// ---------- routing ----------

func route(w http.ResponseWriter, r *http.Request) {
	path := r.URL.Path
	q := r.URL.Query()
	scenario := queryOr(q.Get("scenario"), "owner_absent")
	runbookKey := queryOr(q.Get("runbook"), "db_failover")
	ownerName := queryOr(q.Get("owner"), "bob")

	status := http.StatusOK
	var payload any

	switch path {
	case "/", "/index":
		payload = map[string]any{
			"case":  caseName,
			"stack": stack,
			"routes": []string{
				"/health",
				"/incident-legacy?scenario=owner_absent&runbook=db_failover",
				"/incident-distributed?scenario=owner_absent&runbook=db_failover",
				"/share-knowledge?owner=bob&runbook=db_failover",
				"/incidents", "/diagnostics/summary", "/reset-lab",
			},
		}
	case "/health":
		payload = map[string]string{"status": "ok", "stack": stack, "case": caseName}
	case "/incident-legacy":
		payload = incidentLegacy(scenario, runbookKey)
		atomic.AddInt64(&legacyIncidents, 1)
	case "/incident-distributed":
		payload = incidentDistributed(scenario, runbookKey)
		atomic.AddInt64(&distributedIncidents, 1)
	case "/share-knowledge":
		payload = shareKnowledge(ownerName, runbookKey)
	case "/incidents":
		payload = incidentsJSON()
	case "/diagnostics/summary":
		payload = diagnostics()
	case "/reset-lab":
		atomic.StoreInt64(&legacyIncidents, 0)
		atomic.StoreInt64(&legacyCrashed, 0)
		atomic.StoreInt64(&distributedIncidents, 0)
		atomic.StoreInt64(&distributedHandled, 0)
		resetState()
		payload = map[string]string{"status": "reset"}
	default:
		status = http.StatusNotFound
		payload = map[string]string{"error": "not_found", "path": path}
	}

	sendJSON(w, status, payload)
}

// ---------- endpoints ----------

// pickOwnerLegacy devuelve *owner y puede ser nil. La firma NO obliga a nadie a
// comprobarlo — ese es el bug que la variante legacy demuestra.
func pickOwnerLegacy(scenario string) *owner {
	if scenario == "owner_absent" || scenario == "night_shift" {
		return nil
	}
	stateMu.Lock()
	defer stateMu.Unlock()
	return owners["alice"]
}

// incidentLegacy: acceso ciego. Con owner nil, `o.Runbook` hace panic. El
// recover() en el defer es lo unico que impide que el incidente tumbe el
// proceso — el equivalente Go del catch de ultimo recurso.
func incidentLegacy(scenario, runbookKey string) (result map[string]any) {
	defer func() {
		if rec := recover(); rec != nil {
			atomic.AddInt64(&legacyCrashed, 1)
			record("legacy", scenario, fmt.Sprintf("crashed: %v", rec), 120)
			result = map[string]any{
				"variant": "legacy", "status": "crashed", "scenario": scenario,
				"reason":   fmt.Sprintf("panic: %v", rec),
				"mttr_min": 120,
				"note":     "acceso ciego a estructuras anidadas falla cuando owner/runbook ausente.",
			}
		}
	}()

	o := pickOwnerLegacy(scenario)
	script := o.Runbook[runbookKey] // panic si o == nil
	if script == "" {
		panic("runbook missing")
	}
	executed := strings.ToUpper(script)
	mttr := randBetween(25, 30)
	record("legacy", scenario, "executed", mttr)

	preview := executed
	if len(preview) > 40 {
		preview = preview[:40]
	}
	return map[string]any{
		"variant": "legacy", "status": "executed", "scenario": scenario,
		"runbook": runbookKey, "mttr_min": mttr,
		"executed_preview": preview + "...",
	}
}

// pickOwnerDistributed devuelve (owner, ok). La ausencia esta en la firma: el
// llamador no puede usar el valor sin recibir tambien el booleano.
func pickOwnerDistributed(scenario string) (*owner, bool) {
	stateMu.Lock()
	defer stateMu.Unlock()
	if scenario == "owner_absent" || scenario == "night_shift" {
		for name, o := range owners {
			if name != "alice" && o.Available {
				return o, true
			}
		}
		return nil, false
	}
	o, ok := owners["alice"]
	return o, ok
}

// incidentDistributed: comma-ok en cada nivel. No hay acceso posible sin
// chequeo, asi que no hay panic que recuperar.
func incidentDistributed(scenario, runbookKey string) map[string]any {
	o, hasOwner := pickOwnerDistributed(scenario)

	script := ""
	if hasOwner {
		if s, ok := o.Runbook[runbookKey]; ok {
			script = s
		}
	}

	var mttr int64
	var result string
	if script == "" {
		// Degradacion controlada: runbook compartido por el equipo.
		mttr = randBetween(35, 15)
		result = "owner_absent_handled_via_team_runbook"
	} else {
		mttr = randBetween(15, 10)
		result = "executed_by_primary"
	}
	atomic.AddInt64(&distributedHandled, 1)
	record("distributed", scenario, result, mttr)

	primaryAvailable := false
	if hasOwner {
		primaryAvailable = o.Available
	}

	return map[string]any{
		"variant": "distributed", "status": "handled", "scenario": scenario,
		"runbook": runbookKey, "result": result,
		"primary_available": primaryAvailable,
		"mttr_min":          mttr,
		"coverage":          atomic.LoadInt64(&coverage),
		"bus_factor":        atomic.LoadInt64(&busFactor),
		"note":              "comma-ok en cada nivel: la ausencia esta en la firma, no en un Optional.",
	}
}

// shareKnowledge: copia el runbook del primario a un owner alterno.
func shareKnowledge(name, runbookKey string) map[string]any {
	stateMu.Lock()
	alice, ok := owners["alice"]
	if !ok {
		stateMu.Unlock()
		return map[string]any{"status": "error", "reason": "primary owner missing"}
	}
	copied := make(map[string]string, len(alice.Runbook))
	for k, v := range alice.Runbook {
		copied[k] = v
	}
	owners[name] = &owner{Name: name, Available: true, Runbook: copied}
	stateMu.Unlock()

	newCoverage := atomic.LoadInt64(&coverage) + 15
	if newCoverage > 100 {
		newCoverage = 100
	}
	atomic.StoreInt64(&coverage, newCoverage)
	atomic.AddInt64(&busFactor, 1)

	return map[string]any{
		"status": "shared", "new_owner": name, "runbook": runbookKey,
		"coverage":   newCoverage,
		"bus_factor": atomic.LoadInt64(&busFactor),
		"note":       "el conocimiento dejo de vivir solo en alice; bus factor sube.",
	}
}

func incidentsJSON() map[string]any {
	stateMu.Lock()
	defer stateMu.Unlock()
	history := append([]incident{}, incidents...)
	return map[string]any{"incidents": history, "max_kept": maxIncidents}
}

func diagnostics() map[string]any {
	return map[string]any{
		"stack": stack,
		"case":  caseName,
		"legacy": map[string]any{
			"incidents": atomic.LoadInt64(&legacyIncidents),
			"crashed":   atomic.LoadInt64(&legacyCrashed),
			"behavior":  "acceso ciego a punteros; panic recuperado con recover()",
		},
		"distributed": map[string]any{
			"incidents": atomic.LoadInt64(&distributedIncidents),
			"handled":   atomic.LoadInt64(&distributedHandled),
			"behavior":  "comma-ok + fallback codificado; sin panic posible",
		},
		"knowledge": map[string]any{
			"coverage":   atomic.LoadInt64(&coverage),
			"bus_factor": atomic.LoadInt64(&busFactor),
		},
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
