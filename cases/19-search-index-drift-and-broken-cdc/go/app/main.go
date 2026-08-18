// Caso 19 — Deriva del indice de busqueda y CDC roto — stack Go 1.23.
//
// Dual-write: la aplicacion escribe en la base y despues en el indice. Cuando la
// segunda escritura falla —y falla, porque son dos sistemas sin transaccion
// comun— nadie se entera. La busqueda sigue respondiendo 200; lo que devuelve
// esta mal.
//
// Outbox + checkpoint + reconciliacion: el cambio se anota junto con la escritura
// a la base, el consumidor aplica en orden y solo avanza el checkpoint cuando la
// aplicacion se confirma, y un barrido repara lo que los dos primeros no cubren.
//
// Las tres formas de deriva, que no son la misma cosa:
//
//	missing  — esta en la base, no en el indice      → la busqueda no lo encuentra
//	stale    — esta en los dos, con version vieja    → la busqueda lo encuentra mal
//	orphan   — esta en el indice, borrado en la base → la busqueda devuelve fantasmas
//
// # Primitiva Go distintiva — dos, y tiran para lados opuestos
//
// **A favor: el error es un valor, y descartarlo se ve.** La escritura al indice
// devuelve `error`, y la unica forma de ignorarlo es escribirlo:
//
//	if err := indice.Escribir(doc); err != nil { ... }   // manejado
//	_ = indice.Escribir(doc)                             // descartado, y VISIBLE
//	indice.Escribir(doc)                                 // `errcheck` lo marca
//
// El guion bajo no es azucar: es una declaracion de intencion que queda en el
// diff y que cualquiera puede buscar con grep. `errcheck` —que esta en casi
// todos los CI de Go— convierte la tercera linea en un build rojo.
//
// **En contra: Go no tiene tipo conjunto.** La deriva de tres caras, que en
// Python son tres lineas de algebra de conjuntos y en .NET tres llamadas LINQ,
// aca son tres recorridos escritos a mano con `map[string]struct{}`. Es mas
// codigo, es mas facil equivocarse en un caso borde, y no hay biblioteca
// estandar que lo evite. Igual que Python no tiene read-write lock en el
// [caso 17], Go no tiene conjuntos — y la ausencia se paga en el mismo lugar:
// codigo propio donde deberia haber una primitiva.
package main

import (
	"encoding/json"
	"fmt"
	"math"
	"net/http"
	"os"
	"sort"
	"strconv"
	"sync"
	"time"
)

var (
	appStack = envOr("APP_STACK", "Go 1.23")
	caseName = "19 - Deriva del indice de busqueda y CDC roto"
	terms    = []string{"alfa", "beta", "gamma", "delta", "epsilon", "zeta", "eta", "theta"}
	start    = time.Now()
)

func envOr(k, d string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return d
}

func nowMs() float64 { return float64(time.Since(start).Nanoseconds()) / 1e6 }

type doc struct {
	Version   int
	Term      string
	Deleted   bool
	UpdatedMs float64
}

type idxEntry struct {
	Version int
	Term    string
}

type change struct {
	Seq     int
	ID      string
	Version int
	Term    string
	Deleted bool
	AtMs    float64
}

type slot struct {
	Runs           int `json:"runs"`
	Writes         int `json:"writes"`
	SilentFailures int `json:"silent_failures"`
	DriftCount     int `json:"drift_count"`
	OutboxRetried  int `json:"outbox_retried"`
}

var (
	mu         sync.Mutex
	db         = map[string]doc{}
	index      = map[string]idxEntry{}
	outbox     []change
	checkpoint int
	seq        int
	metrics    = map[string]*slot{"drifted": {}, "reconciled": {}}
)

func resetAll() {
	db = map[string]doc{}
	index = map[string]idxEntry{}
	outbox = nil
	checkpoint = 0
	seq = 0
}

func round2(v float64) float64 { return math.Round(v*100) / 100 }

