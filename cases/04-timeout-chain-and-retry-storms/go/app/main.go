// Caso 04 — Timeout chain y retry storms (stack Go 1.23).
//
// Legacy: 5 reintentos secuenciales sin backoff, sin timeout, sin breaker.
// Resilient: deadline cooperativo + circuit breaker + fallback cacheado.
//
// El contraste que este stack aporta frente a Java y .NET:
//
//   `context.WithTimeout` no es solo un reloj que devuelve el control al
//   llamador — es una señal de cancelacion que VIAJA hacia abajo. La funcion
//   llamada recibe el ctx y hace `select { case <-ctx.Done(): ... }`, asi que
//   cuando vence el deadline el trabajo remoto se abandona de verdad.
//
//   Comparalo con `CompletableFuture.orTimeout(300ms)` en Java: el future
//   completa excepcionalmente a los 300 ms, pero el thread que estaba haciendo
//   el `Thread.sleep(800)` sigue ahi hasta terminar. El llamador cree que
//   corto; el recurso sigue ocupado. Bajo retry storm esa diferencia es la que
//   decide si el pool se agota o no.
//
//   Aca el proveedor simulado hace `select` sobre `ctx.Done()` y un timer: al
//   vencer el deadline la goroutine retorna inmediatamente y libera el recurso.
//
// Otras primitivas: sync/atomic + sync.Mutex para el estado del breaker,
// time.After para el reloj del proveedor.
package main

import (
	"context"
	"encoding/json"
	"errors"
	"log"
	"math/rand"
	"net/http"
	"os"
	"sync"
	"sync/atomic"
	"time"
)

const (
	caseName             = "04 - Timeout chain y retry storms"
	breakerCooldown      = 5 * time.Second
	breakerFailThreshold = 3
	providerLatency      = 800 * time.Millisecond
	resilientDeadline    = 300 * time.Millisecond
	legacyMaxAttempts    = 5
)

var stack = envOr("APP_STACK", "Go 1.23")

var (
	legacyRetries          int64
	legacyFailures         int64
	resilientCalls         int64
	resilientFallbacks     int64
	resilientShortCircuits int64
	lastFallbackPrice      int64
)

var errProviderUnavailable = errors.New("provider_unavailable")

// ---------- circuit breaker ----------

type breakerState struct {
	State     string `json:"state"`
	FailCount int    `json:"fail_count"`
	OpenedAt  int64  `json:"opened_at"`
}

var (
	breakerMu sync.Mutex
	breaker   = breakerState{State: "closed"}
)

func breakerSnapshot() breakerState {
	breakerMu.Lock()
	defer breakerMu.Unlock()
	return breaker
}

func onSuccess() {
	breakerMu.Lock()
	defer breakerMu.Unlock()
	breaker = breakerState{State: "closed"}
}

func onFailure() {
	breakerMu.Lock()
	defer breakerMu.Unlock()
	breaker.FailCount++
	if breaker.FailCount >= breakerFailThreshold {
		breaker.State = "open"
		breaker.OpenedAt = time.Now().UnixMilli()
	}
}

func breakerJSON() map[string]any {
	s := breakerSnapshot()
	cooldownLeft := breakerCooldown.Milliseconds() - (time.Now().UnixMilli() - s.OpenedAt)
	if cooldownLeft < 0 || s.OpenedAt == 0 {
		cooldownLeft = 0
	}
	return map[string]any{
		"state":            s.State,
		"fail_count":       s.FailCount,
		"opened_at":        s.OpenedAt,
		"cooldown_left_ms": cooldownLeft,
		"threshold":        breakerFailThreshold,
		"cooldown_ms":      breakerCooldown.Milliseconds(),
	}
}

// ---------- proveedor simulado ----------

var (
	rngMu sync.Mutex
	rng   = rand.New(rand.NewSource(20420))
)

func nextQuote() int64 {
	rngMu.Lock()
	defer rngMu.Unlock()
	return 100 + int64(rng.Intn(900))
}

// callProvider respeta el deadline del contexto. Si el ctx se cancela antes de
// que el proveedor responda, la funcion retorna de inmediato y la goroutine
// deja de ocupar recursos — no se queda dormida esperando a que pase el sleep.
func callProvider(ctx context.Context, fail bool) (int64, error) {
	select {
	case <-time.After(providerLatency):
		if fail {
			return 0, errProviderUnavailable
		}
		return nextQuote(), nil
	case <-ctx.Done():
		return 0, ctx.Err()
	}
}

// ---------- arranque ----------

func main() {
	mux := http.NewServeMux()
	mux.HandleFunc("/", route)

	port := envOr("PORT", "8080")
	log.Printf("[case04-go] listening on %s", port)
	if err := http.ListenAndServe(":"+port, mux); err != nil {
		log.Fatalf("listen: %v", err)
	}
}

// ---------- routing ----------

