// Caso 05 — Presion de memoria y fugas de recursos (stack Go 1.23).
//
// Legacy: slice global que crece sin limite por request → fuga real
// cross-request. Optimized: cache LRU acotada → memoria estable.
//
// El contraste que este stack aporta:
//
//   Go tiene GC igual que Java y .NET, asi que la fuga aca NO es de memoria
//   no liberada — es de memoria REFERENCIADA de mas, exactamente el mismo bug
//   de diseño que en los otros dos. Un GC no salva de guardar cosas para
//   siempre. Ese es el punto que los tres stacks con GC dejan claro y que el
//   lector suele confundir con "leak = falta free()".
//
//   Lo que Go hace distinto es medirlo: `runtime.ReadMemStats` da HeapAlloc,
//   HeapSys y NumGC sin agente externo ni JMX. Y `runtime/debug.FreeOSMemory`
//   fuerza la devolucion al SO, el equivalente honesto del System.gc() de Java.
//
//   Go tampoco trae LRU en la stdlib (no hay LinkedHashMap con
//   removeEldestEntry como Java). Se implementa con `container/list` +
//   `map[int]*list.Element`, que es el idioma Go para esto: una lista
//   doblemente enlazada y un indice. Mas codigo, cero magia oculta.
package main

import (
	"container/list"
	"encoding/json"
	"log"
	"net/http"
	"os"
	"runtime"
	"runtime/debug"
	"strconv"
	"sync"
	"sync/atomic"
	"time"
)

const (
	caseName     = "05 - Presion de memoria y fugas de recursos"
	optimizedCap = 1000
)

var stack = envOr("APP_STACK", "Go 1.23")

var (
	legacyRequests     int64
	optimizedRequests  int64
	optimizedEvictions int64
)

// ---------- acumulador legacy (la fuga) ----------

var (
	legacyMu          sync.Mutex
	legacyAccumulator [][]byte // nunca se vacia: la fuga real
)

// ---------- cache LRU acotada (la solucion) ----------

type lruEntry struct {
	key     int64
	payload []byte
}

type lru struct {
	mu    sync.Mutex
	cap   int
	ll    *list.List
	index map[int64]*list.Element
}

func newLRU(capacity int) *lru {
	return &lru{cap: capacity, ll: list.New(), index: make(map[int64]*list.Element, capacity)}
}

// put inserta y evicciona el mas viejo si se pasa del cap. Devuelve true si
// hubo eviccion — el equivalente explicito del removeEldestEntry de Java.
func (c *lru) put(key int64, payload []byte) bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	if el, ok := c.index[key]; ok {
		c.ll.MoveToFront(el)
		el.Value.(*lruEntry).payload = payload
		return false
	}
	el := c.ll.PushFront(&lruEntry{key: key, payload: payload})
	c.index[key] = el
	if c.ll.Len() > c.cap {
		oldest := c.ll.Back()
		if oldest != nil {
			c.ll.Remove(oldest)
			delete(c.index, oldest.Value.(*lruEntry).key)
			return true
		}
	}
	return false
}

func (c *lru) len() int {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.ll.Len()
}

func (c *lru) clear() {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.ll.Init()
	c.index = make(map[int64]*list.Element, c.cap)
}

var optimizedCache = newLRU(optimizedCap)

// ---------- arranque ----------

func main() {
	mux := http.NewServeMux()
	mux.HandleFunc("/", route)

	port := envOr("PORT", "8080")
	log.Printf("[case05-go] listening on %s", port)
	if err := http.ListenAndServe(":"+port, mux); err != nil {
		log.Fatalf("listen: %v", err)
	}
}

// ---------- routing ----------