// indexWriteFails: el indice rechaza una fraccion de las escrituras.
//
// El modulo 101 —primo— importa: con 100, las dos escrituras del mismo documento
// (i e i+keyspace) caen en el mismo residuo y corren siempre la misma suerte,
// asi que nunca se produce deriva `stale`. Con 101 se separan.
func indexWriteFails(idx, failRate int) bool { return (idx*37)%101 < failRate }

// escribirIndice es la escritura al segundo sistema. Devuelve `error` a
// proposito: es lo que permite que descartarlo tenga que escribirse.
func escribirIndice(id string, e idxEntry, borrar bool, idx, failRate int) error {
	if indexWriteFails(idx, failRate) {
		return fmt.Errorf("indice rechazo la escritura de %s", id)
	}
	if borrar {
		delete(index, id)
	} else {
		index[id] = e
	}
	return nil
}

// ---------------------------------------------------------------------------
// Variante dual-write: escribir en la base, escribir en el indice, y rezar
// ---------------------------------------------------------------------------

func runDrifted(writes, failRate, deletePct int) int {
	mu.Lock()
	defer mu.Unlock()
	resetAll()
	keyspace := writes / 2
	if keyspace < 1 {
		keyspace = 1
	}
	silent := 0

	for i := 0; i < writes; i++ {
		id := fmt.Sprintf("doc-%d", i%keyspace)
		term := terms[i%len(terms)]
		deleting := (i*53)%101 < deletePct

		version := 1
		if prev, ok := db[id]; ok {
			version = prev.Version + 1
		}
		db[id] = doc{Version: version, Term: term, Deleted: deleting, UpdatedMs: nowMs()}

		// AQUI ESTA EL BUG, y en Go hay que escribirlo: el `_ =` descarta el
		// error de forma visible. Queda en el diff, se encuentra con grep, y
		// `errcheck` marcaria la version sin guion bajo.
		if err := escribirIndice(id, idxEntry{version, term}, deleting, i, failRate); err != nil {
			_ = err
			silent++
		}
	}
	return silent
}

// ---------------------------------------------------------------------------
// Variante outbox + checkpoint + reconciliacion
// ---------------------------------------------------------------------------

func runReconciled(writes, failRate, deletePct int) int {
	mu.Lock()
	resetAll()
	keyspace := writes / 2
	if keyspace < 1 {
		keyspace = 1
	}

	for i := 0; i < writes; i++ {
		id := fmt.Sprintf("doc-%d", i%keyspace)
		term := terms[i%len(terms)]
		deleting := (i*53)%101 < deletePct

		version := 1
		if prev, ok := db[id]; ok {
			version = prev.Version + 1
		}
		db[id] = doc{Version: version, Term: term, Deleted: deleting, UpdatedMs: nowMs()}
		// El cambio se anota JUNTO con la escritura, bajo el mismo lock. Si el
		// indice esta caido, el cambio no se pierde: queda escrito.
		seq++
		outbox = append(outbox, change{seq, id, version, term, deleting, nowMs()})
	}
	mu.Unlock()
	return drainOutbox(failRate, 5)
}

// drainOutbox aplica los cambios pendientes al indice, en orden, reintentando.
//
//   - En orden: saltear un cambio dejaria una version vieja pisando a una nueva.
//   - El checkpoint avanza solo con la confirmacion: si un cambio no entra
//     despues de maxRetries, el consumidor se frena. El cambio queda pendiente,
//     no perdido — que es exactamente lo que el dual-write no puede hacer.
func drainOutbox(failRate, maxRetries int) int {
	mu.Lock()
	defer mu.Unlock()
	retried := 0
	for _, entry := range outbox {
		if entry.Seq <= checkpoint {
			continue
		}
		applied := false
		for attempt := 0; attempt < maxRetries; attempt++ {
			err := escribirIndice(entry.ID, idxEntry{entry.Version, entry.Term}, entry.Deleted,
				entry.Seq*(attempt+1)+attempt, failRate)
			if err != nil {
				retried++
				continue
			}
			applied = true
			break
		}
		if !applied {
			break // el checkpoint se frena: el cambio queda pendiente
		}
		checkpoint = entry.Seq
	}
	return retried
}

