// Caso 17 — Migracion de esquema sin downtime — stack Go 1.23.
//
// Blocking: un `ALTER TABLE` toma el lock exclusivo durante toda la migracion.
// Expand-contract: cuatro fases, y el lock se toma y se suelta en cada lote.
//
// Primitiva Go distintiva — y aca la novedad es un limite, no una virtud:
//
//	`sync.RWMutex` es lo mas simple del set: `RLock`/`RUnlock` para lectores,
//	`Lock`/`Unlock` para el escritor. Cuatro metodos, cero configuracion.
//
//	Pero **no tiene `TryRLock` con timeout**. Go tiene `TryRLock()` desde 1.18,
//	que devuelve inmediatamente sin esperar nada; no hay forma de decir "espera
//	hasta 120 ms y despues rendite". Java lo trae con `tryLock(timeout, unit)`,
//	.NET con `TryEnterReadLock(ms)`, Python se lo construye con `Condition.wait`.
//
//	Asi que el deadline del lector hay que armarlo: una goroutine que toma el
//	`RLock` y avisa por un canal, y un `select` con `time.After` del lado del
//	llamador. Funciona y es idiomatico — pero la goroutine que quedo esperando
//	sigue ahi hasta que el lock se suelte. **El lector se rindio; su goroutine
//	no.** En una migracion larga eso es una fuga de goroutines proporcional al
//	trafico, y es el tipo de detalle que solo se ve escribiendo el caso.
//
//	La contrapartida buena: `sync.RWMutex` SI garantiza que un escritor
//	bloqueado impide que entren lectores nuevos, asi que no hay hambruna de
//	escritor — lo que Java necesita pedir con el flag de equidad y Python
//	resuelve con una bandera a mano.
//
// El tiempo de migracion es un `time.Sleep`: un ALTER TABLE se demora esperando
// I/O del motor, no quemando CPU del proceso de la app.
package main

import (
	"encoding/json"
	"log"
	"net/http"
	"os"
	"sort"
	"strconv"
	"sync"
	"time"
)

const (
	caseName       = "17 - Migracion de esquema sin downtime"
	readTimeoutMs  = 120
)

var stack = envOr("APP_STACK", "Go 1.23")

var (
	rw sync.RWMutex

	stateMu           sync.Mutex
	rows              int
	hasNewColumn      bool
	backfilled        int
	oldColumnDropped  bool
	readFromNewColumn bool
	phase             = "idle"
)

type slot struct {
	Runs            int64   `json:"runs"`
	LockHeldMs      float64 `json:"lock_held_ms"`
	ReadersServed   int64   `json:"readers_served"`
	ReadersFailed   int64   `json:"readers_failed"`
	MaxReadWaitMs   float64 `json:"max_read_wait_ms"`
	BackfillBatches int64   `json:"backfill_batches"`
}

var (
	metricsMu sync.Mutex
	metrics   = freshMetrics()
)

func freshMetrics() map[string]*slot {
	return map[string]*slot{"blocking": {}, "expand_contract": {}}
}

func resetTable(n int) {
	stateMu.Lock()
	rows, hasNewColumn, backfilled, oldColumnDropped, readFromNewColumn, phase = n, false, 0, false, false, "idle"
	stateMu.Unlock()
}

func setPhase(p string) {
	stateMu.Lock()
	phase = p
	stateMu.Unlock()
}

// tryRLock: el deadline que sync.RWMutex no trae.
//
// La goroutine que toma el RLock sigue esperando aunque el llamador se haya
// rendido — el lector se rindio, su goroutine no. Es una fuga proporcional al
// trafico durante una migracion larga, y es honesto decirlo.
func tryRLock(timeout time.Duration) bool {
	got := make(chan struct{})
	go func() {
		rw.RLock()
		close(got)
	}()
	timer := time.NewTimer(timeout)
	defer timer.Stop()
	select {
	case <-got:
		return true
	case <-timer.C:
		// Cuando el lock se libere, esa goroutine va a tomarlo y soltarlo sola.
		go func() {
			<-got
			rw.RUnlock()
		}()
		return false
	}
}

