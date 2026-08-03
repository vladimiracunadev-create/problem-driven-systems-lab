// Caso 06 — Pipeline roto y entrega fragil (stack Go 1.23).
//
// Legacy: deploy directo sin preflight, sin smoke, sin rollback.
// Controlled: preflight → deploy → smoke → promote | rollback.
//
// El contraste que este stack aporta:
//
//   Los otros stacks modelan el estado del ambiente con un mapa concurrente
//   (ConcurrentHashMap en Java, ConcurrentDictionary en .NET) y confian en que
//   la estructura serialice los accesos. Go ofrece la misma opcion con
//   sync.Map, pero el idioma preferido —y el que se usa aca— es distinto:
//   **un mutex que protege una estructura explicita**.
//
//   La razon es que la seccion critica de este caso no es "leer o escribir una
//   clave", es "leer la version actual, decidir si promover o hacer rollback, y
//   escribir el resultado". Eso es una transaccion logica: un mapa concurrente
//   la haria segura por operacion y aun asi incorrecta en conjunto, porque otro
//   deploy puede colarse entre el read y el write. El mutex hace visible que el
//   invariante es la secuencia completa, no cada acceso.
//
//   Es el mismo argumento que sostiene el proverbio de Go: "no comuniques
//   compartiendo memoria; comparte memoria comunicandote" — y cuando compartis
//   memoria, hacelo con el lock a la vista.
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
	caseName       = "06 - Pipeline roto y delivery fragil"
	maxDeployments = 30
)

var stack = envOr("APP_STACK", "Go 1.23")

var (
	legacyDeploys       int64
	legacyBroken        int64
	controlledDeploys   int64
	controlledRollbacks int64
	controlledBlocked   int64
)

// ---------- estado ----------

type envState struct {
	Name    string `json:"name"`
	Version string `json:"version"`
	Health  string `json:"health"`
}

type deployment struct {
	At       string `json:"at"`
	Variant  string `json:"variant"`
	Env      string `json:"env"`
	Version  string `json:"version"`
	Scenario string `json:"scenario"`
	Result   string `json:"result"`
}

// El mutex protege la transaccion completa (leer version → decidir → escribir),
// no cada acceso individual.
var (
	stateMu     sync.Mutex
	environs    map[string]envState
	deployments []deployment
)

func resetState() {
	stateMu.Lock()
	defer stateMu.Unlock()
	environs = map[string]envState{
		"staging": {Name: "staging", Version: "v1.0.0", Health: "healthy"},
		"prod":    {Name: "prod", Version: "v1.0.0", Health: "healthy"},
	}
	deployments = nil
}

// recordLocked asume que el llamador ya tiene stateMu.
func recordLocked(variant, env, version, scenario, result string) {
	deployments = append([]deployment{{
		At:       time.Now().UTC().Format(time.RFC3339Nano),
		Variant:  variant,
		Env:      env,
		Version:  version,
		Scenario: scenario,
		Result:   result,
	}}, deployments...)
	if len(deployments) > maxDeployments {
		deployments = deployments[:maxDeployments]
	}
}

// ---------- arranque ----------

func main() {
	resetState()

	mux := http.NewServeMux()
	mux.HandleFunc("/", route)

	port := envOr("PORT", "8080")
	log.Printf("[case06-go] listening on %s", port)
	if err := http.ListenAndServe(":"+port, mux); err != nil {
		log.Fatalf("listen: %v", err)
	}
}

// ---------- routing ----------

func route(w http.ResponseWriter, r *http.Request) {
	path := r.URL.Path
	q := r.URL.Query()
	env := queryOr(q.Get("env"), "prod")
	version := queryOr(q.Get("version"), "v1.1.0")
	scenario := queryOr(q.Get("scenario"), "clean")

	status := http.StatusOK
	var payload any

	switch path {
	case "/", "/index":
		payload = map[string]any{
			"case":  caseName,
			"stack": stack,
			"routes": []string{
				"/health",
				"/deploy-legacy?env=prod&version=v1.1.0&scenario=secret_drift",
				"/deploy-controlled?env=prod&version=v1.1.0&scenario=secret_drift",
				"/environments", "/deployments", "/diagnostics/summary", "/reset-lab",
			},
		}
	case "/health":
		payload = map[string]string{"status": "ok", "stack": stack, "case": caseName}
	case "/deploy-legacy":
		payload = deployLegacy(env, version, scenario)
	case "/deploy-controlled":
		payload = deployControlled(env, version, scenario)
	case "/environments":
		payload = environmentsJSON()
	case "/deployments":
		payload = deploymentsJSON()
	case "/diagnostics/summary":
		payload = diagnostics()
	case "/reset-lab":
		resetState()
		atomic.StoreInt64(&legacyDeploys, 0)
		atomic.StoreInt64(&legacyBroken, 0)
		atomic.StoreInt64(&controlledDeploys, 0)
		atomic.StoreInt64(&controlledRollbacks, 0)
		atomic.StoreInt64(&controlledBlocked, 0)
		payload = map[string]string{"status": "reset"}
	default:
		status = http.StatusNotFound
		payload = map[string]string{"error": "not_found", "path": path}
	}

	sendJSON(w, status, payload)
}