// ---------------------------------------------------------------------------
// La deriva de tres caras, sin tipo conjunto: tres recorridos a mano
// ---------------------------------------------------------------------------

func computeDriftLocked() map[string]any {
	dbLive := map[string]doc{}
	for k, v := range db {
		if !v.Deleted {
			dbLive[k] = v
		}
	}

	var missing, stale, orphan []string
	for id, d := range dbLive {
		cur, ok := index[id]
		if !ok {
			missing = append(missing, id)
		} else if cur.Version != d.Version {
			stale = append(stale, id)
		}
	}
	for id := range index {
		if _, ok := dbLive[id]; !ok {
			orphan = append(orphan, id)
		}
	}

	now := nowMs()
	oldest := 0.0
	for _, id := range append(append([]string{}, missing...), stale...) {
		if age := now - dbLive[id].UpdatedMs; age > oldest {
			oldest = age
		}
	}

	pending := 0
	for _, e := range outbox {
		if e.Seq > checkpoint {
			pending++
		}
	}

	sort.Strings(missing)
	sort.Strings(orphan)
	return map[string]any{
		"db_count":        len(dbLive),
		"index_count":     len(index),
		"missing":         len(missing),
		"stale":           len(stale),
		"orphan":          len(orphan),
		"drift_count":     len(missing) + len(stale) + len(orphan),
		"drift_age_ms":    round2(oldest),
		"missing_ids":     firstN(missing, 8),
		"orphan_ids":      firstN(orphan, 8),
		"last_checkpoint": checkpoint,
		"outbox_pending":  pending,
	}
}

func firstN(s []string, n int) []string {
	if s == nil {
		return []string{}
	}
	if len(s) > n {
		return s[:n]
	}
	return s
}

func computeDrift() map[string]any {
	mu.Lock()
	defer mu.Unlock()
	return computeDriftLocked()
}

func reconcile() map[string]any {
	t0 := nowMs()
	mu.Lock()
	before := computeDriftLocked()
	dbLive := map[string]doc{}
	for k, v := range db {
		if !v.Deleted {
			dbLive[k] = v
		}
	}
	for id, d := range dbLive {
		if cur, ok := index[id]; !ok || cur.Version != d.Version {
			index[id] = idxEntry{d.Version, d.Term}
		}
	}
	for id := range index {
		if _, ok := dbLive[id]; !ok {
			delete(index, id)
		}
	}
	after := computeDriftLocked()
	mu.Unlock()

	bc := before["drift_count"].(int)
	ac := after["drift_count"].(int)
	return map[string]any{
		"reconcile_duration_ms": round2(nowMs() - t0),
		"drift_before":          bc,
		"drift_after":           ac,
		"repaired":              bc - ac,
		"detail_before": map[string]any{
			"missing": before["missing"], "stale": before["stale"], "orphan": before["orphan"],
		},
		"state": after,
		"note": "El barrido es la red de seguridad de lo que el outbox no cubre: un indice restaurado de un " +
			"backup viejo, una reindexacion parcial, un borrado manual. Sin el, el outbox garantiza que ningun " +
			"cambio NUEVO se pierda — pero no arregla los que ya se perdieron.",
	}
}

// ---------------------------------------------------------------------------
// Las consultas: medir la deriva desde donde la ve el usuario
// ---------------------------------------------------------------------------

