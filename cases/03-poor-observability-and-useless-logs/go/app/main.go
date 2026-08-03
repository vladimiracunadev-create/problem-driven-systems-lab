// Caso 03 — Observabilidad deficiente y logs inutiles (stack Go 1.23).
//
// Legacy: log.Printf sin correlation, sin contexto. Errores opacos.
// Observable: log estructurado JSON con correlation_id propagado, mas /logs
// que devuelve los ultimos N eventos.
//
// Primitivas Go distintivas, y el contraste que este caso hace visible:
//
//   - context.Context es LA via idiomatica de Go para propagar valores con
//     alcance de request. No es un ThreadLocal (Java) ni un AsyncLocal (.NET):
//     el contexto viaja como PARAMETRO EXPLICITO. Eso tiene una consecuencia
//     concreta — una funcion que no recibe ctx no puede leer el correlation_id
//     por accidente, y el compilador lo hace evidente. En Java/.NET el contexto
//     es ambiente: funciona hasta que alguien salta de thread y se pierde en
//     silencio. Aca perderlo es un error de compilacion, no un bug de runtime.
//
//   - log/slog (stdlib desde Go 1.21) emite JSON estructurado sin libreria
//     externa. Es el unico stack del lab donde el logger estructurado viene en
//     la biblioteca estandar.
package main

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"log"
	"log/slog"
	"net/http"
	"os"
	"strconv"
	"sync"
	"sync/atomic"
	"time"
)

const (
	caseName      = "03 - Observabilidad deficiente y logs inutiles"
	maxLogEntries = 200
)

var stack = envOr("APP_STACK", "Go 1.23")

var (
	legacyRequests     int64
	legacyErrors       int64
	observableRequests int64
	observableErrors   int64
)

// ---------- contexto de request ----------

// ctxKey es un tipo privado: nadie fuera de este paquete puede colisionar con
// esta clave en el Context. Es la convencion Go para valores de contexto.
type ctxKey struct{}

type requestContext struct {
	CorrelationID string
	Route         string
	StartedAt     string
}

func withRequestContext(parent context.Context, rc requestContext) context.Context {
	return context.WithValue(parent, ctxKey{}, rc)
}

func fromContext(ctx context.Context) (requestContext, bool) {
	rc, ok := ctx.Value(ctxKey{}).(requestContext)
	return rc, ok
}

// ---------- buffer de logs estructurados ----------

type logEntry map[string]any

var (
	logsMu     sync.Mutex
	recentLogs []logEntry
)

func pushLog(e logEntry) {
	logsMu.Lock()
	defer logsMu.Unlock()
	recentLogs = append([]logEntry{e}, recentLogs...)
	if len(recentLogs) > maxLogEntries {
		recentLogs = recentLogs[:maxLogEntries]
	}
}

func snapshotLogs() []logEntry {
	logsMu.Lock()
	defer logsMu.Unlock()
	return append([]logEntry(nil), recentLogs...)
}

// structuredLog toma ctx como primer parametro — la firma obliga a pasarlo.
// Si el llamador no tiene contexto, no puede fingir que si.
func structuredLog(ctx context.Context, level, event string, fields map[string]any) {
	e := logEntry{
		"ts":    time.Now().UTC().Format(time.RFC3339Nano),
		"level": level,
		"event": event,
	}
	if rc, ok := fromContext(ctx); ok {
		e["correlation_id"] = rc.CorrelationID
		e["route"] = rc.Route
	}
	for k, v := range fields {
		e[k] = v
	}
	pushLog(e)

	// slog emite el mismo evento a stdout en JSON — el operador ve lo mismo
	// que /logs devuelve, sin tener que elegir entre uno u otro.
	attrs := []any{"event", event}
	if rc, ok := fromContext(ctx); ok {
		attrs = append(attrs, "correlation_id", rc.CorrelationID, "route", rc.Route)
	}
	for k, v := range fields {
		attrs = append(attrs, k, v)
	}
	slog.Info(event, attrs...)
}

// ---------- arranque ----------

func main() {
	slog.SetDefault(slog.New(slog.NewJSONHandler(os.Stdout, nil)))

	mux := http.NewServeMux()
	mux.HandleFunc("/", route)

	port := envOr("PORT", "8080")
	log.Printf("[case03-go] listening on %s", port)
	if err := http.ListenAndServe(":"+port, mux); err != nil {
		log.Fatalf("listen: %v", err)
	}
}

// ---------- routing ----------

