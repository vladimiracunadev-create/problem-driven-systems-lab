// Caso 01 — API lenta bajo carga (stack Go 1.23).
//
// Espejo del Main.java / Program.cs equivalentes: mismos endpoints, misma
// semantica, mismo shape de JSON.
//
// Substrato real: SQLite embebido via modernc.org/sqlite — driver escrito en Go
// puro, sin cgo. Eso permite compilar con CGO_ENABLED=0 y producir un binario
// estatico que corre en una imagen `scratch`. Es la razon de elegirlo por sobre
// mattn/go-sqlite3, que exige toolchain de C en la imagen final.
//
// Archivo bajo /tmp con journal_mode=WAL: el worker escribe customer_summary
// mientras los handlers leen, y con WAL los lectores no se bloquean con el
// escritor — el equivalente embebido del MVCC que da PostgreSQL en el stack PHP.
//
// Primitivas Go que aporta este stack, y que ningun otro del lab muestra:
//   - goroutine + time.Ticker para el worker (no hay pool de threads que
//     dimensionar: el runtime multiplexa goroutines sobre los OS threads).
//   - defer para el cierre de rows/stmt — el equivalente del try-with-resources
//     de Java y del using de C#, pero a nivel de funcion.
//   - sync/atomic para contadores lock-free.
//   - encoding/json con struct tags: el unico stack del lab que serializa el
//     contrato desde tipos en vez de concatenar strings a mano.
package main

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	_ "modernc.org/sqlite"
)

const (
	caseName            = "01 - API lenta bajo carga"
	workerName          = "report-refresh-go"
	summaryRefreshEvery = 5 * time.Second
	maxSamples          = 3000
	maxJobRuns          = 30
)

var (
	stack = envOr("APP_STACK", "Go 1.23")
	db    *sql.DB

	legacyMetrics    = &metrics{}
	optimizedMetrics = &metrics{}
)

// ---------- tipos del contrato ----------

type reportRow struct {
	OrderID  int     `json:"order_id"`
	Customer string  `json:"customer"`
	Tier     string  `json:"tier"`
	Region   string  `json:"region"`
	Amount   float64 `json:"amount"`
	// Solo la variante optimized los emite.
	LifetimeOrders *int64   `json:"lifetime_orders,omitempty"`
	LifetimeAmount *float64 `json:"lifetime_amount,omitempty"`
}

type reportResponse struct {
	Variant          string      `json:"variant"`
	Rows             []reportRow `json:"rows"`
	DBHits           int64       `json:"db_hits"`
	ElapsedMs        float64     `json:"elapsed_ms"`
	SummaryCacheSize *int        `json:"summary_cache_size,omitempty"`
	Note             string      `json:"note"`
}

type workerStateResponse struct {
	WorkerName     string `json:"worker_name"`
	LastStatus     string `json:"last_status"`
	LastDurationMs int64  `json:"last_duration_ms"`
	LastMessage    string `json:"last_message"`
	LastHeartbeat  string `json:"last_heartbeat"`
}

type jobRun struct {
	At                 string `json:"at"`
	Status             string `json:"status"`
	DurationMs         int64  `json:"duration_ms"`
	CustomersRefreshed int    `json:"customers_refreshed"`
}

type jobRunsResponse struct {
	Runs        []jobRun `json:"runs"`
	MaxRunsKept int      `json:"max_runs_kept"`
}

type metricsSnapshot struct {
	Label       string  `json:"label"`
	Requests    int64   `json:"requests"`
	SampleCount int     `json:"sample_count"`
	AvgMs       float64 `json:"avg_ms"`
	P95Ms       float64 `json:"p95_ms"`
	P99Ms       float64 `json:"p99_ms"`
}

// ---------- metricas ----------

type metrics struct {
	requests int64
	mu       sync.Mutex
	samples  []float64
}

func (m *metrics) record(elapsedMs float64) {
	atomic.AddInt64(&m.requests, 1)
	m.mu.Lock()
	defer m.mu.Unlock()
	m.samples = append(m.samples, elapsedMs)
	if len(m.samples) > maxSamples {
		m.samples = m.samples[len(m.samples)-maxSamples:]
	}
}

func (m *metrics) reset() {
	atomic.StoreInt64(&m.requests, 0)
	m.mu.Lock()
	defer m.mu.Unlock()
	m.samples = nil
}

