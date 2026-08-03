// Caso 02 — N+1 queries y cuellos de botella DB (stack Go 1.23).
//
// Espejo del Main.java / Program.cs equivalentes: mismos endpoints, mismo shape
// de JSON, mismo dataset.
//
// Substrato real: SQLite embebido via modernc.org/sqlite — port de SQLite a Go
// puro, sin cgo. `db_hits` cuenta ejecuciones reales contra el motor: 1+N en la
// ruta legacy, 2 en la optimizada.
//
// Lo que este stack aporta frente a los otros del lab: Go no tiene ORM en la
// stdlib. `database/sql` obliga a escribir el SQL a mano, asi que el N+1 aca no
// puede aparecer "por accidente" como lo genera un Hibernate o un Entity
// Framework al iterar una coleccion lazy — hay que escribirlo explicitamente.
// El caso lo escribe a proposito para medirlo.
package main

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	_ "modernc.org/sqlite"
)

const (
	caseName   = "02 - N+1 queries y cuellos de botella DB"
	maxSamples = 3000
)

var (
	stack = envOr("APP_STACK", "Go 1.23")
	db    *sql.DB

	legacyMetrics    = &metrics{}
	optimizedMetrics = &metrics{}
)

// ---------- tipos del contrato ----------

type item struct {
	SKU string `json:"sku"`
	Qty int    `json:"qty"`
}

type orderRow struct {
	OrderID    int    `json:"order_id"`
	CustomerID int    `json:"customer_id"`
	ItemCount  int    `json:"item_count"`
	Items      []item `json:"items"`
}