type readerResult struct {
	served, failed int64
	waits          []float64
}

func reader(start <-chan struct{}, stopAt time.Time) readerResult {
	<-start
	var res readerResult
	for time.Now().Before(stopAt) {
		t0 := time.Now()
		ok := tryRLock(readTimeoutMs * time.Millisecond)
		res.waits = append(res.waits, msSince(t0))
		if ok {
			stateMu.Lock()
			_ = rows
			stateMu.Unlock()
			rw.RUnlock()
			res.served++
		} else {
			res.failed++
		}
		time.Sleep(2 * time.Millisecond)
	}
	return res
}

// ---------- variante blocking ----------

func migrateBlocking(n, msPer1k int) (float64, int64) {
	resetTable(n)
	setPhase("expand")
	duration := time.Duration(float64(n)/1000.0*float64(msPer1k)) * time.Millisecond

	t0 := time.Now()
	// El lock exclusivo se toma UNA vez y se suelta al final.
	rw.Lock()
	time.Sleep(duration)
	stateMu.Lock()
	hasNewColumn, backfilled, oldColumnDropped, readFromNewColumn = true, n, true, true
	stateMu.Unlock()
	rw.Unlock()
	held := msSince(t0)
	setPhase("done")
	return held, 1
}

// ---------- variante expand-contract ----------

func migrateExpandContract(n, msPer1k, batchSize, pauseMs int) (float64, int64) {
	resetTable(n)
	totalMs := float64(n) / 1000.0 * float64(msPer1k)
	held := 0.0
	var batches int64

	// 1. EXPAND — columna nullable: metadata, instantaneo.
	setPhase("expand")
	t0 := time.Now()
	rw.Lock()
	stateMu.Lock()
	hasNewColumn = true
	stateMu.Unlock()
	rw.Unlock()
	held += msSince(t0)

	// 2. BACKFILL — por lotes, soltando el lock entre cada uno.
	setPhase("backfill")
	done := 0
	perBatchMs := totalMs * (float64(batchSize) / float64(max(1, n)))
	for done < n {
		chunk := min(batchSize, n-done)
		t0 = time.Now()
		rw.Lock()
		time.Sleep(time.Duration(perBatchMs) * time.Millisecond)
		stateMu.Lock()
		backfilled += chunk
		stateMu.Unlock()
		rw.Unlock()
		held += msSince(t0)
		done += chunk
		batches++
		// La pausa entre lotes es lo que le devuelve el motor a la app.
		time.Sleep(time.Duration(pauseMs) * time.Millisecond)
	}

	// 3. SWITCH — feature flag. No toca datos: reversible en un segundo.
	setPhase("switch")
	stateMu.Lock()
	readFromNewColumn = true
	stateMu.Unlock()

	// 4. CONTRACT — recien ahora se borra la vieja.
	setPhase("contract")
	t0 = time.Now()
	rw.Lock()
	stateMu.Lock()
	oldColumnDropped = true
	stateMu.Unlock()
	rw.Unlock()
	held += msSince(t0)
	setPhase("done")
	return held, batches
}

// ---------- orquestacion ----------

