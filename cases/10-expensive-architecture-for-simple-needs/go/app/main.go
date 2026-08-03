// Caso 10 — Arquitectura cara para un problema simple — stack Go 1.23.
//
// Complex: N hops simulados con serializacion costosa en cada uno, alto CPU.
// Right-sized: lookup directo en un map, O(1), CPU minimo.
//
// El contraste que este stack aporta:
//
//   El costo de este caso es CPU puro — construir y recorrer buffers. Go lo
//   mide con `strings.Builder`, que es el mismo patron de `StringBuilder` en
//   Java o C#, pero con una diferencia que importa al medir: `strings.Builder`
//   garantiza cero copias al convertir a string al final (usa unsafe
//   internamente para reinterpretar el buffer). En Java, `toString()` copia el
//   array.
//
//   Por eso el numero de este stack sale sistematicamente mas bajo que el de
//   Java/.NET para el mismo trabajo nominal, y por eso el caso no compara
//   milisegundos entre lenguajes: compara **la forma de la curva** —lineal en
//   hops vs constante— dentro de cada stack. Ese es el punto del caso: la
//   sobrearquitectura se paga en pendiente, no en constante.
package main

import (
	"encoding/json"
	"log"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync/atomic"
	"time"
)

const caseName = "10 - Arquitectura cara para algo simple"

var stack = envOr("APP_STACK", "Go 1.23")

var (
	complexCalls    int64
	complexTimeouts int64
	rightSizedCalls int64
)

// directStore: el "right-sized" — un map y nada mas. Se llena una vez al
// arrancar y solo se lee, asi que no necesita lock.
var directStore = func() map[string]int64 {
	m := make(map[string]int64, 100)
	for i := 1; i <= 100; i++ {
		m["feature-"+strconv.Itoa(i)] = int64(i * 10)
	}
	return m
}()

var decisions = []string{
	"ADR-001: empezar con monolito + map; revisitar si pasa de 10k QPS sostenido",
	"ADR-002: posponer queue distribuida hasta que el modelo de datos lo requiera",
}

// ---------- arranque ----------

func main() {
	mux := http.NewServeMux()
	mux.HandleFunc("/", route)

	port := envOr("PORT", "8080")
	log.Printf("[case10-go] listening on %s", port)
	if err := http.ListenAndServe(":"+port, mux); err != nil {
		log.Fatalf("listen: %v", err)
	}
}

// ---------- routing ----------

func route(w http.ResponseWriter, r *http.Request) {
	path := r.URL.Path
	q := r.URL.Query()
	key := queryOr(q.Get("key"), "feature-1")
	hops := bounded(q.Get("hops"), 8, 1, 50)

	status := http.StatusOK
	var payload any

	switch path {
	case "/", "/index":
		payload = map[string]any{
			"case":  caseName,
			"stack": stack,
			"routes": []string{
				"/health", "/feature-complex?key=feature-1&hops=8",
				"/feature-right-sized?key=feature-1",
				"/decisions", "/diagnostics/summary", "/reset-lab",
			},
		}
	case "/health":
		payload = map[string]string{"status": "ok", "stack": stack, "case": caseName}
	case "/feature-complex":
		payload = featureComplex(key, hops)
		atomic.AddInt64(&complexCalls, 1)
	case "/feature-right-sized":
		payload = featureRightSized(key)
		atomic.AddInt64(&rightSizedCalls, 1)
	case "/decisions":
		payload = map[string]any{"decisions": decisions}
	case "/diagnostics/summary":
		payload = diagnostics()
	case "/reset-lab":
		atomic.StoreInt64(&complexCalls, 0)
		atomic.StoreInt64(&complexTimeouts, 0)
		atomic.StoreInt64(&rightSizedCalls, 0)
		payload = map[string]string{"status": "reset"}
	default:
		status = http.StatusNotFound
		payload = map[string]string{"error": "not_found", "path": path}
	}

	sendJSON(w, status, payload)
}

// ---------- endpoints ----------

// featureComplex: el payload "viaja" por N servicios y cada uno lo serializa.
// El costo crece linealmente con hops — esa pendiente es el sintoma.
func featureComplex(key string, hops int) map[string]any {
	start := time.Now()

	var payload strings.Builder
	payload.WriteString(`{"key":"`)
	payload.WriteString(key)
	payload.WriteString(`","trace":[`)
	for h := 0; h < hops; h++ {
		var hop strings.Builder
		hop.Grow(2048)
		hop.WriteString(`"hop-`)
		hop.WriteString(strconv.Itoa(h))
		hop.WriteString("-")
		for i := 0; i < 200; i++ {
			hop.WriteByte(byte('A' + (i % 26)))
		}
		hop.WriteString(`"`)
		payload.WriteString(hop.String())
		if h < hops-1 {
			payload.WriteString(",")
		}
	}
	payload.WriteString(`],"final_lookup":`)
	value, found := directStore[key]
	if found {
		payload.WriteString(strconv.FormatInt(value, 10))
	} else {
		payload.WriteString("null")
	}
	payload.WriteString("}")

	elapsedMs := time.Since(start).Milliseconds()

	if hops > 20 {
		atomic.AddInt64(&complexTimeouts, 1)
		return map[string]any{
			"variant": "complex", "status": "internal_timeout",
			"hops": hops, "elapsed_ms": elapsedMs,
			"services_touched": hops, "cost_usd_month_est": hops * 25,
			"lead_time_days": hops * 2,
			"note":           "sobrearquitectura: muchos hops, timeout interno bajo seasonal_peak.",
		}
	}

	return map[string]any{
		"variant": "complex", "key": key,
		"hops": hops, "elapsed_ms": elapsedMs,
		"services_touched": hops, "cost_usd_month_est": hops * 25,
		"lead_time_days": hops * 2,
		"value":          nullableInt(value, found),
		"payload_bytes":  payload.Len(),
		"note":           "N hops con serializacion en cada uno; CPU real medido.",
	}
}

// featureRightSized: un lookup. Constante en el tamaño del problema.
func featureRightSized(key string) map[string]any {
	start := time.Now()
	value, found := directStore[key]
	elapsedMs := time.Since(start).Milliseconds()
	return map[string]any{
		"variant": "right_sized", "key": key,
		"elapsed_ms": elapsedMs, "services_touched": 1,
		"cost_usd_month_est": 3, "lead_time_days": 1,
		"value": nullableInt(value, found),
		"note":  "map lookup O(1); proporcional al problema real.",
	}
}

func diagnostics() map[string]any {
	return map[string]any{
		"stack": stack,
		"case":  caseName,
		"complex": map[string]any{
			"calls":    atomic.LoadInt64(&complexCalls),
			"timeouts": atomic.LoadInt64(&complexTimeouts),
			"behavior": "N hops con serializacion por hop; costo lineal en hops",
		},
		"right_sized": map[string]any{
			"calls":    atomic.LoadInt64(&rightSizedCalls),
			"behavior": "map lookup O(1); costo constante",
		},
		"decisions": decisions,
	}
}

// ---------- helpers ----------

func nullableInt(v int64, found bool) any {
	if !found {
		return nil
	}
	return v
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

func queryOr(v, fallback string) string {
	if v == "" {
		return fallback
	}
	return v
}

func bounded(raw string, fallback, min, max int) int {
	n, err := strconv.Atoi(raw)
	if err != nil {
		n = fallback
	}
	if n < min {
		return min
	}
	if n > max {
		return max
	}
	return n
}