func runQueries(queries int) map[string]any {
	mu.Lock()
	defer mu.Unlock()
	dbLive := map[string]doc{}
	for k, v := range db {
		if !v.Deleted {
			dbLive[k] = v
		}
	}
	hits, expected, returned := 0, 0, 0
	for q := 0; q < queries; q++ {
		term := terms[q%len(terms)]
		esperados := map[string]struct{}{}
		for id, d := range dbLive {
			if d.Term == term {
				esperados[id] = struct{}{}
			}
		}
		for id, e := range index {
			if e.Term == term {
				returned++
				if _, ok := esperados[id]; ok {
					hits++
				}
			}
		}
		expected += len(esperados)
	}
	return map[string]any{
		"queries":              queries,
		"search_recall_pct":    round2(float64(hits) / math.Max(1, float64(expected)) * 100),
		"search_precision_pct": round2(float64(hits) / math.Max(1, float64(returned)) * 100),
		"note": "Recall bajo = la busqueda no encuentra lo que existe. Precision baja = devuelve lo que ya no " +
			"existe. Las dos se ven como 'la busqueda anda rara', no como un error.",
	}
}

func runScenario(variant string, writes, failRate, deletePct, queries int) map[string]any {
	t0 := nowMs()
	silent, retried := 0, 0
	if variant == "drifted" {
		silent = runDrifted(writes, failRate, deletePct)
	} else {
		retried = runReconciled(writes, failRate, deletePct)
		reconcile()
	}

	drift := computeDrift()
	q := runQueries(queries)

	mu.Lock()
	s := metrics[variant]
	s.Runs++
	s.Writes += writes
	s.SilentFailures += silent
	s.DriftCount += drift["drift_count"].(int)
	s.OutboxRetried += retried
	mu.Unlock()

	out := map[string]any{
		"variant": variant, "writes": writes, "fail_rate_pct": failRate, "delete_pct": deletePct,
		"silent_failures": silent, "outbox_retried": retried,
	}
	for k, v := range drift {
		out[k] = v
	}
	for k, v := range q {
		out[k] = v
	}
	out["wall_ms"] = round2(nowMs() - t0)
	if variant == "drifted" {
		out["note"] = "La escritura al indice fallo y el codigo siguio como si nada. La base y el indice no " +
			"comparten transaccion, asi que la unica forma de enterarse es mirando — y nadie mira, porque la " +
			"busqueda sigue respondiendo 200."
	} else {
		out["note"] = "El outbox garantiza que ningun cambio nuevo se pierda, el checkpoint impide saltear uno, " +
			"y el barrido repara lo que los dos primeros no cubren. Deriva final: cero."
	}
	out["go_note"] = "En Go el error es un valor y descartarlo hay que escribirlo: el `_ =` queda en el diff y " +
		"`errcheck` marca la version sin el. La contracara es que Go no tiene tipo conjunto, asi que el diff de " +
		"tres caras son tres recorridos a mano donde Python usa tres lineas de algebra."
	return out
}

func indexState() map[string]any {
	d := computeDrift()
	d["stack"] = appStack
	d["note"] = "`missing` no se encuentra, `stale` se encuentra mal y `orphan` es un fantasma. Las tres se ven " +
		"igual desde afuera — 'la busqueda anda rara' — y se arreglan distinto."
	return d
}

func diagnostics() map[string]any {
	mu.Lock()
	snapshot := map[string]slot{"drifted": *metrics["drifted"], "reconciled": *metrics["reconciled"]}
	mu.Unlock()
	return map[string]any{
		"stack": appStack, "case": caseName, "variants": snapshot, "index": indexState(),
		"fidelity": map[string]any{
			"real": "El diff de tres caras, el outbox con orden y checkpoint, y el barrido de reconciliacion son " +
				"codigo de verdad, con la primitiva idiomatica de cada runtime.",
			"modelado": "El indice de busqueda es un map en memoria, no Elasticsearch. La falla de escritura es " +
				"deterministica (multiplicador primo sobre el indice) para que el escenario sea reproducible.",
			"honesto": "Lo que importa del caso no es el motor de busqueda: es que la base y el indice son dos " +
				"sistemas sin transaccion comun. Eso es igual de cierto con un map que con Elasticsearch.",
		},
		"interpretation": map[string]any{
			"drifted": "drift_count > 0 y recall por debajo de 100 con el servicio respondiendo 200 a todo. " +
				"silent_failures cuenta las escrituras que nadie miro.",
			"reconciled": "drift_count = 0, recall y precision en 100. El outbox no dejo perder ningun cambio y " +
				"el barrido reparo lo que quedaba.",
			"go_note": "El `_ =` es la unica forma de descartar el error, y eso lo vuelve auditable. Lo que falta " +
				"es el otro lado: sin tipo conjunto, el diagnostico se escribe a mano.",
		},
	}
}