// ---------- endpoints ----------

// deployLegacy: aplica la version sin preflight y deja el ambiente como quede.
func deployLegacy(env, version, scenario string) map[string]any {
	atomic.AddInt64(&legacyDeploys, 1)

	health, result := "healthy", "deployed"
	if isBadScenario(scenario) {
		health, result = "degraded", "deployed_but_broken"
		atomic.AddInt64(&legacyBroken, 1)
	}

	stateMu.Lock()
	environs[env] = envState{Name: env, Version: version, Health: health}
	recordLocked("legacy", env, version, scenario, result)
	stateMu.Unlock()

	return map[string]any{
		"variant": "legacy", "env": env, "version": version, "scenario": scenario,
		"result": result, "health": health,
		"note":   "sin preflight ni rollback; ambiente queda como quede.",
	}
}

// deployControlled: preflight → smoke → promote, o rollback si el smoke falla.
// Toda la secuencia corre bajo el mismo lock: leer la version actual, decidir y
// escribir es una sola transaccion logica.
func deployControlled(env, version, scenario string) map[string]any {
	atomic.AddInt64(&controlledDeploys, 1)

	stateMu.Lock()
	defer stateMu.Unlock()

	before := environs[env]

	// Preflight: bloquea antes de tocar el ambiente.
	if scenario == "missing_artifact" || scenario == "secret_drift_detected" {
		atomic.AddInt64(&controlledBlocked, 1)
		recordLocked("controlled", env, version, scenario, "blocked_in_preflight")
		return map[string]any{
			"variant": "controlled", "env": env, "version": version, "scenario": scenario,
			"result": "blocked_in_preflight", "current_version": before.Version,
			"note":   "preflight bloqueo antes de tocar el ambiente.",
		}
	}

	// Smoke posterior al deploy: si falla, rollback a la version previa.
	if isBadScenario(scenario) {
		atomic.AddInt64(&controlledRollbacks, 1)
		recordLocked("controlled", env, version, scenario, "rolled_back_to_"+before.Version)
		return map[string]any{
			"variant": "controlled", "env": env, "version": version, "scenario": scenario,
			"result": "rolled_back", "current_version": before.Version,
			"note":   "smoke fallo, rollback automatico a la version anterior.",
		}
	}

	environs[env] = envState{Name: env, Version: version, Health: "healthy"}
	recordLocked("controlled", env, version, scenario, "promoted")
	return map[string]any{
		"variant": "controlled", "env": env, "version": version, "scenario": scenario,
		"result": "promoted", "health": "healthy",
		"note":   "preflight ok + smoke ok → promote.",
	}
}

func isBadScenario(scenario string) bool {
	return scenario == "secret_drift" || scenario == "breaking_change" || scenario == "schema_mismatch"
}

func environmentsJSON() map[string]any {
	stateMu.Lock()
	defer stateMu.Unlock()
	return environmentsLocked()
}

func environmentsLocked() map[string]any {
	envs := make([]envState, 0, len(environs))
	// Orden estable: staging antes que prod, como en los otros stacks.
	for _, name := range []string{"staging", "prod"} {
		if s, ok := environs[name]; ok {
			envs = append(envs, s)
		}
	}
	return map[string]any{"envs": envs}
}

func deploymentsJSON() map[string]any {
	stateMu.Lock()
	defer stateMu.Unlock()
	history := append([]deployment{}, deployments...)
	return map[string]any{"history": history, "max_kept": maxDeployments}
}

func diagnostics() map[string]any {
	stateMu.Lock()
	envs := environmentsLocked()
	stateMu.Unlock()
	return map[string]any{
		"stack": stack,
		"case":  caseName,
		"legacy": map[string]any{
			"deploys":           atomic.LoadInt64(&legacyDeploys),
			"broken_state_left": atomic.LoadInt64(&legacyBroken),
			"behavior":          "sin preflight, sin rollback",
		},
		"controlled": map[string]any{
			"deploys":              atomic.LoadInt64(&controlledDeploys),
			"blocked_in_preflight": atomic.LoadInt64(&controlledBlocked),
			"rollbacks":            atomic.LoadInt64(&controlledRollbacks),
			"behavior":             "preflight + smoke + rollback automatico",
		},
		"environments": envs,
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