func (m *metrics) snapshot(label string) metricsSnapshot {
	m.mu.Lock()
	snap := append([]float64(nil), m.samples...)
	m.mu.Unlock()
	return metricsSnapshot{
		Label:       label,
		Requests:    atomic.LoadInt64(&m.requests),
		SampleCount: len(snap),
		AvgMs:       avg(snap),
		P95Ms:       percentile(snap, 95),
		P99Ms:       percentile(snap, 99),
	}
}

// ---------- arranque ----------

func main() {
	storageDir := filepath.Join(os.TempDir(), "pdsl-case01-go")
	if err := os.MkdirAll(storageDir, 0o755); err != nil {
		log.Fatalf("no se pudo crear %s: %v", storageDir, err)
	}
	dbPath := filepath.Join(storageDir, "case01.sqlite3")
	// Arranque limpio y determinista: se borra la DB y los sidecars de WAL.
	for _, suffix := range []string{"", "-wal", "-shm"} {
		_ = os.Remove(dbPath + suffix)
	}

	var err error
	db, err = sql.Open("sqlite", dbPath+"?_pragma=journal_mode(WAL)&_pragma=busy_timeout(5000)")
	if err != nil {
		log.Fatalf("no se pudo abrir sqlite: %v", err)
	}
	defer db.Close()

	if err := initSchema(); err != nil {
		log.Fatalf("initSchema: %v", err)
	}
	if err := seedData(); err != nil {
		log.Fatalf("seedData: %v", err)
	}
	refreshSummary()

	// Worker: goroutine + Ticker. No hay pool que dimensionar ni shutdown hook
	// que registrar — la goroutine muere con el proceso.
	go func() {
		ticker := time.NewTicker(summaryRefreshEvery)
		defer ticker.Stop()
		for range ticker.C {
			refreshSummary()
		}
	}()

	mux := http.NewServeMux()
	mux.HandleFunc("/", route)

	port := envOr("PORT", "8080")
	log.Printf("[case01-go] listening on %s", port)
	if err := http.ListenAndServe(":"+port, mux); err != nil {
		log.Fatalf("listen: %v", err)
	}
}

// ---------- routing ----------

func route(w http.ResponseWriter, r *http.Request) {
	start := time.Now()
	path := r.URL.Path
	limit := bounded(r.URL.Query().Get("limit"), 20, 1, 200)

	var (
		status  = http.StatusOK
		payload any
		err     error
		tracked *metrics
	)

	switch path {
	case "/", "/index":
		payload = indexPayload()
	case "/health":
		payload = map[string]string{"status": "ok", "stack": stack, "case": caseName}
	case "/report-legacy":
		payload, err = reportLegacy(limit)
		tracked = legacyMetrics
	case "/report-optimized":
		payload, err = reportOptimized(limit)
		tracked = optimizedMetrics
	case "/batch/status":
		payload, err = workerStateJSON()
	case "/job-runs":
		payload, err = jobRunsJSON()
	case "/diagnostics/summary":
		payload, err = diagnosticsJSON()
	case "/metrics":
		payload = map[string]any{
			"legacy":    legacyMetrics.snapshot("legacy"),
			"optimized": optimizedMetrics.snapshot("optimized"),
		}
	case "/reset-lab":
		legacyMetrics.reset()
		optimizedMetrics.reset()
		_, err = db.Exec("DELETE FROM job_runs")
		payload = map[string]string{"status": "reset", "stack": stack}
	default:
		status = http.StatusNotFound
		payload = map[string]string{"error": "not_found", "path": path}
	}

	if err != nil {
		status = http.StatusInternalServerError
		payload = map[string]string{"error": "internal", "detail": err.Error()}
	}

	elapsedMs := round2(float64(time.Since(start).Microseconds()) / 1000.0)
	if tracked != nil {
		tracked.record(elapsedMs)
	}
	sendJSON(w, status, payload)
}

func indexPayload() map[string]any {
	return map[string]any{
		"lab":       "Problem-Driven Systems Lab",
		"case":      caseName,
		"stack":     stack,
		"substrate": "SQLite embebido via modernc.org/sqlite (Go puro, sin cgo; WAL, archivo en /tmp)",
		"native_primitives": []string{
			"goroutine + time.Ticker (worker)",
			"defer (cierre de rows/stmt)",
			"sync/atomic (counters)",
			"encoding/json con struct tags (contrato tipado)",
		},
		"routes": map[string]string{
			"/health":                    "liveness check",
			"/report-legacy?limit=20":    "filtro no sargable (LOWER sobre la columna) + N+1 real",
			"/report-optimized?limit=20": "rango sargable + batch IN(...) + lectura de customer_summary",
			"/batch/status":              "estado del worker",
			"/job-runs":                  "historial de corridas del worker",
			"/diagnostics/summary":       "contraste legacy vs optimized",
			"/metrics":                   "avg/p95/p99 por ruta",
			"/reset-lab":                 "reinicia contadores e historico",
		},
	}
}