func clampInt(v, lo, hi int) int {
	if v < lo {
		return lo
	}
	if v > hi {
		return hi
	}
	return v
}

func queryInt(r *http.Request, key string, def int) int {
	raw := r.URL.Query().Get(key)
	if raw == "" {
		return def
	}
	n, err := strconv.Atoi(raw)
	if err != nil {
		return def
	}
	return n
}

func writeJSON(w http.ResponseWriter, status int, payload map[string]any) {
	payload["timestamp_utc"] = time.Now().UTC().Format("2006-01-02T15:04:05Z")
	payload["pid"] = os.Getpid()
	body, _ := json.MarshalIndent(payload, "", "  ")
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	w.Write(body)
}

func main() {
	mux := http.NewServeMux()
	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		uri := r.URL.Path
		writes := clampInt(queryInt(r, "writes", 2000), 10, 200000)
		failRate := clampInt(queryInt(r, "fail_rate", 8), 0, 100)
		deletePct := clampInt(queryInt(r, "delete_pct", 5), 0, 50)
		queries := clampInt(queryInt(r, "queries", 200), 1, 5000)

		status := 200
		var payload map[string]any

		switch uri {
		case "/", "/index":
			payload = map[string]any{
				"lab": "Problem-Driven Systems Lab", "case": caseName, "stack": appStack,
				"goal": "Mostrar que una busqueda que responde 200 puede estar respondiendo mal, y que la unica " +
					"forma de saberlo es comparar los dos lados a proposito.",
				"go_specific": "El error como valor hace visible el descarte (`_ =`), y la falta de tipo conjunto " +
					"hace que el diagnostico se escriba a mano.",
				"routes": map[string]string{
					"/health":                                    "Estado basico del servicio.",
					"/search-drifted?writes=2000&fail_rate=8":     "Dual-write: el indice se desincroniza en silencio.",
					"/search-reconciled?writes=2000&fail_rate=8":  "Outbox + checkpoint + barrido: deriva cero.",
					"/reconcile":                                 "Un barrido suelto, para ver que encuentra y que repara.",
					"/index/state":                               "Las tres caras de la deriva y la antiguedad del cambio mas viejo.",
					"/diagnostics/summary":                       "Comparativa entre variantes.",
					"/reset-lab":                                 "Vacia la base, el indice, el outbox y las metricas.",
				},
			}
		case "/health":
			payload = map[string]any{"status": "ok", "stack": appStack, "case": caseName}
		case "/search-drifted":
			payload = runScenario("drifted", writes, failRate, deletePct, queries)
		case "/search-reconciled":
			payload = runScenario("reconciled", writes, failRate, deletePct, queries)
		case "/reconcile":
			payload = reconcile()
		case "/index/state":
			payload = indexState()
		case "/diagnostics/summary":
			payload = diagnostics()
		case "/reset-lab":
			mu.Lock()
			resetAll()
			metrics = map[string]*slot{"drifted": {}, "reconciled": {}}
			mu.Unlock()
			payload = map[string]any{"status": "reset", "message": "Base, indice, outbox y metricas reiniciados."}
		default:
			status = 404
			payload = map[string]any{"error": "Ruta no encontrada", "path": uri}
		}
		writeJSON(w, status, payload)
	})

	port := envOr("PORT", "8080")
	fmt.Printf("Servidor Go escuchando en %s\n", port)
	if err := http.ListenAndServe(":"+port, mux); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}