type ordersResponse struct {
	Variant   string     `json:"variant"`
	Rows      []orderRow `json:"rows"`
	DBHits    int64      `json:"db_hits"`
	ElapsedMs float64    `json:"elapsed_ms"`
	Note      string     `json:"note"`
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
	var err error
	// `:memory:` con cache compartida: una sola DB para todas las goroutines
	// del servidor. Sin `cache=shared`, cada conexion del pool abriria su
	// propia base vacia.
	db, err = sql.Open("sqlite", "file:case02?mode=memory&cache=shared")
	if err != nil {
		log.Fatalf("no se pudo abrir sqlite: %v", err)
	}
	defer db.Close()
	// Mantener al menos una conexion viva: si el pool las cierra todas, la DB
	// en memoria se destruye.
	db.SetMaxIdleConns(4)
	db.SetConnMaxLifetime(0)

	if err := initSchema(); err != nil {
		log.Fatalf("initSchema: %v", err)
	}
	if err := seedData(); err != nil {
		log.Fatalf("seedData: %v", err)
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/", route)

	port := envOr("PORT", "8080")
	log.Printf("[case02-go] listening on %s", port)
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
		payload = map[string]any{
			"case":  caseName,
			"stack": stack,
			"routes": []string{
				"/health", "/orders-legacy?limit=20", "/orders-optimized?limit=20",
				"/diagnostics/summary", "/metrics", "/reset-lab",
			},
		}
	case "/health":
		payload = map[string]string{"status": "ok", "stack": stack, "case": caseName}
	case "/orders-legacy":
		payload, err = ordersLegacy(limit)
		tracked = legacyMetrics
	case "/orders-optimized":
		payload, err = ordersOptimized(limit)
		tracked = optimizedMetrics
	case "/diagnostics/summary":
		payload, err = diagnosticsSummary()
	case "/metrics":
		payload = map[string]any{
			"legacy":    legacyMetrics.snapshot("legacy"),
			"optimized": optimizedMetrics.snapshot("optimized"),
		}
	case "/reset-lab":
		legacyMetrics.reset()
		optimizedMetrics.reset()
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

// ---------- endpoints ----------

type orderKey struct{ id, customerID int }

func selectOrders(limit int) ([]orderKey, error) {
	rows, err := db.Query("SELECT id, customer_id FROM orders ORDER BY id ASC LIMIT ?", limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []orderKey
	for rows.Next() {
		var k orderKey
		if err := rows.Scan(&k.id, &k.customerID); err != nil {
			return nil, err
		}
		out = append(out, k)
	}
	return out, rows.Err()
}

// ordersLegacy: 1 SELECT orders + N SELECT items (uno por order). Es el
// anti-patron que un ORM genera al iterar una coleccion lazy sin JOIN FETCH;
// en Go hay que escribirlo a mano, y aca se escribe para medirlo.
func ordersLegacy(limit int) (*ordersResponse, error) {
	start := time.Now()
	var dbHits int64

	orders, err := selectOrders(limit)
	if err != nil {
		return nil, err
	}
	dbHits++

	out := make([]orderRow, 0, len(orders))
	for _, o := range orders {
		rows, err := db.Query(
			"SELECT sku, qty FROM order_items WHERE order_id = ? ORDER BY id ASC", o.id)
		if err != nil {
			return nil, err
		}
		dbHits++
		items := []item{}
		for rows.Next() {
			var it item
			if err := rows.Scan(&it.SKU, &it.Qty); err != nil {
				rows.Close()
				return nil, err
			}
			items = append(items, it)
		}
		if err := rows.Err(); err != nil {
			rows.Close()
			return nil, err
		}
		rows.Close()
		out = append(out, orderRow{
			OrderID: o.id, CustomerID: o.customerID, ItemCount: len(items), Items: items,
		})
	}

	return &ordersResponse{
		Variant:   "legacy",
		Rows:      out,
		DBHits:    dbHits,
		ElapsedMs: round2(float64(time.Since(start).Microseconds()) / 1000.0),
		Note:      "1 SELECT orders + N SELECT items (uno por order).",
	}, nil
}

// ordersOptimized: 1 SELECT orders + 1 SELECT items con IN(...) batch.
// db_hits queda en 2 sin importar el limit.
func ordersOptimized(limit int) (*ordersResponse, error) {
	start := time.Now()
	var dbHits int64

	orders, err := selectOrders(limit)
	if err != nil {
		return nil, err
	}
	dbHits++

	itemsByOrder := map[int][]item{}
	if len(orders) > 0 {
		ids := make([]any, 0, len(orders))
		for _, o := range orders {
			ids = append(ids, o.id)
		}
		placeholders := strings.TrimRight(strings.Repeat("?,", len(ids)), ",")
		rows, err := db.Query(fmt.Sprintf(
			"SELECT order_id, sku, qty FROM order_items WHERE order_id IN (%s) ORDER BY id ASC",
			placeholders), ids...)
		if err != nil {
			return nil, err
		}
		defer rows.Close()
		dbHits++
		for rows.Next() {
			var oid int
			var it item
			if err := rows.Scan(&oid, &it.SKU, &it.Qty); err != nil {
				return nil, err
			}
			itemsByOrder[oid] = append(itemsByOrder[oid], it)
		}
		if err := rows.Err(); err != nil {
			return nil, err
		}
	}

	out := make([]orderRow, 0, len(orders))
	for _, o := range orders {
		items := itemsByOrder[o.id]
		if items == nil {
			items = []item{}
		}
		out = append(out, orderRow{
			OrderID: o.id, CustomerID: o.customerID, ItemCount: len(items), Items: items,
		})
	}

	return &ordersResponse{
		Variant:   "optimized",
		Rows:      out,
		DBHits:    dbHits,
		ElapsedMs: round2(float64(time.Since(start).Microseconds()) / 1000.0),
		Note:      "1 SELECT orders + 1 SELECT items con IN(...) batch.",
	}, nil
}

func diagnosticsSummary() (map[string]any, error) {
	customers, err := scalarInt("SELECT COUNT(*) FROM customers")
	if err != nil {
		return nil, err
	}
	categories, err := scalarInt("SELECT COUNT(*) FROM categories")
	if err != nil {
		return nil, err
	}
	ordersCount, err := scalarInt("SELECT COUNT(*) FROM orders")
	if err != nil {
		return nil, err
	}
	itemsCount, err := scalarInt("SELECT COUNT(*) FROM order_items")
	if err != nil {
		return nil, err
	}
	avgItems := 0.0
	if ordersCount != 0 {
		avgItems = round2(float64(itemsCount) / float64(ordersCount))
	}
	return map[string]any{
		"stack":                stack,
		"case":                 caseName,
		"customers_total":      customers,
		"categories_total":     categories,
		"orders_total":         ordersCount,
		"items_total":          itemsCount,
		"avg_items_per_order":  avgItems,
		"legacy":               legacyMetrics.snapshot("legacy"),
		"optimized":            optimizedMetrics.snapshot("optimized"),
	}, nil
}

// ---------- schema y seed ----------

func initSchema() error {
	_, err := db.Exec(`
		CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
		CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT NOT NULL, region TEXT NOT NULL, category_id INTEGER NOT NULL);
		CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL, total REAL NOT NULL, created_at INTEGER NOT NULL);
		CREATE TABLE order_items (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, sku TEXT NOT NULL, qty INTEGER NOT NULL, price REAL NOT NULL);
		CREATE INDEX idx_items_order_id ON order_items (order_id);
	`)
	return err
}

// seedData replica el LCG y los parametros del stack Java, por lo que el
// dataset generado es identico fila por fila.
func seedData() error {
	seed := int64(270718)
	regions := []string{"LATAM", "NA", "EMEA", "APAC"}
	now := time.Now().Unix()

	tx, err := db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()

	for i := 1; i <= 24; i++ {
		if _, err := tx.Exec("INSERT INTO categories VALUES (?, ?)", i, fmt.Sprintf("Category %d", i)); err != nil {
			return err
		}
	}
	for i := 1; i <= 900; i++ {
		seed = (seed*9301 + 49297) % 233280
		if _, err := tx.Exec("INSERT INTO customers VALUES (?, ?, ?, ?)",
			i, fmt.Sprintf("Customer %d", i), regions[seed%int64(len(regions))], 1+((i-1)%24)); err != nil {
			return err
		}
	}

	itemID := 1
	for orderID := 1; orderID <= 1500; orderID++ {
		seed = (seed*9301 + 49297) % 233280
		cid := 1 + int(seed%900)
		seed = (seed*9301 + 49297) % 233280
		createdAt := now - (seed % (120 * 86400))
		itemsPerOrder := 2 + int(seed%4) // 2..5

		type pending struct {
			id, orderID int
			sku         string
			qty         int
			price       float64
		}
		var items []pending
		total := 0.0
		for k := 0; k < itemsPerOrder; k++ {
			seed = (seed*9301 + 49297) % 233280
			sku := fmt.Sprintf("SKU-%d", 1000+int(seed%9000))
			qty := 1 + int(seed%8)
			seed = (seed*9301 + 49297) % 233280
			price := round2(10.0 + float64(seed%233280)/233280.0*220.0)
			total += float64(qty) * price
			items = append(items, pending{itemID, orderID, sku, qty, price})
			itemID++
		}

		if _, err := tx.Exec("INSERT INTO orders VALUES (?, ?, ?, ?)",
			orderID, cid, round2(total), createdAt); err != nil {
			return err
		}
		for _, it := range items {
			if _, err := tx.Exec("INSERT INTO order_items VALUES (?, ?, ?, ?, ?)",
				it.id, it.orderID, it.sku, it.qty, it.price); err != nil {
				return err
			}
		}
	}
	return tx.Commit()
}

func scalarInt(query string) (int, error) {
	var n int
	err := db.QueryRow(query).Scan(&n)
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