// ---------- endpoints ----------

// reportLegacy: filtro no sargable — LOWER(region) envuelve la columna e impide
// usar idx_orders_region, el motor recorre la tabla entera. Despues, N+1 real:
// una query dependiente por cada fila devuelta.
func reportLegacy(limit int) (*reportResponse, error) {
	var dbHits int64
	start := time.Now()

	rows, err := db.Query(
		`SELECT id, customer_id, region, amount FROM orders
		 WHERE LOWER(region) LIKE 'n%' ORDER BY id LIMIT ?`, limit)
	if err != nil {
		return nil, err
	}
	type raw struct {
		id, customerID int
		region         string
		amount         float64
	}
	var raws []raw
	for rows.Next() {
		var x raw
		if err := rows.Scan(&x.id, &x.customerID, &x.region, &x.amount); err != nil {
			rows.Close()
			return nil, err
		}
		raws = append(raws, x)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()
	dbHits++

	out := make([]reportRow, 0, len(raws))
	for _, x := range raws {
		var name, tier string
		// QueryRow + Scan: una ejecucion real por fila. Esto es el N+1.
		err := db.QueryRow("SELECT name, tier FROM customers WHERE id = ?", x.customerID).
			Scan(&name, &tier)
		if err != nil && err != sql.ErrNoRows {
			return nil, err
		}
		dbHits++
		out = append(out, reportRow{
			OrderID: x.id, Customer: name, Tier: tier, Region: x.region, Amount: x.amount,
		})
	}

	return &reportResponse{
		Variant:   "legacy",
		Rows:      out,
		DBHits:    dbHits,
		ElapsedMs: round2(float64(time.Since(start).Microseconds()) / 1000.0),
		Note:      "LOWER(region) invalida el indice + N+1 real: 1 + N queries contra SQLite.",
	}, nil
}

// reportOptimized: el mismo filtro reescrito como rango sargable (usa
// idx_orders_region), dos batches IN(...) y lectura de customer_summary que el
// worker mantiene. Queries constantes, no 1+N.
func reportOptimized(limit int) (*reportResponse, error) {
	var dbHits int64
	start := time.Now()

	rows, err := db.Query(
		`SELECT id, customer_id, region, amount FROM orders
		 WHERE region >= 'n' AND region < 'o' ORDER BY id LIMIT ?`, limit)
	if err != nil {
		return nil, err
	}
	type raw struct {
		id, customerID int
		region         string
		amount         float64
	}
	var raws []raw
	for rows.Next() {
		var x raw
		if err := rows.Scan(&x.id, &x.customerID, &x.region, &x.amount); err != nil {
			rows.Close()
			return nil, err
		}
		raws = append(raws, x)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()
	dbHits++

	customerBatch := map[int][2]string{}
	summaryBatch := map[int]struct {
		count  int64
		amount float64
	}{}

	if len(raws) > 0 {
		ids := make([]any, 0, len(raws))
		for _, x := range raws {
			ids = append(ids, x.customerID)
		}
		placeholders := strings.TrimRight(strings.Repeat("?,", len(ids)), ",")

		cRows, err := db.Query(
			fmt.Sprintf("SELECT id, name, tier FROM customers WHERE id IN (%s)", placeholders), ids...)
		if err != nil {
			return nil, err
		}
		for cRows.Next() {
			var id int
			var name, tier string
			if err := cRows.Scan(&id, &name, &tier); err != nil {
				cRows.Close()
				return nil, err
			}
			customerBatch[id] = [2]string{name, tier}
		}
		cRows.Close()
		dbHits++

		sRows, err := db.Query(
			fmt.Sprintf("SELECT customer_id, order_count, total_amount FROM customer_summary WHERE customer_id IN (%s)", placeholders), ids...)
		if err != nil {
			return nil, err
		}
		for sRows.Next() {
			var id int
			var count int64
			var amount float64
			if err := sRows.Scan(&id, &count, &amount); err != nil {
				sRows.Close()
				return nil, err
			}
			summaryBatch[id] = struct {
				count  int64
				amount float64
			}{count, amount}
		}
		sRows.Close()
		dbHits++
	}

	out := make([]reportRow, 0, len(raws))
	for _, x := range raws {
		c := customerBatch[x.customerID]
		s := summaryBatch[x.customerID]
		count, amount := s.count, s.amount
		out = append(out, reportRow{
			OrderID: x.id, Customer: c[0], Tier: c[1], Region: x.region, Amount: x.amount,
			LifetimeOrders: &count, LifetimeAmount: &amount,
		})
	}

	summarySize, err := countRows("customer_summary")
	if err != nil {
		return nil, err
	}
	dbHits++

	return &reportResponse{
		Variant:          "optimized",
		Rows:             out,
		DBHits:           dbHits,
		ElapsedMs:        round2(float64(time.Since(start).Microseconds()) / 1000.0),
		SummaryCacheSize: &summarySize,
		Note:             "Rango sargable + 2 batches IN(...) + customer_summary mantenida por el worker.",
	}, nil
}

func workerStateJSON() (*workerStateResponse, error) {
	var out workerStateResponse
	out.WorkerName = workerName
	err := db.QueryRow(
		`SELECT last_status, last_duration_ms, COALESCE(last_message, ''), COALESCE(last_heartbeat, '')
		 FROM worker_state WHERE worker_name = ?`, workerName).
		Scan(&out.LastStatus, &out.LastDurationMs, &out.LastMessage, &out.LastHeartbeat)
	if err == sql.ErrNoRows {
		return &workerStateResponse{WorkerName: workerName, LastStatus: "unknown", LastDurationMs: -1}, nil
	}
	if err != nil {
		return nil, err
	}
	return &out, nil
}

func jobRunsJSON() (*jobRunsResponse, error) {
	rows, err := db.Query(
		`SELECT at, status, duration_ms, customers_refreshed FROM job_runs
		 ORDER BY id DESC LIMIT ?`, maxJobRuns)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	runs := []jobRun{}
	for rows.Next() {
		var jr jobRun
		if err := rows.Scan(&jr.At, &jr.Status, &jr.DurationMs, &jr.CustomersRefreshed); err != nil {
			return nil, err
		}
		runs = append(runs, jr)
	}
	return &jobRunsResponse{Runs: runs, MaxRunsKept: maxJobRuns}, rows.Err()
}

func diagnosticsJSON() (map[string]any, error) {
	summarySize, err := countRows("customer_summary")
	if err != nil {
		return nil, err
	}
	worker, err := workerStateJSON()
	if err != nil {
		return nil, err
	}
	return map[string]any{
		"stack":              stack,
		"case":               caseName,
		"substrate":          "SQLite embebido (modernc.org/sqlite, Go puro sin cgo, WAL)",
		"legacy":             legacyMetrics.snapshot("legacy"),
		"optimized":          optimizedMetrics.snapshot("optimized"),
		"summary_cache_size": summarySize,
		"worker":             worker,
	}, nil
}

// ---------- worker ----------

// refreshSummary: DELETE + INSERT ... SELECT reales dentro de una transaccion.
// Gracias a WAL los lectores siguen respondiendo mientras esta transaccion
// escribe — sin WAL, cada lectura concurrente quedaria bloqueada, que es
// precisamente el fallo que este caso enseña a evitar.
func refreshSummary() {
	start := time.Now()
	tx, err := db.Begin()
	if err != nil {
		log.Printf("[case01-go] worker error (begin): %v", err)
		return
	}
	defer tx.Rollback() //nolint: no-op si el Commit ya ocurrio

	if _, err := tx.Exec("DELETE FROM customer_summary"); err != nil {
		log.Printf("[case01-go] worker error (delete): %v", err)
		return
	}
	res, err := tx.Exec(
		`INSERT INTO customer_summary (customer_id, order_count, total_amount, refreshed_at)
		 SELECT customer_id, COUNT(*), ROUND(SUM(amount), 2), strftime('%s','now')
		 FROM orders GROUP BY customer_id`)
	if err != nil {
		log.Printf("[case01-go] worker error (insert): %v", err)
		return
	}
	refreshed, _ := res.RowsAffected()
	durMs := time.Since(start).Milliseconds()
	now := time.Now().UTC().Format(time.RFC3339Nano)

	if _, err := tx.Exec(
		`UPDATE worker_state SET last_status = ?, last_duration_ms = ?, last_message = ?, last_heartbeat = ?
		 WHERE worker_name = ?`,
		"ok", durMs, fmt.Sprintf("refreshed %d customer summaries", refreshed), now, workerName); err != nil {
		log.Printf("[case01-go] worker error (state): %v", err)
		return
	}
	if _, err := tx.Exec(
		`INSERT INTO job_runs (at, status, duration_ms, customers_refreshed) VALUES (?, ?, ?, ?)`,
		now, "ok", durMs, refreshed); err != nil {
		log.Printf("[case01-go] worker error (run): %v", err)
		return
	}
	if _, err := tx.Exec(
		`DELETE FROM job_runs WHERE id NOT IN (SELECT id FROM job_runs ORDER BY id DESC LIMIT ?)`,
		maxJobRuns); err != nil {
		log.Printf("[case01-go] worker error (trim): %v", err)
		return
	}
	if err := tx.Commit(); err != nil {
		log.Printf("[case01-go] worker error (commit): %v", err)
	}
}

// ---------- schema y seed ----------

func initSchema() error {
	_, err := db.Exec(`
		CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT NOT NULL, tier TEXT NOT NULL);
		CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL, region TEXT NOT NULL, amount REAL NOT NULL);
		CREATE TABLE customer_summary (customer_id INTEGER PRIMARY KEY, order_count INTEGER NOT NULL, total_amount REAL NOT NULL, refreshed_at INTEGER NOT NULL);
		CREATE TABLE worker_state (worker_name TEXT PRIMARY KEY, last_status TEXT NOT NULL, last_duration_ms INTEGER NOT NULL, last_message TEXT, last_heartbeat TEXT);
		CREATE TABLE job_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, status TEXT NOT NULL, duration_ms INTEGER NOT NULL, customers_refreshed INTEGER NOT NULL);
		-- El indice que la ruta legacy desperdicia al envolver la columna en LOWER().
		CREATE INDEX idx_orders_region ON orders (region, id);
		CREATE INDEX idx_orders_customer ON orders (customer_id);
	`)
	return err
}

// seedData usa el mismo LCG y los mismos parametros que Java y .NET, por lo que
// los tres stacks producen exactamente el mismo dataset.
func seedData() error {
	regions := []string{"north", "south", "east", "west"}
	tiers := []string{"bronze", "silver", "gold"}
	seed := int64(102030)

	tx, err := db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()

	insCustomer, err := tx.Prepare("INSERT INTO customers VALUES (?, ?, ?)")
	if err != nil {
		return err
	}
	defer insCustomer.Close()
	for i := 1; i <= 1600; i++ {
		seed = (seed*9301 + 49297) % 233280
		if _, err := insCustomer.Exec(i, fmt.Sprintf("Customer %d", i), tiers[seed%int64(len(tiers))]); err != nil {
			return err
		}
	}

	insOrder, err := tx.Prepare("INSERT INTO orders VALUES (?, ?, ?, ?)")
	if err != nil {
		return err
	}
	defer insOrder.Close()
	for i := 1; i <= 4800; i++ {
		seed = (seed*9301 + 49297) % 233280
		cid := 1 + int(seed%1600)
		region := regions[(seed/7)%int64(len(regions))]
		amount := round2(20.0 + float64(seed%1000))
		if _, err := insOrder.Exec(i, cid, region, amount); err != nil {
			return err
		}
	}

	if _, err := tx.Exec("INSERT INTO worker_state VALUES (?, ?, ?, ?, ?)",
		workerName, "init", -1, "worker not started yet", ""); err != nil {
		return err
	}
	return tx.Commit()
}

func countRows(table string) (int, error) {
	var n int
	// `table` no viene de input externo — es una constante de este archivo.
	err := db.QueryRow("SELECT COUNT(*) FROM " + table).Scan(&n)
	return n, err
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

func round2(v float64) float64 {
	return float64(int64(v*100+0.5)) / 100.0
}

func avg(values []float64) float64 {
	if len(values) == 0 {
		return 0
	}
	var sum float64
	for _, v := range values {
		sum += v
	}
	return round2(sum / float64(len(values)))
}

func percentile(values []float64, percent int) float64 {
	if len(values) == 0 {
		return 0
	}
	ordered := append([]float64(nil), values...)
	sort.Float64s(ordered)
	idx := int(float64(percent)/100.0*float64(len(ordered))+0.999999) - 1
	if idx < 0 {
		idx = 0
	}
	if idx >= len(ordered) {
		idx = len(ordered) - 1
	}
	return round2(ordered[idx])
}