func runMigration(variant string, n, readers, msPer1k, batchSize, pauseMs int) map[string]any {
	budgetMs := float64(n)/1000.0*float64(msPer1k) + float64(n)/float64(max(1, batchSize))*float64(pauseMs) + 400
	stopAt := time.Now().Add(time.Duration(budgetMs) * time.Millisecond)

	start := make(chan struct{})
	results := make([]readerResult, readers)
	var wg sync.WaitGroup
	wg.Add(readers)
	for i := 0; i < readers; i++ {
		go func(idx int) {
			defer wg.Done()
			results[idx] = reader(start, stopAt)
		}(i)
	}

	started := time.Now()
	close(start)
	var held float64
	var batches int64
	if variant == "blocking" {
		held, batches = migrateBlocking(n, msPer1k)
	} else {
		held, batches = migrateExpandContract(n, msPer1k, batchSize, pauseMs)
	}
	migrationMs := msSince(started)
	wg.Wait()
	wallMs := msSince(started)

	var served, failed int64
	var waits []float64
	for _, r := range results {
		served += r.served
		failed += r.failed
		waits = append(waits, r.waits...)
	}
	sort.Float64s(waits)
	maxWait := 0.0
	if len(waits) > 0 {
		maxWait = waits[len(waits)-1]
	}

	metricsMu.Lock()
	s := metrics[variant]
	s.Runs++
	s.LockHeldMs += held
	s.ReadersServed += served
	s.ReadersFailed += failed
	if maxWait > s.MaxReadWaitMs {
		s.MaxReadWaitMs = maxWait
	}
	s.BackfillBatches += batches
	metricsMu.Unlock()

	stateMu.Lock()
	ph, bf := phase, backfilled
	stateMu.Unlock()

	longest := held
	if variant != "blocking" {
		longest = held / float64(max64(1, batches))
	}
	note := "Un solo lock exclusivo tomado durante toda la migracion: los lectores esperan lo que dure, y los que tienen timeout fallan. Es el ALTER TABLE que devuelve 503 durante veinte minutos."
	if variant != "blocking" {
		note = "Expand, backfill por lotes con pausa, switch por feature flag y contract. El lock se toma y se suelta en cada lote, asi que ningun lector espera mas que un lote."
	}

	return map[string]any{
		"variant":                variant,
		"rows_total":             n,
		"readers":                readers,
		"phase":                  ph,
		"lock_held_ms":           round2(held),
		"longest_single_lock_ms": round2(longest),
		"readers_served":         served,
		"readers_failed":         failed,
		"availability_pct":       round2(float64(served) * 100.0 / float64(max64(1, served+failed))),
		"p99_read_wait_ms":       percentile(waits, 99),
		"max_read_wait_ms":       round2(maxWait),
		"read_timeout_ms":        readTimeoutMs,
		"backfill_batches":       batches,
		"backfill_progress_pct":  round2(float64(bf) * 100.0 / float64(max(1, n))),
		"migration_ms":           round2(migrationMs),
		"wall_ms":                round2(wallMs),
		"note":                   note,
	}
}

// ---------- rutas ----------

func migrationState() map[string]any {
	stateMu.Lock()
	defer stateMu.Unlock()
	return map[string]any{
		"phase":                 phase,
		"phases":                []string{"idle", "expand", "backfill", "switch", "contract", "done"},
		"rows_total":            rows,
		"has_new_column":        hasNewColumn,
		"backfilled":            backfilled,
		"backfill_progress_pct": round2(float64(backfilled) * 100.0 / float64(max(1, rows))),
		"old_column_dropped":    oldColumnDropped,
		"read_from_new_column":  readFromNewColumn,
		"read_timeout_ms":       readTimeoutMs,
		"note":                  "El feature flag read_from_new_column es lo unico reversible en un segundo. Por eso el switch va antes del contract, y no al reves.",
	}
}

func backfillStep(batchSize, msPer1k int) map[string]any {
	stateMu.Lock()
	n, done, hasCol := rows, backfilled, hasNewColumn
	stateMu.Unlock()
	if !hasCol {
		return map[string]any{"status": "skipped", "reason": "la columna nueva todavia no existe: falta la fase expand"}
	}
	if done >= n {
		return map[string]any{"status": "complete", "backfilled": done, "rows_total": n}
	}
	chunk := min(batchSize, n-done)
	t0 := time.Now()
	rw.Lock()
	time.Sleep(time.Duration(float64(n)/1000.0*float64(msPer1k)*(float64(chunk)/float64(max(1, n)))) * time.Millisecond)
	stateMu.Lock()
	backfilled += chunk
	done = backfilled
	stateMu.Unlock()
	rw.Unlock()
	return map[string]any{
		"status":                "batch_done",
		"batch_size":            chunk,
		"lock_held_ms":          round2(msSince(t0)),
		"backfilled":            done,
		"rows_total":            n,
		"backfill_progress_pct": round2(float64(done) * 100.0 / float64(max(1, n))),
	}
}