func route(w http.ResponseWriter, r *http.Request) {
	path := r.URL.Path
	sizeKb := bounded(r.URL.Query().Get("size_kb"), 64, 1, 4096)

	status := http.StatusOK
	var payload any

	switch path {
	case "/", "/index":
		payload = map[string]any{
			"case":  caseName,
			"stack": stack,
			"routes": []string{
				"/health", "/batch-legacy?size_kb=64", "/batch-optimized?size_kb=64",
				"/state", "/diagnostics/summary", "/reset-lab",
			},
		}
	case "/health":
		payload = map[string]string{"status": "ok", "stack": stack, "case": caseName}
	case "/batch-legacy":
		payload = batchLegacy(sizeKb)
		atomic.AddInt64(&legacyRequests, 1)
	case "/batch-optimized":
		payload = batchOptimized(sizeKb)
		atomic.AddInt64(&optimizedRequests, 1)
	case "/state":
		payload = state()
	case "/diagnostics/summary":
		payload = diagnostics()
	case "/reset-lab":
		legacyMu.Lock()
		legacyAccumulator = nil
		legacyMu.Unlock()
		optimizedCache.clear()
		atomic.StoreInt64(&legacyRequests, 0)
		atomic.StoreInt64(&optimizedRequests, 0)
		atomic.StoreInt64(&optimizedEvictions, 0)
		// FreeOSMemory corre un GC y devuelve las paginas libres al SO — el
		// equivalente honesto del System.gc() de Java.
		debug.FreeOSMemory()
		payload = map[string]string{
			"status": "reset",
			"note":   "acumuladores limpios + debug.FreeOSMemory() invocado.",
		}
	default:
		status = http.StatusNotFound
		payload = map[string]string{"error": "not_found", "path": path}
	}

	sendJSON(w, status, payload)
}

// ---------- endpoints ----------

// batchLegacy: cada request appendea al slice global y nada lo saca. El GC no
// puede liberarlo porque sigue referenciado — la fuga es de diseño, no del
// recolector.
func batchLegacy(sizeKb int) map[string]any {
	payload := make([]byte, sizeKb*1024)
	for i := range payload {
		payload[i] = byte(i & 0xff)
	}
	legacyMu.Lock()
	legacyAccumulator = append(legacyAccumulator, payload)
	retained := len(legacyAccumulator)
	legacyMu.Unlock()

	return map[string]any{
		"variant":              "legacy",
		"appended_kb":          sizeKb,
		"retained_count":       retained,
		"retained_kb_estimate": retained * sizeKb,
		"note":                 "se acumula en slice global sin eviccion → fuga real cross-request.",
	}
}

// batchOptimized: la LRU acotada mantiene el cap fijo. La entrada evictada
// pierde su ultima referencia y el GC la reclama en el siguiente ciclo.
func batchOptimized(sizeKb int) map[string]any {
	payload := make([]byte, sizeKb*1024)
	for i := range payload {
		payload[i] = byte(i & 0xff)
	}
	key := time.Now().UnixNano()
	if evicted := optimizedCache.put(key, payload); evicted {
		atomic.AddInt64(&optimizedEvictions, 1)
	}
	return map[string]any{
		"variant":          "optimized",
		"appended_kb":      sizeKb,
		"retained_count":   optimizedCache.len(),
		"cap":              optimizedCap,
		"evictions_total":  atomic.LoadInt64(&optimizedEvictions),
		"note":             "container/list + map como LRU con cap fijo, memoria estable.",
	}
}

// state: runtime.ReadMemStats da la presion real sin agente externo ni JMX.
func state() map[string]any {
	var ms runtime.MemStats
	runtime.ReadMemStats(&ms)
	legacyMu.Lock()
	legacyCount := len(legacyAccumulator)
	legacyMu.Unlock()

	const mb = 1024 * 1024
	return map[string]any{
		"stack":                    stack,
		"heap_used_mb":             ms.HeapAlloc / mb,
		"heap_total_mb":            ms.HeapSys / mb,
		"heap_max_mb":              ms.Sys / mb,
		"heap_free_mb":             (ms.HeapSys - ms.HeapAlloc) / mb,
		"gc_cycles":                ms.NumGC,
		"goroutines":               runtime.NumGoroutine(),
		"legacy_retained_count":    legacyCount,
		"optimized_retained_count": optimizedCache.len(),
		"optimized_cap":            optimizedCap,
	}
}

func diagnostics() map[string]any {
	legacyMu.Lock()
	legacyCount := len(legacyAccumulator)
	legacyMu.Unlock()
	return map[string]any{
		"stack": stack,
		"case":  caseName,
		"legacy": map[string]any{
			"requests":       atomic.LoadInt64(&legacyRequests),
			"retained_count": legacyCount,
			"behavior":       "sin eviccion, leak monotonicamente creciente",
		},
		"optimized": map[string]any{
			"requests":       atomic.LoadInt64(&optimizedRequests),
			"retained_count": optimizedCache.len(),
			"evictions":      atomic.LoadInt64(&optimizedEvictions),
			"cap":            optimizedCap,
			"behavior":       "LRU con container/list y cap fijo",
		},
		"runtime": state(),
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