func route(w http.ResponseWriter, r *http.Request) {
	path := r.URL.Path
	fail := r.URL.Query().Get("fail") == "on"

	status := http.StatusOK
	var payload any

	switch path {
	case "/", "/index":
		payload = map[string]any{
			"case":  caseName,
			"stack": stack,
			"routes": []string{
				"/health", "/quote-legacy?fail=on", "/quote-resilient?fail=on",
				"/dependency/state", "/diagnostics/summary", "/reset-lab",
			},
		}
	case "/health":
		payload = map[string]string{"status": "ok", "stack": stack, "case": caseName}
	case "/quote-legacy":
		payload = quoteLegacy(r.Context(), fail)
	case "/quote-resilient":
		payload = quoteResilient(r.Context(), fail)
		atomic.AddInt64(&resilientCalls, 1)
	case "/dependency/state":
		payload = breakerJSON()
	case "/diagnostics/summary":
		payload = diagnostics()
	case "/reset-lab":
		atomic.StoreInt64(&legacyRetries, 0)
		atomic.StoreInt64(&legacyFailures, 0)
		atomic.StoreInt64(&resilientCalls, 0)
		atomic.StoreInt64(&resilientFallbacks, 0)
		atomic.StoreInt64(&resilientShortCircuits, 0)
		breakerMu.Lock()
		breaker = breakerState{State: "closed"}
		breakerMu.Unlock()
		payload = map[string]string{"status": "reset"}
	default:
		status = http.StatusNotFound
		payload = map[string]string{"error": "not_found", "path": path}
	}

	sendJSON(w, status, payload)
}

// ---------- endpoints ----------

// quoteLegacy: 5 reintentos secuenciales sin backoff, sin deadline propio y sin
// breaker. Cada intento espera los 800 ms completos del proveedor: 4 segundos
// de recurso ocupado antes de rendirse. Multiplicado por los clientes que
// reintentan a la vez, esto es el retry storm.
func quoteLegacy(ctx context.Context, fail bool) map[string]any {
	start := time.Now()
	for attempt := 1; attempt <= legacyMaxAttempts; attempt++ {
		atomic.AddInt64(&legacyRetries, 1)
		quote, err := callProvider(ctx, fail)
		if err == nil {
			return map[string]any{
				"variant": "legacy", "status": "ok", "attempts": attempt, "quote": quote,
				"elapsed_ms": elapsedMs(start),
			}
		}
		// sin backoff, sin breaker: se reintenta de inmediato
	}
	atomic.AddInt64(&legacyFailures, 1)
	return map[string]any{
		"variant": "legacy", "status": "failed", "attempts": legacyMaxAttempts,
		"elapsed_ms": elapsedMs(start),
		"note":       "5 reintentos sin backoff agotaron al proveedor; sin circuit breaker.",
	}
}

// quoteResilient: si el breaker esta abierto y sigue en cooldown, corta sin
// tocar al proveedor. Si no, un solo intento con deadline cooperativo de 300 ms.
func quoteResilient(parent context.Context, fail bool) map[string]any {
	start := time.Now()

	s := breakerSnapshot()
	if s.State == "open" && time.Since(time.UnixMilli(s.OpenedAt)) < breakerCooldown {
		atomic.AddInt64(&resilientShortCircuits, 1)
		return map[string]any{
			"variant": "resilient", "status": "short_circuited", "breaker": "open",
			"fallback_quote": atomic.LoadInt64(&lastFallbackPrice),
			"elapsed_ms":     elapsedMs(start),
			"note":           "breaker abierto, devuelve fallback sin tocar al proveedor.",
		}
	}

	// El deadline viaja con el contexto: al vencer, callProvider retorna ya.
	ctx, cancel := context.WithTimeout(parent, resilientDeadline)
	defer cancel()

	quote, err := callProvider(ctx, fail)
	if err == nil {
		onSuccess()
		atomic.StoreInt64(&lastFallbackPrice, quote)
		return map[string]any{
			"variant": "resilient", "status": "ok", "quote": quote,
			"breaker": breakerSnapshot().State, "elapsed_ms": elapsedMs(start),
		}
	}

	onFailure()
	atomic.AddInt64(&resilientFallbacks, 1)
	cause := "provider_error"
	if errors.Is(err, context.DeadlineExceeded) {
		cause = "timeout"
	}
	return map[string]any{
		"variant": "resilient", "status": "fallback",
		"breaker":        breakerSnapshot().State,
		"fallback_quote": atomic.LoadInt64(&lastFallbackPrice),
		"elapsed_ms":     elapsedMs(start),
		"cause":          cause,
	}
}

func diagnostics() map[string]any {
	return map[string]any{
		"stack": stack,
		"case":  caseName,
		"legacy": map[string]any{
			"retries_total": atomic.LoadInt64(&legacyRetries),
			"failures":      atomic.LoadInt64(&legacyFailures),
			"note":          "reintentos lineales sin breaker producen retry storm",
		},
		"resilient": map[string]any{
			"calls":          atomic.LoadInt64(&resilientCalls),
			"fallbacks":      atomic.LoadInt64(&resilientFallbacks),
			"short_circuits": atomic.LoadInt64(&resilientShortCircuits),
			"breaker":        breakerJSON(),
		},
	}
}

// ---------- helpers ----------

func elapsedMs(start time.Time) float64 {
	return round2(float64(time.Since(start).Microseconds()) / 1000.0)
}

func round2(v float64) float64 {
	return float64(int64(v*100+0.5)) / 100.0
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