func diagnostics() map[string]any {
	metricsMu.Lock()
	variants := map[string]any{}
	for name, s := range metrics {
		variants[name] = *s
	}
	metricsMu.Unlock()
	return map[string]any{
		"stack":     stack,
		"case":      caseName,
		"variants":  variants,
		"migration": migrationState(),
		"interpretation": map[string]string{
			"blocking":        "readers_failed > 0 y max_read_wait_ms = la duracion entera de la migracion: la app estuvo caida todo ese tiempo aunque el proceso siguiera vivo.",
			"expand_contract": "readers_failed = 0 y max_read_wait_ms = lo que dura UN lote. El trabajo total es el mismo; lo que cambia es como se reparte.",
			"go_note":         "sync.RWMutex es lo mas simple del set y no tiene hambruna de escritor, pero no trae RLock con timeout: hay que armarlo con una goroutine y select, y la goroutine que quedo esperando sobrevive al lector que se rindio.",
		},
	}
}

func route(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	n := clamp(atoiOr(q.Get("rows"), 20000), 1000, 500000)
	readers := clamp(atoiOr(q.Get("readers"), 8), 1, 64)
	msPer1k := clamp(atoiOr(q.Get("ms_per_1k"), 20), 1, 200)
	batch := clamp(atoiOr(q.Get("batch"), 2000), 100, 100000)
	pauseMs := clamp(atoiOr(q.Get("pause_ms"), 5), 0, 200)

	status := http.StatusOK
	var payload any

	switch r.URL.Path {
	case "/", "/index":
		payload = map[string]any{
			"case":  caseName,
			"stack": stack,
			"go_specific": "sync.RWMutex sin hambruna de escritor, pero sin RLock con deadline: el timeout del lector se arma con goroutine + select.",
			"routes": []string{
				"/health", "/migrate-blocking?rows=20000&readers=8",
				"/migrate-expand-contract?rows=20000&readers=8&batch=2000&pause_ms=5",
				"/migration/state", "/backfill?batch=2000", "/diagnostics/summary", "/reset-lab",
			},
		}
	case "/health":
		payload = map[string]string{"status": "ok", "stack": stack, "case": caseName}
	case "/migrate-blocking":
		payload = runMigration("blocking", n, readers, msPer1k, batch, pauseMs)
	case "/migrate-expand-contract":
		payload = runMigration("expand_contract", n, readers, msPer1k, batch, pauseMs)
	case "/migration/state":
		payload = migrationState()
	case "/backfill":
		payload = backfillStep(batch, msPer1k)
	case "/diagnostics/summary":
		payload = diagnostics()
	case "/reset-lab":
		resetTable(n)
		metricsMu.Lock()
		metrics = freshMetrics()
		metricsMu.Unlock()
		payload = map[string]string{"status": "reset", "message": "Tabla, fase y metricas reiniciadas."}
	default:
		status = http.StatusNotFound
		payload = map[string]string{"error": "Ruta no encontrada", "path": r.URL.Path}
	}

	sendJSON(w, status, payload)
}

func main() {
	resetTable(20000)
	mux := http.NewServeMux()
	mux.HandleFunc("/", route)
	port := envOr("PORT", "8080")
	log.Printf("[case17-go] listening on %s", port)
	if err := http.ListenAndServe(":"+port, mux); err != nil {
		log.Fatalf("listen: %v", err)
	}
}

// ---------- helpers ----------

func msSince(t0 time.Time) float64 { return float64(time.Since(t0).Microseconds()) / 1000.0 }
func round2(v float64) float64     { return float64(int64(v*100+0.5)) / 100 }

func percentile(sorted []float64, pct int) float64 {
	if len(sorted) == 0 {
		return 0
	}
	idx := (pct*len(sorted)+99)/100 - 1
	if idx < 0 {
		idx = 0
	}
	if idx >= len(sorted) {
		idx = len(sorted) - 1
	}
	return round2(sorted[idx])
}

func max(a, b int) int {
	if a > b {
		return a
	}
	return b
}

func min(a, b int) int {
	if a < b {
		return a
	}
	return b
}

func max64(a, b int64) int64 {
	if a > b {
		return a
	}
	return b
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