func route(w http.ResponseWriter, r *http.Request) {
	path := r.URL.Path
	totalRaw := r.URL.Query().Get("total")

	status := http.StatusOK
	var payload any

	switch path {
	case "/", "/index":
		payload = map[string]any{
			"case":  caseName,
			"stack": stack,
			"routes": []string{
				"/health", "/checkout-legacy?total=100", "/checkout-observable?total=100",
				"/logs", "/metrics", "/diagnostics/summary", "/reset-lab",
			},
		}
	case "/health":
		payload = map[string]string{"status": "ok", "stack": stack, "case": caseName}
	case "/checkout-legacy":
		payload = checkoutLegacy(totalRaw)
		atomic.AddInt64(&legacyRequests, 1)
	case "/checkout-observable":
		payload = checkoutObservable(r.Context(), totalRaw)
		atomic.AddInt64(&observableRequests, 1)
	case "/logs":
		payload = map[string]any{"entries": snapshotLogs(), "max_kept": maxLogEntries}
	case "/metrics", "/diagnostics/summary":
		payload = diagnostics()
	case "/reset-lab":
		atomic.StoreInt64(&legacyRequests, 0)
		atomic.StoreInt64(&legacyErrors, 0)
		atomic.StoreInt64(&observableRequests, 0)
		atomic.StoreInt64(&observableErrors, 0)
		logsMu.Lock()
		recentLogs = nil
		logsMu.Unlock()
		payload = map[string]string{"status": "reset"}
	default:
		status = http.StatusNotFound
		payload = map[string]string{"error": "not_found", "path": path}
	}

	sendJSON(w, status, payload)
}

// ---------- endpoints ----------

// checkoutLegacy: log opaco. No recibe ctx — y esa es justamente la señal: no
// tiene forma de correlacionar nada aunque quisiera.
func checkoutLegacy(totalRaw string) map[string]any {
	total := parseFloatOr(totalRaw, 100.0)
	log.Printf("[INFO] processing checkout")
	if total > 500 {
		log.Printf("[ERROR] checkout failed")
		atomic.AddInt64(&legacyErrors, 1)
		return map[string]any{
			"variant": "legacy",
			"status":  "error",
			"note":    "log dice 'checkout failed' sin id, sin total, sin causa.",
		}
	}
	log.Printf("[INFO] checkout ok")
	return map[string]any{
		"variant": "legacy",
		"status":  "ok",
		"note":    "log dice 'checkout ok' sin contexto util.",
	}
}

// checkoutObservable: correlation ID en el Context, propagado explicitamente a
// cada llamada que loguea.
func checkoutObservable(parent context.Context, totalRaw string) map[string]any {
	corrID := newCorrelationID()
	ctx := withRequestContext(parent, requestContext{
		CorrelationID: corrID,
		Route:         "checkout-observable",
		StartedAt:     time.Now().UTC().Format(time.RFC3339Nano),
	})

	total := parseFloatOr(totalRaw, 100.0)
	structuredLog(ctx, "info", "checkout_start", map[string]any{"total": total})

	if total > 500 {
		structuredLog(ctx, "error", "checkout_failed", map[string]any{
			"total": total, "reason": "exceeds_limit", "limit": 500,
		})
		atomic.AddInt64(&observableErrors, 1)
		return map[string]any{
			"variant":        "observable",
			"status":         "error",
			"correlation_id": corrID,
			"reason":         "exceeds_limit",
			"limit":          500,
			"total":          total,
		}
	}
	structuredLog(ctx, "info", "checkout_ok", map[string]any{"total": total})
	return map[string]any{
		"variant":        "observable",
		"status":         "ok",
		"correlation_id": corrID,
		"total":          total,
		"note":           "correlation_id propagado via context.Context en logs estructurados.",
	}
}

func diagnostics() map[string]any {
	return map[string]any{
		"stack": stack,
		"case":  caseName,
		"legacy": map[string]any{
			"requests":      atomic.LoadInt64(&legacyRequests),
			"errors":        atomic.LoadInt64(&legacyErrors),
			"observability": "log.Printf sin correlation, sin contexto",
		},
		"observable": map[string]any{
			"requests":      atomic.LoadInt64(&observableRequests),
			"errors":        atomic.LoadInt64(&observableErrors),
			"observability": "log/slog estructurado con correlation_id via context.Context, /logs endpoint",
		},
	}
}

// ---------- helpers ----------

func newCorrelationID() string {
	b := make([]byte, 16)
	if _, err := rand.Read(b); err != nil {
		return strconv.FormatInt(time.Now().UnixNano(), 16)
	}
	return hex.EncodeToString(b)
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

func parseFloatOr(raw string, fallback float64) float64 {
	v, err := strconv.ParseFloat(raw, 64)
	if err != nil {
		return fallback
	}
	return v
}
